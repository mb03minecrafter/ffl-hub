<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles displaying FFL information on the WooCommerce order admin page.
 */
class FFLHub_FFL_Order_Admin {

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        // Render FFL block on the order edit screen.
        add_action(
            'woocommerce_admin_order_data_after_billing_address',
            array( __CLASS__, 'render_order_ffl_panel' ),
            10,
            1
        );

        // Enqueue CSS for the order admin FFL block.
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Enqueue styles only on the order edit screens.
     *
     * @param string $hook
     */
    public static function enqueue_assets( string $hook ): void {
        // Only load on post edit screens.
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'shop_order' ) {
            return;
        }

        $base_url = plugin_dir_url( __FILE__ ) . '../assets/';

        wp_enqueue_style(
            'fflhub-order-admin',
            FFLHUB_PLUGIN_URL . 'assets/css/order-admin.css',
            array(),
            '0.1.0'
        );
    }

    /**
     * Render the FFL information panel on the order edit screen.
     *
     * @param WC_Order $order
     */
    public static function render_order_ffl_panel( $order ): void {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $ffl = self::get_order_ffl_meta( $order );

        // If no FFL data, don't render anything.
        if ( empty( $ffl ) ) {
            return;
        }
        ?>
        <div class="fflhub-order-ffl-panel">
            <h3 class="fflhub-order-ffl-title">
                <?php esc_html_e( 'FFL for Firearm Shipment', 'ffl-hub' ); ?>
            </h3>

            <table class="fflhub-order-ffl-table">
                <tbody>
                    <?php if ( ! empty( $ffl['number'] ) ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'FFL Number', 'ffl-hub' ); ?></th>
                            <td><?php echo esc_html( $ffl['number'] ); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ( ! empty( $ffl['name'] ) ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Business Name', 'ffl-hub' ); ?></th>
                            <td><?php echo esc_html( $ffl['name'] ); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ( ! empty( $ffl['premise'] ) ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Premise Address', 'ffl-hub' ); ?></th>
                            <td><?php echo esc_html( $ffl['premise'] ); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if ( ! empty( $ffl['phone'] ) ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Phone', 'ffl-hub' ); ?></th>
                            <td><?php echo esc_html( $ffl['phone'] ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Resolve FFL metadata from the order, handling new structured meta
     * and legacy meta keys.
     *
     * Returns:
     * [
     *   'number'  => string,
     *   'name'    => string,
     *   'premise' => string (single-line premise address),
     *   'phone'   => string,
     * ]
     *
     * @param WC_Order $order
     * @return array<string,string>
     */
    private static function get_order_ffl_meta( $order ): array {
        $result = array();

        // 1) Preferred: new structured meta stored under 'fflhub_receiving_ffl'.
        $structured = $order->get_meta( 'fflhub_receiving_ffl' );

        if ( is_array( $structured ) && ! empty( $structured ) ) {
            $number  = isset( $structured['ffl_number'] ) ? (string) $structured['ffl_number'] : '';
            $name    = isset( $structured['name'] ) ? (string) $structured['name'] : '';
            $premise = isset( $structured['premise'] ) && is_array( $structured['premise'] )
                ? $structured['premise']
                : array();
            $phone   = isset( $structured['phone'] ) ? (string) $structured['phone'] : '';

            if ( $number !== '' ) {
                $result['number'] = $number;
            }
            if ( $name !== '' ) {
                $result['name'] = $name;
            }

            // Build a single-line premise address from the structured fields.
            if ( ! empty( $premise ) ) {
                $street = $premise['street'] ?? '';
                $city   = $premise['city'] ?? '';
                $state  = $premise['state'] ?? '';
                $zip    = $premise['zip'] ?? '';

                $line_parts = array();

                if ( $street !== '' ) {
                    $line_parts[] = $street;
                }

                $city_state_zip = trim(
                    sprintf(
                        '%s%s%s',
                        $city,
                        ($city && ($state || $zip)) ? ', ' : '',
                        trim( $state . ' ' . $zip )
                    )
                );

                if ( $city_state_zip !== '' ) {
                    $line_parts[] = $city_state_zip;
                }

                $premise_line = trim( implode( ', ', $line_parts ) );
                if ( $premise_line !== '' ) {
                    $result['premise'] = $premise_line;
                }
            }

            if ( $phone !== '' ) {
                $result['phone'] = $phone;
            }

            // We got structured data; return it.
            if ( ! empty( $result ) ) {
                return $result;
            }
        }

        // 2) Fallback: new "number only" meta + legacy meta keys.
        $fields = array(
            'number' => array(
                'fflhub_receiving_ffl_number', // new
                'fflhub_ffl_number',           // legacy
            ),
            'name'   => array(
                'fflhub_ffl_name',
            ),
            'premise' => array(
                'fflhub_ffl_premise',
            ),
            'phone'  => array(
                'fflhub_ffl_phone',
            ),
        );

        foreach ( $fields as $field_key => $meta_keys ) {
            $value = self::get_first_meta_value( $order, $meta_keys );
            if ( $value !== '' ) {
                $result[ $field_key ] = $value;
            }
        }

        return $result;
    }

    /**
     * Helper: look up the first non-empty value from a list of meta keys.
     *
     * @param WC_Order $order
     * @param string[] $meta_keys
     * @return string
     */
    private static function get_first_meta_value( $order, array $meta_keys ): string {
        foreach ( $meta_keys as $key ) {
            $value = $order->get_meta( $key );
            if ( is_array( $value ) ) {
                // If a structured value ever sneaks in here, skip it.
                continue;
            }

            $value = (string) $value;
            if ( $value !== '' ) {
                return $value;
            }
        }

        return '';
    }
}
