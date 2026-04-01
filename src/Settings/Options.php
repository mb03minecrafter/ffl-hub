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
    public const OPTION_TEST_ORDER_DEBUG_ENABLED      = 'fflhub_test_order_debug_enabled';
    public const OPTION_MAP_BRAND_POLICIES            = 'fflhub_map_brand_policies';
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
    private const DEFAULT_TEST_ORDER_DEBUG_ENABLED      = true;
    private const DEFAULT_MAP_BRAND_POLICIES            = [];
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

    public static function default_test_order_debug_enabled(): bool
    {
        return self::DEFAULT_TEST_ORDER_DEBUG_ENABLED;
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

        if (get_option(self::OPTION_TEST_ORDER_DEBUG_ENABLED, null) === null) {
            add_option(self::OPTION_TEST_ORDER_DEBUG_ENABLED, self::DEFAULT_TEST_ORDER_DEBUG_ENABLED ? '1' : '0');
        }

        if (get_option(self::OPTION_MAP_BRAND_POLICIES, null) === null) {
            add_option(self::OPTION_MAP_BRAND_POLICIES, self::DEFAULT_MAP_BRAND_POLICIES);
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
        $key = self::normalize_brand_policy_key($brand);

        if ($key !== '' && isset($lookup[$key])) {
            return (string) $lookup[$key];
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
        $key = strtolower(trim($brand));
        return (string) preg_replace('/[^a-z0-9]+/', '', $key);
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
}
