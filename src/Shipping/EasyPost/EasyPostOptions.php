<?php
declare(strict_types=1);

namespace FFLHub\Shipping\EasyPost;

use FFLHub\Shipping\ShippingOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Option gateway for FFL Hub's EasyPost integration.
 *
 * EasyPost keeps separate test and production API keys. The active key follows
 * the same precedence pattern as ShipStation:
 * 1. Mode-specific constant/env var
 * 2. Generic EasyPost constant/env var
 * 3. Mode-specific saved WordPress option
 */
final class EasyPostOptions
{
    public const OPTION_SETTINGS = 'fflhub_easypost_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'mode' => 'test',
            'test_api_key' => '',
            'production_api_key' => '',
            'address_verification_mode' => 'off',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_all(): array
    {
        $settings = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $merged = array_merge(self::defaults(), $settings);
        $merged['mode'] = self::choice((string) ($merged['mode'] ?? 'test'), ['test', 'production'], 'test');
        $merged['address_verification_mode'] = self::choice(
            (string) ($merged['address_verification_mode'] ?? 'off'),
            ['off', 'verify', 'strict'],
            'off'
        );

        return $merged;
    }

    /**
     * @param array<string,mixed> $input
     * @param string[] $clear_keys
     */
    public static function save(array $input, array $clear_keys = []): void
    {
        $current = self::get_all();
        $saved = [
            'enabled' => !empty($input['enabled']) ? '1' : '0',
            'mode' => self::choice((string) ($input['mode'] ?? 'test'), ['test', 'production'], 'test'),
            'test_api_key' => in_array('test', $clear_keys, true) ? '' : (string) ($current['test_api_key'] ?? ''),
            'production_api_key' => in_array('production', $clear_keys, true) ? '' : (string) ($current['production_api_key'] ?? ''),
            'address_verification_mode' => self::choice(
                (string) ($input['address_verification_mode'] ?? 'off'),
                ['off', 'verify', 'strict'],
                'off'
            ),
        ];

        $posted_test_key = trim((string) ($input['test_api_key'] ?? ''));
        if ($posted_test_key !== '') {
            $saved['test_api_key'] = $posted_test_key;
        }

        $posted_production_key = trim((string) ($input['production_api_key'] ?? ''));
        if ($posted_production_key !== '') {
            $saved['production_api_key'] = $posted_production_key;
        }

        update_option(self::OPTION_SETTINGS, $saved, false);
    }

    public static function is_enabled(): bool
    {
        return ((string) (self::get_all()['enabled'] ?? '0')) === '1';
    }

    public static function mode(): string
    {
        return self::choice((string) (self::get_all()['mode'] ?? 'test'), ['test', 'production'], 'test');
    }

    public static function address_verification_mode(): string
    {
        return self::choice(
            (string) (self::get_all()['address_verification_mode'] ?? 'off'),
            ['off', 'verify', 'strict'],
            'off'
        );
    }

    public static function api_key(?string $mode = null): string
    {
        $mode = self::mode_value($mode);
        $mode_constant = self::api_key_constant_name($mode);
        if (defined($mode_constant) && trim((string) constant($mode_constant)) !== '') {
            return trim((string) constant($mode_constant));
        }

        $mode_env = getenv($mode_constant);
        if ($mode_env !== false && trim((string) $mode_env) !== '') {
            return trim((string) $mode_env);
        }

        if (defined('FFLHUB_EASYPOST_API_KEY') && trim((string) constant('FFLHUB_EASYPOST_API_KEY')) !== '') {
            return trim((string) constant('FFLHUB_EASYPOST_API_KEY'));
        }

        $env = getenv('FFLHUB_EASYPOST_API_KEY');
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }

        $settings = self::get_all();
        return trim((string) ($settings[$mode . '_api_key'] ?? ''));
    }

    public static function api_key_source(?string $mode = null): string
    {
        $mode = self::mode_value($mode);
        $mode_constant = self::api_key_constant_name($mode);
        if (defined($mode_constant) && trim((string) constant($mode_constant)) !== '') {
            return 'constant:' . $mode_constant;
        }

        $mode_env = getenv($mode_constant);
        if ($mode_env !== false && trim((string) $mode_env) !== '') {
            return 'environment:' . $mode_constant;
        }

        if (defined('FFLHUB_EASYPOST_API_KEY') && trim((string) constant('FFLHUB_EASYPOST_API_KEY')) !== '') {
            return 'constant:FFLHUB_EASYPOST_API_KEY';
        }

        $env = getenv('FFLHUB_EASYPOST_API_KEY');
        if ($env !== false && trim((string) $env) !== '') {
            return 'environment:FFLHUB_EASYPOST_API_KEY';
        }

        return self::api_key($mode) !== '' ? 'saved_option:' . $mode : 'none';
    }

    public static function api_key_mask(?string $mode = null): string
    {
        $key = self::api_key($mode);
        if ($key === '') {
            return '';
        }

        return '********' . substr($key, -4);
    }

    public static function active_api_key_source_label(): string
    {
        return self::api_key_source_label(self::api_key_source());
    }

    public static function api_key_source_label(string $source): string
    {
        if ($source === 'none') {
            return 'not configured';
        }

        if (strpos($source, 'constant:') === 0) {
            return 'constant ' . substr($source, strlen('constant:'));
        }

        if (strpos($source, 'environment:') === 0) {
            return 'environment variable ' . substr($source, strlen('environment:'));
        }

        if (strpos($source, 'saved_option:') === 0) {
            return 'saved ' . substr($source, strlen('saved_option:')) . ' option';
        }

        return $source;
    }

    /**
     * @return array<string,mixed>
     */
    public static function origin_address(): array
    {
        return ShippingOptions::origin_address();
    }

    public static function label_format(): string
    {
        return ShippingOptions::label_format();
    }

    public static function label_layout(): string
    {
        return ShippingOptions::label_layout();
    }

    public static function confirmation(): string
    {
        return ShippingOptions::confirmation();
    }

    public static function insurance_mode(): string
    {
        return ShippingOptions::insurance_mode();
    }

    /**
     * @param string[] $allowed
     */
    private static function choice(string $value, array $allowed, string $default): string
    {
        $value = trim($value);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function mode_value(?string $mode): string
    {
        return self::choice((string) ($mode ?? self::mode()), ['test', 'production'], 'test');
    }

    private static function api_key_constant_name(string $mode): string
    {
        return $mode === 'production'
            ? 'FFLHUB_EASYPOST_PRODUCTION_API_KEY'
            : 'FFLHUB_EASYPOST_TEST_API_KEY';
    }
}
