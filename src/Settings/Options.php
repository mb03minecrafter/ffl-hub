<?php

namespace FFLHub\Settings;


use FFLHub\Distributor\DistributorRegistry;

/**
 * Central registry + helpers for all FFL Hub options.
 */
class Options
{
    /**
     * Option names.
     */
    public const OPTION_DISTRIBUTOR_STATE            = 'fflhub_distributor_state';
    public const OPTION_PAYMENT_PROCESSOR_FEE_PERCENT = 'fflhub_payment_processor_fee_percent';
    public const OPTION_GLOBAL_MARKUP                = 'fflhub_global_markup';

    /**
     * Default values.
     */
    private const DEFAULT_PAYMENT_PROCESSOR_FEE_PERCENT = 2.9;   // %
    private const DEFAULT_GLOBAL_MARKUP                 = 10.0;  // %

    /**
     * Initialize all core options with sane defaults.
     *
     * Call this from Plugin::activate(), passing in the known distributor slugs.
     *
     * @param string[] $distributor_slugs
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

        // Distributor state (enabled/disabled, disabled by default).
        $state = get_option(self::OPTION_DISTRIBUTOR_STATE, []);

        if (! is_array($state)) {
            $state = [];
        }


        $distributor_slugs = DistributorRegistry::get_distributor_ids();

        foreach ($distributor_slugs as $slug) {
            if (! isset($state[$slug]) || ! is_array($state[$slug])) {
                $state[$slug] = [
                    'enabled'    => false,
                    'created_at' => time(),
                    // add future metadata here if you want (last_sync_at, etc.)
                ];
            }
        }

        update_option(self::OPTION_DISTRIBUTOR_STATE, $state);
    }

    /**
     * Get the raw distributor state array.
     *
     * @return array<string, array{enabled:bool,created_at?:int}>
     */
    public static function get_distributor_state(): array
    {
        $state = get_option(self::OPTION_DISTRIBUTOR_STATE, []);

        if (! is_array($state)) {
            return [];
        }

        return $state;
    }

    /**
     * Persist the entire distributor state array.
     *
     * @param array<string, array{enabled:bool,created_at?:int}> $state
     */
    public static function set_distributor_state(array $state): void
    {
        update_option(self::OPTION_DISTRIBUTOR_STATE, $state);
    }

    /**
     * Check if a given distributor is enabled (defaults to false).
     */
    public static function is_distributor_enabled(string $slug): bool
    {
        $state = self::get_distributor_state();

        return ! empty($state[$slug]['enabled']);
    }

    /**
     * Flip a distributor's enabled/disabled flag.
     */
    public static function set_distributor_enabled(string $slug, bool $enabled): void
    {
        $state = self::get_distributor_state();

        if (! isset($state[$slug]) || ! is_array($state[$slug])) {
            $state[$slug] = [
                'created_at' => time(),
            ];
        }

        $state[$slug]['enabled'] = $enabled;

        self::set_distributor_state($state);
    }

    /**
     * Get payment processor fee percent as float.
     */
    public static function get_payment_processor_fee_percent(): float
    {
        $value = get_option(
            self::OPTION_PAYMENT_PROCESSOR_FEE_PERCENT,
            (string) self::DEFAULT_PAYMENT_PROCESSOR_FEE_PERCENT
        );

        return (float) $value;
    }

    /**
     * Set payment processor fee percent.
     */
    public static function set_payment_processor_fee_percent(float $percent): void
    {
        update_option(self::OPTION_PAYMENT_PROCESSOR_FEE_PERCENT, (string) $percent);
    }

    /**
     * Get global markup percent as float.
     */
    public static function get_global_markup(): float
    {
        $value = get_option(
            self::OPTION_GLOBAL_MARKUP,
            (string) self::DEFAULT_GLOBAL_MARKUP
        );

        return (float) $value;
    }

    /**
     * Set global markup percent.
     */
    public static function set_global_markup(float $percent): void
    {
        update_option(self::OPTION_GLOBAL_MARKUP, (string) $percent);
    }
}
