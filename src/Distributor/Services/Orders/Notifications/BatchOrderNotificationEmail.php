<?php

namespace FFLHub\Distributor\Services\Orders\Notifications;

use WC_Order;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sends internal notices after an aggregate batch order is successfully placed.
 */
final class BatchOrderNotificationEmail
{
    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     * @param array<int,DistributorOrderLine> $aggregate_lines
     */
    public static function send(
        string $dist_id,
        string $po,
        string $batch_kind,
        DistributorOrderResult $result,
        array $batch_candidates,
        array $aggregate_lines,
        ?DistributorShipTo $ship_to,
        bool $is_ca_relay
    ): void {
        if (!function_exists('wp_mail')) {
            return;
        }

        $recipients = self::recipients();
        if (empty($recipients)) {
            return;
        }

        try {
            $label = self::distributor_label($dist_id);
            $type_label = $is_ca_relay ? 'CA RELAY BATCH' : 'Dealer batch';
            $subject = sprintf('[FFLHub] %s sent: %s %s', $type_label, $label, $po);

            wp_mail(
                $recipients,
                $subject,
                self::body($label, $po, $batch_kind, $result, $batch_candidates, $aggregate_lines, $ship_to, $is_ca_relay),
                ['Content-Type: text/html; charset=UTF-8']
            );
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[FFLHub][BatchOrderNotificationEmail] failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private static function recipients(): array
    {
        $raw = get_option(Options::OPTION_BATCH_ORDER_NOTIFICATION_EMAIL, Options::default_batch_order_notification_email());
        $raw = apply_filters('fflhub_batch_order_notification_recipients', $raw);

        if (is_string($raw)) {
            $parts = preg_split('/[,;\s]+/', $raw);
            $raw = is_array($parts) ? $parts : [];
        }

        if (!is_array($raw)) {
            $raw = [];
        }

        $emails = [];
        foreach ($raw as $email) {
            $email = trim((string) $email);
            if ($email === '' || !is_email($email)) {
                continue;
            }
            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     * @param array<int,DistributorOrderLine> $aggregate_lines
     */
    private static function body(
        string $distributor_label,
        string $po,
        string $batch_kind,
        DistributorOrderResult $result,
        array $batch_candidates,
        array $aggregate_lines,
        ?DistributorShipTo $ship_to,
        bool $is_ca_relay
    ): string {
        $title = $is_ca_relay ? 'CA Relay Batch Order Sent' : 'Dealer Batch Order Sent';
        $accent = $is_ca_relay ? '#d63638' : '#2271b1';
        $external_ids = array_filter(array_map('strval', (array) $result->external_order_ids));
        $external_display = empty($external_ids) ? '-' : implode(', ', $external_ids);

        $relay_note = '';
        if ($is_ca_relay) {
            $relay_note = '<div style="margin:16px 0;padding:12px 14px;border-left:5px solid #d63638;background:#fff5f5;color:#8a0000;font-weight:700;">'
                . 'CA RELAY: this distributor batch was sent to the configured relay ship-to address, not directly to the California customer.'
                . '</div>';
        }

        return '<div style="font-family:Arial,sans-serif;color:#1d2327;line-height:1.45;">'
            . '<h2 style="margin:0 0 12px;border-left:6px solid ' . esc_attr($accent) . ';padding-left:10px;">' . esc_html($title) . '</h2>'
            . $relay_note
            . self::summary_table([
                'Distributor' => $distributor_label,
                'PO' => $po,
                'Batch kind' => $batch_kind,
                'Woo rows' => (string) count($batch_candidates),
                'Unique UPC lines' => (string) count($aggregate_lines),
                'External order ID(s)' => $external_display,
                'Result' => trim((string) $result->code . ' - ' . (string) $result->message),
            ])
            . self::ship_to_block($ship_to, $is_ca_relay)
            . '<h3 style="margin:20px 0 8px;">Aggregate Items</h3>'
            . self::aggregate_lines_table($aggregate_lines)
            . '<h3 style="margin:20px 0 8px;">Contributing Woo Orders</h3>'
            . self::orders_table($batch_candidates)
            . '</div>';
    }

    /**
     * @param array<string,string> $rows
     */
    private static function summary_table(array $rows): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:760px;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;width:190px;">' . esc_html($label) . '</th>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($value) . '</td>'
                . '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private static function ship_to_block(?DistributorShipTo $ship_to, bool $is_ca_relay): string
    {
        if (!($ship_to instanceof DistributorShipTo)) {
            return '';
        }

        $heading = $is_ca_relay ? 'Relay Ship-To' : 'Batch Ship-To';
        $lines = array_filter([
            trim($ship_to->name),
            trim($ship_to->company),
            trim($ship_to->address1),
            trim($ship_to->address2),
            trim($ship_to->city . ', ' . $ship_to->state . ' ' . $ship_to->zip),
        ]);

        return '<h3 style="margin:20px 0 8px;">' . esc_html($heading) . '</h3>'
            . '<div style="border:1px solid #dcdcde;background:#fbfbfc;padding:10px;max-width:760px;">'
            . implode('<br>', array_map('esc_html', $lines))
            . '</div>';
    }

    /**
     * @param array<int,DistributorOrderLine> $aggregate_lines
     */
    private static function aggregate_lines_table(array $aggregate_lines): string
    {
        if (empty($aggregate_lines)) {
            return '<p>No aggregate lines were available.</p>';
        }

        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:760px;">'
            . '<thead><tr>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">UPC</th>'
            . '<th align="right" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Qty</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">FFL</th>'
            . '</tr></thead><tbody>';

        foreach ($aggregate_lines as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                continue;
            }
            $html .= '<tr>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) $line->upc) . '</td>'
                . '<td align="right" style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) max(1, (int) $line->quantity)) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($line->ffl_required ? 'Yes' : 'No') . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param array<int,array{job:OrderPlacementJobRow,order:WC_Order,lines:array<int,DistributorOrderLine>}> $batch_candidates
     */
    private static function orders_table(array $batch_candidates): string
    {
        if (empty($batch_candidates)) {
            return '<p>No contributing Woo orders were available.</p>';
        }

        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:920px;">'
            . '<thead><tr>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Order</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Customer</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Job</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Lines</th>'
            . '</tr></thead><tbody>';

        foreach ($batch_candidates as $entry) {
            $order = $entry['order'] ?? null;
            $job = $entry['job'] ?? null;
            $lines = $entry['lines'] ?? [];

            $html .= '<tr>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html(self::order_label($order, $job)) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html(self::customer_label($order)) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html(self::job_label($job)) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . self::line_summary($lines) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private static function order_label($order, $job): string
    {
        if ($order instanceof WC_Order) {
            return '#' . (string) $order->get_order_number();
        }

        if ($job instanceof OrderPlacementJobRow && (int) $job->order_id > 0) {
            return '#' . (string) $job->order_id;
        }

        return '-';
    }

    private static function customer_label($order): string
    {
        if (!($order instanceof WC_Order)) {
            return '-';
        }

        $name = trim((string) $order->get_formatted_billing_full_name());
        if ($name === '') {
            $name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
        }

        return $name !== '' ? $name : '-';
    }

    private static function job_label($job): string
    {
        if (!($job instanceof OrderPlacementJobRow)) {
            return '-';
        }

        $parts = [];
        if ((int) $job->id > 0) {
            $parts[] = 'ID ' . (string) $job->id;
        }

        $job_key = trim((string) $job->job_key_norm());
        if ($job_key !== '') {
            $parts[] = $job_key;
        }

        return empty($parts) ? '-' : implode(' / ', $parts);
    }

    /**
     * @param array<int,DistributorOrderLine> $lines
     */
    private static function line_summary(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                continue;
            }
            $parts[] = esc_html((string) $line->upc . ' x ' . (string) max(1, (int) $line->quantity));
        }

        return empty($parts) ? '-' : implode('<br>', $parts);
    }

    private static function distributor_label(string $dist_id): string
    {
        $dist_id = strtolower(trim($dist_id));
        if ($dist_id === 'rsr') {
            return 'RSR';
        }
        if ($dist_id === 'lipseys') {
            return "Lipsey's";
        }
        if ($dist_id === 'zanders') {
            return 'Zanders';
        }

        return strtoupper($dist_id);
    }
}
