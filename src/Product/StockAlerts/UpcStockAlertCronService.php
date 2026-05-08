<?php

namespace FFLHub\Product\StockAlerts;

use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class UpcStockAlertCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_upc_stock_alert_check';

    private const DEBUG_CONST = 'FFLHUB_UPC_STOCK_ALERT_DEBUG';
    private const LOG_PREFIX = '[FFLHub][UPCStockAlerts]';

    protected function get_interval_seconds(): int
    {
        return 5 * 60;
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
                $patch['last_product_name'] = (string) ($lookup['product_name'] ?? '');
                $patch['last_product_id'] = (int) ($lookup['product_id'] ?? 0);
                $patch['last_stock_status'] = (string) ($lookup['stock_status'] ?? '');
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
     *   product_name:string,
     *   product_id:int,
     *   stock_status:string,
     *   min_price:?float,
     *   error:string
     * }
     */
    private function lookup_upc(string $upc): array
    {
        $context = UpcStockAlertStore::get_product_context_for_upc($upc);
        $product_id = (int) ($context['product_id'] ?? 0);
        $quantity = $context['stock_quantity'];
        $is_in_stock = ($quantity !== null) ? ((int) $quantity > 0) : !empty($context['is_in_stock']);

        return [
            'reliable' => true,
            'in_stock' => $is_in_stock,
            'total_quantity' => ($quantity !== null) ? (int) $quantity : ($is_in_stock ? 1 : 0),
            'product_name' => (string) ($context['name'] ?? ''),
            'product_id' => $product_id,
            'stock_status' => (string) ($context['stock_status'] ?? ''),
            'min_price' => $context['price'],
            'error' => $product_id > 0 ? '' : 'No linked Woo product found for this UPC.',
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
            'Woo stock quantity: ' . (string) ((int) ($lookup['total_quantity'] ?? 0)),
            'Woo stock status: ' . (string) (($lookup['stock_status'] ?? '') !== '' ? $lookup['stock_status'] : 'unknown'),
            'Checked at: ' . $now_utc . ' UTC',
        ];

        $product_id = (int) ($lookup['product_id'] ?? 0);
        if ($product_id > 0) {
            $lines[] = 'Woo product ID: ' . (string) $product_id;
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
