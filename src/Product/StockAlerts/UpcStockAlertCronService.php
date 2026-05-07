<?php

namespace FFLHub\Product\StockAlerts;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class UpcStockAlertCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_upc_stock_alert_check';

    private const DEBUG_CONST = 'FFLHUB_UPC_STOCK_ALERT_DEBUG';
    private const LOG_PREFIX = '[FFLHub][UPCStockAlerts]';

    private DistributorHandler $handler;

    public function __construct(DistributorHandler $handler)
    {
        $this->handler = $handler;
    }

    protected function get_interval_seconds(): int
    {
        return 30 * 60;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 5 * 60;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_stock_alerts';
    }

    public function run(): void
    {
        $started = microtime(true);
        $now_utc = (string) current_time('mysql', true);

        if (!UpcStockAlertStore::is_enabled()) {
            self::debug('run skipped: alerts disabled');
            return;
        }

        $watchlist = UpcStockAlertStore::get_watchlist();
        if (empty($watchlist)) {
            self::debug('run skipped: empty watchlist');
            return;
        }

        $recipients = UpcStockAlertStore::get_recipient_emails();
        if (empty($recipients)) {
            self::debug('run skipped: no valid recipients');
            return;
        }

        $checked = 0;
        $alerted = 0;
        $errors = 0;

        foreach ($watchlist as $upc => $row) {
            if (empty($row['enabled'])) {
                continue;
            }

            $checked++;
            $lookup = $this->lookup_upc($upc);
            $reliable = !empty($lookup['reliable']);
            $is_in_stock = !empty($lookup['in_stock']);
            $was_in_stock = $row['last_in_stock'] ?? null;
            $should_alert = ($reliable && $is_in_stock && $was_in_stock !== true);

            $patch = [
                'last_checked_at' => $now_utc,
                'last_error' => (string) ($lookup['error'] ?? ''),
            ];

            if ($reliable) {
                $patch['last_quantity'] = (int) ($lookup['total_quantity'] ?? 0);
                $patch['last_distributors'] = implode(', ', (array) ($lookup['in_stock_distributors'] ?? []));
                $patch['last_product_name'] = (string) ($lookup['product_name'] ?? '');
                $patch['last_price'] = $lookup['min_price'];

                if (!$should_alert) {
                    $patch['last_in_stock'] = $is_in_stock;
                }
            } else {
                $errors++;
            }

            if ($should_alert) {
                $sent = $this->send_alert_email($recipients, $upc, $row, $lookup, $now_utc);
                if ($sent) {
                    $patch['last_in_stock'] = true;
                    $patch['last_notified_at'] = $now_utc;
                    $patch['last_error'] = '';
                    $alerted++;
                } else {
                    $patch['last_error'] = 'Stock alert email failed to send; will retry while item remains in stock.';
                    $errors++;
                }
            }

            UpcStockAlertStore::update_row($upc, $patch);
        }

        self::debug_ctx('run complete', [
            'watchlist_count' => count($watchlist),
            'checked' => $checked,
            'alerted' => $alerted,
            'errors' => $errors,
            'elapsed_ms' => round((microtime(true) - $started) * 1000.0, 2),
        ]);
    }

    /**
     * @return array{
     *   reliable:bool,
     *   in_stock:bool,
     *   total_quantity:int,
     *   in_stock_distributors:string[],
     *   product_name:string,
     *   min_price:?float,
     *   offers:array<int,array<string,mixed>>,
     *   error:string
     * }
     */
    private function lookup_upc(string $upc): array
    {
        $offers = [];
        $errors = [];
        $queried = 0;
        $payloads_seen = 0;
        $product_name = '';
        $min_price = null;
        $total_quantity = 0;
        $in_stock_distributors = [];

        foreach ($this->handler->get_distributors() as $dist_id => $distributor) {
            if (!Options::is_distributor_enabled((string) $dist_id)) {
                continue;
            }
            if (!($distributor instanceof DistributorBase)) {
                continue;
            }

            $queried++;
            try {
                $payload = $distributor->get_pricing_payload_by_upc($upc);
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s', (string) $dist_id, $e->getMessage());
                continue;
            }

            if (!($payload instanceof DistributorProductPayload)) {
                continue;
            }

            $payloads_seen++;
            $qty = max(0, (int) $payload->quantity);
            $price = max(0.0, (float) $payload->price);
            $name = trim((string) ($payload->name !== '' ? $payload->name : $payload->description));
            if ($product_name === '' && $name !== '') {
                $product_name = $name;
            }
            if ($price > 0.0 && ($min_price === null || $price < $min_price)) {
                $min_price = $price;
            }

            if ($qty <= 0) {
                continue;
            }

            $total_quantity += $qty;
            $in_stock_distributors[] = (string) $dist_id;
            $offers[] = [
                'distributor' => (string) $dist_id,
                'quantity' => $qty,
                'price' => $price,
                'name' => $name,
                'sku' => (string) $payload->sku,
            ];
        }

        $reliable = ($queried > 0 && count($errors) < $queried);
        $error = '';
        if ($queried <= 0) {
            $error = 'No enabled distributors available for UPC stock checks.';
        } elseif (!$reliable) {
            $error = 'All enabled distributor lookups failed: ' . implode('; ', $errors);
        } elseif (!empty($errors)) {
            $error = 'Some distributor lookups failed: ' . implode('; ', $errors);
        }

        return [
            'reliable' => $reliable,
            'in_stock' => ($total_quantity > 0),
            'total_quantity' => $total_quantity,
            'in_stock_distributors' => array_values(array_unique($in_stock_distributors)),
            'product_name' => $product_name,
            'min_price' => $min_price,
            'offers' => $offers,
            'error' => $error,
            'payloads_seen' => $payloads_seen,
        ];
    }

    /**
     * @param string[] $recipients
     * @param array<string,mixed> $row
     * @param array<string,mixed> $lookup
     */
    private function send_alert_email(array $recipients, string $upc, array $row, array $lookup, string $now_utc): bool
    {
        if (!function_exists('wp_mail')) {
            return false;
        }

        $product_name = trim((string) ($lookup['product_name'] ?? ''));
        if ($product_name === '') {
            $product_name = trim((string) ($row['last_product_name'] ?? ''));
        }
        if ($product_name === '') {
            $product_name = 'Watched UPC';
        }

        $subject = sprintf('[%s] UPC %s is back in stock', wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES), $upc);
        $lines = [
            'A watched UPC is back in stock.',
            '',
            'UPC: ' . $upc,
            'Product: ' . $product_name,
            'Total available quantity: ' . (string) ((int) ($lookup['total_quantity'] ?? 0)),
            'Checked at: ' . $now_utc . ' UTC',
            '',
            'Available distributor offers:',
        ];

        foreach ((array) ($lookup['offers'] ?? []) as $offer) {
            $price = isset($offer['price']) && is_numeric($offer['price']) ? '$' . number_format((float) $offer['price'], 2) : 'unknown';
            $sku = trim((string) ($offer['sku'] ?? ''));
            $lines[] = sprintf(
                '- %s: qty %d, price %s%s',
                (string) ($offer['distributor'] ?? 'unknown'),
                (int) ($offer['quantity'] ?? 0),
                $price,
                $sku !== '' ? ', sku ' . $sku : ''
            );
        }

        $body = implode("\n", $lines);
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($recipients, $subject, $body, $headers);

        self::debug_ctx('alert email attempted', [
            'upc' => $upc,
            'recipient_count' => count($recipients),
            'sent' => $sent ? 1 : 0,
            'total_quantity' => (int) ($lookup['total_quantity'] ?? 0),
        ]);

        return (bool) $sent;
    }

    private static function debug(string $message): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $message);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function debug_ctx(string $message, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $message, $ctx);
    }
}
