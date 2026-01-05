<?php

namespace FFLHub\Settings;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\DistributorRegistry;
use FFLHub\Distributor\DistributorModuleInterface;

/**
 * Registers all FFLHub settings with the WP Settings API.
 *
 * AdminPage should NOT call register_setting() anymore.
 * All settings are registered here, driven by distributor modules.
 */
final class SettingsRegistrar
{
    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'register_all_settings']);
    }

    public static function register_all_settings(): void
    {
        self::register_global_settings();
        self::register_distributor_settings();
    }

    /* -------------------------------------------------------------------------
     * Global settings
     * ---------------------------------------------------------------------- */

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
    }

    /* -------------------------------------------------------------------------
     * Distributor settings (module-driven)
     * ---------------------------------------------------------------------- */

    private static function register_distributor_settings(): void
    {
        foreach (DistributorRegistry::get_modules() as $module) {
            if (! ($module instanceof DistributorModuleInterface)) {
                continue;
            }

            $dist_id = $module->id();
            $fields  = $module->settings_schema();

            if (empty($fields) || ! is_array($fields)) {
                continue;
            }

            $group = Options::distributor_settings_group($dist_id);

            foreach ($fields as $key => $def) {
                $key         = (string) $key;
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
     * Percent sanitizer:
     *  - allow digits + dot
     *  - coerce to float string
     */
    public static function sanitize_percent_string($value): string
    {
        $value = preg_replace('/[^0-9.]/', '', (string) $value);
        return (string) (float) $value;
    }

    /**
     * Conservative string sanitizer for secrets (does not clobber special chars).
     */
    public static function sanitize_loose_string($value): string
    {
        $v = (string) $value;
        $v = str_replace("\0", '', $v);
        return trim($v);
    }

    /**
     * Standard text field sanitizer.
     */
    public static function sanitize_text($value): string
    {
        return sanitize_text_field((string) $value);
    }

    /**
     * Checkbox sanitizer: force "0" or "1".
     */
    public static function sanitize_checkbox($value): string
    {
        return ((string) $value === '1') ? '1' : '0';
    }

    /**
     * Select a sanitizer based on field type.
     */
    private static function sanitize_callback_for_type(string $type): callable
    {
        $type = strtolower(trim($type));

        switch ($type) {
            case 'number':
                return [__CLASS__, 'sanitize_percent_string'];

            case 'password':
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
