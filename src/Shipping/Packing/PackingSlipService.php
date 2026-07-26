<?php
declare(strict_types=1);

namespace FFLHub\Shipping\Packing;

use FFLHub\Shipping\DTO\ShippingPackage;
use FFLHub\Shipping\ShippingOptions;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds print-ready packing slips from the package DTO used by label flows.
 *
 * The slip is intentionally HTML instead of PDF: the admin can preview it in a
 * browser, print it directly, and we can reuse the exact same markup from the
 * order-label panel and the Shipping > Packing Slips preview page.
 */
final class PackingSlipService
{
    /**
     * @param array<string,mixed> $label
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function generate_for_label(WC_Order $order, array $label, int $package_index = 0)
    {
        $snapshot = is_array($label['shipment_snapshot'] ?? null) ? $label['shipment_snapshot'] : [];
        $packages = isset($snapshot['packages']) && is_array($snapshot['packages'])
            ? array_values($snapshot['packages'])
            : [];
        $package_details = isset($label['package_details']) && is_array($label['package_details'])
            ? array_values($label['package_details'])
            : [];
        $package_items = isset($label['package_items']) && is_array($label['package_items'])
            ? array_values($label['package_items'])
            : [];

        $package_count = max(count($packages), count($package_details), count($package_items), 1);
        $package_index = max(0, $package_index);
        if ($package_index >= $package_count) {
            return new WP_Error(
                'fflhub_packing_slip_package_missing',
                'That label does not have a package at the requested index.',
                ['status' => 404]
            );
        }

        $provider_package = is_array($packages[$package_index] ?? null) ? $packages[$package_index] : [];
        $package_detail = self::indexed_or_single($package_details, $package_index);
        $assignment_rows = self::indexed_or_single($package_items, $package_index);
        $package_row = array_replace_recursive($provider_package, $package_detail);
        $items = $this->item_rows_for_assignments(
            $order,
            is_array($assignment_rows) ? $assignment_rows : [],
            is_array($package_row['items'] ?? null) ? $package_row['items'] : []
        );
        $package_row['items'] = $items;

        $destination = is_array($snapshot['ship_to'] ?? null)
            ? $snapshot['ship_to']
            : $this->destination_from_order($order);

        $package = ShippingPackage::from_array($package_row, $items);

        return $this->generate_for_package($order, $package, $destination, [
            'label_id' => (string) ($label['label_id'] ?? ''),
            'tracking_number' => (string) ($label['tracking_number'] ?? ''),
            'carrier' => (string) ($label['carrier_nickname'] ?? $label['carrier_friendly_name'] ?? $label['carrier_code'] ?? ''),
            'service' => (string) ($label['service_name'] ?? $label['service_code'] ?? ''),
            'package_index' => $package_index + 1,
            'package_count' => $package_count,
        ]);
    }

    /**
     * @return array{body:string,content_type:string,filename:string}
     */
    public function generate_preview(?WC_Order $order = null): array
    {
        if ($order instanceof WC_Order) {
            $packing = (new OrderBoxPackingService())->pack_dealer_fulfilled_order($order);
            $boxes = isset($packing['boxes']) && is_array($packing['boxes']) ? $packing['boxes'] : [];
            $box = is_array($boxes[0] ?? null) ? $boxes[0] : [];

            if (!empty($box)) {
                $package = ShippingPackage::from_packed_box($box, 0.0, strtolower((string) (get_woocommerce_currency() ?: 'usd')));

                return $this->generate_for_package($order, $package, $this->destination_from_order($order), [
                    'package_index' => 1,
                    'package_count' => max(1, count($boxes)),
                    'preview_note' => 'Preview generated from the first auto-packed dealer-fulfilled package on this order.',
                ]);
            }
        }

        $package = ShippingPackage::from_array($this->sample_package(), $this->sample_items());

        return $this->generate_for_package($order, $package, $this->sample_destination(), [
            'package_index' => 1,
            'package_count' => 1,
            'preview_note' => $order instanceof WC_Order
                ? 'No auto-packed package was available for that order, so this sample slip is shown instead.'
                : 'Sample packing slip preview.',
        ]);
    }

    /**
     * @param array<string,mixed> $destination
     * @param array<string,mixed> $meta
     * @return array{body:string,content_type:string,filename:string}
     */
    public function generate_for_package(?WC_Order $order, ShippingPackage $package, array $destination, array $meta = []): array
    {
        $package_row = $package->to_array();
        $order_id = $order instanceof WC_Order ? (int) $order->get_id() : 0;
        $order_number = $order instanceof WC_Order ? (string) $order->get_order_number() : 'PREVIEW';
        $package_index = max(1, (int) ($meta['package_index'] ?? 1));
        $package_count = max(1, (int) ($meta['package_count'] ?? 1));
        $filename = 'packing-slip-order-' . sanitize_file_name($order_number) . '-package-' . $package_index . '.html';

        return [
            'body' => $this->render_html($order, $package_row, $destination, [
                ...$meta,
                'order_id' => $order_id,
                'order_number' => $order_number,
                'package_index' => $package_index,
                'package_count' => $package_count,
            ]),
            'content_type' => 'text/html; charset=UTF-8',
            'filename' => $filename,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $assignments
     * @param array<int,array<string,mixed>> $fallback_rows
     * @return array<int,array<string,mixed>>
     */
    private function item_rows_for_assignments(WC_Order $order, array $assignments, array $fallback_rows = []): array
    {
        $fallback_by_id = [];
        foreach ($fallback_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item_id = absint($row['item_id'] ?? $row['order_item_id'] ?? 0);
            if ($item_id > 0) {
                $fallback_by_id[$item_id] = $row;
            }
        }

        if (empty($assignments) && !empty($fallback_rows)) {
            $assignments = $fallback_rows;
        }

        $rows = [];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }

            $item_id = absint($assignment['item_id'] ?? $assignment['order_item_id'] ?? 0);
            $quantity = max(0, (int) ($assignment['quantity'] ?? 0));
            if ($item_id <= 0 || $quantity <= 0) {
                continue;
            }

            $fallback = is_array($fallback_by_id[$item_id] ?? null) ? $fallback_by_id[$item_id] : [];
            $order_item = $order->get_item($item_id);
            $product = $order_item instanceof WC_Order_Item_Product ? $order_item->get_product() : null;
            $product = $product instanceof WC_Product ? $product : null;

            $name = trim((string) ($fallback['name'] ?? ''));
            if ($name === '' && $order_item instanceof WC_Order_Item_Product) {
                $name = (string) $order_item->get_name();
            }
            if ($name === '') {
                $name = 'Order item #' . $item_id;
            }

            $sku = trim((string) ($fallback['sku'] ?? ''));
            if ($sku === '' && $product instanceof WC_Product) {
                $sku = (string) $product->get_sku();
            }

            $upc = trim((string) ($fallback['upc'] ?? ''));
            if ($upc === '' && $product instanceof WC_Product && method_exists($product, 'get_global_unique_id')) {
                $upc = trim((string) $product->get_global_unique_id('edit'));
            }

            $rows[] = [
                ...$fallback,
                'item_id' => $item_id,
                'quantity' => $quantity,
                'order_quantity' => $order_item instanceof WC_Order_Item_Product ? max(0, (int) $order_item->get_quantity()) : $quantity,
                'name' => $name,
                'sku' => $sku,
                'upc' => $upc,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $package
     * @param array<string,mixed> $destination
     * @param array<string,mixed> $meta
     */
    private function render_html(?WC_Order $order, array $package, array $destination, array $meta): string
    {
        $logo_url = $this->logo_url();
        $brand = get_bloginfo('name') ?: 'Deerford Defense';
        $package_title = $this->package_title($package);
        $package_detail = $this->package_detail($package);
        $ship_to_lines = $this->address_lines($destination);
        $customer_lines = $this->customer_lines($order);
        $items = is_array($package['items'] ?? null) ? $package['items'] : [];
        $item_density_class = count($items) > 4 ? ' is-dense' : '';
        $generated_at = current_time('mysql');

        ob_start();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html('Packing Slip - Order ' . (string) ($meta['order_number'] ?? 'PREVIEW')); ?></title>
    <style>
        @page { size: 4in 6in; margin: 0; }
        * { box-sizing: border-box; }
        html, body { width: 4in; min-height: 6in; margin: 0; }
        body { background: #f1f1f1; color: #111; font-family: Arial, Helvetica, sans-serif; }
        .fflhub-slip { width: 4in; min-height: 6in; margin: 0 auto; background: #fff; padding: .11in; overflow: hidden; }
        .no-print { width: 4in; margin: 0 auto; padding: 8px 0; text-align: right; }
        .no-print button { border: 1px solid #111; background: #111; color: #fff; border-radius: 4px; padding: 7px 10px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .slip-head { display: grid; grid-template-columns: minmax(0, 1fr) .85in; gap: .08in; align-items: start; border-bottom: 2px solid #111; padding-bottom: .06in; }
        .pack-label { display: block; margin-bottom: 2px; font-size: 8px; line-height: 1; font-weight: 900; text-transform: uppercase; color: #555; }
        .package-name { margin: 0; font-size: 18px; line-height: .95; font-weight: 900; text-transform: uppercase; letter-spacing: 0; overflow-wrap: anywhere; }
        .package-detail { margin-top: 3px; font-size: 8.5px; line-height: 1.15; font-weight: 800; color: #333; overflow-wrap: anywhere; }
        .logo-wrap { min-height: .38in; text-align: right; }
        .logo-wrap img { max-width: .82in; max-height: .38in; object-fit: contain; }
        .logo-fallback { display: inline-block; border: 1px solid #111; padding: 4px 5px; font-size: 9px; line-height: 1; font-weight: 900; text-transform: uppercase; }
        .meta-row { display: grid; grid-template-columns: .75in .62in minmax(0, 1fr); gap: 4px; margin: .055in 0; }
        .meta-box { border: 1.5px solid #111; padding: 4px 5px; min-height: .35in; overflow: hidden; }
        .meta-box span { display: block; font-size: 6.5px; line-height: 1; font-weight: 900; text-transform: uppercase; color: #555; }
        .meta-box strong { display: block; margin-top: 2px; font-size: 12px; line-height: 1.05; font-weight: 900; overflow-wrap: anywhere; }
        .meta-box.is-tracking strong { font-size: 8.5px; line-height: 1.12; }
        .address-grid { display: grid; grid-template-columns: 1fr; gap: 4px; margin-bottom: .055in; }
        .address-card { border: 1.5px solid #111; padding: 5px 6px; }
        .address-card h2 { margin: 0 0 3px; font-size: 7px; line-height: 1; text-transform: uppercase; color: #555; }
        .address-card address, .address-card .lines { margin: 0; font-style: normal; font-size: 10px; line-height: 1.12; font-weight: 900; overflow-wrap: anywhere; }
        .address-card.is-customer .lines { font-size: 8.5px; line-height: 1.1; font-weight: 800; }
        .items-title { margin: .055in 0 .035in; font-size: 10px; line-height: 1; text-transform: uppercase; }
        .item-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .item-table th { border: 1.5px solid #111; background: #111; color: #fff; padding: 4px 5px; font-size: 7px; line-height: 1; text-align: left; text-transform: uppercase; }
        .item-table td { border: 1.5px solid #111; padding: 5px 5px; vertical-align: top; }
        .qty { width: .42in; text-align: center; }
        .qty strong { display: block; font-size: 20px; line-height: 1; }
        .item-name { font-size: 11px; line-height: 1.08; font-weight: 900; overflow-wrap: anywhere; }
        .item-sub { margin-top: 3px; font-size: 7.5px; line-height: 1.15; color: #333; font-weight: 800; overflow-wrap: anywhere; }
        .small-col { width: .68in; font-size: 8.5px; line-height: 1.12; font-weight: 900; overflow-wrap: anywhere; }
        .is-dense .item-table td { padding: 4px; }
        .is-dense .qty strong { font-size: 17px; }
        .is-dense .item-name { font-size: 9.5px; }
        .is-dense .item-sub { font-size: 6.8px; }
        .is-dense .small-col { font-size: 7.4px; }
        .preview-note { margin-top: .045in; border-left: 3px solid #2271b1; background: #f0f6fc; padding: 4px 5px; color: #1d2327; font-size: 7.5px; line-height: 1.15; font-weight: 800; }
        .footer { margin-top: .055in; display: flex; justify-content: space-between; gap: 6px; border-top: 1.5px solid #111; padding-top: 4px; color: #555; font-size: 6.8px; line-height: 1.1; font-weight: 800; }
        @media print {
            body { background: #fff; }
            .no-print { display: none; }
            .fflhub-slip { width: 4in; min-height: 6in; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print"><button type="button" onclick="window.print()">Print 4x6 Packing Slip</button></div>
    <main class="fflhub-slip<?php echo esc_attr($item_density_class); ?>">
        <header class="slip-head">
            <div>
                <span class="pack-label">Pack In</span>
                <h1 class="package-name"><?php echo esc_html($package_title); ?></h1>
                <?php if ($package_detail !== '') : ?>
                    <div class="package-detail"><?php echo esc_html($package_detail); ?></div>
                <?php endif; ?>
            </div>
            <div class="logo-wrap">
                <?php if ($logo_url !== '') : ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($brand); ?>" />
                <?php else : ?>
                    <span class="logo-fallback"><?php echo esc_html($brand); ?></span>
                <?php endif; ?>
            </div>
        </header>

        <section class="meta-row">
            <div class="meta-box"><span>Order</span><strong>#<?php echo esc_html((string) ($meta['order_number'] ?? 'PREVIEW')); ?></strong></div>
            <div class="meta-box"><span>Pkg</span><strong><?php echo esc_html((string) ($meta['package_index'] ?? 1)); ?>/<?php echo esc_html((string) ($meta['package_count'] ?? 1)); ?></strong></div>
            <div class="meta-box is-tracking"><span><?php echo esc_html(trim((string) ($meta['carrier'] ?? '')) ?: 'Tracking'); ?></span><strong><?php echo esc_html(trim((string) ($meta['tracking_number'] ?? '')) ?: 'Pending'); ?></strong></div>
        </section>

        <section class="address-grid">
            <div class="address-card">
                <h2>Ship To</h2>
                <address><?php echo wp_kses_post(implode('<br>', array_map('esc_html', $ship_to_lines))); ?></address>
            </div>
            <div class="address-card is-customer">
                <h2>Customer</h2>
                <div class="lines"><?php echo wp_kses_post(implode('<br>', array_map('esc_html', $customer_lines))); ?></div>
            </div>
        </section>

        <h2 class="items-title">Items To Pack</h2>
        <table class="item-table">
            <thead>
                <tr>
                    <th class="qty">Qty</th>
                    <th>Item</th>
                    <th class="small-col">SKU</th>
                    <th class="small-col">UPC</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr><td colspan="4"><div class="item-name">No package item assignments found.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($items as $item) : ?>
                    <?php $item = is_array($item) ? $item : []; ?>
                    <tr>
                        <td class="qty"><strong><?php echo esc_html((string) max(0, (int) ($item['quantity'] ?? 0))); ?></strong></td>
                        <td>
                            <div class="item-name"><?php echo esc_html((string) ($item['name'] ?? 'Order item')); ?></div>
                            <div class="item-sub">
                                Order item #<?php echo esc_html((string) absint($item['item_id'] ?? $item['order_item_id'] ?? 0)); ?>
                                <?php if (!empty($item['order_quantity'])) : ?>
                                    | Order qty <?php echo esc_html((string) max(0, (int) $item['order_quantity'])); ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="small-col"><?php echo esc_html(trim((string) ($item['sku'] ?? '')) ?: '-'); ?></td>
                        <td class="small-col"><?php echo esc_html(trim((string) ($item['upc'] ?? '')) ?: '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (trim((string) ($meta['preview_note'] ?? '')) !== '') : ?>
            <div class="preview-note"><?php echo esc_html((string) $meta['preview_note']); ?></div>
        <?php endif; ?>

        <footer class="footer">
            <span><?php echo esc_html($brand); ?></span>
            <span>Generated <?php echo esc_html($generated_at); ?></span>
        </footer>
    </main>
</body>
</html>
        <?php
        return trim((string) ob_get_clean());
    }

    /**
     * @param array<string,mixed> $package
     */
    private function package_title(array $package): string
    {
        $name = trim((string) ($package['preset_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $kind = trim((string) ($package['package_kind'] ?? ''));
        if ($kind === 'envelope') {
            return 'Envelope';
        }

        $code = trim((string) ($package['package_code'] ?? 'package'));
        $label = ucwords(str_replace('_', ' ', $code));

        return $label !== '' ? $label : 'Package';
    }

    /**
     * @param array<string,mixed> $package
     */
    private function package_detail(array $package): string
    {
        $dimensions = is_array($package['dimensions'] ?? null) ? $package['dimensions'] : [];
        $weight = is_array($package['weight'] ?? null) ? $package['weight'] : [];
        $parts = [];

        $length = self::number_label($dimensions['length'] ?? null);
        $width = self::number_label($dimensions['width'] ?? null);
        $height = self::number_label($dimensions['height'] ?? null);
        if ($length !== '' && $width !== '' && $height !== '') {
            $parts[] = "{$length} x {$width} x {$height} in";
        }

        $total_oz = self::number_label($weight['value'] ?? null);
        if ($total_oz !== '') {
            $parts[] = "{$total_oz} oz total";
        }

        $package_oz = self::number_label($package['package_weight_oz'] ?? null);
        if ($package_oz !== '' && (float) $package_oz > 0.0) {
            $parts[] = "{$package_oz} oz package";
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string,mixed> $address
     * @return string[]
     */
    private function address_lines(array $address): array
    {
        $name = trim((string) ($address['name'] ?? ''));
        $company = trim((string) ($address['company_name'] ?? $address['company'] ?? ''));
        $city = trim((string) ($address['city_locality'] ?? $address['city'] ?? ''));
        $state = trim((string) ($address['state_province'] ?? $address['state'] ?? ''));
        $zip = trim((string) ($address['postal_code'] ?? $address['zip'] ?? ''));
        $city_state_zip = trim(implode(', ', array_filter([
            $city,
            trim($state . ' ' . $zip),
        ])));

        $lines = array_values(array_filter([
            $name,
            $company !== $name ? $company : '',
            trim((string) ($address['address_line1'] ?? $address['address1'] ?? '')),
            trim((string) ($address['address_line2'] ?? $address['address2'] ?? '')),
            $city_state_zip,
            trim((string) ($address['country_code'] ?? $address['country'] ?? 'US')),
        ]));

        return !empty($lines) ? $lines : ['Shipping address not available'];
    }

    /**
     * @return string[]
     */
    private function customer_lines(?WC_Order $order): array
    {
        if (!($order instanceof WC_Order)) {
            return ['Sample Customer', 'customer@example.com', '(555) 555-1234'];
        }

        $name = trim($order->get_formatted_billing_full_name());
        $email = trim((string) $order->get_billing_email());
        $phone = trim((string) $order->get_billing_phone());

        return array_values(array_filter([
            $name !== '' ? $name : trim($order->get_formatted_shipping_full_name()),
            $email,
            $phone,
        ])) ?: ['Customer not available'];
    }

    /**
     * @return array<string,mixed>
     */
    private function destination_from_order(WC_Order $order): array
    {
        $name = trim($order->get_formatted_shipping_full_name());
        if ($name === '') {
            $name = trim($order->get_formatted_billing_full_name());
        }

        return [
            'name' => $name,
            'company_name' => trim((string) $order->get_shipping_company()),
            'address_line1' => trim((string) $order->get_shipping_address_1()),
            'address_line2' => trim((string) $order->get_shipping_address_2()),
            'city_locality' => trim((string) $order->get_shipping_city()),
            'state_province' => trim((string) $order->get_shipping_state()),
            'postal_code' => trim((string) $order->get_shipping_postcode()),
            'country_code' => trim((string) $order->get_shipping_country()) ?: 'US',
        ];
    }

    private function logo_url(): string
    {
        $custom_logo_id = (int) get_theme_mod('custom_logo');
        if ($custom_logo_id > 0) {
            $url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $site_icon = get_site_icon_url(192);
        return is_string($site_icon) ? $site_icon : '';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function indexed_or_single(array $rows, int $index): array
    {
        if (is_array($rows[$index] ?? null)) {
            return $rows[$index];
        }

        if (count($rows) === 1 && is_array($rows[0] ?? null)) {
            return $rows[0];
        }

        return [];
    }

    /**
     * @param mixed $value
     */
    private static function number_label($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $number = (float) $value;
        if ($number <= 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array<string,mixed>
     */
    private function sample_package(): array
    {
        $presets = ShippingOptions::package_presets();
        $preset = is_array($presets[0] ?? null) ? $presets[0] : [];

        return [
            'preset_id' => (string) ($preset['id'] ?? 'sample-envelope'),
            'preset_name' => (string) ($preset['name'] ?? '5 x 10 Padded Envelope'),
            'package_kind' => (string) ($preset['kind'] ?? 'envelope'),
            'package_code' => (string) ($preset['package_code'] ?? 'thick_envelope'),
            'content_weight_oz' => 13.5,
            'package_weight_oz' => (float) ($preset['weight_oz'] ?? 0.8),
            'weight' => [
                'value' => 14.3,
                'unit' => 'ounce',
            ],
            'dimensions' => [
                'unit' => 'inch',
                'length' => (float) ($preset['length'] ?? 10),
                'width' => (float) ($preset['width'] ?? 5),
                'height' => (float) ($preset['height'] ?? 2.5),
            ],
            'insured_value' => [
                'currency' => strtolower((string) (get_woocommerce_currency() ?: 'usd')),
                'amount' => 0,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sample_items(): array
    {
        return [
            [
                'item_id' => 1001,
                'quantity' => 1,
                'order_quantity' => 1,
                'name' => 'Sample Red Dot Sight with Mount',
                'sku' => 'SAMPLE-RD-01',
                'upc' => '000000000001',
            ],
            [
                'item_id' => 1002,
                'quantity' => 2,
                'order_quantity' => 2,
                'name' => 'Sample 15 Round Magazine',
                'sku' => 'SAMPLE-MAG-15',
                'upc' => '000000000002',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sample_destination(): array
    {
        return [
            'name' => 'Sample Customer',
            'company_name' => '',
            'address_line1' => '123 Example Street',
            'address_line2' => '',
            'city_locality' => 'Zachary',
            'state_province' => 'LA',
            'postal_code' => '70791',
            'country_code' => 'US',
        ];
    }
}
