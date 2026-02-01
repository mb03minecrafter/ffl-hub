<?php

declare(strict_types=1);

namespace FFLHub\Checkout\Fields;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;
use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\FFL\Tables\FFLTable;
use WC_Order;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checkout additional field: Receiving FFL (Dealer Number)
 *
 * Refactor goals:
 * - Instance-based, constructor takes FFLTable.
 * - No raw SQL here (lookups via FFLRepository).
 * - Validation done via woocommerce_after_checkout_validation (instance-aware),
 *   so we don't need a static validate_callback or any "table provider" bridge.
 */
final class CheckoutFields
{
    private const FIELD_ID = 'ffl-hub/receiving-ffl';

    /**
     * Snapshot array of FFL details at selection time (optional but recommended).
     */
    private const ORDER_META_KEY = 'fflhub_receiving_ffl';

    /**
     * Normalized FFL number (string).
     */
    private const ORDER_META_KEY_NUMBER = 'fflhub_receiving_ffl_number';

    // Session keys used by CartCompliance / Builder
    private const SESSION_KEY_RECEIVING_FFL    = 'fflhub_receiving_ffl_number';
    private const SESSION_KEY_RECEIVING_FFL_FP = 'fflhub_receiving_ffl_cart_fp';

    private FFLTable $table;

    public function __construct(FFLTable $table)
    {
        $this->table = $table;
    }

    public function register(): void
    {
        add_action('woocommerce_init', [$this, 'register_additional_fields']);

        add_action(
            'woocommerce_set_additional_field_value',
            [$this, 'handle_set_additional_field_value'],
            10,
            4
        );

        // ✅ Instance-aware validation hook (no static callback needed)
        add_action(
            'woocommerce_after_checkout_validation',
            [$this, 'validate_receiving_ffl_on_checkout'],
            10,
            2
        );
    }

    public function register_additional_fields(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field(
            [
                'id'       => self::FIELD_ID,
                'label'    => __('Receiving FFL (Dealer Number)', 'ffl-hub'),
                'location' => 'order',
                'type'     => 'text',

                // Required only if cart extension says requires_ffl === true
                'required' => [
                    'cart' => [
                        'properties' => [
                            'extensions' => [
                                'properties' => [
                                    'ffl-hub' => [
                                        'properties' => [
                                            'requires_ffl' => [
                                                'const' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],

                // Hidden if requires_ffl === false
                'hidden'   => [
                    'cart' => [
                        'properties' => [
                            'extensions' => [
                                'properties' => [
                                    'ffl-hub' => [
                                        'properties' => [
                                            'requires_ffl' => [
                                                'const' => false,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],

                'placeholder' => '',
                'attributes'  => [
                    'autocomplete'                    => 'off',
                    'data-fflhub-receiving-ffl-input' => '1',
                ],
                'sanitize_callback' => static function ($value) {
                    return sanitize_text_field((string) $value);
                },

                // ❌ No validate_callback here. We validate in woocommerce_after_checkout_validation.
            ]
        );
    }

    /**
     * Woo callback for field updates (fires for session + order object).
     *
     * @param mixed $field_id
     * @param mixed $value
     * @param mixed $group
     * @param mixed $wc_object
     */
    public function handle_set_additional_field_value($field_id, $value, $group, $wc_object): void
    {
        if ($field_id !== self::FIELD_ID) {
            return;
        }

        $sanitized = FFLRowMapper::normalize_ffl_number((string) $value);

        // ✅ Always persist to WC session so cart/checkout validators can read it consistently.
        $this->persist_session_selection($sanitized);

        if (!$wc_object instanceof WC_Order) {
            return;
        }

        if ($sanitized === '') {
            $wc_object->delete_meta_data(self::ORDER_META_KEY);
            $wc_object->delete_meta_data(self::ORDER_META_KEY_NUMBER);
            return;
        }

        // Always store the normalized number.
        $wc_object->update_meta_data(self::ORDER_META_KEY_NUMBER, $sanitized);

        // Store snapshot array (recommended: stable order history even if registry changes later).
        $ffl = FFLRepository::find_by_number($this->table, $sanitized);

        if (is_array($ffl)) {
            $wc_object->update_meta_data(self::ORDER_META_KEY, $ffl);
        } else {
            // Keep number but avoid mixed types in the snapshot meta.
            $wc_object->delete_meta_data(self::ORDER_META_KEY);
        }
    }

    /**
     * Instance-aware checkout validation.
     *
     * Woo passes $data as the posted checkout data array for classic checkout.
     * For blocks/additional fields, the most reliable source for our selection
     * is the WC session key we set in handle_set_additional_field_value().
     *
     * @param array<string,mixed> $data
     * @param WP_Error $errors
     */
    public function validate_receiving_ffl_on_checkout(array $data, WP_Error $errors): void
    {
        // Prefer session because it is consistently set by woocommerce_set_additional_field_value
        // for both classic + blocks flows.
        $ffl_number = $this->get_selected_ffl_number_from_session();

        // Fallback: try to read directly from posted data (best-effort).
        if ($ffl_number === '') {
            $ffl_number = $this->get_selected_ffl_number_from_posted_data($data);
        }

        if ($ffl_number === '') {
            $errors->add(
                'fflhub_missing_receiving_ffl',
                __('Please select a receiving FFL before placing your order.', 'ffl-hub')
            );
            return;
        }

        $ffl = FFLRepository::find_by_number($this->table, $ffl_number);

        if ($ffl === null) {
            $errors->add(
                'fflhub_invalid_receiving_ffl',
                __(
                    'The FFL number you selected could not be found in our dealer registry. Please choose a valid FFL from the list or verify the number.',
                    'ffl-hub'
                )
            );
        }
    }

    // --------------------------------------------------------------------------------------------
    // Session persistence helpers
    // --------------------------------------------------------------------------------------------

    private function persist_session_selection(string $ffl_number): void
    {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return;
        }




        if ($ffl_number === '') {
            WC()->session->set(self::SESSION_KEY_RECEIVING_FFL, null);
            WC()->session->set(self::SESSION_KEY_RECEIVING_FFL_FP, null);
            return;
        }

        WC()->session->set(self::SESSION_KEY_RECEIVING_FFL, $ffl_number);

        // Bind selection to cart fingerprint so CartCompliance can detect stale values after cart changes.
        $fp = CheckoutOrderRequestBuilder::current_cart_ffl_fingerprint();
        WC()->session->set(self::SESSION_KEY_RECEIVING_FFL_FP, $fp !== '' ? $fp : null);
    }

    private function get_selected_ffl_number_from_session(): string
    {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            return '';
        }

        $val = WC()->session->get(self::SESSION_KEY_RECEIVING_FFL);
        return FFLRowMapper::normalize_ffl_number((string) ($val ?? ''));
    }

    /**
     * Best-effort fallback for classic checkout payload shapes.
     *
     * @param array<string,mixed> $data
     */
    private function get_selected_ffl_number_from_posted_data(array $data): string
    {
        // Common patterns:
        // - $data[self::FIELD_ID]
        // - $data['additional_fields'][self::FIELD_ID]
        // - other plugin-mediated shapes
        $candidates = [];

        if (isset($data[self::FIELD_ID])) {
            $candidates[] = $data[self::FIELD_ID];
        }

        if (isset($data['additional_fields']) && is_array($data['additional_fields']) && isset($data['additional_fields'][self::FIELD_ID])) {
            $candidates[] = $data['additional_fields'][self::FIELD_ID];
        }

        foreach ($candidates as $candidate) {
            $norm = FFLRowMapper::normalize_ffl_number((string) $candidate);
            if ($norm !== '') {
                return $norm;
            }
        }

        return '';
    }
}
