<?php
declare(strict_types=1);

namespace FFLHub\Shipping;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared shipping settings used by every label provider.
 *
 * Provider pages should only own provider credentials and account selection.
 * Anything that describes our shipping operation itself, such as the ship-from
 * address, package presets, label defaults, and banned services, lives here so
 * ShipStation and future EasyPost code can read one consistent source.
 */
final class ShippingOptions
{
    public const OPTION_SETTINGS = 'fflhub_shipping_settings';
    private const LEGACY_SHIPSTATION_OPTION = 'fflhub_shipstation_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
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
            'show_debug_fields' => '0',
            'package_presets' => self::default_package_presets(),
            'banned_service_codes' => ['usps_media_mail'],
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

        return self::sanitize(array_merge(
            self::defaults(),
            self::legacy_shipstation_shared_settings(),
            $settings
        ));
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save(array $input): void
    {
        update_option(self::OPTION_SETTINGS, self::sanitize($input), false);
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save_partial(array $input): void
    {
        self::save(array_merge(self::get_all(), $input));
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

    public static function show_debug_fields(): bool
    {
        return ((string) (self::get_all()['show_debug_fields'] ?? '0')) === '1';
    }

    /**
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string}>
     */
    public static function package_presets(): array
    {
        return self::sanitize_package_presets(self::get_all()['package_presets'] ?? []);
    }

    /**
     * @return string[]
     */
    public static function banned_service_codes(): array
    {
        return self::string_list(self::get_all()['banned_service_codes'] ?? []);
    }

    public static function banned_service_codes_text(): string
    {
        return implode("\n", self::banned_service_codes());
    }

    /**
     * @param array<string,mixed> $rate
     */
    public static function rate_service_is_banned(array $rate): bool
    {
        $codes = self::banned_service_codes();
        if (empty($codes)) {
            return false;
        }

        $service_code = strtolower(trim((string) ($rate['service_code'] ?? '')));
        $haystack = strtolower(implode(' ', [
            (string) ($rate['service_code'] ?? ''),
            (string) ($rate['service_type'] ?? ''),
            (string) ($rate['package_type'] ?? ''),
        ]));
        $normalized_haystack = str_replace(['_', '-'], ' ', $haystack);

        foreach ($codes as $code) {
            $code = strtolower(trim($code));
            if ($code === '') {
                continue;
            }

            if ($service_code === $code) {
                return true;
            }

            $normalized_code = trim(str_replace(['_', '-'], ' ', $code));
            if ($normalized_code !== '' && strpos($normalized_haystack, $normalized_code) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    public static function string_list($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        } elseif (!is_array($value)) {
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

    /**
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string}>
     */
    public static function default_package_presets(): array
    {
        return [
            [
                'id' => 'generic_package',
                'name' => 'Generic Package',
                'kind' => 'package',
                'package_code' => 'package',
                'length' => '',
                'width' => '',
                'height' => '',
                'weight_oz' => '',
            ],
            [
                'id' => 'generic_envelope',
                'name' => 'Generic Envelope',
                'kind' => 'envelope',
                'package_code' => 'package',
                'length' => '',
                'width' => '',
                'height' => '',
                'weight_oz' => '',
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string}>
     */
    public static function sanitize_package_presets($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        $used_ids = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['remove'])) {
                continue;
            }

            $name = self::text($row['name'] ?? '');
            $length = self::decimal_text($row['length'] ?? '');
            $width = self::decimal_text($row['width'] ?? '');
            $height = self::decimal_text($row['height'] ?? '');
            $weight_oz = self::decimal_text($row['weight_oz'] ?? '');
            if ($name === '' && $length === '' && $width === '' && $height === '' && $weight_oz === '') {
                continue;
            }
            if ($name === '') {
                $name = 'Package Preset';
            }

            $id = sanitize_key((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = sanitize_key($name);
            }
            if ($id === '') {
                $id = 'package_preset';
            }

            $base_id = $id;
            $suffix = 2;
            while (isset($used_ids[$id])) {
                $id = $base_id . '_' . $suffix;
                $suffix++;
            }
            $used_ids[$id] = true;

            $out[] = [
                'id' => $id,
                'name' => $name,
                'kind' => self::choice((string) ($row['kind'] ?? 'package'), ['package', 'envelope'], 'package'),
                'package_code' => self::text($row['package_code'] ?? 'package') ?: 'package',
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'weight_oz' => $weight_oz,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private static function sanitize(array $settings): array
    {
        $saved = [
            'origin_name' => self::text($settings['origin_name'] ?? ''),
            'origin_company' => self::text($settings['origin_company'] ?? ''),
            'origin_phone' => self::text($settings['origin_phone'] ?? ''),
            'origin_email' => sanitize_email((string) ($settings['origin_email'] ?? '')),
            'origin_address1' => self::text($settings['origin_address1'] ?? ''),
            'origin_address2' => self::text($settings['origin_address2'] ?? ''),
            'origin_city' => self::text($settings['origin_city'] ?? ''),
            'origin_state' => strtoupper(self::text($settings['origin_state'] ?? '')),
            'origin_postal_code' => self::text($settings['origin_postal_code'] ?? ''),
            'origin_country' => strtoupper(self::text($settings['origin_country'] ?? 'US')),
            'origin_residential' => self::choice((string) ($settings['origin_residential'] ?? 'no'), ['unknown', 'yes', 'no'], 'no'),
            'label_format' => self::choice(strtolower((string) ($settings['label_format'] ?? 'pdf')), ['pdf', 'png', 'zpl'], 'pdf'),
            'label_layout' => self::choice(strtolower((string) ($settings['label_layout'] ?? '4x6')), ['4x6', 'letter'], '4x6'),
            'confirmation' => self::choice(
                strtolower((string) ($settings['confirmation'] ?? 'delivery')),
                ['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'],
                'delivery'
            ),
            'insurance_mode' => self::choice((string) ($settings['insurance_mode'] ?? 'none'), ['none', 'declared_value'], 'none'),
            'after_purchase_status' => self::text($settings['after_purchase_status'] ?? ''),
            'show_debug_fields' => !empty($settings['show_debug_fields']) ? '1' : '0',
            'package_presets' => self::sanitize_package_presets($settings['package_presets'] ?? []),
            'banned_service_codes' => self::string_list($settings['banned_service_codes'] ?? []),
        ];

        if ($saved['label_format'] === 'zpl') {
            $saved['label_layout'] = '4x6';
        }

        return $saved;
    }

    /**
     * @return array<string,mixed>
     */
    private static function legacy_shipstation_shared_settings(): array
    {
        $legacy = get_option(self::LEGACY_SHIPSTATION_OPTION, []);
        if (!is_array($legacy)) {
            return [];
        }

        $shared = [];
        foreach (array_keys(self::defaults()) as $key) {
            if (array_key_exists($key, $legacy)) {
                $shared[$key] = $legacy[$key];
            }
        }

        return $shared;
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

    /**
     * @param mixed $value
     */
    private static function decimal_text($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $number = (float) preg_replace('/[^0-9.]/', '', $value);
        if ($number <= 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
