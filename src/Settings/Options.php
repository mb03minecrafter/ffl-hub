<?php

namespace FFLHub\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorRegistry;

/**
 * Central registry + helpers for all FFL Hub options.
 *
 * Goals:
 * - Keep option names consistent and discoverable.
 * - Avoid scattered get_option()/update_option() usage across the codebase.
 * - Provide safe defaults and light normalization at boundaries.
 *
 * Storage notes:
 * - WordPress stores options as strings/arrays. We store numeric settings as
 *   strings and cast to float on reads.
 * - Distributor state is stored as an array keyed by distributor id.
 */
final class Options
{
    /* -------------------------------------------------------------------------
     * Option names
     * ---------------------------------------------------------------------- */

    /**
     * Distributor enable/disable state.
     *
     * Shape:
     *   [
     *     'rsr' => ['enabled' => true,  'created_at' => 1700000000],
     *     'lip' => ['enabled' => false, 'created_at' => 1700000123],
     *   ]
     */
    public const OPTION_DISTRIBUTOR_STATE = 'fflhub_distributor_state';

    /**
     * Global percent values (stored as string, cast to float on read).
     */
    public const OPTION_PAYMENT_PROCESSOR_FEE_PERCENT = 'fflhub_payment_processor_fee_percent';
    public const OPTION_GLOBAL_MARKUP                 = 'fflhub_global_markup';
    public const OPTION_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT = 'fflhub_free_shipping_max_profit_spend_percent';
    public const OPTION_TEST_ORDER_DEBUG_ENABLED      = 'fflhub_test_order_debug_enabled';
    public const OPTION_HOLOSUN_IMAGE_NOTICE_ENABLED  = 'fflhub_holosun_image_notice_enabled';
    public const OPTION_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED = 'fflhub_holosun_show_price_override_enabled';
    public const OPTION_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED = 'fflhub_pretty_random_email_quotes_enabled';
    public const OPTION_PUBLIC_BRAND_NAME             = 'fflhub_public_brand_name';
    public const OPTION_QUOTE_EMAIL_REP_NAMES         = 'fflhub_quote_email_rep_names';
    public const OPTION_QUOTE_EMAIL_TEAM_SIGNATURE    = 'fflhub_quote_email_team_signature';
    public const OPTION_BATCH_ORDER_NOTIFICATION_EMAIL = 'fflhub_batch_order_notification_email';
    public const OPTION_DISTRIBUTOR_PRIORITY_LIST     = 'fflhub_distributor_priority_list';
    public const OPTION_MAP_BRAND_POLICIES            = 'fflhub_map_brand_policies';
    public const OPTION_DEALER_SHIP_TO_NAME           = 'fflhub_dealer_ship_to_name';
    public const OPTION_DEALER_SHIP_TO_COMPANY        = 'fflhub_dealer_ship_to_company';
    public const OPTION_DEALER_SHIP_TO_ADDRESS1       = 'fflhub_dealer_ship_to_address1';
    public const OPTION_DEALER_SHIP_TO_ADDRESS2       = 'fflhub_dealer_ship_to_address2';
    public const OPTION_DEALER_SHIP_TO_CITY           = 'fflhub_dealer_ship_to_city';
    public const OPTION_DEALER_SHIP_TO_STATE          = 'fflhub_dealer_ship_to_state';
    public const OPTION_DEALER_SHIP_TO_ZIP            = 'fflhub_dealer_ship_to_zip';
    public const OPTION_DEALER_SHIP_TO_PHONE          = 'fflhub_dealer_ship_to_phone';
    public const OPTION_DEALER_SHIP_TO_EMAIL          = 'fflhub_dealer_ship_to_email';
    public const OPTION_RELAY_SHIP_TO_NAME            = 'fflhub_relay_ship_to_name';
    public const OPTION_RELAY_SHIP_TO_COMPANY         = 'fflhub_relay_ship_to_company';
    public const OPTION_RELAY_SHIP_TO_ADDRESS1        = 'fflhub_relay_ship_to_address1';
    public const OPTION_RELAY_SHIP_TO_ADDRESS2        = 'fflhub_relay_ship_to_address2';
    public const OPTION_RELAY_SHIP_TO_CITY            = 'fflhub_relay_ship_to_city';
    public const OPTION_RELAY_SHIP_TO_STATE           = 'fflhub_relay_ship_to_state';
    public const OPTION_RELAY_SHIP_TO_ZIP             = 'fflhub_relay_ship_to_zip';
    public const OPTION_RELAY_SHIP_TO_PHONE           = 'fflhub_relay_ship_to_phone';
    public const OPTION_RELAY_SHIP_TO_EMAIL           = 'fflhub_relay_ship_to_email';
    public const OPTION_USPS_ESTIMATE_ENABLED         = 'fflhub_usps_estimate_enabled';
    public const OPTION_USPS_USE_TEST_ENV             = 'fflhub_usps_use_test_env';
    public const OPTION_USPS_BASE_URL                 = 'fflhub_usps_base_url';
    public const OPTION_USPS_CLIENT_ID                = 'fflhub_usps_client_id';
    public const OPTION_USPS_CLIENT_SECRET            = 'fflhub_usps_client_secret';
    public const OPTION_USPS_ORIGIN_ZIP               = 'fflhub_usps_origin_zip';
    public const OPTION_USPS_ACCOUNT_TYPE             = 'fflhub_usps_account_type';
    public const OPTION_USPS_ACCOUNT_NUMBER           = 'fflhub_usps_account_number';
    public const OPTION_USPS_MAIL_CLASS               = 'fflhub_usps_mail_class';
    public const OPTION_USPS_PROCESSING_CATEGORY      = 'fflhub_usps_processing_category';
    public const OPTION_USPS_DEST_ENTRY_FACILITY_TYPE = 'fflhub_usps_destination_entry_facility_type';
    public const OPTION_USPS_RATE_INDICATOR           = 'fflhub_usps_rate_indicator';
    public const OPTION_USPS_PRICE_TYPE               = 'fflhub_usps_price_type';
    public const OPTION_USPS_TIMEOUT_SEC              = 'fflhub_usps_timeout_sec';
    public const OPTION_USPS_TARE_WEIGHT_OZ           = 'fflhub_usps_tare_weight_oz';

    /* -------------------------------------------------------------------------
     * Defaults
     * ---------------------------------------------------------------------- */

    /**
     * Default values are numeric, but stored in wp_options as strings.
     */
    private const DEFAULT_PAYMENT_PROCESSOR_FEE_PERCENT = 2.9;  // %
    private const DEFAULT_GLOBAL_MARKUP                 = 10.0; // %
    private const DEFAULT_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT = 50.0; // %
    private const DEFAULT_TEST_ORDER_DEBUG_ENABLED      = true;
    private const DEFAULT_HOLOSUN_IMAGE_NOTICE_ENABLED  = false;
    private const DEFAULT_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED = false;
    private const DEFAULT_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED = true;
    private const DEFAULT_PUBLIC_BRAND_NAME             = '';
    private const DEFAULT_QUOTE_EMAIL_REP_NAMES         = '';
    private const DEFAULT_QUOTE_EMAIL_TEAM_SIGNATURE    = '';
    private const DEFAULT_BATCH_ORDER_NOTIFICATION_EMAIL = '';
    private const DEFAULT_MAP_BRAND_POLICIES            = [];
    private const DEFAULT_DEALER_SHIP_TO_NAME           = '';
    private const DEFAULT_DEALER_SHIP_TO_COMPANY        = '';
    private const DEFAULT_DEALER_SHIP_TO_ADDRESS1       = '';
    private const DEFAULT_DEALER_SHIP_TO_ADDRESS2       = '';
    private const DEFAULT_DEALER_SHIP_TO_CITY           = '';
    private const DEFAULT_DEALER_SHIP_TO_STATE          = '';
    private const DEFAULT_DEALER_SHIP_TO_ZIP            = '';
    private const DEFAULT_DEALER_SHIP_TO_PHONE          = '';
    private const DEFAULT_DEALER_SHIP_TO_EMAIL          = '';
    private const DEFAULT_RELAY_SHIP_TO_NAME            = '';
    private const DEFAULT_RELAY_SHIP_TO_COMPANY         = '';
    private const DEFAULT_RELAY_SHIP_TO_ADDRESS1        = '';
    private const DEFAULT_RELAY_SHIP_TO_ADDRESS2        = '';
    private const DEFAULT_RELAY_SHIP_TO_CITY            = '';
    private const DEFAULT_RELAY_SHIP_TO_STATE           = '';
    private const DEFAULT_RELAY_SHIP_TO_ZIP             = '';
    private const DEFAULT_RELAY_SHIP_TO_PHONE           = '';
    private const DEFAULT_RELAY_SHIP_TO_EMAIL           = '';
    private const DEFAULT_USPS_ESTIMATE_ENABLED         = false;
    private const DEFAULT_USPS_USE_TEST_ENV             = true;
    private const DEFAULT_USPS_BASE_URL                 = '';
    private const DEFAULT_USPS_CLIENT_ID                = '';
    private const DEFAULT_USPS_CLIENT_SECRET            = '';
    private const DEFAULT_USPS_ORIGIN_ZIP               = '';
    private const DEFAULT_USPS_ACCOUNT_TYPE             = 'EPS';
    private const DEFAULT_USPS_ACCOUNT_NUMBER           = '';
    private const DEFAULT_USPS_MAIL_CLASS               = 'USPS_GROUND_ADVANTAGE';
    private const DEFAULT_USPS_PROCESSING_CATEGORY      = 'MACHINABLE';
    private const DEFAULT_USPS_DEST_ENTRY_FACILITY_TYPE = 'NONE';
    private const DEFAULT_USPS_RATE_INDICATOR           = '';
    private const DEFAULT_USPS_PRICE_TYPE               = 'COMMERCIAL';
    private const DEFAULT_USPS_TIMEOUT_SEC              = 8;
    private const DEFAULT_USPS_TARE_WEIGHT_OZ           = 0.0;

    public const MAP_POLICY_ADD_TO_CART_FOR_PRICE   = 'add_to_cart_for_price';
    public const MAP_POLICY_EMAIL_FOR_QUOTE         = 'email_for_quote';
    public const MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART = 'no_email_no_add_to_cart';

    /* -------------------------------------------------------------------------
     * Settings groups (WP Settings API)
     * ---------------------------------------------------------------------- */

    /**
     * Settings group for global (non-distributor) options.
     */
    public static function global_settings_group(): string
    {
        return 'fflhub_global_settings';
    }

    /**
     * Settings group for MAP brand policy rules.
     */
    public static function map_policy_settings_group(): string
    {
        return 'fflhub_map_policy_settings';
    }

    /**
     * Settings group for USPS outbound estimate configuration.
     */
    public static function usps_settings_group(): string
    {
        return 'fflhub_usps_settings';
    }

    /**
     * Settings group for a specific distributor's schema-driven options.
     */
    public static function distributor_settings_group(string $distributor_id): string
    {
        return 'fflhub_' . $distributor_id . '_settings_group';
    }

    /* -------------------------------------------------------------------------
     * Option naming helpers
     * ---------------------------------------------------------------------- */

    /**
     * Build the wp_options key for a distributor-scoped option.
     *
     * Example:
     * - distributor_option_name('rsr', 'api_key') => 'fflhub_rsr_api_key'
     */
    public static function distributor_option_name(string $distributor_id, string $key): string
    {
        return 'fflhub_' . $distributor_id . '_' . $key;
    }

    /**
     * Canonical option name for distributor credit limit.
     */
    public static function distributor_credit_limit_option_name(string $distributor_id): string
    {
        return self::distributor_option_name($distributor_id, 'credit_limit');
    }

    /**
     * Canonical option name for per-distributor non-drop-ship blocking.
     *
     * When enabled, this distributor is treated as drop-ship only in offer
     * selection flows (non-dropship rows are ignored as viable offers).
     */
    public static function distributor_non_dropship_blocked_option_name(string $distributor_id): string
    {
        return self::distributor_option_name($distributor_id, 'non_dropship_blocked');
    }

    /**
     * Canonical option name for per-distributor SIG SAUER dropship approval.
     *
     * When enabled, SIG SAUER rows from this distributor are forced to
     * drop-ship eligible during product and inventory imports.
     */
    public static function distributor_sig_approved_option_name(string $distributor_id): string
    {
        return self::distributor_option_name($distributor_id, 'sig_approved');
    }

    /* -------------------------------------------------------------------------
     * Defaults (exposed for registrars / installers)
     * ---------------------------------------------------------------------- */

    public static function default_payment_processor_fee_percent(): float
    {
        return self::DEFAULT_PAYMENT_PROCESSOR_FEE_PERCENT;
    }

    public static function default_global_markup(): float
    {
        return self::DEFAULT_GLOBAL_MARKUP;
    }

    public static function default_free_shipping_max_profit_spend_percent(): float
    {
        return self::DEFAULT_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT;
    }

    public static function default_test_order_debug_enabled(): bool
    {
        return self::DEFAULT_TEST_ORDER_DEBUG_ENABLED;
    }

    public static function default_holosun_image_notice_enabled(): bool
    {
        return self::DEFAULT_HOLOSUN_IMAGE_NOTICE_ENABLED;
    }

    public static function default_holosun_show_price_override_enabled(): bool
    {
        return self::DEFAULT_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED;
    }

    public static function default_pretty_random_email_quotes_enabled(): bool
    {
        return self::DEFAULT_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED;
    }

    public static function default_public_brand_name(): string
    {
        return self::site_name_fallback();
    }

    public static function default_quote_email_rep_names(): string
    {
        return 'Sales Team';
    }

    public static function default_quote_email_team_signature(): string
    {
        return self::default_sales_team_signature();
    }

    public static function default_batch_order_notification_email(): string
    {
        $email = function_exists('get_option') ? (string) get_option('admin_email', '') : '';
        $email = sanitize_email($email);

        return is_email($email) ? $email : self::DEFAULT_BATCH_ORDER_NOTIFICATION_EMAIL;
    }

    public static function default_usps_estimate_enabled(): bool
    {
        return self::DEFAULT_USPS_ESTIMATE_ENABLED;
    }

    public static function default_usps_use_test_env(): bool
    {
        return self::DEFAULT_USPS_USE_TEST_ENV;
    }

    public static function default_usps_base_url(): string
    {
        return self::DEFAULT_USPS_BASE_URL;
    }

    public static function default_usps_client_id(): string
    {
        return self::DEFAULT_USPS_CLIENT_ID;
    }

    public static function default_usps_client_secret(): string
    {
        return self::DEFAULT_USPS_CLIENT_SECRET;
    }

    public static function default_usps_origin_zip(): string
    {
        return self::DEFAULT_USPS_ORIGIN_ZIP;
    }

    public static function default_usps_account_type(): string
    {
        return self::DEFAULT_USPS_ACCOUNT_TYPE;
    }

    public static function default_usps_account_number(): string
    {
        return self::DEFAULT_USPS_ACCOUNT_NUMBER;
    }

    public static function default_usps_mail_class(): string
    {
        return self::DEFAULT_USPS_MAIL_CLASS;
    }

    public static function default_usps_processing_category(): string
    {
        return self::DEFAULT_USPS_PROCESSING_CATEGORY;
    }

    public static function default_usps_destination_entry_facility_type(): string
    {
        return self::DEFAULT_USPS_DEST_ENTRY_FACILITY_TYPE;
    }

    public static function default_usps_rate_indicator(): string
    {
        return self::DEFAULT_USPS_RATE_INDICATOR;
    }

    public static function default_usps_price_type(): string
    {
        return self::DEFAULT_USPS_PRICE_TYPE;
    }

    public static function default_usps_timeout_sec(): int
    {
        return self::DEFAULT_USPS_TIMEOUT_SEC;
    }

    public static function default_usps_tare_weight_oz(): float
    {
        return self::DEFAULT_USPS_TARE_WEIGHT_OZ;
    }

    /* -------------------------------------------------------------------------
     * Initialization
     * ---------------------------------------------------------------------- */

    /**
     * Initialize all core options with sane defaults.
     *
     * Call from Plugin::activate().
     *
     * Behavior:
     * - Adds options only if they do not exist (does not overwrite).
     * - Ensures distributor state exists and matches current module set.
     */
    public static function init_defaults(): void
    {
        // Numeric settings: initialize only if missing.
        if (get_option(self::OPTION_PAYMENT_PROCESSOR_FEE_PERCENT, null) === null) {
            add_option(
                self::OPTION_PAYMENT_PROCESSOR_FEE_PERCENT,
                (string) self::DEFAULT_PAYMENT_PROCESSOR_FEE_PERCENT
            );
        }

        if (get_option(self::OPTION_GLOBAL_MARKUP, null) === null) {
            add_option(
                self::OPTION_GLOBAL_MARKUP,
                (string) self::DEFAULT_GLOBAL_MARKUP
            );
        }

        if (get_option(self::OPTION_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT, null) === null) {
            add_option(
                self::OPTION_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT,
                (string) self::DEFAULT_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT
            );
        }

        if (get_option(self::OPTION_TEST_ORDER_DEBUG_ENABLED, null) === null) {
            add_option(self::OPTION_TEST_ORDER_DEBUG_ENABLED, self::DEFAULT_TEST_ORDER_DEBUG_ENABLED ? '1' : '0');
        }

        if (get_option(self::OPTION_HOLOSUN_IMAGE_NOTICE_ENABLED, null) === null) {
            add_option(self::OPTION_HOLOSUN_IMAGE_NOTICE_ENABLED, self::DEFAULT_HOLOSUN_IMAGE_NOTICE_ENABLED ? '1' : '0');
        }

        if (get_option(self::OPTION_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED, null) === null) {
            add_option(
                self::OPTION_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED,
                self::DEFAULT_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED ? '1' : '0'
            );
        }

        if (get_option(self::OPTION_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED, null) === null) {
            add_option(
                self::OPTION_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED,
                self::DEFAULT_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED ? '1' : '0'
            );
        }

        if (get_option(self::OPTION_PUBLIC_BRAND_NAME, null) === null) {
            add_option(self::OPTION_PUBLIC_BRAND_NAME, self::DEFAULT_PUBLIC_BRAND_NAME);
        }

        if (get_option(self::OPTION_QUOTE_EMAIL_REP_NAMES, null) === null) {
            add_option(self::OPTION_QUOTE_EMAIL_REP_NAMES, self::DEFAULT_QUOTE_EMAIL_REP_NAMES);
        }

        if (get_option(self::OPTION_QUOTE_EMAIL_TEAM_SIGNATURE, null) === null) {
            add_option(self::OPTION_QUOTE_EMAIL_TEAM_SIGNATURE, self::DEFAULT_QUOTE_EMAIL_TEAM_SIGNATURE);
        }

        if (get_option(self::OPTION_BATCH_ORDER_NOTIFICATION_EMAIL, null) === null) {
            add_option(self::OPTION_BATCH_ORDER_NOTIFICATION_EMAIL, self::default_batch_order_notification_email());
        }

        if (get_option(self::OPTION_DISTRIBUTOR_PRIORITY_LIST, null) === null) {
            add_option(self::OPTION_DISTRIBUTOR_PRIORITY_LIST, self::default_distributor_priority_csv());
        }

        if (get_option(self::OPTION_MAP_BRAND_POLICIES, null) === null) {
            add_option(self::OPTION_MAP_BRAND_POLICIES, self::DEFAULT_MAP_BRAND_POLICIES);
        }

        foreach (self::default_dealer_ship_to_options() as $option_name => $default_value) {
            if (get_option($option_name, null) === null) {
                add_option($option_name, $default_value);
            }
        }

        foreach (self::default_relay_ship_to_options() as $option_name => $default_value) {
            if (get_option($option_name, null) === null) {
                add_option($option_name, $default_value);
            }
        }

        if (get_option(self::OPTION_USPS_ESTIMATE_ENABLED, null) === null) {
            add_option(self::OPTION_USPS_ESTIMATE_ENABLED, self::DEFAULT_USPS_ESTIMATE_ENABLED ? '1' : '0');
        }
        if (get_option(self::OPTION_USPS_USE_TEST_ENV, null) === null) {
            add_option(self::OPTION_USPS_USE_TEST_ENV, self::DEFAULT_USPS_USE_TEST_ENV ? '1' : '0');
        }
        if (get_option(self::OPTION_USPS_BASE_URL, null) === null) {
            add_option(self::OPTION_USPS_BASE_URL, self::DEFAULT_USPS_BASE_URL);
        }
        if (get_option(self::OPTION_USPS_CLIENT_ID, null) === null) {
            add_option(self::OPTION_USPS_CLIENT_ID, self::DEFAULT_USPS_CLIENT_ID);
        }
        if (get_option(self::OPTION_USPS_CLIENT_SECRET, null) === null) {
            add_option(self::OPTION_USPS_CLIENT_SECRET, self::DEFAULT_USPS_CLIENT_SECRET);
        }
        if (get_option(self::OPTION_USPS_ORIGIN_ZIP, null) === null) {
            add_option(self::OPTION_USPS_ORIGIN_ZIP, self::DEFAULT_USPS_ORIGIN_ZIP);
        }
        if (get_option(self::OPTION_USPS_ACCOUNT_TYPE, null) === null) {
            add_option(self::OPTION_USPS_ACCOUNT_TYPE, self::DEFAULT_USPS_ACCOUNT_TYPE);
        }
        if (get_option(self::OPTION_USPS_ACCOUNT_NUMBER, null) === null) {
            add_option(self::OPTION_USPS_ACCOUNT_NUMBER, self::DEFAULT_USPS_ACCOUNT_NUMBER);
        }
        if (get_option(self::OPTION_USPS_MAIL_CLASS, null) === null) {
            add_option(self::OPTION_USPS_MAIL_CLASS, self::DEFAULT_USPS_MAIL_CLASS);
        }
        if (get_option(self::OPTION_USPS_PROCESSING_CATEGORY, null) === null) {
            add_option(self::OPTION_USPS_PROCESSING_CATEGORY, self::DEFAULT_USPS_PROCESSING_CATEGORY);
        }
        if (get_option(self::OPTION_USPS_DEST_ENTRY_FACILITY_TYPE, null) === null) {
            add_option(self::OPTION_USPS_DEST_ENTRY_FACILITY_TYPE, self::DEFAULT_USPS_DEST_ENTRY_FACILITY_TYPE);
        }
        if (get_option(self::OPTION_USPS_RATE_INDICATOR, null) === null) {
            add_option(self::OPTION_USPS_RATE_INDICATOR, self::DEFAULT_USPS_RATE_INDICATOR);
        }
        if (get_option(self::OPTION_USPS_PRICE_TYPE, null) === null) {
            add_option(self::OPTION_USPS_PRICE_TYPE, self::DEFAULT_USPS_PRICE_TYPE);
        }
        if (get_option(self::OPTION_USPS_TIMEOUT_SEC, null) === null) {
            add_option(self::OPTION_USPS_TIMEOUT_SEC, (string) self::DEFAULT_USPS_TIMEOUT_SEC);
        }
        if (get_option(self::OPTION_USPS_TARE_WEIGHT_OZ, null) === null) {
            add_option(self::OPTION_USPS_TARE_WEIGHT_OZ, (string) self::DEFAULT_USPS_TARE_WEIGHT_OZ);
        }

        self::sync_distributor_state();
    }

    /**
     * Ensure distributor state exists for all registered modules.
     *
     * Keeps state consistent when:
     * - new distributors are added
     * - old distributors are removed
     *
     * Also initializes missing entries with:
     * - enabled = false
     * - created_at = current unix time
     */
    public static function sync_distributor_state(): void
    {
        $state = get_option(self::OPTION_DISTRIBUTOR_STATE, []);
        if (!is_array($state)) {
            $state = [];
        }

        $known_ids = DistributorRegistry::get_distributor_ids();

        // Ensure every known distributor has a state entry.
        foreach ($known_ids as $id) {
            if (!isset($state[$id]) || !is_array($state[$id])) {
                $state[$id] = [
                    'enabled'    => false,
                    'created_at' => time(),
                ];
            }
        }

        // Remove stale distributors that are no longer registered.
        foreach ($state as $id => $_) {
            if (!in_array($id, $known_ids, true)) {
                unset($state[$id]);
            }
        }

        update_option(self::OPTION_DISTRIBUTOR_STATE, $state);
    }

    /* -------------------------------------------------------------------------
     * Distributor enable / disable state
     * ---------------------------------------------------------------------- */

    /**
     * Read distributor state map.
     *
     * @return array<string, array{enabled:bool,created_at?:int}>
     */
    public static function get_distributor_state(): array
    {
        $state = get_option(self::OPTION_DISTRIBUTOR_STATE, []);
        return is_array($state) ? $state : [];
    }

    /**
     * Persist distributor state map.
     *
     * @param array<string, array{enabled:bool,created_at?:int}> $state
     */
    public static function set_distributor_state(array $state): void
    {
        update_option(self::OPTION_DISTRIBUTOR_STATE, $state);
    }

    /**
     * Convenience check: is a distributor enabled?
     */
    public static function is_distributor_enabled(string $id): bool
    {
        $state = self::get_distributor_state();
        return !empty($state[$id]['enabled']);
    }

    /**
     * Enable/disable a distributor.
     *
     * If the distributor has no existing entry, this initializes created_at.
     */
    public static function set_distributor_enabled(string $id, bool $enabled): void
    {
        $state = self::get_distributor_state();

        if (!isset($state[$id]) || !is_array($state[$id])) {
            $state[$id] = [
                'created_at' => time(),
            ];
        }

        $state[$id]['enabled'] = $enabled;

        self::set_distributor_state($state);
    }

    /* -------------------------------------------------------------------------
     * Global options
     * ---------------------------------------------------------------------- */

    /**
     * Payment processor fee percent (e.g., 2.9).
     */
    public static function get_payment_processor_fee_percent(): float
    {
        $value = get_option(
            self::OPTION_PAYMENT_PROCESSOR_FEE_PERCENT,
            (string) self::DEFAULT_PAYMENT_PROCESSOR_FEE_PERCENT
        );

        return (float) $value;
    }

    public static function set_payment_processor_fee_percent(float $percent): void
    {
        update_option(self::OPTION_PAYMENT_PROCESSOR_FEE_PERCENT, (string) $percent);
    }

    /**
     * Global markup percent applied to pricing (e.g., 10.0).
     */
    public static function get_global_markup(): float
    {
        $value = get_option(
            self::OPTION_GLOBAL_MARKUP,
            (string) self::DEFAULT_GLOBAL_MARKUP
        );

        return (float) $value;
    }

    public static function set_global_markup(float $percent): void
    {
        update_option(self::OPTION_GLOBAL_MARKUP, (string) $percent);
    }

    /**
     * Percent of net cart profit that customer-facing free shipping may consume.
     *
     * 50 preserves the original "shipping is less than half of profit" behavior.
     * 100 allows free shipping as long as at least one cent of net profit remains.
     */
    public static function get_free_shipping_max_profit_spend_percent(): float
    {
        $value = get_option(
            self::OPTION_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT,
            (string) self::DEFAULT_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT
        );

        $percent = self::to_non_negative_float($value);
        if ($percent > 100.0) {
            return 100.0;
        }

        return $percent;
    }

    public static function set_free_shipping_max_profit_spend_percent(float $percent): void
    {
        $percent = max(0.0, min(100.0, $percent));
        update_option(self::OPTION_FREE_SHIPPING_MAX_PROFIT_SPEND_PERCENT, (string) $percent);
    }

    /**
     * Default distributor priority CSV in registry order.
     */
    public static function default_distributor_priority_csv(): string
    {
        $ids = DistributorRegistry::get_distributor_ids();
        return implode(',', array_values(array_filter(array_map('strval', $ids))));
    }

    /**
     * Normalize a raw distributor-priority string into a canonical CSV of ids.
     *
     * Rules:
     * - Accepts comma/semicolon/newline/pipe separated tokens.
     * - Tokens may be distributor id, name, or label (best effort).
     * - Unknown tokens are ignored.
     * - Missing known distributors are appended in registry order.
     */
    public static function normalize_distributor_priority_csv(string $raw): string
    {
        $known_ids = DistributorRegistry::get_distributor_ids();
        $known_ids = array_values(array_filter(array_map('strval', $known_ids)));
        if (empty($known_ids)) {
            return '';
        }

        $alias_map = self::distributor_alias_to_id_map();
        $tokens = preg_split('/[\s,;|]+/', strtolower((string) $raw));
        if (!is_array($tokens)) {
            $tokens = [];
        }

        $ordered = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            $canonical = '';
            if (isset($alias_map[$token])) {
                $canonical = (string) $alias_map[$token];
            } else {
                $slug = (string) preg_replace('/[^a-z0-9]+/', '', $token);
                if ($slug !== '' && isset($alias_map[$slug])) {
                    $canonical = (string) $alias_map[$slug];
                }
            }

            if ($canonical === '') {
                continue;
            }

            $ordered[$canonical] = $canonical;
        }

        // Always include all known distributors so ranking is complete.
        foreach ($known_ids as $id) {
            if (!isset($ordered[$id])) {
                $ordered[$id] = $id;
            }
        }

        return implode(',', array_values($ordered));
    }

    /**
     * @return array<int,string>
     */
    public static function get_distributor_priority_list(): array
    {
        $stored = (string) get_option(
            self::OPTION_DISTRIBUTOR_PRIORITY_LIST,
            self::default_distributor_priority_csv()
        );

        $normalized = self::normalize_distributor_priority_csv($stored);
        if ($normalized === '') {
            $normalized = self::default_distributor_priority_csv();
        }

        $parts = array_filter(array_map('trim', explode(',', $normalized)));
        return array_values(array_map('strval', $parts));
    }

    /**
     * Canonical CSV form used by the settings UI.
     */
    public static function get_distributor_priority_csv(): string
    {
        return implode(',', self::get_distributor_priority_list());
    }

    /**
     * Rank for tie-break decisions (lower number = higher priority).
     */
    public static function get_distributor_priority_rank(string $dist_id): int
    {
        $dist_id = strtolower(trim($dist_id));
        if ($dist_id === '') {
            return PHP_INT_MAX;
        }

        $list = self::get_distributor_priority_list();
        $idx = array_search($dist_id, $list, true);
        if ($idx === false) {
            return PHP_INT_MAX;
        }

        return (int) $idx;
    }

    /**
     * Build a token map for id/name/label -> canonical id.
     *
     * @return array<string,string>
     */
    private static function distributor_alias_to_id_map(): array
    {
        $map = [];

        foreach (DistributorRegistry::get_modules() as $module) {
            if (!is_object($module) || !method_exists($module, 'id')) {
                continue;
            }

            $id = strtolower(trim((string) $module->id()));
            if ($id === '') {
                continue;
            }

            $map[$id] = $id;
            $map[(string) preg_replace('/[^a-z0-9]+/', '', $id)] = $id;

            if (method_exists($module, 'name')) {
                $name = strtolower(trim((string) $module->name()));
                if ($name !== '') {
                    $map[$name] = $id;
                    $map[(string) preg_replace('/[^a-z0-9]+/', '', $name)] = $id;
                }
            }

            if (method_exists($module, 'label')) {
                $label = strtolower(trim((string) $module->label()));
                if ($label !== '') {
                    $map[$label] = $id;
                    $map[(string) preg_replace('/[^a-z0-9]+/', '', $label)] = $id;
                }
            }
        }

        return $map;
    }

    /**
     * MAP policy rows keyed by brand names entered by admins.
     *
     * @return array<int,array{brand:string,policy:string}>
     */
    public static function get_map_brand_policies(): array
    {
        return self::normalize_map_brand_policies(
            get_option(self::OPTION_MAP_BRAND_POLICIES, self::DEFAULT_MAP_BRAND_POLICIES)
        );
    }

    /**
     * @param array<int,array{brand?:string,policy?:string}> $policies
     */
    public static function set_map_brand_policies(array $policies): void
    {
        update_option(self::OPTION_MAP_BRAND_POLICIES, self::normalize_map_brand_policies($policies));
    }

    /**
     * Resolve configured MAP policy for a brand name.
     */
    public static function get_map_policy_for_brand(string $brand): string
    {
        $lookup = self::get_map_brand_policy_lookup();
        $candidate_keys = self::map_policy_lookup_keys_for_brand($brand);

        foreach ($candidate_keys as $key) {
            if (isset($lookup[$key])) {
                return (string) $lookup[$key];
            }
        }

        return self::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    /**
     * Fast lookup map: normalized brand key => policy.
     *
     * @return array<string,string>
     */
    public static function get_map_brand_policy_lookup(): array
    {
        $lookup = [];

        foreach (self::get_map_brand_policies() as $row) {
            $brand = (string) ($row['brand'] ?? '');
            $policy = (string) ($row['policy'] ?? self::MAP_POLICY_ADD_TO_CART_FOR_PRICE);
            $key = self::normalize_brand_policy_key($brand);

            if ($key === '') {
                continue;
            }

            $lookup[$key] = self::normalize_map_policy($policy);
        }

        return $lookup;
    }

    /**
     * Normalize a brand name into an internal comparison key.
     */
    public static function normalize_brand_policy_key(string $brand): string
    {
        $brand = html_entity_decode($brand, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $brand = wp_strip_all_tags($brand);
        $key = strtolower(trim($brand));
        $key = (string) preg_replace('/\s+/', ' ', $key);
        return (string) preg_replace('/[^a-z0-9]+/', '', $key);
    }

    /**
     * Build normalized lookup keys for tolerant brand matching.
     *
     * This handles common variants like:
     * - "Smith & Wesson"
     * - "Smith and Wesson"
     * - "Smith Wesson"
     *
     * @return array<int,string>
     */
    private static function map_policy_lookup_keys_for_brand(string $brand): array
    {
        $brand = trim(wp_strip_all_tags($brand));
        if ($brand === '') {
            return [];
        }

        $variants = [
            $brand,
            str_replace('&', ' and ', $brand),
            str_replace('&', ' ', $brand),
            (string) preg_replace('/\band\b/i', ' ', str_replace('&', ' and ', $brand)),
        ];

        $keys = [];
        foreach ($variants as $variant) {
            $normalized = self::normalize_brand_policy_key((string) $variant);
            if ($normalized !== '') {
                $keys[$normalized] = $normalized;
            }
        }

        return array_values($keys);
    }

    /**
     * @param mixed $raw
     * @return array<int,array{brand:string,policy:string}>
     */
    private static function normalize_map_brand_policies($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $normalized_rows = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $brand = sanitize_text_field((string) ($row['brand'] ?? ''));
            $brand = trim((string) preg_replace('/\s+/', ' ', $brand));
            if ($brand === '') {
                continue;
            }

            $key = self::normalize_brand_policy_key($brand);
            if ($key === '') {
                continue;
            }

            $normalized_rows[$key] = [
                'brand'  => $brand,
                'policy' => self::normalize_map_policy((string) ($row['policy'] ?? '')),
            ];
        }

        return array_values($normalized_rows);
    }

    private static function normalize_map_policy(string $policy): string
    {
        $policy = strtolower(trim($policy));

        if ($policy === self::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return self::MAP_POLICY_EMAIL_FOR_QUOTE;
        }

        if ($policy === self::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART) {
            return self::MAP_POLICY_NO_EMAIL_NO_ADD_TO_CART;
        }

        return self::MAP_POLICY_ADD_TO_CART_FOR_PRICE;
    }

    public static function get_test_order_debug_enabled(): bool
    {
        return ((string) get_option(
            self::OPTION_TEST_ORDER_DEBUG_ENABLED,
            self::DEFAULT_TEST_ORDER_DEBUG_ENABLED ? '1' : '0'
        )) === '1';
    }

    public static function get_holosun_image_notice_enabled(): bool
    {
        return ((string) get_option(
            self::OPTION_HOLOSUN_IMAGE_NOTICE_ENABLED,
            self::DEFAULT_HOLOSUN_IMAGE_NOTICE_ENABLED ? '1' : '0'
        )) === '1';
    }

    public static function get_holosun_show_price_override_enabled(): bool
    {
        return ((string) get_option(
            self::OPTION_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED,
            self::DEFAULT_HOLOSUN_SHOW_PRICE_OVERRIDE_ENABLED ? '1' : '0'
        )) === '1';
    }

    public static function get_pretty_random_email_quotes_enabled(): bool
    {
        return ((string) get_option(
            self::OPTION_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED,
            self::DEFAULT_PRETTY_RANDOM_EMAIL_QUOTES_ENABLED ? '1' : '0'
        )) === '1';
    }

    public static function get_public_brand_name(): string
    {
        $name = trim((string) get_option(self::OPTION_PUBLIC_BRAND_NAME, self::DEFAULT_PUBLIC_BRAND_NAME));
        if ($name !== '') {
            return $name;
        }

        return self::site_name_fallback();
    }

    /**
     * @return string[]
     */
    public static function get_quote_email_rep_names(): array
    {
        return self::normalize_quote_email_rep_names(
            get_option(self::OPTION_QUOTE_EMAIL_REP_NAMES, self::DEFAULT_QUOTE_EMAIL_REP_NAMES)
        );
    }

    public static function get_quote_email_rep_names_text(): string
    {
        return implode("\n", self::get_quote_email_rep_names());
    }

    public static function get_quote_email_team_signature(): string
    {
        $signature = trim((string) get_option(
            self::OPTION_QUOTE_EMAIL_TEAM_SIGNATURE,
            self::DEFAULT_QUOTE_EMAIL_TEAM_SIGNATURE
        ));

        return $signature !== '' ? $signature : self::default_sales_team_signature();
    }

    public static function get_batch_order_notification_email(): string
    {
        $email = (string) get_option(
            self::OPTION_BATCH_ORDER_NOTIFICATION_EMAIL,
            self::default_batch_order_notification_email()
        );

        return trim($email);
    }

    /**
     * @return array<string,string>
     */
    public static function get_dealer_ship_to_address(): array
    {
        return [
            'name'     => (string) get_option(self::OPTION_DEALER_SHIP_TO_NAME, self::DEFAULT_DEALER_SHIP_TO_NAME),
            'company'  => (string) get_option(self::OPTION_DEALER_SHIP_TO_COMPANY, self::DEFAULT_DEALER_SHIP_TO_COMPANY),
            'address1' => (string) get_option(self::OPTION_DEALER_SHIP_TO_ADDRESS1, self::DEFAULT_DEALER_SHIP_TO_ADDRESS1),
            'address2' => (string) get_option(self::OPTION_DEALER_SHIP_TO_ADDRESS2, self::DEFAULT_DEALER_SHIP_TO_ADDRESS2),
            'city'     => (string) get_option(self::OPTION_DEALER_SHIP_TO_CITY, self::DEFAULT_DEALER_SHIP_TO_CITY),
            'state'    => (string) get_option(self::OPTION_DEALER_SHIP_TO_STATE, self::DEFAULT_DEALER_SHIP_TO_STATE),
            'zip'      => (string) get_option(self::OPTION_DEALER_SHIP_TO_ZIP, self::DEFAULT_DEALER_SHIP_TO_ZIP),
            'phone'    => (string) get_option(self::OPTION_DEALER_SHIP_TO_PHONE, self::DEFAULT_DEALER_SHIP_TO_PHONE),
            'email'    => (string) get_option(self::OPTION_DEALER_SHIP_TO_EMAIL, self::DEFAULT_DEALER_SHIP_TO_EMAIL),
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function get_relay_ship_to_address(): array
    {
        return [
            'name'     => (string) get_option(self::OPTION_RELAY_SHIP_TO_NAME, self::DEFAULT_RELAY_SHIP_TO_NAME),
            'company'  => (string) get_option(self::OPTION_RELAY_SHIP_TO_COMPANY, self::DEFAULT_RELAY_SHIP_TO_COMPANY),
            'address1' => (string) get_option(self::OPTION_RELAY_SHIP_TO_ADDRESS1, self::DEFAULT_RELAY_SHIP_TO_ADDRESS1),
            'address2' => (string) get_option(self::OPTION_RELAY_SHIP_TO_ADDRESS2, self::DEFAULT_RELAY_SHIP_TO_ADDRESS2),
            'city'     => (string) get_option(self::OPTION_RELAY_SHIP_TO_CITY, self::DEFAULT_RELAY_SHIP_TO_CITY),
            'state'    => (string) get_option(self::OPTION_RELAY_SHIP_TO_STATE, self::DEFAULT_RELAY_SHIP_TO_STATE),
            'zip'      => (string) get_option(self::OPTION_RELAY_SHIP_TO_ZIP, self::DEFAULT_RELAY_SHIP_TO_ZIP),
            'phone'    => (string) get_option(self::OPTION_RELAY_SHIP_TO_PHONE, self::DEFAULT_RELAY_SHIP_TO_PHONE),
            'email'    => (string) get_option(self::OPTION_RELAY_SHIP_TO_EMAIL, self::DEFAULT_RELAY_SHIP_TO_EMAIL),
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function default_dealer_ship_to_options(): array
    {
        return [
            self::OPTION_DEALER_SHIP_TO_NAME     => self::DEFAULT_DEALER_SHIP_TO_NAME,
            self::OPTION_DEALER_SHIP_TO_COMPANY  => self::DEFAULT_DEALER_SHIP_TO_COMPANY,
            self::OPTION_DEALER_SHIP_TO_ADDRESS1 => self::DEFAULT_DEALER_SHIP_TO_ADDRESS1,
            self::OPTION_DEALER_SHIP_TO_ADDRESS2 => self::DEFAULT_DEALER_SHIP_TO_ADDRESS2,
            self::OPTION_DEALER_SHIP_TO_CITY     => self::DEFAULT_DEALER_SHIP_TO_CITY,
            self::OPTION_DEALER_SHIP_TO_STATE    => self::DEFAULT_DEALER_SHIP_TO_STATE,
            self::OPTION_DEALER_SHIP_TO_ZIP      => self::DEFAULT_DEALER_SHIP_TO_ZIP,
            self::OPTION_DEALER_SHIP_TO_PHONE    => self::DEFAULT_DEALER_SHIP_TO_PHONE,
            self::OPTION_DEALER_SHIP_TO_EMAIL    => self::DEFAULT_DEALER_SHIP_TO_EMAIL,
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function default_relay_ship_to_options(): array
    {
        return [
            self::OPTION_RELAY_SHIP_TO_NAME     => self::DEFAULT_RELAY_SHIP_TO_NAME,
            self::OPTION_RELAY_SHIP_TO_COMPANY  => self::DEFAULT_RELAY_SHIP_TO_COMPANY,
            self::OPTION_RELAY_SHIP_TO_ADDRESS1 => self::DEFAULT_RELAY_SHIP_TO_ADDRESS1,
            self::OPTION_RELAY_SHIP_TO_ADDRESS2 => self::DEFAULT_RELAY_SHIP_TO_ADDRESS2,
            self::OPTION_RELAY_SHIP_TO_CITY     => self::DEFAULT_RELAY_SHIP_TO_CITY,
            self::OPTION_RELAY_SHIP_TO_STATE    => self::DEFAULT_RELAY_SHIP_TO_STATE,
            self::OPTION_RELAY_SHIP_TO_ZIP      => self::DEFAULT_RELAY_SHIP_TO_ZIP,
            self::OPTION_RELAY_SHIP_TO_PHONE    => self::DEFAULT_RELAY_SHIP_TO_PHONE,
            self::OPTION_RELAY_SHIP_TO_EMAIL    => self::DEFAULT_RELAY_SHIP_TO_EMAIL,
        ];
    }

    public static function get_usps_estimate_enabled(): bool
    {
        return ((string) get_option(
            self::OPTION_USPS_ESTIMATE_ENABLED,
            self::DEFAULT_USPS_ESTIMATE_ENABLED ? '1' : '0'
        )) === '1';
    }

    public static function get_usps_use_test_env(): bool
    {
        return ((string) get_option(
            self::OPTION_USPS_USE_TEST_ENV,
            self::DEFAULT_USPS_USE_TEST_ENV ? '1' : '0'
        )) === '1';
    }

    public static function get_usps_base_url(): string
    {
        return (string) get_option(self::OPTION_USPS_BASE_URL, self::DEFAULT_USPS_BASE_URL);
    }

    public static function get_usps_client_id(): string
    {
        return (string) get_option(self::OPTION_USPS_CLIENT_ID, self::DEFAULT_USPS_CLIENT_ID);
    }

    public static function get_usps_client_secret(): string
    {
        return (string) get_option(self::OPTION_USPS_CLIENT_SECRET, self::DEFAULT_USPS_CLIENT_SECRET);
    }

    public static function get_usps_origin_zip(): string
    {
        return (string) get_option(self::OPTION_USPS_ORIGIN_ZIP, self::DEFAULT_USPS_ORIGIN_ZIP);
    }

    public static function get_usps_account_type(): string
    {
        return (string) get_option(self::OPTION_USPS_ACCOUNT_TYPE, self::DEFAULT_USPS_ACCOUNT_TYPE);
    }

    public static function get_usps_account_number(): string
    {
        return (string) get_option(self::OPTION_USPS_ACCOUNT_NUMBER, self::DEFAULT_USPS_ACCOUNT_NUMBER);
    }

    public static function get_usps_mail_class(): string
    {
        return (string) get_option(self::OPTION_USPS_MAIL_CLASS, self::DEFAULT_USPS_MAIL_CLASS);
    }

    public static function get_usps_processing_category(): string
    {
        return (string) get_option(self::OPTION_USPS_PROCESSING_CATEGORY, self::DEFAULT_USPS_PROCESSING_CATEGORY);
    }

    public static function get_usps_destination_entry_facility_type(): string
    {
        return (string) get_option(
            self::OPTION_USPS_DEST_ENTRY_FACILITY_TYPE,
            self::DEFAULT_USPS_DEST_ENTRY_FACILITY_TYPE
        );
    }

    public static function get_usps_rate_indicator(): string
    {
        return (string) get_option(self::OPTION_USPS_RATE_INDICATOR, self::DEFAULT_USPS_RATE_INDICATOR);
    }

    public static function get_usps_price_type(): string
    {
        return (string) get_option(self::OPTION_USPS_PRICE_TYPE, self::DEFAULT_USPS_PRICE_TYPE);
    }

    public static function get_usps_timeout_sec(): int
    {
        $raw = (string) get_option(self::OPTION_USPS_TIMEOUT_SEC, (string) self::DEFAULT_USPS_TIMEOUT_SEC);
        $v = (int) preg_replace('/[^0-9]/', '', $raw);
        if ($v <= 0) {
            $v = self::DEFAULT_USPS_TIMEOUT_SEC;
        }
        return $v;
    }

    public static function get_usps_tare_weight_oz(): float
    {
        $raw = (string) get_option(self::OPTION_USPS_TARE_WEIGHT_OZ, (string) self::DEFAULT_USPS_TARE_WEIGHT_OZ);
        if (!is_numeric($raw)) {
            $raw = trim((string) preg_replace('/[^0-9.\-]/', '', $raw));
        }

        if ($raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_USPS_TARE_WEIGHT_OZ;
        }

        $v = (float) $raw;
        if (!is_finite($v) || $v < 0.0) {
            return self::DEFAULT_USPS_TARE_WEIGHT_OZ;
        }

        return $v;
    }

    /* -------------------------------------------------------------------------
     * Distributor field options (credentials, flags, etc.)
     * ---------------------------------------------------------------------- */

    /**
     * Read a distributor-scoped option.
     *
     * Intended for credentials + distributor configuration fields that are
     * registered via SettingsRegistrar (schema-driven).
     */
    public static function get_distributor_option(
        string $distributor_id,
        string $key,
        string $default = ''
    ): string {
        $name = self::distributor_option_name($distributor_id, $key);
        return (string) get_option($name, $default);
    }

    /**
     * Default credit limits by distributor.
     */
    public static function default_distributor_credit_limit(string $distributor_id): float
    {
        $id = strtolower(trim($distributor_id));
        if ($id === 'davidsons') {
            return 2500.0;
        }
        if ($id === 'zanders') {
            return 5000.0;
        }
        if ($id === 'lipseys') {
            return 5000.0;
        }
        if ($id === 'rsr') {
            return 5000.0;
        }

        return 0.0;
    }

    /**
     * Resolve distributor credit limit from distributor settings with legacy fallback.
     */
    public static function get_distributor_credit_limit(string $distributor_id, float $fallback = 0.0): float
    {
        $id = strtolower(trim($distributor_id));
        if ($id === '') {
            return self::to_non_negative_float($fallback);
        }

        $default = self::to_non_negative_float($fallback);
        if ($default <= 0.0) {
            $default = self::default_distributor_credit_limit($id);
        }

        $option_name = self::distributor_credit_limit_option_name($id);
        $raw = get_option($option_name, null);
        if ($raw !== null && trim((string) $raw) !== '') {
            $direct = self::to_non_negative_float($raw);
            if ($direct > 0.0) {
                return $direct;
            }
        }

        // Backward-compatibility: existing dedicated credit-limit options.
        $legacy_option = '';
        if ($id === 'davidsons') {
            $legacy_option = 'fflhub_davidsons_credit_limit';
        } elseif ($id === 'zanders') {
            $legacy_option = 'fflhub_zanders_credit_limit';
        } elseif ($id === 'lipseys') {
            $legacy_option = 'fflhub_lipseys_credit_limit';
        }

        if ($legacy_option !== '') {
            $legacy = get_option($legacy_option, null);
            if ($legacy !== null && trim((string) $legacy) !== '') {
                $legacy_v = self::to_non_negative_float($legacy);
                if ($legacy_v > 0.0) {
                    return $legacy_v;
                }
            }
        }

        return $default;
    }

    /**
     * Whether a distributor is configured as drop-ship only.
     *
     * If true, non-drop-ship offers from that distributor should be ignored
     * in product-creation/sync viability selection.
     */
    public static function is_distributor_non_dropship_blocked(string $distributor_id): bool
    {
        $id = strtolower(trim($distributor_id));
        if ($id === '') {
            return false;
        }

        $option_name = self::distributor_non_dropship_blocked_option_name($id);
        $raw = (string) get_option($option_name, '0');
        return $raw === '1';
    }

    /**
     * Whether a distributor is approved to drop ship SIG SAUER products.
     */
    public static function is_distributor_sig_approved(string $distributor_id): bool
    {
        $id = strtolower(trim($distributor_id));
        if ($id === '') {
            return false;
        }

        $option_name = self::distributor_sig_approved_option_name($id);
        $raw = (string) get_option($option_name, '0');
        return $raw === '1';
    }

    /**
     * Write a distributor-scoped option.
     */
    public static function set_distributor_option(
        string $distributor_id,
        string $key,
        string $value
    ): void {
        $name = self::distributor_option_name($distributor_id, $key);
        update_option($name, $value);
    }

    /**
     * @param mixed $value
     */
    private static function to_non_negative_float($value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        if (!is_numeric($raw)) {
            $raw = trim((string) preg_replace('/[^0-9.\-]/', '', $raw));
        }

        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }

        $v = (float) $raw;
        if (!is_finite($v) || $v < 0.0) {
            return 0.0;
        }

        return $v;
    }

    private static function site_name_fallback(): string
    {
        $name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
        $name = trim(wp_strip_all_tags($name));

        return $name !== '' ? $name : 'Store';
    }

    private static function default_sales_team_signature(): string
    {
        $brand = self::get_public_brand_name();

        return $brand !== '' ? 'Sales Team, ' . $brand : 'Sales Team';
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private static function normalize_quote_email_rep_names($raw): array
    {
        $parts = is_array($raw)
            ? $raw
            : preg_split('/[\r\n,]+/', (string) $raw);

        if (!is_array($parts)) {
            $parts = [];
        }

        $names = [];
        foreach ($parts as $part) {
            $name = trim(wp_strip_all_tags((string) $part));
            $name = trim((string) preg_replace('/\s+/', ' ', $name));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        if (empty($names)) {
            $default = trim(self::default_quote_email_rep_names());
            if ($default !== '') {
                $names[$default] = $default;
            }
        }

        return array_values($names);
    }
}
