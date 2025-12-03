<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds a meta box to WooCommerce products showing FFLHub metadata
 * and allowing FFL Required to be toggled.
 */
class FFLHub_Product_Meta_Box {

    public static function init(): void {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_product', array( __CLASS__, 'save_meta_box' ) );
    }

    /**
     * Register the meta box on WooCommerce product edit screens.
     */
    public static function add_meta_box(): void {
        add_meta_box(
            'fflhub_product_meta',
            __( 'FFLHub Product Metadata', 'ffl-hub' ),
            array( __CLASS__, 'render_meta_box' ),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render the meta box contents.
     *
     * @param \WP_Post $post
     */
    public static function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'fflhub_save_product_meta', 'fflhub_product_meta_nonce' );

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;

        if ( ! $product ) {
            echo '<p style="margin:0;font-size:11px;color:#6b7280;">' .
                esc_html__( 'Unable to load product data.', 'ffl-hub' ) .
                '</p>';
            return;
        }

        // Read-only fields (FFL required is rendered separately as a checkbox).
        $fields = array(
            FFLHub_Product_Meta::FFLHUB_MANAGED_META             => __( 'Managed by FFLHub', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_UPC_META                 => __( 'UPC', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_SOURCE_DISTRIBUTOR_META  => __( 'Primary Distributor', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_LAST_TRUE_COST_META      => __( 'Last True Cost', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_LAST_DEALER_PRICE_META   => __( 'Last Dealer Price', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_LAST_MAP_META            => __( 'Last MAP', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_LAST_MSRP_META           => __( 'Last MSRP', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_LAST_COMPUTED_PRICE_META => __( 'Last Computed Price', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_LAST_SYNC_META           => __( 'Last Sync At', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_MARKUP_MODE_META         => __( 'Markup Mode', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_MARKUP_PERCENT_META      => __( 'Markup Percent', 'ffl-hub' ),
            FFLHub_Product_Meta::FFLHUB_NFA_ITEM_META            => __( 'NFA Item', 'ffl-hub' ),
        );

        echo '<table class="fflhub-meta-table" style="width:100%;border-collapse:collapse;">';

        foreach ( $fields as $key => $label ) {
            $value = $product->get_meta( $key, true );

            echo '<tr>';
            echo '<th style="text-align:left;padding:2px 4px;font-weight:600;font-size:11px;">' . esc_html( $label ) . '</th>';
            echo '<td style="text-align:right;padding:2px 4px;font-size:11px;">';

            if ( '' === $value && '0' !== (string) $value ) {
                echo '<span style="color:#9ca3af;">' . esc_html__( '—', 'ffl-hub' ) . '</span>';
            } else {
                echo esc_html( (string) $value );
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        // Editable checkbox: FFL Required.
        $raw_required  = $product->get_meta( FFLHub_Product_Meta::FFLHUB_FFL_REQUIRED_META, true );
        $ffl_required  = (string) $raw_required === '1' || $raw_required === 1 || $raw_required === true;

        echo '<div style="margin-top:8px;padding-top:6px;border-top:1px solid #e5e7eb;">';
        echo '<label style="display:flex;align-items:center;font-size:11px;gap:6px;">';
        echo '<input type="checkbox" name="fflhub_ffl_required" value="1" ' . checked( true, $ffl_required, false ) . ' />';
        echo '<span style="font-weight:600;">' . esc_html__( 'FFL Required', 'ffl-hub' ) . '</span>';
        echo '</label>';
        echo '<p style="margin:4px 0 0;font-size:11px;color:#6b7280;">' .
             esc_html__( 'If checked, this product requires shipment to an FFL.', 'ffl-hub' ) .
             '</p>';
        echo '</div>';

        echo '<p style="margin-top:6px;font-size:11px;color:#6b7280;">';
        esc_html_e( 'Most values are managed by FFLHub and updated automatically by sync jobs.', 'ffl-hub' );
        echo '</p>';
    }

    /**
     * Save handler for the meta box.
     *
     * @param int $post_id
     */
    public static function save_meta_box( int $post_id ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if (
            ! isset( $_POST['fflhub_product_meta_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['fflhub_product_meta_nonce'] ) ),
                'fflhub_save_product_meta'
            )
        ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
        if ( ! $product ) {
            return;
        }

        // Checkbox: if not set in POST, it means unchecked.
        $required = isset( $_POST['fflhub_ffl_required'] ) ? 1 : 0;

        // WC CRUD meta write + persist.
        $product->update_meta_data( FFLHub_Product_Meta::FFLHUB_FFL_REQUIRED_META, $required );
        $product->save();
    }
}

// Somewhere in your plugin bootstrap:
// FFLHub_Product_Meta_Box::init();
