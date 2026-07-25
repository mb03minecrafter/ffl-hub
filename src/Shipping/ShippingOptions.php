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
            'future_package_presets' => self::default_future_package_presets(),
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
        return self::merge_builtin_package_presets(
            self::sanitize_package_presets(self::get_all()['package_presets'] ?? [])
        );
    }

    /**
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string,max_weight_oz:string}>
     */
    public static function future_package_presets(): array
    {
        return self::sanitize_package_presets(self::get_all()['future_package_presets'] ?? [], 'box');
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
                'name' => 'Generic Box',
                'kind' => 'box',
                'package_code' => 'package',
                'length' => '',
                'width' => '',
                'height' => '',
                'weight_oz' => '',
            ],
            [
                'id' => 'usps_thick_envelope',
                'name' => 'USPS Thick Envelope',
                'kind' => 'envelope',
                'package_code' => 'thick_envelope',
                'length' => '',
                'width' => '',
                'height' => '',
                'weight_oz' => '',
            ],
        ];
    }

    /**
     * Potential package sizes we do not own yet. These are audit-only inputs for
     * the order packing tester; label flows continue to use package_presets().
     *
     * Uline lists package weight and max load in pounds, while our package
     * preset UI stores weight in ounces. The defaults below are converted to oz.
     *
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string,max_weight_oz:string}>
     */
    public static function default_future_package_presets(): array
    {
        return [
            self::future_box('future_box_4x4x4', 'Potential Box 4 x 4 x 4', 4, 4, 4, 0.32, 3),
            self::future_box('s_4050', 'S-4050 Box 5 x 5 x 5', 5, 5, 5, 0.41, 5),
            self::future_box('s_4060', 'S-4060 Box 6 x 4 x 4', 6, 4, 4, 0.37, 4),
            self::future_box('s_4062', 'S-4062 Box 6 x 6 x 6', 6, 6, 6, 0.45, 7),
            self::future_box('s_4080', 'S-4080 Box 8 x 6 x 4', 8, 6, 4, 0.46, 7),
            self::future_box('s_4082', 'S-4082 Box 8 x 8 x 4', 8, 8, 4, 0.65, 9),
            self::future_box('s_4084', 'S-4084 Box 8 x 8 x 8', 8, 8, 8, 0.66, 12),
            self::future_box('s_4094', 'S-4094 Box 9 x 9 x 9', 9, 9, 9, 0.82, 16),
            self::future_box('s_4103', 'S-4103 Box 10 x 8 x 6', 10, 8, 6, 0.70, 12),
            self::future_box('s_4105', 'S-4105 Box 10 x 10 x 10', 10, 10, 10, 0.84, 19),
            self::future_box('s_4521', 'S-4521 Box 12 x 9 x 4', 12, 9, 4, 0.84, 13),
            self::future_box('s_4406', 'S-4406 Box 12 x 9 x 6', 12, 9, 6, 0.92, 15),
            self::future_box('s_4120', 'S-4120 Box 12 x 10 x 8', 12, 10, 8, 0.96, 19),
            self::future_box('s_4122', 'S-4122 Box 12 x 12 x 6', 12, 12, 6, 0.99, 20),
            self::future_box('s_4124', 'S-4124 Box 12 x 12 x 8', 12, 12, 8, 1.06, 23),
            self::future_box('s_4125', 'S-4125 Box 12 x 12 x 12', 12, 12, 12, 1.09, 27),
            self::future_box('s_4233', 'S-4233 Box 14 x 10 x 6', 14, 10, 6, 0.96, 18),
            self::future_box('s_4142', 'S-4142 Box 14 x 14 x 14', 14, 14, 14, 1.72, 38),
            self::future_box('s_4318', 'S-4318 Box 15 x 15 x 15', 15, 15, 15, 1.97, 41),
            self::future_box('s_4235', 'S-4235 Box 16 x 12 x 8', 16, 12, 8, 1.29, 25),
            self::future_box('s_4160', 'S-4160 Box 16 x 12 x 10', 16, 12, 10, 1.42, 29),
            self::future_box('s_4163', 'S-4163 Box 16 x 12 x 12', 16, 12, 12, 1.51, 31),
            self::future_box('s_4166', 'S-4166 Box 16 x 16 x 16', 16, 16, 16, 2.16, 47),
            self::future_box('s_4187', 'S-4187 Box 18 x 12 x 6', 18, 12, 6, 1.45, 25),
            self::future_box('s_4189', 'S-4189 Box 18 x 12 x 10', 18, 12, 10, 1.53, 31),
            self::future_box('s_4181', 'S-4181 Box 18 x 12 x 12', 18, 12, 12, 1.57, 33),
            self::future_box('s_4183', 'S-4183 Box 18 x 14 x 12', 18, 14, 12, 1.89, 31),
            self::future_box('s_4185', 'S-4185 Box 18 x 18 x 18', 18, 18, 18, 2.70, 48),
            self::future_box('s_4200', 'S-4200 Box 20 x 16 x 14', 20, 16, 14, 2.50, 40),
            self::future_box('s_4201', 'S-4201 Box 20 x 20 x 20', 20, 20, 20, 3.50, 29),
            self::future_box('s_4243', 'S-4243 Box 24 x 12 x 12', 24, 12, 12, 1.95, 32),
            self::future_box('s_4218', 'S-4218 Box 24 x 16 x 16', 24, 16, 16, 2.92, 24),
            self::future_box('s_4219', 'S-4219 Box 24 x 18 x 12', 24, 18, 12, 2.86, 23),
            self::future_box('s_4340', 'S-4340 Box 24 x 18 x 18', 24, 18, 18, 3.59, 40),
            self::future_box('s_4247', 'S-4247 Box 24 x 24 x 24', 24, 24, 24, 5.14, 42),
            self::future_box('s_4193', 'S-4193 Box 36 x 36 x 36', 36, 36, 36, 11.16, 92),
        ];
    }

    /**
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string}>
     */
    private static function builtin_package_presets(): array
    {
        return self::default_package_presets();
    }

    /**
     * @param array<int,array<string,mixed>> $saved
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string}>
     */
    private static function merge_builtin_package_presets(array $saved): array
    {
        $out = [];
        foreach (array_merge(self::builtin_package_presets(), $saved) as $preset) {
            $id = (string) ($preset['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $out[$id] = $preset;
        }

        return array_values($out);
    }

    /**
     * @param mixed $value
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string}>
     */
    public static function sanitize_package_presets($value, ?string $force_kind = null): array
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

            $kind = $force_kind !== null
                ? self::package_type($force_kind)
                : self::package_type($row['kind'] ?? ($row['type'] ?? 'box'));
            $length = self::decimal_text($row['length'] ?? '');
            $width = self::decimal_text($row['width'] ?? '');
            $height = self::decimal_text($row['height'] ?? '');
            $weight_oz = self::decimal_text($row['weight_oz'] ?? '');
            $max_weight_oz = self::decimal_text($row['max_weight_oz'] ?? '');
            if ($length === '' && $width === '' && $height === '' && $weight_oz === '') {
                continue;
            }
            $name = self::text($row['name'] ?? '');
            if ($name === '') {
                $name = self::package_preset_name($kind, $length, $width, $height);
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
                'kind' => $kind,
                'package_code' => $kind === 'envelope' ? 'thick_envelope' : 'package',
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'weight_oz' => $weight_oz,
                'max_weight_oz' => $max_weight_oz,
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
            'future_package_presets' => self::sanitize_package_presets($settings['future_package_presets'] ?? [], 'box'),
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
    private static function package_type($value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === 'package') {
            return 'box';
        }

        return in_array($value, ['box', 'envelope'], true) ? $value : 'box';
    }

    private static function package_preset_name(string $type, string $length, string $width, string $height): string
    {
        $label = $type === 'envelope' ? 'Envelope' : 'Box';
        $dims = array_filter([$length, $width, $height], static fn (string $value): bool => $value !== '');

        return count($dims) === 3
            ? $label . ' ' . implode(' x ', $dims)
            : $label . ' Preset';
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

    /**
     * @return array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string,max_weight_oz:string}
     */
    private static function future_box(
        string $id,
        string $name,
        float $length,
        float $width,
        float $height,
        float $box_weight_lb,
        float $max_weight_lb
    ): array {
        return [
            'id' => sanitize_key($id),
            'name' => $name,
            'kind' => 'box',
            'package_code' => 'package',
            'length' => self::decimal_text((string) $length),
            'width' => self::decimal_text((string) $width),
            'height' => self::decimal_text((string) $height),
            'weight_oz' => self::decimal_text((string) ($box_weight_lb * 16.0)),
            'max_weight_oz' => self::decimal_text((string) ($max_weight_lb * 16.0)),
        ];
    }
}
