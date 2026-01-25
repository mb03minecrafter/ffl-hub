<?php

namespace FFLHub\Checkout\Fields;

use FFLHub\Admin\Pages\FFLImporterPage;
use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;
use WC_Order;
use WC_Data;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class CheckoutFields
{
    private const FIELD_ID = 'ffl-hub/receiving-ffl';

    private const ORDER_META_KEY = 'fflhub_receiving_ffl';
    private const ORDER_META_KEY_NUMBER = 'fflhub_receiving_ffl_number';

    // Session keys used by CartCompliance / Builder
    private const SESSION_KEY_RECEIVING_FFL = 'fflhub_receiving_ffl_number';
    private const SESSION_KEY_RECEIVING_FFL_FP = 'fflhub_receiving_ffl_cart_fp';

    public static function init(): void
    {
        add_action('woocommerce_init', array(__CLASS__, 'register_additional_fields'));

        add_action(
            'woocommerce_set_additional_field_value',
            array(__CLASS__, 'handle_set_additional_field_value'),
            10,
            4
        );
    }

    public static function register_additional_fields(): void
    {
        if (! function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field(
            array(
                'id'       => self::FIELD_ID,
                'label'    => __('Receiving FFL (Dealer Number)', 'ffl-hub'),
                'location' => 'order',
                'type'     => 'text',
                'required' => array(
                    'cart' => array(
                        'properties' => array(
                            'extensions' => array(
                                'properties' => array(
                                    'ffl-hub' => array(
                                        'properties' => array(
                                            'requires_ffl' => array(
                                                'const' => true,
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'hidden'   => array(
                    'cart' => array(
                        'properties' => array(
                            'extensions' => array(
                                'properties' => array(
                                    'ffl-hub' => array(
                                        'properties' => array(
                                            'requires_ffl' => array(
                                                'const' => false,
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'placeholder' => '',
                'attributes'  => array(
                    'autocomplete'                    => 'off',
                    'data-fflhub-receiving-ffl-input' => '1',
                ),
                'sanitize_callback' => function ($value) {
                    return sanitize_text_field((string) $value);
                },
                'validate_callback' => array(__CLASS__, 'validate_receiving_ffl'),
            )
        );
    }

    public static function handle_set_additional_field_value($field_id, $value, $group, $wc_object): void
    {
        if ($field_id !== self::FIELD_ID) {
            return;
        }

        $sanitized = strtoupper(trim(sanitize_text_field((string) $value)));

        // ✅ Always persist to WC session so cart/checkout validators can read it consistently.
        if (function_exists('WC') && WC()->session) {
            if ($sanitized === '') {
                WC()->session->set(self::SESSION_KEY_RECEIVING_FFL, null);
                WC()->session->set(self::SESSION_KEY_RECEIVING_FFL_FP, null);
            } else {
                WC()->session->set(self::SESSION_KEY_RECEIVING_FFL, $sanitized);

                // ✅ Bind this FFL selection to the cart fingerprint at selection-time
                // so CartCompliance can detect stale values after cart changes.
                $fp = CheckoutOrderRequestBuilder::current_cart_ffl_fingerprint();
                WC()->session->set(self::SESSION_KEY_RECEIVING_FFL_FP, $fp !== '' ? $fp : null);
            }
        }

        if (! $wc_object instanceof WC_Order) {
            return;
        }

        if ($sanitized === '') {
            $wc_object->delete_meta_data(self::ORDER_META_KEY);
            $wc_object->delete_meta_data(self::ORDER_META_KEY_NUMBER);
            return;
        }

        $wc_object->update_meta_data(self::ORDER_META_KEY_NUMBER, $sanitized);

        $ffl_data = self::get_ffl_data_by_number($sanitized);

        if ($ffl_data) {
            $wc_object->update_meta_data(self::ORDER_META_KEY, $ffl_data);
        } else {
            $wc_object->update_meta_data(self::ORDER_META_KEY, $sanitized);
        }
    }

    public static function validate_receiving_ffl($value)
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return new WP_Error(
                'fflhub_missing_receiving_ffl',
                __('Please select a receiving FFL before placing your order.', 'ffl-hub')
            );
        }

        $ffl = self::get_ffl_data_by_number($value);

        if ($ffl === null) {
            return new WP_Error(
                'fflhub_invalid_receiving_ffl',
                __(
                    'The FFL number you selected could not be found in our dealer registry. Please choose a valid FFL from the list or verify the number.',
                    'ffl-hub'
                )
            );
        }

        return true;
    }

    private static function get_ffl_data_by_number(string $ffl_number): ?array
    {
        if ($ffl_number === '') {
            return null;
        }

        global $wpdb;

        $table_name = FFLImporterPage::get_table_name_public();
        if (empty($table_name)) {
            return null;
        }

        $sql = $wpdb->prepare(
            "
                SELECT
                    ffl_number,
                    license_name,
                    premise_street,
                    premise_city,
                    premise_state,
                    premise_zip,
                    mail_street,
                    mail_city,
                    mail_state,
                    mail_zip,
                    voice_phone
                FROM {$table_name}
                WHERE ffl_number = %s
                LIMIT 1
            ",
            $ffl_number
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        if (! $row) {
            return null;
        }

        return array(
            'ffl_number' => $row['ffl_number'],
            'name'       => $row['license_name'],
            'premise'    => array(
                'street' => $row['premise_street'],
                'city'   => $row['premise_city'],
                'state'  => $row['premise_state'],
                'zip'    => $row['premise_zip'],
            ),
            'mailing'    => array(
                'street' => $row['mail_street'],
                'city'   => $row['mail_city'],
                'state'  => $row['mail_state'],
                'zip'    => $row['mail_zip'],
            ),
            'phone'      => $row['voice_phone'],
        );
    }
}
