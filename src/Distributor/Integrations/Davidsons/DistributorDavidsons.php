<?php

namespace FFLHub\Distributor\Integrations\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

/**
 * Davidson's runtime distributor.
 *
 * Rules:
 * - Ordering is manual-handoff-only: dealer batch dispatch emails the items to order.
 * - Shipment lookup by PO is not supported (manual flow).
 * - Validation is local-table only (no remote validation API).
 * - Product lookup is fulfilled from local Davidson's table by UPC.
 */
final class DistributorDavidsons extends DistributorBase
{
    private const DEFAULT_FLAT_SHIPPING_COST = 13.0;

    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, true);
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, false);
    }

    /**
     * @param array<int,string> $upcs
     * @return array<string,DistributorProductPayload>
     */
    public function get_pricing_payloads_by_upcs(array $upcs): array
    {
        return $this->get_local_pricing_payloads_by_upcs(
            $upcs,
            [
                'sku'              => ['davidsons_item_number', 'sku'],
                'upc'              => ['upc'],
                'name'             => ['model'],
                'description'      => ['product_description'],
                'brand'            => ['manufacturer'],
                'price'            => ['distributor_price'],
                'map'              => ['retail_map'],
                'msrp'             => ['retail_msrp'],
                'quantity'         => ['inventory_quantity'],
                'category'         => ['item_type'],
                'shipping_weight'  => ['shipping_weight'],
                'ffl_required'     => ['ffl_required'],
                'sot_required'     => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw_item_type): ?array => self::map_davidsons_category($raw_item_type),
            true,
            function (DistributorProductPayload $payload, array $row, string $normalized_upc): DistributorProductPayload {
                // Davidson's is manual-order-only unless SIG approval explicitly opts it into dropship treatment.
                $payload->dropship_enabled = SigDropshipApproval::should_force_row($this->get_id(), $row);
                return $payload;
            }
        );
    }

    protected function supports_remote_validation(): bool
    {
        return false;
    }

    /**
     * @param array<string,int> $required_by_upc
     * @return array<string,mixed>
     */
    protected function validation_local_options(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): array {
        return [
            'label'              => "Davidson's validation (local)",
            'max_unique'         => 100,
            'inventory_keys'     => ['inventory_quantity', 'qty', 'quantity', 'available', 'on_hand'],
            'unknown_qty_blocks' => true,
            'code_prefix'        => 'DAVIDSONS',
        ];
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if ($this->is_dealer_batch_manual_handoff_request($request)) {
            $sent = $this->send_dealer_batch_manual_order_email($request);
            if (!$sent) {
                return DistributorOrderResult::block_retryable(
                    "Davidson's manual order email could not be sent; batch row(s) will retry.",
                    [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                    [
                        'merchant_order_id' => (string) $request->merchant_order_id,
                        'line_count' => count($request->valid_lines()),
                    ]
                );
            }
        }

        return DistributorOrderResult::manual(
            "Davidson's manual order handoff created. Enter the merchant PO on the Davidson's Manual Order Status page after ordering.",
            [DistributorOrderResult::REASON_MANUAL_REQUIRED],
            [
                'merchant_order_id' => (string) $request->merchant_order_id,
                'line_count' => count($request->valid_lines()),
                'email_sent' => $this->is_dealer_batch_manual_handoff_request($request) ? 1 : 0,
            ]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return null;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $cost = apply_filters(
            'fflhub_davidsons_flat_shipping_cost',
            self::DEFAULT_FLAT_SHIPPING_COST,
            $normalized,
            $this
        );

        return is_numeric($cost) ? (float) $cost : self::DEFAULT_FLAT_SHIPPING_COST;
    }

    private function build_payload_from_local_row(string $upc, bool $include_images): ?DistributorProductPayload
    {
        $lookup = $this->get_fulfillment_row_for_upc($upc);
        if ($lookup === null) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $lookup['row'],
            [
                'sku'              => ['davidsons_item_number', 'sku'],
                'upc'              => ['upc'],
                'name'             => ['model'],
                'description'      => ['product_description'],
                'brand'            => ['manufacturer'],
                'price'            => ['distributor_price'],
                'map'              => ['retail_map'],
                'msrp'             => ['retail_msrp'],
                'quantity'         => ['inventory_quantity'],
                'category'         => ['item_type'],
                'shipping_weight'  => ['shipping_weight'],
                'ffl_required'     => ['ffl_required'],
                'sot_required'     => ['sot_required'],
                'dropship_enabled' => ['dropship_enabled'],
            ],
            static fn($raw_item_type): ?array => self::map_davidsons_category($raw_item_type),
            $lookup['normalized_upc'],
            $include_images
        );

        // Davidson's is manual-order-only unless SIG approval explicitly opts it into dropship treatment.
        $payload->dropship_enabled = SigDropshipApproval::should_force_row($this->get_id(), $lookup['row']);

        return $payload;
    }

    /**
     * @return array{row:array<string,mixed>,normalized_upc:string}|null
     */
    private function get_fulfillment_row_for_upc(string $upc): ?array
    {
        if (!$this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $table = $this->services->get_fulfillment_table();

        // Primary exact lookup.
        $row = $table->get_row_by_upc($normalized_upc);
        if (!$row) {
            // Fallbacks for common UPC formatting differences:
            // - leading zero omitted by operator entry (11-digit input)
            // - leading zero present in DB but not in search (or vice versa)
            $candidates = $this->build_upc_lookup_candidates($normalized_upc);
            foreach ($candidates as $candidate_upc) {
                $row = $table->get_row_by_upc($candidate_upc);
                if ($row) {
                    DebugLogUtil::log_ctx(
                        'FFLHUB_ADMIN_DEBUG',
                        '[FFLHub][DistributorDavidsons]',
                        'UPC lookup matched via fallback candidate',
                        [
                            'requested_upc' => $normalized_upc,
                            'matched_upc' => $candidate_upc,
                        ]
                    );
                    break;
                }
            }
        }

        if (!$row) {
            return null;
        }
        if (!is_array($row)) {
            if (!is_object($row)) {
                return null;
            }
            $row = get_object_vars($row);
        }

        return [
            'row'            => $row,
            'normalized_upc' => $normalized_upc,
        ];
    }

    /**
     * Build non-primary UPC candidates for local-table lookups.
     *
     * @return array<int,string>
     */
    private function build_upc_lookup_candidates(string $normalized_upc): array
    {
        $candidates = [];
        $len = strlen($normalized_upc);

        if ($len === 11) {
            $candidates[] = '0' . $normalized_upc;
        } elseif ($len === 12 && strpos($normalized_upc, '0') === 0) {
            $candidates[] = substr($normalized_upc, 1);
        }

        return $candidates;
    }

    private function is_dealer_batch_manual_handoff_request(DistributorOrderRequest $request): bool
    {
        return strtolower(trim((string) $request->lane)) === 'dealer_fulfilled'
            && trim((string) $request->merchant_order_id) !== ''
            && !empty($request->valid_lines());
    }

    private function send_dealer_batch_manual_order_email(DistributorOrderRequest $request): bool
    {
        if (!function_exists('wp_mail')) {
            return false;
        }

        $recipients = $this->manual_order_email_recipients();
        if (empty($recipients)) {
            return false;
        }

        $subject = '[FFLHub] Davidson\'s manual dealer batch ready: ' . trim((string) $request->merchant_order_id);
        $sent = wp_mail(
            $recipients,
            $subject,
            $this->manual_order_email_body($request),
            ['Content-Type: text/html; charset=UTF-8']
        );

        return (bool) $sent;
    }

    /**
     * @return array<int,string>
     */
    private function manual_order_email_recipients(): array
    {
        $raw = Options::get_batch_order_notification_email();
        $raw = apply_filters('fflhub_batch_order_notification_recipients', $raw);

        if (is_string($raw)) {
            $parts = preg_split('/[,;\s]+/', $raw);
            $raw = is_array($parts) ? $parts : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $emails = [];
        foreach ($raw as $email) {
            $email = trim((string) $email);
            if ($email !== '' && is_email($email)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    private function manual_order_email_body(DistributorOrderRequest $request): string
    {
        $lines = $request->valid_lines();
        $payloads = $this->get_pricing_payloads_by_upcs(array_map(
            static fn($line): string => $line instanceof DistributorOrderLine ? (string) $line->upc : '',
            $lines
        ));

        $admin_url = function_exists('admin_url')
            ? admin_url('admin.php?page=fflhub-davidsons-manual-order-status')
            : '';

        return '<div style="font-family:Arial,sans-serif;color:#1d2327;line-height:1.45;">'
            . '<h2 style="margin:0 0 12px;border-left:6px solid #2271b1;padding-left:10px;">Davidson\'s Manual Dealer Batch</h2>'
            . '<p>This Davidson\'s dealer batch is ready to order manually. After placing the order, use the Davidson\'s Manual Order Status page to enter/confirm the merchant PO.</p>'
            . $this->manual_order_summary_table($request)
            . $this->manual_order_ship_to_block($request)
            . '<h3 style="margin:20px 0 8px;">Items To Order</h3>'
            . $this->manual_order_lines_table($lines, $payloads)
            . ($admin_url !== '' ? '<p><a href="' . esc_url($admin_url) . '">Open Davidson\'s Manual Order Status</a></p>' : '')
            . '</div>';
    }

    private function manual_order_summary_table(DistributorOrderRequest $request): string
    {
        $qty = 0;
        foreach ($request->valid_lines() as $line) {
            $qty += $line->quantity;
        }

        return $this->manual_order_key_value_table([
            'Distributor' => "Davidson's",
            'Merchant PO' => trim((string) $request->merchant_order_id),
            'Line count' => (string) count($request->valid_lines()),
            'Total quantity' => (string) $qty,
            'Lane' => trim((string) $request->lane),
            'Notes' => trim((string) $request->notes),
        ]);
    }

    private function manual_order_ship_to_block(DistributorOrderRequest $request): string
    {
        $ship_to = $request->ship_to_customer;
        $lines = array_filter([
            trim($ship_to->name),
            trim($ship_to->company),
            trim($ship_to->address1),
            trim($ship_to->address2),
            trim($ship_to->city . ', ' . $ship_to->state . ' ' . $ship_to->zip),
            trim($ship_to->phone),
            trim($ship_to->email),
        ]);

        return '<h3 style="margin:20px 0 8px;">Dealer Ship-To</h3>'
            . '<div style="border:1px solid #dcdcde;background:#fbfbfc;padding:10px;max-width:760px;">'
            . implode('<br>', array_map('esc_html', $lines))
            . '</div>';
    }

    /**
     * @param array<string,string> $rows
     */
    private function manual_order_key_value_table(array $rows): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:760px;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;width:170px;">' . esc_html($label) . '</th>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($value !== '' ? $value : '-') . '</td>'
                . '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * @param array<int,DistributorOrderLine> $lines
     * @param array<string,\FFLHub\Distributor\Models\DistributorProductPayload> $payloads
     */
    private function manual_order_lines_table(array $lines, array $payloads): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:980px;">'
            . '<thead><tr>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">UPC</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Davidson\'s Item #</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Product</th>'
            . '<th align="right" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Qty</th>'
            . '<th align="right" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">Dealer Cost</th>'
            . '<th align="left" style="border:1px solid #dcdcde;background:#f6f7f7;padding:8px;">FFL</th>'
            . '</tr></thead><tbody>';

        foreach ($lines as $line) {
            $upc = trim((string) $line->upc);
            $lookup_upc = $this->normalize_upc($upc) ?? $upc;
            $payload = $payloads[$lookup_upc] ?? $payloads[$upc] ?? null;
            $name = $payload ? trim((string) $payload->name) : '';
            $sku = $payload ? trim((string) $payload->sku) : '';
            $price = $payload ? (float) $payload->price : 0.0;

            $html .= '<tr>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($upc) . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($sku !== '' ? $sku : '-') . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($name !== '' ? $name : '-') . '</td>'
                . '<td align="right" style="border:1px solid #dcdcde;padding:8px;">' . esc_html((string) max(1, (int) $line->quantity)) . '</td>'
                . '<td align="right" style="border:1px solid #dcdcde;padding:8px;">' . esc_html($price > 0.0 ? '$' . number_format($price, 2) : '-') . '</td>'
                . '<td style="border:1px solid #dcdcde;padding:8px;">' . esc_html($line->ffl_required ? 'Yes' : 'No') . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param mixed $raw_item_type
     * @return array<int,string>|null
     */
    private static function map_davidsons_category($raw_item_type): ?array
    {
        $item_type = trim((string) $raw_item_type);
        if ($item_type === '') {
            return null;
        }

        return DistributorProductCategoryMapper::map_davidsons($item_type);
    }
}
