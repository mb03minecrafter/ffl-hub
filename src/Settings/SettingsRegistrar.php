<?php

namespace FFLHub\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

/**
 * Centralized WP Settings API registration for FFL Hub.
 *
 * Why this exists:
 * - Keeps settings registration out of admin page rendering code.
 * - Allows distributor modules to declare their own settings via a schema,
 *   while still enforcing consistent sanitize/typing rules.
 *
 * Entry point:
 * - Plugin constructor calls SettingsRegistrar::init()
 * - This hooks admin_init and registers all known settings.
 */
final class SettingsRegistrar
{
    /**
     * Hook settings registration into WordPress admin init.
     *
     * NOTE: register_setting() should only run in admin context.
     */
    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'register_all_settings']);
    }

    /**
     * Register all settings:
     * - Global (plugin-wide)
     * - Distributor module settings (schema-driven)
     */
    public static function register_all_settings(): void
    {
        self::register_global_settings();
        self::register_map_policy_settings();
        self::register_usps_settings();
        self::register_distributor_settings();
    }

    /* -------------------------------------------------------------------------
     * Global settings
     * ---------------------------------------------------------------------- */

    /**
     * Register plugin-wide settings (not tied to a distributor).
     *
     * All values are stored as strings in wp_options (WP Settings API behavior).
     * We sanitize values on write and provide a default for first-time reads.
     */
    private static function register_global_settings(): void
    {
        $group = Options::global_settings_group();

        register_setting(
            $group,
            Options::OPTION_PAYMENT_PROCESSOR_FEE_PERCENT,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_percent_string'],
                'default'           => (string) Options::default_payment_processor_fee_percent(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_GLOBAL_MARKUP,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_percent_string'],
                'default'           => (string) Options::default_global_markup(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_TEST_ORDER_DEBUG_ENABLED,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                'default'           => Options::default_test_order_debug_enabled() ? '1' : '0',
            ]
        );

        register_setting(
            $group,
            Options::OPTION_HOLOSUN_IMAGE_NOTICE_ENABLED,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                'default'           => Options::default_holosun_image_notice_enabled() ? '1' : '0',
            ]
        );

        register_setting(
            $group,
            Options::OPTION_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                'default'           => Options::default_holosun_show_price_override_enabled() ? '1' : '0',
            ]
        );

        register_setting(
            $group,
            Options::OPTION_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                'default'           => Options::default_pretty_random_email_quotes_enabled() ? '1' : '0',
            ]
        );

        register_setting(
            $group,
            Options::OPTION_BATCH_ORDER_NOTIFICATION_EMAIL,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_email_list'],
                'default'           => Options::default_batch_order_notification_email(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_DISTRIBUTOR_PRIORITY_LIST,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_distributor_priority_list'],
                'default'           => Options::default_distributor_priority_csv(),
            ]
        );

        foreach (Options::default_dealer_ship_to_options() as $option_name => $default_value) {
            register_setting(
                $group,
                $option_name,
                [
                    'type'              => 'string',
                    'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                    'default'           => $default_value,
                ]
            );
        }

        foreach (Options::default_relay_ship_to_options() as $option_name => $default_value) {
            register_setting(
                $group,
                $option_name,
                [
                    'type'              => 'string',
                    'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                    'default'           => $default_value,
                ]
            );
        }
    }

    /**
     * Register MAP brand policy settings.
     */
    private static function register_map_policy_settings(): void
    {
        $group = Options::map_policy_settings_group();

        register_setting(
            $group,
            Options::OPTION_MAP_BRAND_POLICIES,
            [
                'type'              => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_map_brand_policies'],
                'default'           => [],
            ]
        );
    }

    /**
     * Register USPS outbound estimate settings.
     */
    private static function register_usps_settings(): void
    {
        $group = Options::usps_settings_group();

        register_setting(
            $group,
            Options::OPTION_USPS_ESTIMATE_ENABLED,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                'default'           => Options::default_usps_estimate_enabled() ? '1' : '0',
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_USE_TEST_ENV,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                'default'           => Options::default_usps_use_test_env() ? '1' : '0',
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_BASE_URL,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_loose_string'],
                'default'           => Options::default_usps_base_url(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_CLIENT_ID,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_loose_string'],
                'default'           => Options::default_usps_client_id(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_CLIENT_SECRET,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_loose_string'],
                'default'           => Options::default_usps_client_secret(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_ORIGIN_ZIP,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                'default'           => Options::default_usps_origin_zip(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_ACCOUNT_TYPE,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                'default'           => Options::default_usps_account_type(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_ACCOUNT_NUMBER,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_loose_string'],
                'default'           => Options::default_usps_account_number(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_MAIL_CLASS,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                'default'           => Options::default_usps_mail_class(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_PROCESSING_CATEGORY,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                'default'           => Options::default_usps_processing_category(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_DEST_ENTRY_FACILITY_TYPE,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                'default'           => Options::default_usps_destination_entry_facility_type(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_RATE_INDICATOR,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                'default'           => Options::default_usps_rate_indicator(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_PRICE_TYPE,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_text'],
                'default'           => Options::default_usps_price_type(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_TIMEOUT_SEC,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_positive_int_string'],
                'default'           => (string) Options::default_usps_timeout_sec(),
            ]
        );

        register_setting(
            $group,
            Options::OPTION_USPS_TARE_WEIGHT_OZ,
            [
                'type'              => 'string',
                'sanitize_callback' => [__CLASS__, 'sanitize_non_negative_decimal_string'],
                'default'           => (string) Options::default_usps_tare_weight_oz(),
            ]
        );
    }

    /* -------------------------------------------------------------------------
     * Distributor settings (module-driven)
     * ---------------------------------------------------------------------- */

    /**
     * Register settings declared by each distributor module.
     *
     * Contract (expected schema shape):
     *   $module->settings_schema() returns:
     *     [
     *       'field_key' => [
     *         'type'    => 'text'|'textarea'|'select'|'number'|'password'|'checkbox',
     *         'default' => '...',
     *         // (optional future keys: label, description, options, etc.)
     *       ],
     *       ...
     *     ]
     *
     * Notes:
     * - We store each setting under a generated option name that namespaces it
     *   by distributor id and schema key.
     * - Each setting is registered as a string option because WP stores option
     *   values as strings, and our sanitizers also return strings.
     */
    private static function register_distributor_settings(): void
    {
        foreach (DistributorRegistry::get_modules() as $module) {
            if (!($module instanceof DistributorModuleInterface)) {
                continue;
            }

            $dist_id = $module->id();
            $fields  = $module->settings_schema();
            if (!is_array($fields)) {
                $fields = [];
            }

            $group = Options::distributor_settings_group($dist_id);

            // Shared per-distributor credit limit shown in each distributor modal.
            register_setting(
                $group,
                Options::distributor_credit_limit_option_name($dist_id),
                [
                    'type'              => 'string',
                    'sanitize_callback' => [__CLASS__, 'sanitize_non_negative_decimal_string'],
                    'default'           => (string) Options::default_distributor_credit_limit($dist_id),
                ]
            );

            // Shared per-distributor toggle:
            // if enabled, this distributor is treated as drop-ship only.
            register_setting(
                $group,
                Options::distributor_non_dropship_blocked_option_name($dist_id),
                [
                    'type'              => 'string',
                    'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                    'default'           => '0',
                ]
            );

            // Shared per-distributor toggle:
            // if enabled, SIG SAUER rows are treated as drop-ship eligible.
            register_setting(
                $group,
                Options::distributor_sig_approved_option_name($dist_id),
                [
                    'type'              => 'string',
                    'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
                    'default'           => '0',
                ]
            );

            foreach ($fields as $key => $def) {
                $key = (string) $key;

                // Option name is centralized so we can change naming once if needed.
                $option_name = Options::distributor_option_name($dist_id, $key);

                $type    = isset($def['type']) ? (string) $def['type'] : 'text';
                $default = isset($def['default']) ? (string) $def['default'] : '';

                register_setting(
                    $group,
                    $option_name,
                    [
                        'type'              => 'string',
                        'sanitize_callback' => self::sanitize_callback_for_type($type),
                        'default'           => $default,
                    ]
                );
            }
        }
    }

    /* -------------------------------------------------------------------------
     * Sanitizers
     * ---------------------------------------------------------------------- */

    /**
     * Percent/number sanitizer:
     * - Removes everything except digits and '.'.
     * - Coerces to float and returns the normalized float string.
     *
     * Examples:
     * - " 5 "      -> "5"
     * - "5.25%"    -> "5.25"
     * - "abc"      -> "0"
     *
     * NOTE: This matches existing behavior. If you later want stricter behavior
     * (e.g., reject invalid), that would be a functional change.
     *
     * @param mixed $value
     */
    public static function sanitize_percent_string($value): string
    {
        $value = preg_replace('/[^0-9.]/', '', (string) $value);
        return (string) (float) $value;
    }

    /**
     * Positive integer sanitizer.
     *
     * @param mixed $value
     */
    public static function sanitize_positive_int_string($value): string
    {
        $value = preg_replace('/[^0-9]/', '', (string) $value);
        $num = (int) $value;
        if ($num < 0) {
            $num = 0;
        }
        return (string) $num;
    }

    /**
     * Non-negative decimal sanitizer.
     *
     * @param mixed $value
     */
    public static function sanitize_non_negative_decimal_string($value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', (string) $value);
        $num = is_numeric($value) ? (float) $value : 0.0;
        if ($num < 0.0 || !is_finite($num)) {
            $num = 0.0;
        }
        return (string) $num;
    }

    /**
     * Sanitize MAP brand policy rows.
     *
     * Input shape:
     * - array<int,array{brand:string,policy:string}>
     *
     * Output shape:
     * - array<int,array{brand:string,policy:string}>
     *
     * @param mixed $value
     * @return array<int,array{brand:string,policy:string}>
     */
    public static function sanitize_map_brand_policies($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized_rows = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $brand = sanitize_text_field((string) ($row['brand'] ?? ''));
            $brand = trim((string) preg_replace('/\s+/', ' ', $brand));
            if ($brand === '') {
                continue;
            }

            $key = Options::normalize_brand_policy_key($brand);
            if ($key === '') {
                continue;
            }

            $policy = strtolower(trim((string) ($row['policy'] ?? '')));
            if (
                $policy !== Options::MAP_POLICY_EMAIL_FOR_QUOTE
                && $policy !== Options::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART
            ) {
                $policy = Options::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
            }

            $normalized_rows[$key] = [
                'brand'  => $brand,
                'policy' => $policy,
            ];
        }

        return array_values($normalized_rows);
    }

    /**
     * Conservative string sanitizer for secrets:
     * - Does NOT use sanitize_text_field() because that can clobber characters
     *   commonly used in API keys or passwords.
     * - Removes null bytes and trims whitespace.
     *
     * @param mixed $value
     */
    public static function sanitize_loose_string($value): string
    {
        $v = (string) $value;
        $v = str_replace("\0", '', $v);
        return trim($v);
    }

    /**
     * Standard WP text sanitizer (safe for typical admin text inputs).
     *
     * @param mixed $value
     */
    public static function sanitize_text($value): string
    {
        return sanitize_text_field((string) $value);
    }

    /**
     * Checkbox sanitizer:
     * - Forces stored value to "1" or "0" (strings).
     *
     * @param mixed $value
     */
    public static function sanitize_checkbox($value): string
    {
        return ((string) $value === '1') ? '1' : '0';
    }

    /**
     * Sanitize distributor priority list string into canonical CSV.
     *
     * @param mixed $value
     */
    public static function sanitize_distributor_priority_list($value): string
    {
        return Options::normalize_distributor_priority_csv((string) $value);
    }

    /**
     * Sanitize one or more notification recipients into comma-separated email addresses.
     *
     * @param mixed $value
     */
    public static function sanitize_email_list($value): string
    {
        $parts = preg_split('/[,;\s]+/', (string) $value);
        $emails = [];

        foreach ((array) $parts as $part) {
            $email = sanitize_email((string) $part);
            if ($email !== '' && is_email($email)) {
                $emails[] = $email;
            }
        }

        return implode(',', array_values(array_unique($emails)));
    }

    /**
     * Select a sanitizer based on schema "type".
     *
     * IMPORTANT: This maps UI field types to sanitizers.
     * WP's "type" in register_setting is still 'string' for storage purposes.
     */
    private static function sanitize_callback_for_type(string $type): callable
    {
        $type = strtolower(trim($type));

        switch ($type) {
            case 'number':
                // Schema "number" maps to percent/float normalization.
                return [__CLASS__, 'sanitize_percent_string'];

            case 'password':
                // Preserve most characters for secrets.
                return [__CLASS__, 'sanitize_loose_string'];

            case 'checkbox':
                return [__CLASS__, 'sanitize_checkbox'];

            case 'text':
            case 'textarea':
            case 'select':
            default:
                return [__CLASS__, 'sanitize_text'];
        }
    }
}
