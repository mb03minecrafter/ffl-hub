<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Base class for distributors with common functionality.
 */
abstract class FFLHub_Distributor_Base implements FFLHub_Distributor_Interface
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

        $option_group   = $this->get_option_group();
        $settings_page  = $this->get_settings_page();
        $section_id     = $this->get_section_id();

        // Register options for each field
        foreach ($this->fields as $key => $field) {
            $option_name = $this->get_option_name($key);
            register_setting($option_group, $option_name);
        }

        // Section
        add_settings_section(
            $section_id,
            '',
            array($this, 'render_section_intro'),
            $settings_page
        );

        // Fields
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
        $key   = $args['field_key'] ?? '';
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
        <input type="<?php echo esc_attr($type); ?>"
            name="<?php echo esc_attr($option_name); ?>"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text"
            <?php if ($placeholder) : ?>
            placeholder="<?php echo esc_attr($placeholder); ?>"
            <?php endif; ?> />
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
     * @return array|null
     */
    public function get_product_by_upc(string $upc): ?FFLHub_Distributor_Product_Payload
    {
        // By default, UPC lookup is not implemented.
        // Returning null lets callers gracefully handle "no support".
        return null;
    }


    /**
     * Default implementation: call get_product_by_upc() and return the
     * normalized 'quantity' field.
     *
     * Concrete distributors can override this if they want a lighter-weight
     * quantity-only call, but they don't have to.
     *
     * @param string $upc
     * @return int|null
     */
    public function get_stock_quantity_by_upc(string $upc): ?int
    {
        $product = $this->get_product_by_upc($upc);

        if (! is_array($product)) {
            return null;
        }

        if (! array_key_exists('quantity', $product)) {
            return null;
        }

        if ($product['quantity'] === null || $product['quantity'] === '') {
            return null;
        }

        return (int) $product['quantity'];
    }


    public function get_distributor_price_by_upc(string $upc): ?float
    {
        $product = $this->get_product_by_upc($upc);

        if (! is_array($product)) {
            return null;
        }

        if (! array_key_exists('price', $product)) {
            return null;
        }

        if ($product['price'] === null || $product['price'] === '') {
            return null;
        }

        return (float) $product['price'];
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
                'FFLHub Lipseys: UPC empty after normalization. Original: ' . $upc
            );
            return null;
        }

        return $normalized;
    }

    public function get_shipping_cost_by_upc( string $upc ): ?float {
        return 0;
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


    protected function get_bool_field(array $item, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return (bool) $item[$key];
            }
        }
        return null;
    }



     protected function get_true_cost_by_upc( string $upc ): ?float
    {
        // 1) Get what YOU pay the distributor for this UPC.
        $distributor_cost = $this->get_distributor_price_by_upc( $upc );
        if ( $distributor_cost === null ) {
            return null;
        }

        // 2) Get the child-specific shipping estimate (RSR vs Lipsey's).
        //    This will call the RSR or Lipsey's implementation automatically
        //    depending on which child class $this actually is.
        $shipping_cost = $this->get_shipping_cost_by_upc( $upc );
        if ( $shipping_cost === null ) {
            $shipping_cost = 0.0;
        }

        // Base cost before processor fees.
        $base_cost = $distributor_cost + $shipping_cost;

        // 3) Global payment processor fee (percent) -> decimal.
        $fee_percent = (float) get_option( 'fflhub_payment_processor_fee_percent', '2.9' );
        $fee_decimal = $fee_percent / 100.0;

        // Guard against bad config like 100%+ fee.
        if ( $fee_decimal >= 1.0 ) {
            // Fall back to just returning base cost if config is nonsense.
            return $base_cost;
        }

        // 4) Breakeven sale price that covers distributor cost + shipping + processor fee.
        //    P = (C + S) / (1 - p)
        $true_cost = $base_cost / ( 1.0 - $fee_decimal );

        return $true_cost;
    }



     protected function get_true_cost_by_distributor_cost_shipping_cost( float $distributor_cost,  float $shipping_cost): ?float
    {
        

        // Base cost before processor fees.
        $base_cost = $distributor_cost + $shipping_cost;

        // 3) Global payment processor fee (percent) -> decimal.
        $fee_percent = (float) get_option( 'fflhub_payment_processor_fee_percent', '2.9' );
        $fee_decimal = $fee_percent / 100.0;

        // Guard against bad config like 100%+ fee.
        if ( $fee_decimal >= 1.0 ) {
            // Fall back to just returning base cost if config is nonsense.
            return $base_cost;
        }

        // 4) Breakeven sale price that covers distributor cost + shipping + processor fee.
        //    P = (C + S) / (1 - p)
        $true_cost = $base_cost / ( 1.0 - $fee_decimal );

        return $true_cost;
    }

}
