<?php
declare(strict_types=1);

namespace FFLHub\Admin\Orders;

use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Shipping\ShipStation\ShipStationRestController;
use FFLHub\Shipping\ShipStation\ShipStationShipmentService;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Native WooCommerce order panel for FFL Hub ShipStation labels.
 */
final class ShipStationOrderMetaBox
{
    private const META_BOX_ID = 'fflhub_shipstation_labels';
    private const META_BOX_TITLE = 'FFL Hub - ShipStation Labels';

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
        ?>
        <div
            class="fflhub-ss-panel"
            data-order-id="<?php echo esc_attr((string) $order_id); ?>"
            data-context="<?php echo esc_attr((string) wp_json_encode($context)); ?>"
        >
            <?php if (empty($context['enabled'])) : ?>
                <div class="fflhub-ss-notice is-warning">
                    <?php esc_html_e('ShipStation labels are disabled. Enable them under FFL Hub > ShipStation Labels.', 'ffl-hub'); ?>
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
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=fflhub-shipstation-settings')); ?>">
                            <?php esc_html_e('Edit Global Origin', 'ffl-hub'); ?>
                        </a>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Managed globally under FFL Hub > ShipStation Labels. Rates and labels always use this saved origin.', 'ffl-hub'); ?>
                    </p>
                    <?php $this->render_address_summary((array) ($context['origin'] ?? [])); ?>
                </section>

                <section class="fflhub-ss-card">
                    <div class="fflhub-ss-card-title-row">
                        <h4><?php esc_html_e('Ship To', 'ffl-hub'); ?></h4>
                        <button type="button" class="button fflhub-ss-validate-address"><?php esc_html_e('Validate', 'ffl-hub'); ?></button>
                    </div>
                    <?php $this->render_address_fields('destination', (array) ($context['destination'] ?? [])); ?>
                </section>
            </div>

            <section class="fflhub-ss-card">
                <div class="fflhub-ss-card-title-row">
                    <h4><?php esc_html_e('Packages', 'ffl-hub'); ?></h4>
                    <button type="button" class="button fflhub-ss-add-package"><?php esc_html_e('Add Package', 'ffl-hub'); ?></button>
                </div>
                <div class="fflhub-ss-package-list">
                    <?php foreach ((array) ($context['packages'] ?? []) as $index => $package) : ?>
                        <?php $this->render_package_row((int) $index, is_array($package) ? $package : []); ?>
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
            echo '<div class="fflhub-ss-empty">' . esc_html__('No FFL Hub ShipStation labels purchased yet.', 'ffl-hub') . '</div>';
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
            echo '<strong>' . esc_html((string) ($label['service_name'] ?? $label['service_code'] ?? 'ShipStation label')) . '</strong>';
            echo '<span>' . esc_html((string) ($label['carrier_nickname'] ?? $label['carrier_friendly_name'] ?? $label['carrier_code'] ?? '')) . '</span>';
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
     */
    private function render_package_row(int $index, array $package): void
    {
        $weight = isset($package['weight']) && is_array($package['weight']) ? $package['weight'] : [];
        $dims = isset($package['dimensions']) && is_array($package['dimensions']) ? $package['dimensions'] : [];
        $insured = isset($package['insured_value']) && is_array($package['insured_value']) ? $package['insured_value'] : [];
        echo '<div class="fflhub-ss-package-row" data-package-index="' . esc_attr((string) $index) . '">';
        echo '<label><span>Package</span><input data-field="package_code" value="' . esc_attr((string) ($package['package_code'] ?? 'package')) . '" /></label>';
        echo '<label><span>Weight oz</span><input type="number" step="0.01" min="0" data-field="weight.value" value="' . esc_attr((string) ($weight['value'] ?? '')) . '" /></label>';
        echo '<label><span>Length</span><input type="number" step="0.01" min="0" data-field="dimensions.length" value="' . esc_attr((string) ($dims['length'] ?? '')) . '" /></label>';
        echo '<label><span>Width</span><input type="number" step="0.01" min="0" data-field="dimensions.width" value="' . esc_attr((string) ($dims['width'] ?? '')) . '" /></label>';
        echo '<label><span>Height</span><input type="number" step="0.01" min="0" data-field="dimensions.height" value="' . esc_attr((string) ($dims['height'] ?? '')) . '" /></label>';
        echo '<label><span>Insured $</span><input type="number" step="0.01" min="0" data-field="insured_value.amount" value="' . esc_attr((string) ($insured['amount'] ?? '0')) . '" /></label>';
        echo '<label class="fflhub-ss-wide"><span>Description</span><input data-field="description" value="' . esc_attr((string) ($package['description'] ?? '')) . '" /></label>';
        echo '<button type="button" class="button-link-delete fflhub-ss-remove-package">Remove</button>';
        echo '</div>';
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
