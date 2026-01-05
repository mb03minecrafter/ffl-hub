<?php

namespace FFLHub\Settings;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\DistributorRegistry;

/**
 * Central registry + helpers for all FFL Hub options.
 *
 * All code should avoid calling get_option()/update_option() directly
 * and go through this class instead.
 */
final class Options
{
    /**
     * Option names.
     */
    public const OPTION_DISTRIBUTOR_STATE             = 'fflhub_distributor_state';
    public const OPTION_PAYMENT_PROCESSOR_FEE_PERCENT = 'fflhub_payment_processor_fee_percent';
    public const OPTION_GLOBAL_MARKUP                 = 'fflhub_global_markup';

    /**
     * Default values.
     */
    private const DEFAULT_PAYMENT_PROCESSOR_FEE_PERCENT = 2.9;   // %
    private const DEFAULT_GLOBAL_MARKUP                 = 10.0;  // %

    /* -------------------------------------------------------------------------
     * Settings groups
     * ---------------------------------------------------------------------- */

    public static function global_settings_group(): string
    {
        return 'fflhub_global_settings';
    }

    public static function distributor_settings_group(string $distributor_id): string
    {
        return 'fflhub_' . $distributor_id . '_settings_group';
    }

    /* -------------------------------------------------------------------------
     * Option naming helpers
     * ---------------------------------------------------------------------- */

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

    /* -------------------------------------------------------------------------
     * Initialization
     * ---------------------------------------------------------------------- */

    /**
     * Initialize all core options with sane defaults.
     *
     * Call this from Plugin::activate().
     */
    public static function init_defaults(): void
    {
        // Numeric settings.
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

        self::sync_distributor_state();
    }

    /**
     * Ensure distributor state exists for all registered modules.
     *
     * This keeps state consistent when:
     * - new distributors are added
     * - old distributors are removed
     */
    public static function sync_distributor_state(): void
    {
        $state = get_option(self::OPTION_DISTRIBUTOR_STATE, []);

        if (! is_array($state)) {
            $state = [];
        }

        $known_ids = DistributorRegistry::get_distributor_ids();

        foreach ($known_ids as $id) {
            if (! isset($state[$id]) || ! is_array($state[$id])) {
                $state[$id] = [
                    'enabled'    => false,
                    'created_at' => time(),
                ];
            }
        }

        // Optional cleanup: remove stale distributors.
        foreach ($state as $id => $_) {
            if (! in_array($id, $known_ids, true)) {
                unset($state[$id]);
            }
        }

        update_option(self::OPTION_DISTRIBUTOR_STATE, $state);
    }

    /* -------------------------------------------------------------------------
     * Distributor enable / disable state
     * ---------------------------------------------------------------------- */

    /**
     * @return array<string, array{enabled:bool,created_at?:int}>
     */
    public static function get_distributor_state(): array
    {
        $state = get_option(self::OPTION_DISTRIBUTOR_STATE, []);
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, array{enabled:bool,created_at?:int}> $state
     */
    public static function set_distributor_state(array $state): void
    {
        update_option(self::OPTION_DISTRIBUTOR_STATE, $state);
    }

    public static function is_distributor_enabled(string $id): bool
    {
        $state = self::get_distributor_state();
        return ! empty($state[$id]['enabled']);
    }

    public static function set_distributor_enabled(string $id, bool $enabled): void
    {
        $state = self::get_distributor_state();

        if (! isset($state[$id]) || ! is_array($state[$id])) {
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

    /* -------------------------------------------------------------------------
     * Distributor field options (credentials, flags, etc.)
     * ---------------------------------------------------------------------- */

    public static function get_distributor_option(
        string $distributor_id,
        string $key,
        string $default = ''
    ): string {
        $name = self::distributor_option_name($distributor_id, $key);
        return (string) get_option($name, $default);
    }

    public static function set_distributor_option(
        string $distributor_id,
        string $key,
        string $value
    ): void {
        $name = self::distributor_option_name($distributor_id, $key);
        update_option($name, $value);
    }
}
