<?php

namespace FFLHub\Distributor;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Services\DistributorServicesInterface;
use FFLHub\Distributor\Services\Tables\DistributorTableInterface;

/**
 * Base class for distributors with common functionality.
 */
abstract class DistributorBase implements DistributorInterface
{
    /**
     * Optional services bundle for this distributor (tables, cron, etc.).
     */
    protected ?DistributorServicesInterface $services = null;

    /**
     * Children MUST implement these instance methods.
     */
    abstract public function get_id(): string;
    abstract public function get_label(): string;
    abstract public function get_name(): string;
    abstract public function get_description(): string;
    abstract public function get_section_description(): string;
    abstract public function get_icon_url(): string;
    abstract public function get_field_definitions(): array;

    /**
     * Optional DI-style constructor.
     *
     * You can call `new DistributorRSR()` with no args and inject
     * services later via set_services(), or pass services here.
     */
    public function __construct(?DistributorServicesInterface $services = null)
    {
        if ($services !== null) {
            $this->services = $services;
        }
    }

    /**
     * Inject or replace the services bundle at runtime.
     */
    public function set_services(DistributorServicesInterface $services): void
    {
        $this->services = $services;
    }

    /**
     * Instance-level accessor required by DistributorInterface.
     */
    public function get_services(): ?DistributorServicesInterface
    {
        return $this->services;
    }

    /**
     * Do we currently have a services bundle wired in?
     */
    public function has_services(): bool
    {
        return $this->services instanceof DistributorServicesInterface;
    }

    /**
     * Convenience shortcut: the fulfillment table for this distributor,
     * or null if no services are wired.
     */
    protected function get_fulfillment_table(): ?DistributorTableInterface
    {
        if (! $this->services instanceof DistributorServicesInterface) {
            return null;
        }

        return $this->services->get_fulfillment_table();
    }

    /* ---- Helpers for WordPress Settings API ---- */

    protected function get_option_group(): string
    {
        return 'fflhub_' . $this->get_id() . '_settings_group';
    }

    protected function get_settings_page(): string
    {
        return 'ffl-hub-settings-' . $this->get_id();
    }

    protected function get_section_id(): string
    {
        return 'fflhub_' . $this->get_id() . '_section';
    }

    public function get_option_name(string $field_key): string
    {
        return 'fflhub_' . $this->get_id() . '_' . $field_key;
    }

    protected function get_field_id(string $field_key): string
    {
        return 'fflhub_' . $this->get_id() . '_' . $field_key . '_field';
    }

    /* ---- (Optional) Settings registration; still instance-based now ----
    public function register_settings(): void
    {
        $fields = $this->get_field_definitions();

        if ($this->get_id() === '' || empty($fields)) {
            return;
        }

        $option_group  = $this->get_option_group();
        $settings_page = $this->get_settings_page();
        $section_id    = $this->get_section_id();

        foreach ($fields as $key => $field) {
            $option_name = $this->get_option_name($key);
            register_setting($option_group, $option_name);
        }

        add_settings_section(
            $section_id,
            '',
            [$this, 'render_section_intro'],
            $settings_page
        );

        foreach ($fields as $key => $field) {
            add_settings_field(
                $this->get_field_id($key),
                $field['label'] ?? $key,
                [$this, 'render_field'],
                $settings_page,
                $section_id,
                [
                    'field_key' => $key,
                ]
            );
        }
    }

    public function render_section_intro(): void
    {
        $desc = $this->get_section_description();
        if ($desc !== '') {
            echo '<p>' . esc_html($desc) . '</p>';
        }
    }

    public function render_field($args): void
    {
        $key    = $args['field_key'] ?? '';
        $fields = $this->get_field_definitions();

        if ($key === '' || ! isset($fields[$key])) {
            return;
        }

        $field       = $fields[$key];
        $type        = $field['type']        ?? 'text';
        $placeholder = $field['placeholder'] ?? '';
        $description = $field['description'] ?? '';
        $default     = $field['default']     ?? '';

        $option_name = $this->get_option_name($key);
        $value       = get_option($option_name, $default);
        ?>
        <input
            type="<?php echo esc_attr($type); ?>"
            name="<?php echo esc_attr($option_name); ?>"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text"
            <?php if ($placeholder) : ?>
                placeholder="<?php echo esc_attr($placeholder); ?>"
            <?php endif; ?>
        />
        <?php if ($description) : ?>
            <p class="description"><?php echo esc_html($description); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_settings_panel(): void
    {
        ?>
        <h2><?php echo esc_html($this->get_name()); ?> Settings</h2>

        <form method="post" action="options.php">
            <?php
            settings_fields($this->get_option_group());
            do_settings_sections($this->get_settings_page());
            submit_button();
            ?>
        </form>
        <?php
    }
    */

    /* ---- Default product / pricing implementations ---- */

    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        // Default: not implemented.
        return null;
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->get_product_by_upc($upc);
    }

    public function get_stock_quantity_by_upc(string $upc): ?int
    {
        $product = $this->get_pricing_payload_by_upc($upc);

        if (! $product instanceof DistributorProductPayload) {
            return null;
        }

        if ($product->quantity === null || $product->quantity === '') {
            return null;
        }

        return (int) $product->quantity;
    }

    public function get_distributor_price_by_upc(string $upc): ?float
    {
        $product = $this->get_pricing_payload_by_upc($upc);

        if (! $product instanceof DistributorProductPayload) {
            return null;
        }

        if ($product->price === null || $product->price === '') {
            return null;
        }

        return (float) $product->price;
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        return 0.0;
    }

    /**
     * Normalize a UPC by stripping non-digits.
     */
    protected function normalize_upc(string $upc): ?string
    {
        $normalized = preg_replace('/\D+/', '', $upc);

        if ($normalized === '') {
            error_log(
                'FFLHub Distributor: UPC empty after normalization. Original: ' . $upc
            );
            return null;
        }

        return $normalized;
    }

    /* ---- Field helpers ---- */

    protected function get_string_field(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (string) $item[$key];
            }
        }
        return null;
    }

    protected function get_int_field(array $item, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (int) $item[$key];
            }
        }
        return null;
    }

    protected function get_float_field(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (float) $item[$key];
            }
        }
        return null;
    }

    protected function get_bool_field(array $item, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (bool) $item[$key];
            }
        }
        return null;
    }

    /* ---- True cost helpers ---- */

    protected function get_true_cost_by_upc(string $upc): ?float
    {
        $distributor_cost = $this->get_distributor_price_by_upc($upc);
        if ($distributor_cost === null) {
            return null;
        }

        $shipping_cost = $this->get_shipping_cost_by_upc($upc);
        if ($shipping_cost === null) {
            $shipping_cost = 0.0;
        }

        $base_cost = $distributor_cost + $shipping_cost;

        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $fee_decimal = $fee_percent / 100.0;

        if ($fee_decimal >= 1.0) {
            return $base_cost;
        }

        return $base_cost / (1.0 - $fee_decimal);
    }

    protected function get_true_cost_by_distributor_cost_shipping_cost(
        float $distributor_cost,
        float $shipping_cost
    ): ?float {
        $base_cost = $distributor_cost + $shipping_cost;

        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $fee_decimal = $fee_percent / 100.0;

        if ($fee_decimal >= 1.0) {
            return $base_cost;
        }

        return $base_cost / (1.0 - $fee_decimal);
    }
}
