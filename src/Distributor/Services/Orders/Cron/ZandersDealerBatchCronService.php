<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin Zanders dealer batch wrapper over the shared batch engine.
 */
final class ZandersDealerBatchCronService extends AbstractOrderBatchCronService
{
    /** Action Scheduler / WP-Cron hook polled every minute for Zanders dealer batch work. */
    public const CRON_HOOK = 'fflhub_zanders_dealer_batch_poll';

    private const LOG_PREFIX = '[FFLHub][ZandersDealerBatchCronService]';

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_log_prefix(): string
    {
        return self::LOG_PREFIX;
    }

    protected function get_distributor_id(): string
    {
        return 'zanders';
    }

    protected function get_option_prefix(): string
    {
        return 'fflhub_zanders_dealer_batch';
    }

    protected function get_po_prefix(): string
    {
        return 'ZANB';
    }

    protected function get_batch_description_prefix(): string
    {
        return 'Zanders dealer batch aggregate';
    }

    protected function get_batch_mode(): string
    {
        return self::MODE_DEALER;
    }

    /**
     * @param array<string,mixed> $entry
     */
    protected function should_hold_batch_entry_for_manual_order(array $entry): bool
    {
        return $this->manual_dealer_fulfilled_enabled();
    }

    protected function manual_batch_entry_message(): string
    {
        return 'Zanders dealer-fulfilled row requires manual ordering. Switch Zanders dealer-fulfilled mode to Auto when SOAP dealer credentials are ready.';
    }

    protected function manual_batch_entry_reason_code(): string
    {
        return 'ZANDERS_DEALER_FULFILLED_MANUAL_MODE';
    }

    protected function should_alert_risky_manual_hold_entries(): bool
    {
        return $this->manual_dealer_fulfilled_enabled();
    }

    /**
     * @param array<int,array<string,mixed>> $manual_hold_entries
     * @param array<string,int> $demand_by_upc
     * @param array<string,int|null> $stock_cache
     * @param array<string,true> $risky_upcs
     */
    protected function send_risky_manual_hold_alert(
        array $manual_hold_entries,
        array $demand_by_upc,
        array $stock_cache,
        array $risky_upcs,
        int $low_threshold,
        string $run_id
    ): void {
        if (!function_exists('wp_mail')) {
            return;
        }

        $recipients = $this->risk_alert_recipients();
        if (empty($recipients)) {
            return;
        }

        $rows = $this->risk_alert_rows($manual_hold_entries, $demand_by_upc, $stock_cache, $risky_upcs);
        if (empty($rows)) {
            return;
        }

        $upcs = array_values(array_unique(array_map(static function (array $row): string {
            return (string) ($row['upc'] ?? '');
        }, $rows)));
        $upcs = array_values(array_filter($upcs, static function (string $upc): bool {
            return $upc !== '';
        }));

        $subject = (string) apply_filters(
            'fflhub_zanders_dealer_manual_risk_alert_subject',
            sprintf(
                '[FFLHub] Zanders manual dealer row at stock risk: %s',
                empty($upcs) ? 'unknown UPC' : implode(', ', array_slice($upcs, 0, 5))
            ),
            $rows,
            $run_id
        );

        $body = (string) apply_filters(
            'fflhub_zanders_dealer_manual_risk_alert_body',
            $this->risk_alert_body($rows, $low_threshold, $run_id),
            $rows,
            $run_id
        );

        try {
            wp_mail(
                $recipients,
                $subject,
                $body,
                ['Content-Type: text/html; charset=UTF-8']
            );
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                error_log(self::LOG_PREFIX . ' manual risk alert failed: ' . $e->getMessage());
            }
        }
    }

    private function manual_dealer_fulfilled_enabled(): bool
    {
        $mode = strtolower(trim((string) Options::get_distributor_option('zanders', 'dealer_fulfilled_mode', 'manual')));
        return $mode !== 'auto';
    }

    /**
     * @return array<int,string>
     */
    private function risk_alert_recipients(): array
    {
        $raw = get_option(
            Options::OPTION_BATCH_ORDER_NOTIFICATION_EMAIL,
            Options::default_batch_order_notification_email()
        );
        $raw = apply_filters('fflhub_zanders_dealer_manual_risk_alert_recipients', $raw);

        if (is_string($raw)) {
            $parts = preg_split('/[,;\s]+/', $raw);
            $raw = is_array($parts) ? $parts : [];
        }

        if (!is_array($raw)) {
            $raw = [];
        }

        $recipients = [];
        foreach ($raw as $email) {
            $email = sanitize_email((string) $email);
            if ($email !== '' && is_email($email)) {
                $recipients[$email] = true;
            }
        }

        return array_keys($recipients);
    }

    /**
     * @param array<int,array<string,mixed>> $manual_hold_entries
     * @param array<string,int> $demand_by_upc
     * @param array<string,int|null> $stock_cache
     * @param array<string,true> $risky_upcs
     * @return array<int,array<string,mixed>>
     */
    private function risk_alert_rows(
        array $manual_hold_entries,
        array $demand_by_upc,
        array $stock_cache,
        array $risky_upcs
    ): array {
        $rows = [];

        foreach ($manual_hold_entries as $entry) {
            $job = $entry['job'] ?? null;
            $order = $entry['order'] ?? null;
            $lines = isset($entry['lines']) && is_array($entry['lines']) ? $entry['lines'] : [];

            foreach ($lines as $line) {
                if (!($line instanceof DistributorOrderLine)) {
                    continue;
                }

                $upc_key = $this->normalize_upc_key((string) $line->upc);
                if ($upc_key === '' || !isset($risky_upcs[$upc_key])) {
                    continue;
                }

                $rows[] = [
                    'upc' => trim((string) $line->upc),
                    'upc_key' => $upc_key,
                    'qty' => max(1, (int) $line->quantity),
                    'queued_demand' => (int) ($demand_by_upc[$upc_key] ?? 0),
                    'available_stock' => array_key_exists($upc_key, $stock_cache) ? $stock_cache[$upc_key] : null,
                    'order_label' => $this->order_label($order, $job),
                    'customer_label' => $this->customer_label($order),
                    'job_label' => $this->job_label($job),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function risk_alert_body(array $rows, int $low_threshold, string $run_id): string
    {
        return '<div style="font-family:Arial,sans-serif;color:#1d2327;line-height:1.45;">'
            . '<h2 style="margin:0 0 12px;border-left:6px solid #d63638;padding-left:10px;">Zanders Manual Dealer Row At Stock Risk</h2>'
            . '<p>A Zanders dealer-fulfilled row was held for manual ordering while Zanders is in manual mode. One or more UPCs are at/under the low-stock risk rule and should be handled quickly.</p>'
            . $this->risk_alert_summary_table([
                'Run ID' => $run_id,
                'Low-stock threshold' => (string) $low_threshold,
                'Manual rows affected' => (string) count($rows),
                'Action needed' => 'Place or review the manual Zanders dealer order row.',
            ])
            . '<h3 style="margin:20px 0 8px;">At-Risk Manual Rows</h3>'
            . $this->risk_alert_rows_table($rows)
            . '</div>';
    }

    /**
     * @param array<string,string> $rows
     */
    private function risk_alert_summary_table(array $rows): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:760px;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;width:190px;">' . esc_html($label) . '</th>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($value) . '</td>'
                . '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function risk_alert_rows_table(array $rows): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:960px;">'
            . '<thead><tr>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">UPC</th>'
            . '<th align="right" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Row Qty</th>'
            . '<th align="right" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Queued Demand</th>'
            . '<th align="right" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Zanders Stock</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Order</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Customer</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Job</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $available = $row['available_stock'] ?? null;
            $html .= '<tr>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) ($row['upc'] ?? '')) . '</td>'
                . '<td align="right" style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) ((int) ($row['qty'] ?? 0))) . '</td>'
                . '<td align="right" style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) ((int) ($row['queued_demand'] ?? 0))) . '</td>'
                . '<td align="right" style="border:1px solid #dcdcde;padding:8px;">' . esc_html($available === null ? 'Unknown' : (string) ((int) $available)) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) ($row['order_label'] ?? '-')) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) ($row['customer_label'] ?? '-')) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) ($row['job_label'] ?? '-')) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function order_label($order, $job): string
    {
        if ($order instanceof \WC_Order) {
            return '#' . (string) $order->get_order_number();
        }

        if (is_object($job) && isset($job->order_id) && (int) $job->order_id > 0) {
            return '#' . (string) ((int) $job->order_id);
        }

        return '-';
    }

    private function customer_label($order): string
    {
        if (!($order instanceof \WC_Order)) {
            return '-';
        }

        $name = trim((string) $order->get_formatted_billing_full_name());
        if ($name === '') {
            $name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
        }

        return $name !== '' ? $name : '-';
    }

    private function job_label($job): string
    {
        if (!is_object($job)) {
            return '-';
        }

        $parts = [];
        if (isset($job->id) && (int) $job->id > 0) {
            $parts[] = 'ID ' . (string) ((int) $job->id);
        }
        if (method_exists($job, 'job_key_norm')) {
            $job_key = trim((string) $job->job_key_norm());
            if ($job_key !== '') {
                $parts[] = $job_key;
            }
        }

        return empty($parts) ? '-' : implode(' / ', $parts);
    }

    private function normalize_upc_key(string $upc): string
    {
        $upc = trim($upc);
        if ($upc === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $upc);
        if (is_string($digits) && $digits !== '') {
            return $digits;
        }

        return strtoupper($upc);
    }
}
