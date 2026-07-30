<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipOutdoors;

use FFLHub\Shipping\ShippingOptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Option gateway for ShipOutdoors firearm labels.
 *
 * ShipOutdoors is production-only, so this class deliberately has no sandbox
 * mode. The API key is still resolved with the same precedence pattern used by
 * other providers: constant/env var first, then the saved WordPress option.
 */
final class ShipOutdoorsOptions
{
    public const OPTION_SETTINGS = 'fflhub_shipoutdoors_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'production_api_key' => '',
            'notification_email' => '',
            'return_label_original_size' => '1',
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

        return array_merge(self::defaults(), $settings);
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save(array $input, bool $clear_api_key = false): void
    {
        $current = self::get_all();
        $saved = [
            'enabled' => !empty($input['enabled']) ? '1' : '0',
            'production_api_key' => $clear_api_key ? '' : (string) ($current['production_api_key'] ?? ''),
            'notification_email' => sanitize_email((string) ($input['notification_email'] ?? '')),
            'return_label_original_size' => !empty($input['return_label_original_size']) ? '1' : '0',
        ];

        $posted_key = trim((string) ($input['production_api_key'] ?? ''));
        if ($posted_key !== '') {
            $saved['production_api_key'] = $posted_key;
        }

        update_option(self::OPTION_SETTINGS, $saved, false);
    }

    public static function is_enabled(): bool
    {
        return ((string) (self::get_all()['enabled'] ?? '0')) === '1';
    }

    public static function configured(): bool
    {
        return self::is_enabled() && self::api_key() !== '';
    }

    public static function api_key(): string
    {
        if (defined('FFLHUB_SHIPOUTDOORS_API_KEY') && trim((string) constant('FFLHUB_SHIPOUTDOORS_API_KEY')) !== '') {
            return trim((string) constant('FFLHUB_SHIPOUTDOORS_API_KEY'));
        }

        $env = getenv('FFLHUB_SHIPOUTDOORS_API_KEY');
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }

        return trim((string) (self::get_all()['production_api_key'] ?? ''));
    }

    public static function api_key_source(): string
    {
        if (defined('FFLHUB_SHIPOUTDOORS_API_KEY') && trim((string) constant('FFLHUB_SHIPOUTDOORS_API_KEY')) !== '') {
            return 'constant:FFLHUB_SHIPOUTDOORS_API_KEY';
        }

        $env = getenv('FFLHUB_SHIPOUTDOORS_API_KEY');
        if ($env !== false && trim((string) $env) !== '') {
            return 'environment:FFLHUB_SHIPOUTDOORS_API_KEY';
        }

        return self::api_key() !== '' ? 'saved_option:production' : 'none';
    }

    public static function api_key_mask(): string
    {
        $key = self::api_key();
        if ($key === '') {
            return '';
        }

        return '********' . substr($key, -4);
    }

    public static function api_key_source_label(): string
    {
        $source = self::api_key_source();
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
            return 'saved production option';
        }

        return $source;
    }

    public static function notification_email(): string
    {
        return sanitize_email((string) (self::get_all()['notification_email'] ?? ''));
    }

    public static function return_label_original_size(): bool
    {
        return ((string) (self::get_all()['return_label_original_size'] ?? '1')) === '1';
    }

    /**
     * @return array<string,mixed>
     */
    public static function origin_address(): array
    {
        return ShippingOptions::origin_address();
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
}
