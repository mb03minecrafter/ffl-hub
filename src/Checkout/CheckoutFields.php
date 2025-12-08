<?php

namespace FFLHub\Checkout;

use FFLHub\Admin\FFLImporterPage;
use WC_Order;
use WC_Data;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers FFL-related additional checkout fields for the Checkout Block.
 *
 * Uses the "Additional Checkout Fields" API (WooCommerce 8.9+) and conditional
 * visibility via JSON Schema (WooCommerce 9.9+).
 */
class CheckoutFields
{
    /**
     * Additional field ID (namespace/name).
     *
     * This is the ID used by the Additional Checkout Fields API.
     */
    private const FIELD_ID = 'ffl-hub/receiving-ffl';

    /**
     * Order meta key where we store the selected FFL value.
     */
    private const ORDER_META_KEY = 'fflhub_receiving_ffl';

    /**
     * Order meta key where we store just the FFL number (string).
     */
    private const ORDER_META_KEY_NUMBER = 'fflhub_receiving_ffl_number';

    /**
     * Bootstrap hooks.
     */
    public static function init(): void
    {
        // Register the additional checkout field.
        add_action('woocommerce_init', array(__CLASS__, 'register_additional_fields'));

        // Persist the field value to order meta when checkout is submitted.
        add_action(
            'woocommerce_set_additional_field_value',
            array(__CLASS__, 'handle_set_additional_field_value'),
            10,
            4
        );
    }

    /**
     * Register the "Receiving FFL" checkout field.
     *
     * This uses the Additional Checkout Fields API so the field appears in the
     * Checkout Block UI automatically.
     */
    public static function register_additional_fields(): void
    {
        if (! function_exists('woocommerce_register_additional_checkout_field')) {
            // Older WooCommerce – API not available.
            return;
        }

        // Field will show in the "Order information" step.
        woocommerce_register_additional_checkout_field(
            array(
                'id'       => self::FIELD_ID,
                'label'    => __('Receiving FFL (Dealer Number)', 'ffl-hub'),
                'location' => 'order', // "Order information" section.
                'type'     => 'text',
                'required' => array(
                    // JSON Schema condition: required when cart.extensions['ffl-hub'].requires_ffl === true
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
                    // Hide the field when requires_ffl === false
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
                // Optional: placeholder + attributes.
                'placeholder' => '',
                'attributes'  => array(
                    'autocomplete'                    => 'off',
                    // Custom attribute so JS can find this exact field:
                    'data-fflhub-receiving-ffl-input' => '1',
                ),

                // Optional sanitization/validation.
                'sanitize_callback' => function ($value) {
                    return sanitize_text_field((string) $value);
                },
                'validate_callback' => array(__CLASS__, 'validate_receiving_ffl'),
            )
        );
    }

    /**
     * Save additional checkout field values to order meta.
     *
     * Hook: woocommerce_set_additional_field_value (WC 8.9+).
     *
     * @param string         $field_id  The additional field ID ("namespace/name").
     * @param mixed          $value     The raw field value.
     * @param string         $group     Group name ("billing", "shipping", "other").
     * @param WC_Data|object $wc_object WC_Order or WC_Customer object being updated.
     */
    public static function handle_set_additional_field_value($field_id, $value, $group, $wc_object): void
    {
        if ($field_id !== self::FIELD_ID) {
            return;
        }

        if (! $wc_object instanceof WC_Order) {
            // We only care about storing it on the order.
            return;
        }

        $sanitized = strtoupper(trim(sanitize_text_field((string) $value)));

        if ($sanitized === '') {
            // Clear both metas if field is empty.
            $wc_object->delete_meta_data(self::ORDER_META_KEY);
            $wc_object->delete_meta_data(self::ORDER_META_KEY_NUMBER);
            return;
        }

        // Always store the plain FFL number for quick reference / search.
        $wc_object->update_meta_data(self::ORDER_META_KEY_NUMBER, $sanitized);

        // Try to fetch full FFL data from our DB.
        $ffl_data = self::get_ffl_data_by_number($sanitized);

        if ($ffl_data) {
            // Store the full structured record.
            $wc_object->update_meta_data(self::ORDER_META_KEY, $ffl_data);
        } else {
            // Fallback: if somehow not found, at least store the number.
            $wc_object->update_meta_data(self::ORDER_META_KEY, $sanitized);
        }
    }

    /**
     * Validate that the Receiving FFL field is non-empty (when required)
     * and that the value corresponds to a real FFL in our SQL database.
     *
     * This is used as the `validate_callback` for the Additional Checkout Field.
     *
     * @param mixed $value Raw field value from the Checkout Block.
     * @return true|WP_Error
     */
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

    /**
     * Look up an FFL row in our SQL table by FFL number.
     *
     * Returns an array shaped like our public FFL API response:
     * [
     *   'ffl_number' => '...',
     *   'name'       => '...',
     *   'premise'    => [ 'street', 'city', 'state', 'zip' ],
     *   'mailing'    => [ 'street', 'city', 'state', 'zip' ],
     *   'phone'      => '...',
     * ]
     *
     * @param string $ffl_number
     * @return array|null
     */
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
