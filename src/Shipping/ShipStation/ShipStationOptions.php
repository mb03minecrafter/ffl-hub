<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Option gateway for FFL Hub's ShipStation API v2 integration.
 *
 * The active API key is selected by mode and has a strict precedence order:
 * 1. Mode-specific constant/env var
 * 2. Generic FFLHUB_SHIPSTATION_API_KEY constant/env var
 * 3. Mode-specific saved WordPress option
 */
final class ShipStationOptions
{
    public const OPTION_SETTINGS = 'fflhub_shipstation_settings';
    public const OPTION_CARRIER_CACHE_PREFIX = 'fflhub_shipstation_carrier_cache_';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'mode' => 'sandbox',
            'sandbox_api_key' => '',
            'production_api_key' => '',
            'api_key' => '', // Legacy single-key option, read-only fallback.
            'origin_name' => '',
            'origin_company' => '',
            'origin_phone' => '',
            'origin_email' => '',
            'origin_address1' => '',
            'origin_address2' => '',
            'origin_city' => '',
            'origin_state' => '',
            'origin_postal_code' => '',
            'origin_country' => 'US',
            'origin_residential' => 'no',
            'label_format' => 'pdf',
            'label_layout' => '4x6',
            'confirmation' => 'delivery',
            'insurance_mode' => 'none',
            'after_purchase_status' => '',
            'enabled_carrier_ids' => [],
            'firearm_carrier_ids' => [],
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
        $mode = self::choice((string) ($merged['mode'] ?? 'sandbox'), ['sandbox', 'production'], 'sandbox');
        $legacy_key = trim((string) ($merged['api_key'] ?? ''));
        $mode_key = $mode . '_api_key';

        if ($legacy_key !== '' && trim((string) ($merged[$mode_key] ?? '')) === '') {
            $merged[$mode_key] = $legacy_key;
        }

        return $merged;
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save(array $input, array $clear_keys = []): void
    {
        $current = self::get_all();
        $saved = [
            'enabled' => !empty($input['enabled']) ? '1' : '0',
            'mode' => self::choice((string) ($input['mode'] ?? 'sandbox'), ['sandbox', 'production'], 'sandbox'),
            'sandbox_api_key' => in_array('sandbox', $clear_keys, true) ? '' : (string) ($current['sandbox_api_key'] ?? ''),
            'production_api_key' => in_array('production', $clear_keys, true) ? '' : (string) ($current['production_api_key'] ?? ''),
            'api_key' => '',
            'origin_name' => self::text($input['origin_name'] ?? ''),
            'origin_company' => self::text($input['origin_company'] ?? ''),
            'origin_phone' => self::text($input['origin_phone'] ?? ''),
            'origin_email' => sanitize_email((string) ($input['origin_email'] ?? '')),
            'origin_address1' => self::text($input['origin_address1'] ?? ''),
            'origin_address2' => self::text($input['origin_address2'] ?? ''),
            'origin_city' => self::text($input['origin_city'] ?? ''),
            'origin_state' => strtoupper(self::text($input['origin_state'] ?? '')),
            'origin_postal_code' => self::text($input['origin_postal_code'] ?? ''),
            'origin_country' => strtoupper(self::text($input['origin_country'] ?? 'US')),
            'origin_residential' => self::choice((string) ($input['origin_residential'] ?? 'no'), ['unknown', 'yes', 'no'], 'no'),
            'label_format' => self::choice(strtolower((string) ($input['label_format'] ?? 'pdf')), ['pdf', 'png', 'zpl'], 'pdf'),
            'label_layout' => self::choice(strtolower((string) ($input['label_layout'] ?? '4x6')), ['4x6', 'letter'], '4x6'),
            'confirmation' => self::choice(
                strtolower((string) ($input['confirmation'] ?? 'delivery')),
                ['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'],
                'delivery'
            ),
            'insurance_mode' => self::choice((string) ($input['insurance_mode'] ?? 'none'), ['none', 'declared_value'], 'none'),
            'after_purchase_status' => self::text($input['after_purchase_status'] ?? ''),
            'enabled_carrier_ids' => self::string_list($input['enabled_carrier_ids'] ?? []),
            'firearm_carrier_ids' => self::string_list($input['firearm_carrier_ids'] ?? []),
        ];

        if ($saved['label_format'] === 'zpl') {
            $saved['label_layout'] = '4x6';
        }

        $posted_sandbox_key = trim((string) ($input['sandbox_api_key'] ?? ''));
        if ($posted_sandbox_key !== '') {
            $saved['sandbox_api_key'] = $posted_sandbox_key;
        }

        $posted_production_key = trim((string) ($input['production_api_key'] ?? ''));
        if ($posted_production_key !== '') {
            $saved['production_api_key'] = $posted_production_key;
        }

        update_option(self::OPTION_SETTINGS, $saved, false);
        self::delete_carrier_cache();
    }

    public static function is_enabled(): bool
    {
        return ((string) (self::get_all()['enabled'] ?? '0')) === '1';
    }

    public static function mode(): string
    {
        return self::choice((string) (self::get_all()['mode'] ?? 'sandbox'), ['sandbox', 'production'], 'sandbox');
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

        if (defined('FFLHUB_SHIPSTATION_API_KEY') && trim((string) constant('FFLHUB_SHIPSTATION_API_KEY')) !== '') {
            return trim((string) constant('FFLHUB_SHIPSTATION_API_KEY'));
        }

        $env = getenv('FFLHUB_SHIPSTATION_API_KEY');
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

        if (defined('FFLHUB_SHIPSTATION_API_KEY') && trim((string) constant('FFLHUB_SHIPSTATION_API_KEY')) !== '') {
            return 'constant:FFLHUB_SHIPSTATION_API_KEY';
        }

        $env = getenv('FFLHUB_SHIPSTATION_API_KEY');
        if ($env !== false && trim((string) $env) !== '') {
            return 'environment:FFLHUB_SHIPSTATION_API_KEY';
        }

        return self::api_key($mode) !== '' ? 'saved_option:' . $mode : 'none';
    }

    public static function api_key_mask(?string $mode = null): string
    {
        $key = self::api_key($mode);
        if ($key === '') {
            return '';
        }

        $tail = substr($key, -4);
        return '********' . $tail;
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
        $s = self::get_all();

        return [
            'name' => (string) $s['origin_name'],
            'phone' => (string) $s['origin_phone'],
            'email' => (string) $s['origin_email'],
            'company_name' => (string) $s['origin_company'],
            'address_line1' => (string) $s['origin_address1'],
            'address_line2' => (string) $s['origin_address2'],
            'address_line3' => '',
            'city_locality' => (string) $s['origin_city'],
            'state_province' => (string) $s['origin_state'],
            'postal_code' => (string) $s['origin_postal_code'],
            'country_code' => (string) $s['origin_country'],
            'address_residential_indicator' => (string) $s['origin_residential'],
        ];
    }

    public static function label_format(): string
    {
        return self::choice((string) (self::get_all()['label_format'] ?? 'pdf'), ['pdf', 'png', 'zpl'], 'pdf');
    }

    public static function label_layout(): string
    {
        return self::choice((string) (self::get_all()['label_layout'] ?? '4x6'), ['4x6', 'letter'], '4x6');
    }

    public static function confirmation(): string
    {
        return self::choice(
            (string) (self::get_all()['confirmation'] ?? 'delivery'),
            ['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'],
            'delivery'
        );
    }

    public static function insurance_mode(): string
    {
        return self::choice((string) (self::get_all()['insurance_mode'] ?? 'none'), ['none', 'declared_value'], 'none');
    }

    public static function after_purchase_status(): string
    {
        return sanitize_key((string) (self::get_all()['after_purchase_status'] ?? ''));
    }

    /**
     * @return string[]
     */
    public static function enabled_carrier_ids(): array
    {
        return self::string_list(self::get_all()['enabled_carrier_ids'] ?? []);
    }

    /**
     * @return string[]
     */
    public static function firearm_carrier_ids(): array
    {
        return self::string_list(self::get_all()['firearm_carrier_ids'] ?? []);
    }

    public static function carrier_cache_option_name(): string
    {
        return self::OPTION_CARRIER_CACHE_PREFIX . self::mode();
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_carrier_cache(): array
    {
        $cache = get_option(self::carrier_cache_option_name(), []);
        return is_array($cache) ? $cache : [];
    }

    /**
     * @param array<string,mixed> $cache
     */
    public static function set_carrier_cache(array $cache): void
    {
        update_option(self::carrier_cache_option_name(), $cache, false);
    }

    public static function delete_carrier_cache(): void
    {
        delete_option(self::OPTION_CARRIER_CACHE_PREFIX . 'sandbox');
        delete_option(self::OPTION_CARRIER_CACHE_PREFIX . 'production');
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return sanitize_text_field((string) $value);
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
        return self::choice((string) ($mode ?? self::mode()), ['sandbox', 'production'], 'sandbox');
    }

    private static function api_key_constant_name(string $mode): string
    {
        return $mode === 'production'
            ? 'FFLHUB_SHIPSTATION_PRODUCTION_API_KEY'
            : 'FFLHUB_SHIPSTATION_SANDBOX_API_KEY';
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function string_list($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $out = [];
        foreach ($value as $item) {
            $item = sanitize_text_field((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }
}
