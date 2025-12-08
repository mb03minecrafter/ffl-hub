<?php

namespace FFLHub\Distributor;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Product\DistributorProductPayload;

/**
 * Base class for distributors with common functionality.
 */
abstract class DistributorBase implements DistributorInterface
{
    /** @var string */
    protected $id = '';

    /** @var string */
    protected $label = '';

    /** @var string */
    protected $name = '';

    /** @var string */
    protected $description = '';

    /** @var string */
    protected $icon_url = '';

    /** @var string */
    protected $section_description = '';

    /**
     * Field schema:
     *  [
     *    'field_key' => [
     *      'label'       => 'Field Label',
     *      'type'        => 'text|password',
     *      'placeholder' => '...',
     *      'description' => 'Optional help text',
     *      'default'     => '',
     *    ],
     *  ]
     *
     * @var array
     */
    protected $fields = array();

    /* ---- Simple getters ---- */

    public function get_id(): string
    {
        return $this->id;
    }

    public function get_label(): string
    {
        return $this->label;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_description(): string
    {
        return $this->description;
    }

    public function get_icon_url(): string
    {
        return $this->icon_url;
    }

    /* ---- Helpers for WordPress Settings API ---- */

    protected function get_option_group(): string
    {
        return 'fflhub_' . $this->id . '_settings_group';
    }

    protected function get_settings_page(): string
    {
        return 'ffl-hub-settings-' . $this->id;
    }

    protected function get_section_id(): string
    {
        return 'fflhub_' . $this->id . '_section';
    }

    protected function get_option_name(string $field_key): string
    {
        return 'fflhub_' . $this->id . '_' . $field_key;
    }

    protected function get_field_id(string $field_key): string
    {
        return 'fflhub_' . $this->id . '_' . $field_key . '_field';
    }

    /* ---- Settings registration ---- */

    public function register_settings(): void
    {
        if (empty($this->id) || empty($this->fields)) {
            return;
        }

        $option_group  = $this->get_option_group();
        $settings_page = $this->get_settings_page();
        $section_id    = $this->get_section_id();

        // Register options for each field.
        foreach ($this->fields as $key => $field) {
            $option_name = $this->get_option_name($key);
            register_setting($option_group, $option_name);
        }

        // Section.
        add_settings_section(
            $section_id,
            '',
            array($this, 'render_section_intro'),
            $settings_page
        );

        // Fields.
        foreach ($this->fields as $key => $field) {
            add_settings_field(
                $this->get_field_id($key),
                $field['label'] ?? $key,
                array($this, 'render_field'),
                $settings_page,
                $section_id,
                array(
                    'field_key' => $key,
                )
            );
        }
    }

    /**
     * Section intro text (uses $section_description if present).
     */
    public function render_section_intro(): void
    {
        if (! empty($this->section_description)) {
            echo '<p>' . esc_html($this->section_description) . '</p>';
        }
    }

    /**
     * Generic field renderer for any field in $fields.
     *
     * @param array $args
     */
    public function render_field($args): void
    {
        $key = $args['field_key'] ?? '';
        if (! $key || ! isset($this->fields[$key])) {
            return;
        }

        $field       = $this->fields[$key];
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

    /**
     * Render the entire settings panel (form) for this distributor.
     */
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

    /**
     * Default UPC lookup implementation.
     *
     * Concrete distributors should override this with a real API call.
     *
     * @param string $upc
     * @return DistributorProductPayload|null
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        // By default, UPC lookup is not implemented.
        // Returning null lets callers gracefully handle "no support".
        return null;
    }

    /**
     * Default pricing-only implementation just calls get_product_by_upc().
     *
     * Concrete distributors can override this to skip image lookups, etc.
     */
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->get_product_by_upc($upc);
    }

    /**
     * Default implementation: call get_pricing_payload_by_upc()
     * and return the normalized quantity field.
     *
     * @param string $upc
     * @return int|null
     */
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

    /**
     * Default implementation: call get_pricing_payload_by_upc()
     * and return the normalized price field.
     *
     * @param string $upc
     * @return float|null
     */
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

    /**
     * Normalize a UPC by stripping non-digits.
     *
     * @param string $upc
     * @return string|null Normalized UPC (digits only) or null if empty/invalid.
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

    /**
     * Default shipping cost implementation. Concrete distributors
     * should override this if they support item-level shipping costs.
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        return 0.0;
    }

    /**
     * Get a string field from a list of possible keys.
     *
     * @param array $item
     * @param array $keys
     * @return string|null
     */
    protected function get_string_field(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (string) $item[$key];
            }
        }
        return null;
    }

    /**
     * Get an integer field from a list of possible keys.
     *
     * @param array $item
     * @param array $keys
     * @return int|null
     */
    protected function get_int_field(array $item, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (int) $item[$key];
            }
        }
        return null;
    }

    /**
     * Get a float field from a list of possible keys.
     *
     * @param array $item
     * @param array $keys
     * @return float|null
     */
    protected function get_float_field(array $item, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (float) $item[$key];
            }
        }
        return null;
    }

    /**
     * Get a bool field from a list of possible keys.
     *
     * @param array $item
     * @param array $keys
     * @return bool|null
     */
    protected function get_bool_field(array $item, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (bool) $item[$key];
            }
        }
        return null;
    }

    /**
     * Compute true cost by UPC, using distributor cost + shipping +
     * payment processor fee.
     */
    protected function get_true_cost_by_upc(string $upc): ?float
    {
        // 1) What you pay the distributor.
        $distributor_cost = $this->get_distributor_price_by_upc($upc);
        if ($distributor_cost === null) {
            return null;
        }

        // 2) Child-specific shipping estimate.
        $shipping_cost = $this->get_shipping_cost_by_upc($upc);
        if ($shipping_cost === null) {
            $shipping_cost = 0.0;
        }

        $base_cost = $distributor_cost + $shipping_cost;

        // 3) Payment processor fee (%).
        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $fee_decimal = $fee_percent / 100.0;

        if ($fee_decimal >= 1.0) {
            // Nonsense config like 100%+ fee, fall back to base cost.
            return $base_cost;
        }

        // 4) Breakeven sale price that covers distributor cost + shipping + fee.
        //    P = (C + S) / (1 - p)
        $true_cost = $base_cost / (1.0 - $fee_decimal);

        return $true_cost;
    }

    /**
     * Compute true cost given explicit distributor and shipping costs.
     */
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

        $true_cost = $base_cost / (1.0 - $fee_decimal);

        return $true_cost;
    }
}

