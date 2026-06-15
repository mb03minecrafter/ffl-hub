<?php

declare(strict_types=1);

namespace FFLHub\Checkout\Fields;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;
use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Util\DebugLogUtil;
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
    private const DEBUG_ENV = 'FFLHUB_CHECKOUT_FIELDS_DEBUG';
    private const DEBUG_PREFIX = '[FFLHub CheckoutFields DEBUG] ';
    private const PROFILING_ENV = 'FFLHUB_CHECKOUT_FIELDS_PROFILE';
    private const PROFILE_PREFIX = '[FFLHub CheckoutFields PROFILE] ';

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

    private function debug_enabled(): bool
    {
        if (defined(self::DEBUG_ENV)) {
            return (bool) constant(self::DEBUG_ENV);
        }

        $env = getenv(self::DEBUG_ENV);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            if (in_array($env, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (defined('WP_DEBUG') && WP_DEBUG);
    }

    private function profile_enabled(): bool
    {
        if (defined(self::PROFILING_ENV)) {
            return (bool) constant(self::PROFILING_ENV);
        }

        $env = getenv(self::PROFILING_ENV);
        if ($env !== false) {
            $env = strtolower(trim((string) $env));
            return in_array($env, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function dbg(string $event, array $payload = []): void
    {
        $enabled = $this->debug_enabled();
        if (!$enabled) {
            return;
        }

        DebugLogUtil::log_if_ctx(
            $enabled,
            self::DEBUG_PREFIX,
            'event',
            array_merge(['event' => $event], $payload),
            self::DEBUG_ENV
        );
    }

    /**
     * @template T
     * @param callable():T $fn
     * @param array<string,mixed> $context
     * @return T
     */
    private function prof(string $span, callable $fn, array $context = [])
    {
        $enabled = $this->profile_enabled();
        if ($enabled) {
            DebugLogUtil::log_if_ctx($enabled, self::PROFILE_PREFIX, "START {$span}", $context, self::PROFILING_ENV);
        }

        $t0 = microtime(true);
        $mem0 = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        try {
            return $fn();
        } finally {
            if ($enabled) {
                $t1 = microtime(true);
                $mem1 = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
                DebugLogUtil::log_if_ctx(
                    $enabled,
                    self::PROFILE_PREFIX,
                    "END {$span}",
                    array_merge($context, [
                        'ms' => (int) round(($t1 - $t0) * 1000.0),
                        'mem_delta' => $mem1 - $mem0,
                        'mem_now' => $mem1,
                    ]),
                    self::PROFILING_ENV
                );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function request_context(): array
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

        return [
            'method' => $method,
            'uri' => $uri,
            'is_calc_totals' => (strpos($uri, '__experimental_calc_totals=true') !== false) ? 1 : 0,
        ];
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
                    return FFLRowMapper::normalize_ffl_number((string) $value);
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
        $this->prof('handle_set_additional_field_value.total', function () use ($field_id, $value, $group, $wc_object): void {
            $is_target_field = ($field_id === self::FIELD_ID);

            $this->dbg('set_field.received', array_merge($this->request_context(), [
                'field_id' => (string) $field_id,
                'is_target_field' => $is_target_field ? 1 : 0,
                'group' => is_scalar($group) ? (string) $group : gettype($group),
                'wc_object_class' => is_object($wc_object) ? get_class($wc_object) : gettype($wc_object),
                'raw_value' => CheckoutOrderRequestBuilder::dbg_val($value),
            ]));

            if (!$is_target_field) {
                return;
            }

            $sanitized = FFLRowMapper::normalize_ffl_number((string) $value);
            $this->dbg('set_field.normalized', [
                'ffl_number' => $sanitized,
                'summary' => CheckoutOrderRequestBuilder::dbg_val($sanitized),
            ]);

            // Always persist to WC session so cart/checkout validators can read it consistently.
            $this->persist_session_selection($sanitized);

            if (!$wc_object instanceof WC_Order) {
                $this->dbg('set_field.skip_order_meta_non_order_object', []);
                return;
            }

            $order_id = (int) $wc_object->get_id();

            if ($sanitized === '') {
                $wc_object->delete_meta_data(self::ORDER_META_KEY);
                $wc_object->delete_meta_data(self::ORDER_META_KEY_NUMBER);
                $this->dbg('set_field.order_meta_cleared', [
                    'order_id' => $order_id,
                ]);
                return;
            }

            // Always store the normalized number.
            $wc_object->update_meta_data(self::ORDER_META_KEY_NUMBER, $sanitized);

            // Store snapshot array (recommended: stable order history even if registry changes later).
            $ffl = $this->prof('handle_set_additional_field_value.lookup_ffl', function () use ($sanitized) {
                return FFLRepository::find_by_number($this->table, $sanitized);
            }, [
                'ffl_number' => $sanitized,
            ]);

            if (is_array($ffl)) {
                $wc_object->update_meta_data(self::ORDER_META_KEY, $ffl);
                $this->dbg('set_field.order_meta_updated', [
                    'order_id' => $order_id,
                    'ffl_found' => 1,
                    'ffl_number' => $sanitized,
                ]);
            } else {
                // Keep number but avoid mixed types in the snapshot meta.
                $wc_object->delete_meta_data(self::ORDER_META_KEY);
                $this->dbg('set_field.order_meta_updated', [
                    'order_id' => $order_id,
                    'ffl_found' => 0,
                    'ffl_number' => $sanitized,
                ]);
            }
        });
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
        $this->prof('validate_receiving_ffl_on_checkout.total', function () use ($data, $errors): void {
            $this->dbg('validate.start', array_merge($this->request_context(), [
                'data_keys_count' => count($data),
                'existing_error_count' => count($errors->get_error_codes()),
            ]));

            // Prefer session because it is consistently set by woocommerce_set_additional_field_value
            // for both classic + blocks flows.
            $ffl_number = $this->get_selected_ffl_number_from_session();
            $this->dbg('validate.session_value', [
                'ffl_number' => $ffl_number,
                'summary' => CheckoutOrderRequestBuilder::dbg_val($ffl_number),
            ]);

            // Fallback: try to read directly from posted data (best-effort).
            if ($ffl_number === '') {
                $ffl_number = $this->get_selected_ffl_number_from_posted_data($data);
                $this->dbg('validate.posted_fallback_value', [
                    'ffl_number' => $ffl_number,
                    'summary' => CheckoutOrderRequestBuilder::dbg_val($ffl_number),
                ]);
            }

            if ($ffl_number === '') {
                $errors->add(
                    'fflhub_missing_receiving_ffl',
                    __('Please select a receiving FFL before placing your order.', 'ffl-hub')
                );
                $this->dbg('validate.blocked_missing', [
                    'error_count_after' => count($errors->get_error_codes()),
                ]);
                return;
            }

            $ffl = $this->prof('validate_receiving_ffl_on_checkout.lookup_ffl', function () use ($ffl_number) {
                return FFLRepository::find_by_number($this->table, $ffl_number);
            }, [
                'ffl_number' => $ffl_number,
            ]);

            if ($ffl === null) {
                $errors->add(
                    'fflhub_invalid_receiving_ffl',
                    __('The FFL number you selected could not be found in our dealer registry. Please choose a valid FFL from the list or verify the number.', 'ffl-hub')
                );
                $this->dbg('validate.blocked_invalid', [
                    'ffl_number' => $ffl_number,
                    'error_count_after' => count($errors->get_error_codes()),
                ]);
                return;
            }

            $this->dbg('validate.ok', [
                'ffl_number' => $ffl_number,
            ]);
        });
    }

    // --------------------------------------------------------------------------------------------
    // Session persistence helpers
    // --------------------------------------------------------------------------------------------

    private function persist_session_selection(string $ffl_number): void
    {
        $this->prof('persist_session_selection.total', function () use ($ffl_number): void {
            if (!function_exists('WC') || !WC() || !WC()->session) {
                $this->dbg('session.persist.skip_no_wc_session', [
                    'ffl_number' => $ffl_number,
                ]);
                return;
            }

            $before_number = WC()->session->get(self::SESSION_KEY_RECEIVING_FFL);
            $before_fp = WC()->session->get(self::SESSION_KEY_RECEIVING_FFL_FP);

            if ($ffl_number === '') {
                WC()->session->set(self::SESSION_KEY_RECEIVING_FFL, null);
                WC()->session->set(self::SESSION_KEY_RECEIVING_FFL_FP, null);
                $this->dbg('session.persist.cleared', [
                    'before_number' => CheckoutOrderRequestBuilder::dbg_val($before_number),
                    'before_fp' => CheckoutOrderRequestBuilder::dbg_val($before_fp),
                ]);
                return;
            }

            WC()->session->set(self::SESSION_KEY_RECEIVING_FFL, $ffl_number);

            // Bind selection to cart fingerprint for debugging visibility.
            $fp = CheckoutOrderRequestBuilder::current_cart_ffl_fingerprint();
            WC()->session->set(self::SESSION_KEY_RECEIVING_FFL_FP, $fp !== '' ? $fp : null);

            $after_number = WC()->session->get(self::SESSION_KEY_RECEIVING_FFL);
            $after_fp = WC()->session->get(self::SESSION_KEY_RECEIVING_FFL_FP);

            $this->dbg('session.persist.saved', [
                'incoming_ffl_number' => $ffl_number,
                'before_number' => CheckoutOrderRequestBuilder::dbg_val($before_number),
                'before_fp' => CheckoutOrderRequestBuilder::dbg_val($before_fp),
                'after_number' => CheckoutOrderRequestBuilder::dbg_val($after_number),
                'after_fp' => CheckoutOrderRequestBuilder::dbg_val($after_fp),
                'computed_fp' => CheckoutOrderRequestBuilder::dbg_val($fp),
            ]);
        });
    }

    private function get_selected_ffl_number_from_session(): string
    {
        if (!function_exists('WC') || !WC() || !WC()->session) {
            $this->dbg('session.read.skip_no_wc_session', []);
            return '';
        }

        $val = WC()->session->get(self::SESSION_KEY_RECEIVING_FFL);
        $normalized = FFLRowMapper::normalize_ffl_number((string) ($val ?? ''));

        $this->dbg('session.read', [
            'raw' => CheckoutOrderRequestBuilder::dbg_val($val),
            'normalized' => $normalized,
            'normalized_summary' => CheckoutOrderRequestBuilder::dbg_val($normalized),
        ]);

        return $normalized;
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

        $this->dbg('posted_data.candidates', [
            'candidate_count' => count($candidates),
            'candidate_summaries' => array_map(static function ($v) {
                return CheckoutOrderRequestBuilder::dbg_val($v);
            }, $candidates),
        ]);

        foreach ($candidates as $candidate) {
            $norm = FFLRowMapper::normalize_ffl_number((string) $candidate);
            if ($norm !== '') {
                $this->dbg('posted_data.selected', [
                    'ffl_number' => $norm,
                    'summary' => CheckoutOrderRequestBuilder::dbg_val($norm),
                ]);
                return $norm;
            }
        }

        $this->dbg('posted_data.selected', [
            'ffl_number' => '',
        ]);

        return '';
    }
}
