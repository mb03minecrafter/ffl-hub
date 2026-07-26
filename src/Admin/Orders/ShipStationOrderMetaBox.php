<?php
declare(strict_types=1);

namespace FFLHub\Admin\Orders;

use FFLHub\Admin\Pages\ShippingAdminPage;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Shipping\ShipStation\ShipStationRestController;
use FFLHub\Shipping\ShipStation\ShipStationShipmentService;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Native WooCommerce order panel for FFL Hub shipping labels.
 */
final class ShipStationOrderMetaBox
{
    private const META_BOX_ID = 'fflhub_shipstation_labels';
    private const META_BOX_TITLE = 'FFL Hub - Shipping Labels';

    private FFLTable $ffl_table;

    public function __construct(FFLTable $ffl_table)
    {
        $this->ffl_table = $ffl_table;
    }

    public function register(): void
    {
        add_action('add_meta_boxes_shop_order', [$this, 'register_metabox']);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', [$this, 'register_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_metabox(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            self::META_BOX_TITLE,
            [$this, 'render_metabox'],
            null,
            'normal',
            'high'
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $is_order_screen =
            ($screen->id === 'shop_order') ||
            ($screen->id === 'woocommerce_page_wc-orders') ||
            ($screen->post_type === 'shop_order');

        if (!$is_order_screen) {
            return;
        }

        $css_rel_path = 'assets/css/fflhub-shipstation-order.css';
        $js_rel_path = 'assets/js/fflhub-shipstation-order.js';
        $css_abs_path = FFLHUB_PLUGIN_PATH . $css_rel_path;
        $js_abs_path = FFLHUB_PLUGIN_PATH . $js_rel_path;

        wp_enqueue_style(
            'fflhub-shipstation-order',
            plugins_url($css_rel_path, FFLHUB_PLUGIN_FILE),
            [],
            file_exists($css_abs_path) ? (string) filemtime($css_abs_path) : FFLHUB_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'fflhub-shipstation-order',
            plugins_url($js_rel_path, FFLHUB_PLUGIN_FILE),
            [],
            file_exists($js_abs_path) ? (string) filemtime($js_abs_path) : FFLHUB_PLUGIN_VERSION,
            true
        );

        wp_localize_script('fflhub-shipstation-order', 'FFLHubShipStation', [
            'restRoot' => esc_url_raw(rest_url('fflhub/v1/shipstation/order/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'carrierLogos' => [
                'usps' => esc_url_raw(plugins_url('assets/icons/carrier-usps.svg', FFLHUB_PLUGIN_FILE)),
                'ups' => esc_url_raw(plugins_url('assets/icons/carrier-ups.svg', FFLHUB_PLUGIN_FILE)),
                'fedex' => esc_url_raw(plugins_url('assets/icons/carrier-fedex.svg', FFLHUB_PLUGIN_FILE)),
                'dhl' => esc_url_raw(plugins_url('assets/icons/carrier-dhl.svg', FFLHUB_PLUGIN_FILE)),
            ],
        ]);
    }

    /**
     * @param mixed $post_or_order WP_Post|WC_Order depending on screen.
     */
    public function render_metabox($post_or_order): void
    {
        $order = self::resolve_order($post_or_order);
        if (!($order instanceof WC_Order)) {
            echo '<div class="fflhub-ss-empty">Order not available.</div>';
            return;
        }

        $context = (new ShipStationShipmentService($this->ffl_table))->build_context($order);
        if (is_wp_error($context)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($context->get_error_message()) . '</p></div>';
            return;
        }

        $order_id = (int) $order->get_id();
        $settings = isset($context['settings']) && is_array($context['settings']) ? $context['settings'] : [];
        $labels = isset($context['labels']) && is_array($context['labels']) ? $context['labels'] : [];
        $package_presets = isset($context['package_presets']) && is_array($context['package_presets']) ? $context['package_presets'] : [];
        $order_items = isset($context['order_items']) && is_array($context['order_items']) ? $context['order_items'] : [];
        ?>
        <div
            class="fflhub-ss-panel"
            data-order-id="<?php echo esc_attr((string) $order_id); ?>"
            data-context="<?php echo esc_attr((string) wp_json_encode($context)); ?>"
        >
            <?php if (empty($context['enabled'])) : ?>
                <div class="fflhub-ss-notice is-warning">
                    <?php esc_html_e('Shipping labels are disabled. Enable ShipStation and/or EasyPost under FFLHub Shipping.', 'ffl-hub'); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($context['requires_ffl'])) : ?>
                <div class="fflhub-ss-notice is-ffl">
                    <strong><?php esc_html_e('FFL shipment', 'ffl-hub'); ?></strong>
                    <?php esc_html_e('Destination is the receiving FFL premise address. Documents such as PS Form 1508, when required, are handled outside the label API.', 'ffl-hub'); ?>
                </div>
            <?php endif; ?>

            <?php $carrier_cache = isset($context['carrier_cache']) && is_array($context['carrier_cache']) ? $context['carrier_cache'] : []; ?>
            <?php if (!empty($carrier_cache['stale'])) : ?>
                <div class="fflhub-ss-notice is-warning">
                    <?php esc_html_e('Using stale ShipStation carrier data because the latest carrier refresh failed.', 'ffl-hub'); ?>
                    <?php if (!empty($carrier_cache['error'])) : ?>
                        <?php echo esc_html((string) $carrier_cache['error']); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php $this->render_label_history($order, $labels); ?>

            <div class="fflhub-ss-grid">
                <section class="fflhub-ss-card">
                    <div class="fflhub-ss-card-title-row">
                        <h4><?php esc_html_e('Ship From', 'ffl-hub'); ?></h4>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . ShippingAdminPage::SHIP_FROM_SLUG)); ?>">
                            <?php esc_html_e('Edit Global Origin', 'ffl-hub'); ?>
                        </a>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Managed globally under FFLHub Shipping > Ship-From Locations. Rates and labels always use this saved origin.', 'ffl-hub'); ?>
                    </p>
                    <?php $this->render_address_summary((array) ($context['origin'] ?? [])); ?>
                </section>

                <section class="fflhub-ss-card">
                    <div class="fflhub-ss-card-title-row">
                        <h4><?php esc_html_e('Ship To', 'ffl-hub'); ?></h4>
                        <button type="button" class="button fflhub-ss-validate-address"><?php esc_html_e('Validate Optional', 'ffl-hub'); ?></button>
                    </div>
                    <?php $this->render_address_fields('destination', (array) ($context['destination'] ?? [])); ?>
                </section>
            </div>

            <section class="fflhub-ss-card">
                <div class="fflhub-ss-card-title-row">
                    <h4><?php esc_html_e('Packages', 'ffl-hub'); ?></h4>
                    <div class="fflhub-ss-card-actions">
                        <button type="button" class="button fflhub-ss-auto-pack"><?php esc_html_e('Auto Pack Dealer Items', 'ffl-hub'); ?></button>
                        <button type="button" class="button fflhub-ss-add-package"><?php esc_html_e('Add Package', 'ffl-hub'); ?></button>
                    </div>
                </div>
                <div class="fflhub-ss-package-list">
                    <?php foreach ((array) ($context['packages'] ?? []) as $index => $package) : ?>
                        <?php $this->render_package_row((int) $index, is_array($package) ? $package : [], $package_presets, $order_items); ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="fflhub-ss-card">
                <h4><?php esc_html_e('Rate Options', 'ffl-hub'); ?></h4>
                <div class="fflhub-ss-rate-options">
                    <label>
                        <?php esc_html_e('Ship Date', 'ffl-hub'); ?>
                        <input type="date" class="fflhub-ss-ship-date" value="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                    </label>
                    <label>
                        <?php esc_html_e('Confirmation', 'ffl-hub'); ?>
                        <select class="fflhub-ss-confirmation">
                            <?php foreach (['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'] as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected((string) ($settings['confirmation'] ?? 'delivery'), $option); ?>><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="fflhub-ss-actions">
                    <button type="button" class="button button-primary fflhub-ss-get-rates"><?php esc_html_e('Get Rates', 'ffl-hub'); ?></button>
                    <span class="spinner"></span>
                </div>
                <div class="fflhub-ss-message" aria-live="polite"></div>
                <div class="fflhub-ss-rates"></div>
                <details class="fflhub-ss-diagnostics">
                    <summary><?php esc_html_e('Rate diagnostics', 'ffl-hub'); ?></summary>
                    <div class="fflhub-ss-diagnostics-body"></div>
                </details>
            </section>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $labels
     */
    private function render_label_history(WC_Order $order, array $labels): void
    {
        echo '<section class="fflhub-ss-card fflhub-ss-label-history">';
        echo '<h4>' . esc_html__('Purchased Labels', 'ffl-hub') . '</h4>';

        if (empty($labels)) {
            echo '<div class="fflhub-ss-empty">' . esc_html__('No FFL Hub shipping labels purchased yet.', 'ffl-hub') . '</div>';
            echo '</section>';
            return;
        }

        echo '<div class="fflhub-ss-label-stack">';
        foreach (array_reverse($labels) as $label) {
            if (!is_array($label)) {
                continue;
            }
            $label_id = (string) ($label['label_id'] ?? '');
            $tracking = (string) ($label['tracking_number'] ?? '');
            $is_voided = !empty($label['voided']) || strtolower((string) ($label['label_status'] ?? '')) === 'voided';
            echo '<div class="fflhub-ss-label-card ' . ($is_voided ? 'is-voided' : '') . '">';
            echo '<div>';
            echo '<strong>' . esc_html((string) ($label['service_name'] ?? $label['service_code'] ?? 'Shipping label')) . '</strong>';
            echo '<span>' . esc_html((string) ($label['provider_label'] ?? 'Provider')) . ' | ' . esc_html((string) ($label['carrier_nickname'] ?? $label['carrier_friendly_name'] ?? $label['carrier_code'] ?? '')) . '</span>';
            echo '<code>' . esc_html($label_id) . '</code>';
            if ($tracking !== '') {
                echo '<div class="fflhub-ss-tracking">Tracking: <button type="button" class="button-link fflhub-ss-copy" data-copy="' . esc_attr($tracking) . '">' . esc_html($tracking) . '</button></div>';
            }
            echo '</div>';
            echo '<div class="fflhub-ss-label-actions">';
            echo '<span class="fflhub-ss-price">$' . esc_html(number_format((float) ($label['total_cost'] ?? 0), 2)) . '</span>';
            if ($label_id !== '') {
                echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url(ShipStationRestController::download_url($order, $label_id, false)) . '">' . esc_html__('Print / Open', 'ffl-hub') . '</a>';
                echo '<a class="button" href="' . esc_url(ShipStationRestController::download_url($order, $label_id, true)) . '">' . esc_html__('Download', 'ffl-hub') . '</a>';
                $this->render_packing_slip_links($order, $label, $label_id);
                if (!$is_voided) {
                    echo '<button type="button" class="button fflhub-ss-void-label" data-label-id="' . esc_attr($label_id) . '">' . esc_html__('Void', 'ffl-hub') . '</button>';
                }
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div></section>';
    }

    /**
     * @param array<string,mixed> $label
     */
    private function render_packing_slip_links(WC_Order $order, array $label, string $label_id): void
    {
        $package_count = self::label_package_count($label);
        echo '<div class="fflhub-ss-slip-actions">';
        if ($package_count <= 1) {
            echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url(ShipStationRestController::packing_slip_url($order, $label_id, 0)) . '">' . esc_html__('Packing Slip', 'ffl-hub') . '</a>';
        } else {
            for ($package_index = 0; $package_index < $package_count; $package_index++) {
                echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url(ShipStationRestController::packing_slip_url($order, $label_id, $package_index)) . '">' . esc_html(sprintf(
                    /* translators: %d is the package number. */
                    __('Slip %d', 'ffl-hub'),
                    $package_index + 1
                )) . '</a>';
            }
        }
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $label
     */
    private static function label_package_count(array $label): int
    {
        $snapshot = is_array($label['shipment_snapshot'] ?? null) ? $label['shipment_snapshot'] : [];
        $packages = isset($snapshot['packages']) && is_array($snapshot['packages'])
            ? $snapshot['packages']
            : [];
        $details = isset($label['package_details']) && is_array($label['package_details'])
            ? $label['package_details']
            : [];
        $items = isset($label['package_items']) && is_array($label['package_items'])
            ? $label['package_items']
            : [];

        return max(1, count($packages), count($details), count($items));
    }

    /**
     * @param array<string,mixed> $address
     */
    private function render_address_summary(array $address): void
    {
        $lines = array_values(array_filter([
            trim((string) ($address['name'] ?? '')),
            trim((string) ($address['company_name'] ?? '')),
            trim((string) ($address['address_line1'] ?? '')),
            trim((string) ($address['address_line2'] ?? '')),
            trim(implode(', ', array_filter([
                trim((string) ($address['city_locality'] ?? '')),
                trim((string) ($address['state_province'] ?? '')),
                trim((string) ($address['postal_code'] ?? '')),
            ]))),
            trim((string) ($address['country_code'] ?? '')),
        ]));

        echo '<div class="fflhub-ss-address-summary">';
        if (empty($lines)) {
            echo '<div class="fflhub-ss-empty">' . esc_html__('No global ShipStation origin address is configured yet.', 'ffl-hub') . '</div>';
        } else {
            echo '<address>' . wp_kses_post(implode('<br>', array_map('esc_html', $lines))) . '</address>';
        }

        echo '<dl>';
        foreach ([
            'phone' => __('Phone', 'ffl-hub'),
            'email' => __('Email', 'ffl-hub'),
            'address_residential_indicator' => __('Residential', 'ffl-hub'),
        ] as $key => $label) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
        }
        echo '</dl>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $address
     */
    private function render_address_fields(string $group, array $address): void
    {
        $fields = [
            'name' => 'Name',
            'company_name' => 'Company',
            'phone' => 'Phone',
            'email' => 'Email',
            'address_line1' => 'Address 1',
            'address_line2' => 'Address 2',
            'city_locality' => 'City',
            'state_province' => 'State',
            'postal_code' => 'ZIP',
            'country_code' => 'Country',
        ];

        echo '<div class="fflhub-ss-address-grid" data-address-group="' . esc_attr($group) . '">';
        foreach ($fields as $key => $label) {
            $type = $key === 'email' ? 'email' : 'text';
            echo '<label><span>' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" data-field="' . esc_attr($key) . '" value="' . esc_attr((string) ($address[$key] ?? '')) . '" /></label>';
        }
        echo '<label><span>' . esc_html__('Residential', 'ffl-hub') . '</span><select data-field="address_residential_indicator">';
        foreach (['unknown' => 'Unknown', 'yes' => 'Residential', 'no' => 'Commercial'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected((string) ($address['address_residential_indicator'] ?? 'unknown'), $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $package
     * @param array<int,array<string,mixed>> $presets
     * @param array<int,array<string,mixed>> $order_items
     */
    private function render_package_row(int $index, array $package, array $presets, array $order_items): void
    {
        $weight = isset($package['weight']) && is_array($package['weight']) ? $package['weight'] : [];
        $dims = isset($package['dimensions']) && is_array($package['dimensions']) ? $package['dimensions'] : [];
        $insured = isset($package['insured_value']) && is_array($package['insured_value']) ? $package['insured_value'] : [];
        $content_weight = (string) ($weight['value'] ?? '');
        $selected_preset_id = (string) ($package['preset_id'] ?? '');
        echo '<div class="fflhub-ss-package-row" data-package-index="' . esc_attr((string) $index) . '">';
        echo '<label><span>Preset</span><select class="fflhub-ss-package-preset"><option value="">Manual</option>';
        foreach ($presets as $preset) {
            if (!is_array($preset)) {
                continue;
            }
            $id = (string) ($preset['id'] ?? '');
            $name = (string) ($preset['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            echo '<option value="' . esc_attr($id) . '" ' . selected($selected_preset_id, $id, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select></label>';
        $this->render_package_code_select((string) ($package['package_code'] ?? 'package'));
        echo '<label><span>Item oz</span><input type="number" step="0.01" min="0" class="fflhub-ss-content-weight" data-weight-role="content" value="' . esc_attr($content_weight) . '" /></label>';
        echo '<label><span>Pkg oz</span><input type="number" step="0.01" min="0" class="fflhub-ss-package-weight" data-weight-role="package" value="" /></label>';
        echo '<label><span>Total oz</span><input type="number" step="0.01" min="0" class="fflhub-ss-total-weight" data-field="weight.value" data-weight-role="total" value="' . esc_attr($content_weight) . '" readonly /></label>';
        echo '<label><span>Pkg Length</span><input type="number" step="0.01" min="0" data-field="dimensions.length" value="' . esc_attr((string) ($dims['length'] ?? '')) . '" /></label>';
        echo '<label><span>Pkg Width</span><input type="number" step="0.01" min="0" data-field="dimensions.width" value="' . esc_attr((string) ($dims['width'] ?? '')) . '" /></label>';
        echo '<label><span>Pkg Height</span><input type="number" step="0.01" min="0" data-field="dimensions.height" value="' . esc_attr((string) ($dims['height'] ?? '')) . '" /></label>';
        echo '<label><span>Insured $</span><input type="number" step="0.01" min="0" data-field="insured_value.amount" value="' . esc_attr((string) ($insured['amount'] ?? '0')) . '" /></label>';
        $this->render_package_items($package, $order_items);
        echo '<button type="button" class="button-link-delete fflhub-ss-remove-package">Remove</button>';
        echo '</div>';
    }

    private function render_package_code_select(string $current): void
    {
        $current = trim($current) !== '' ? trim($current) : 'package';
        $options = [
            'package' => 'Package',
            'thick_envelope' => 'Thick Envelope',
            'large_envelope_or_flat' => 'Large Envelope / Flat',
            'large_package' => 'Large Package',
            'flat_rate_envelope' => 'USPS Flat Rate Envelope',
            'flat_rate_legal_envelope' => 'USPS Legal Flat Rate Envelope',
            'flat_rate_padded_envelope' => 'USPS Padded Flat Rate Envelope',
            'small_flat_rate_box' => 'USPS Small Flat Rate Box',
            'medium_flat_rate_box' => 'USPS Medium Flat Rate Box',
            'large_flat_rate_box' => 'USPS Large Flat Rate Box',
        ];
        if (!isset($options[$current])) {
            $options[$current] = $current;
        }

        echo '<label><span>Package</span><select data-field="package_code">';
        foreach ($options as $code => $label) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($current, $code, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
    }

    /**
     * @param array<string,mixed> $package
     * @param array<int,array<string,mixed>> $order_items
     */
    private function render_package_items(array $package, array $order_items): void
    {
        echo '<div class="fflhub-ss-package-items fflhub-ss-wide">';
        echo '<div class="fflhub-ss-package-items-heading">';
        echo '<strong>' . esc_html__('Items in this package', 'ffl-hub') . '</strong>';
        echo '<span>' . esc_html__('Assign the Woo order item quantities this label/package represents.', 'ffl-hub') . '</span>';
        echo '</div>';

        if (empty($order_items)) {
            echo '<div class="fflhub-ss-empty">' . esc_html__('No shippable Woo line items found on this order.', 'ffl-hub') . '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="fflhub-ss-package-item-list">';
        foreach ($order_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_id = absint($item['item_id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }

            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $assigned = self::assigned_package_item_quantity($package, $item_id);
            $name = (string) ($item['name'] ?? __('Order item', 'ffl-hub'));
            $sku = trim((string) ($item['sku'] ?? ''));
            $detail = sprintf(
                /* translators: %d is the order item quantity. */
                __('Order qty %d', 'ffl-hub'),
                $quantity
            );
            if ($sku !== '') {
                $detail = sprintf(
                    /* translators: 1: SKU, 2: order quantity detail. */
                    __('SKU %1$s | %2$s', 'ffl-hub'),
                    $sku,
                    $detail
                );
            }
            $measurement_parts = [];
            $weight_oz = self::positive_number_label($item['weight_oz'] ?? null);
            if ($weight_oz !== '') {
                $measurement_parts[] = sprintf(
                    /* translators: %s is the item shipping weight in ounces. */
                    __('Item %s oz', 'ffl-hub'),
                    $weight_oz
                );
            }
            $dimension_label = self::item_dimension_label($item);
            if ($dimension_label !== '') {
                $measurement_parts[] = sprintf(
                    /* translators: %s is length x width x height in inches. */
                    __('Item %s in', 'ffl-hub'),
                    $dimension_label
                );
            }
            $measurements = implode(' | ', $measurement_parts);

            echo '<div class="fflhub-ss-package-item">';
            echo '<div class="fflhub-ss-package-item-main">';
            echo '<strong>' . esc_html($name) . '</strong>';
            echo '<span>' . esc_html($detail) . '</span>';
            if ($measurements !== '') {
                echo '<span class="fflhub-ss-package-item-measurements">' . esc_html($measurements) . '</span>';
            }
            echo '</div>';
            echo '<label><span>' . esc_html__('Qty', 'ffl-hub') . '</span><input type="number" min="0" max="' . esc_attr((string) $quantity) . '" step="1" data-package-item-qty data-item-id="' . esc_attr((string) $item_id) . '" value="' . esc_attr((string) min($assigned, $quantity)) . '" /></label>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $package
     */
    private static function assigned_package_item_quantity(array $package, int $item_id): int
    {
        $items = isset($package['items']) && is_array($package['items']) ? $package['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (absint($item['item_id'] ?? 0) === $item_id) {
                return max(0, (int) ($item['quantity'] ?? 0));
            }
        }

        return 0;
    }

    /**
     * @param mixed $value
     */
    private static function positive_number_label($value): string
    {
        if (!is_numeric($value) || (float) $value <= 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function item_dimension_label(array $item): string
    {
        $length = self::positive_number_label($item['length_in'] ?? null);
        $width = self::positive_number_label($item['width_in'] ?? null);
        $height = self::positive_number_label($item['height_in'] ?? null);
        if ($length === '' || $width === '' || $height === '') {
            return '';
        }

        return $length . ' x ' . $width . ' x ' . $height;
    }

    private static function resolve_order($post_or_order): ?WC_Order
    {
        if ($post_or_order instanceof WC_Order) {
            return $post_or_order;
        }

        if (is_object($post_or_order) && isset($post_or_order->ID)) {
            $order = wc_get_order((int) $post_or_order->ID);
            return $order instanceof WC_Order ? $order : null;
        }

        if (is_numeric($post_or_order)) {
            $order = wc_get_order((int) $post_or_order);
            return $order instanceof WC_Order ? $order : null;
        }

        return null;
    }
}
