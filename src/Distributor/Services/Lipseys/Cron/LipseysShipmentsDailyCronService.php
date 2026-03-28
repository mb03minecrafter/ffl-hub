<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysShipmentTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Daily job:
 * - Calls Lipsey's Shipping/OneDay for the previous day (UTC)
 *   using both dealer (dropship) and main credentials
 * - Normalizes response
 * - Upserts into Lipsey's shipment table keyed by PO + Tracking
 */
final class LipseysShipmentsDailyCronService extends AbstractCronService
{
    private const CRON_HOOK = 'fflhub_lipseys_shipments_daily';

    private LipseysShipmentTable $shipmentTable;

    public function __construct(LipseysShipmentTable $shipmentTable)
    {
        $this->shipmentTable = $shipmentTable;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * Informational only (AS handles scheduling).
     */
    protected function get_schedule_key(): string
    {
        return 'fflhub_daily';
    }

    protected function get_interval_seconds(): int
    {
        return DAY_IN_SECONDS;
    }

    protected function get_interval_display(): string
    {
        return __('Daily (FFLHub Lipsey\'s Shipments)', 'ffl-hub');
    }

    protected function get_initial_delay_seconds(): int
    {
        return 120;
    }

    /**
     * Main worker.
     */
    public function run(): void
    {
        $t0 = microtime(true);

        $this->log_debug('[FFLHub][Lipsey\'s Shipments Cron] ---- RUN START ----');

        // Always ensure table exists (safe + idempotent).
        if (method_exists($this->shipmentTable, 'createTables')) {
            $this->shipmentTable->createTables();
        }

        // Target previous day (UTC)
        // Example: "1/25/2026"
        $ship_date = gmdate('n/j/Y', time() - DAY_IN_SECONDS);
        $this->log_debug('[FFLHub][Lipsey\'s Shipments Cron] target_date=' . $ship_date);

        $ship_date_mysql = gmdate('Y-m-d', strtotime($ship_date));
        $all_rows = [];

        $credential_profiles = [
            [
                'label' => 'dealer',
                'email' => trim((string) Options::get_distributor_option('lipseys', 'dealer_email', '')),
                'password' => trim((string) Options::get_distributor_option('lipseys', 'dealer_password', '')),
            ],
            [
                'label' => 'main',
                'email' => trim((string) Options::get_distributor_option('lipseys', 'main_account_email', '')),
                'password' => trim((string) Options::get_distributor_option('lipseys', 'main_account_password', '')),
            ],
        ];

        foreach ($credential_profiles as $profile) {
            $label = (string) ($profile['label'] ?? 'unknown');
            $email = trim((string) ($profile['email'] ?? ''));
            $password = trim((string) ($profile['password'] ?? ''));

            if ($email === '' || $password === '') {
                $this->log_debug(
                    '[FFLHub][Lipsey\'s Shipments Cron] Missing credentials for profile=' . $label
                );
                continue;
            }

            // ------------------------------------------------------------
            // 1) Build API client
            // ------------------------------------------------------------
            try {
                $client = new LipseysClient($email, $password);
            } catch (\Throwable $e) {
                $this->log_debug(
                    '[FFLHub][Lipsey\'s Shipments Cron] Client init failed for profile='
                    . $label . ': ' . $e->getMessage()
                );
                continue;
            }

            // ------------------------------------------------------------
            // 2) Call API
            // ------------------------------------------------------------
            $t_api    = microtime(true);
            $response = $client->OneDaysShipping($ship_date);
            $api_ms   = (microtime(true) - $t_api) * 1000.0;

            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipsey\'s Shipments Cron] API call completed in %.2f ms (profile=%s)',
                    $api_ms,
                    $label
                )
            );

            $this->log_debug(
                '[FFLHub][Lipsey\'s Shipments Cron] RAW_API_RESPONSE profile='
                . $label . ' payload=' . wp_json_encode($response, JSON_PRETTY_PRINT)
            );

            // ------------------------------------------------------------
            // 3) Validate response
            // ------------------------------------------------------------
            if (!is_array($response)) {
                $this->log_debug(
                    '[FFLHub][Lipsey\'s Shipments Cron] Invalid API response type for profile=' . $label
                );
                continue;
            }

            if (
                empty($response['authorized']) ||
                empty($response['success'])
            ) {
                $this->log_debug(
                    '[FFLHub][Lipsey\'s Shipments Cron] API error for profile='
                    . $label . ': '
                    . wp_json_encode($response['errors'] ?? [])
                );
                continue;
            }

            $shipments = $response['data'] ?? [];

            if (!is_array($shipments) || empty($shipments)) {
                $this->log_debug(
                    '[FFLHub][Lipsey\'s Shipments Cron] No shipments returned for date '
                    . $ship_date . ' (profile=' . $label . ')'
                );
                continue;
            }

            $this->log_debug(
                '[FFLHub][Lipsey\'s Shipments Cron] Shipments returned='
                . count($shipments) . ' (profile=' . $label . ')'
            );

            // ------------------------------------------------------------
            // 4) Normalize rows
            // ------------------------------------------------------------
            $t_norm = microtime(true);
            $rows   = $this->normalize_rows($shipments, $ship_date_mysql);
            $norm_ms = (microtime(true) - $t_norm) * 1000.0;

            $this->log_debug(
                sprintf(
                    '[FFLHub][Lipsey\'s Shipments Cron] Normalized rows=%d in %.2f ms (profile=%s)',
                    count($rows),
                    $norm_ms,
                    $label
                )
            );

            if (!empty($rows)) {
                $all_rows = array_merge($all_rows, $rows);
            }
        }

        if (empty($all_rows)) {
            $this->log_debug('[FFLHub][Lipsey\'s Shipments Cron] No valid rows after normalization across all credential profiles');
            return;
        }

        // ------------------------------------------------------------
        // 5) Insert
        // ------------------------------------------------------------

        $t_insert = microtime(true);
        $inserted = $this->shipmentTable->insert_rows($all_rows);
        $insert_ms = (microtime(true) - $t_insert) * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipsey\'s Shipments Cron] Inserted rows=%d from normalized_total=%d (%.2f ms)',
                $inserted,
                count($all_rows),
                $insert_ms
            )
        );

        // ------------------------------------------------------------
        // 6) Done
        // ------------------------------------------------------------

        $total_ms = (microtime(true) - $t0) * 1000.0;

        $this->log_debug(
            sprintf(
                '[FFLHub][Lipsey\'s Shipments Cron] ---- RUN END (total_ms=%.2f) ----',
                $total_ms
            )
        );
    }

    /**
     * Normalize API shipment rows into DB rows.
     *
     * @param array<int,array<string,mixed>> $api_rows
     * @param string                        $ship_date_mysql
     *
     * @return array<int,array<string,string>>
     */
    private function normalize_rows(array $api_rows, string $ship_date_mysql): array
    {
        $rows = [];
        $now  = gmdate('Y-m-d H:i:s');

        foreach ($api_rows as $r) {
            if (! is_array($r)) {
                continue;
            }

            $po = trim((string) ($r['poNumber'] ?? ''));
            $tn = trim((string) ($r['trackingNumber'] ?? ''));

            // PK requirements
            if ($po === '' || $tn === '') {
                continue;
            }

            $raw_hash = sha1(wp_json_encode($r));

            $rows[] = [
                'po_number'        => $po,
                'invoice_number'  => (string) ($r['invoiceNumber'] ?? ''),
                'order_number'    => (string) ($r['orderNumber'] ?? ''),
                'tracking_number' => $tn,

                'shipping_service' => (string) ($r['shippingService'] ?? ''),
                'weight'           => (string) ($r['weight'] ?? ''),
                'bill_name'        => (string) ($r['billName'] ?? ''),
                'ship_name'        => (string) ($r['shipName'] ?? ''),

                'ship_date'   => $ship_date_mysql,
                'ingested_at' => $now,
                'source'      => 'lipseys',
                'raw_hash'    => $raw_hash,
            ];
        }

        return $rows;
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][LipseysShipmentsCron]', $message);
    }
}
