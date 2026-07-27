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
            'packing_slip_format' => 'pdf',
            'confirmation' => 'delivery',
            'insurance_mode' => 'none',
            'after_purchase_status' => '',
            'show_debug_fields' => '0',
            'exclude_globalpost' => '1',
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

    public static function packing_slip_format(): string
    {
        return self::choice((string) (self::get_all()['packing_slip_format'] ?? 'pdf'), ['pdf', 'zpl'], 'pdf');
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

    public static function exclude_globalpost(): bool
    {
        return ((string) (self::get_all()['exclude_globalpost'] ?? '1')) === '1';
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
        return self::merge_builtin_future_package_presets(
            self::sanitize_package_presets(self::get_all()['future_package_presets'] ?? [], 'box')
        );
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
        if (self::exclude_globalpost() && self::rate_is_globalpost($rate)) {
            return true;
        }

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
     * @param array<string,mixed> $rate
     */
    public static function rate_is_globalpost(array $rate): bool
    {
        $text = strtolower(implode(' ', [
            (string) ($rate['carrier_code'] ?? ''),
            (string) ($rate['carrier_nickname'] ?? ''),
            (string) ($rate['carrier_friendly_name'] ?? ''),
            (string) ($rate['service_code'] ?? ''),
            (string) ($rate['service_type'] ?? ''),
            (string) ($rate['package_type'] ?? ''),
        ]));
        $normalized = str_replace(['_', '-'], ' ', $text);

        return strpos($text, 'globalpost') !== false
            || strpos($normalized, 'global post') !== false
            || strpos($text, 'goglobalpost') !== false;
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
     * Older Uline rows include package weight and max load in pounds, while our
     * package preset UI stores weight in ounces. Newer pasted Uline rows only
     * include price tiers, so those are stored as dimension-only audit boxes and
     * let BoxPacker use the normal carrier-default max capacity.
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
            self::future_box_dimensions_only('s_16721', 'S-16721 Box 12 x 3 x 3', 12, 3, 3),
            self::future_box_dimensions_only('s_4116', 'S-4116 Box 12 x 4 x 4', 12, 4, 4),
            self::future_box_dimensions_only('s_22606', 'S-22606 Box 12 x 4 x 4', 12, 4, 4),
            self::future_box_dimensions_only('s_18237', 'S-18237 Box 12 x 4 x 90', 12, 4, 90),
            self::future_box_dimensions_only('s_19099', 'S-19099 Box 12 x 5 x 4', 12, 5, 4),
            self::future_box_dimensions_only('s_16722', 'S-16722 Box 12 x 5 x 5', 12, 5, 5),
            self::future_box_dimensions_only('s_11372', 'S-11372 Box 12 x 6 x 2', 12, 6, 2),
            self::future_box_dimensions_only('s_16723', 'S-16723 Box 12 x 6 x 3', 12, 6, 3),
            self::future_box_dimensions_only('s_4127', 'S-4127 Box 12 x 6 x 4', 12, 6, 4),
            self::future_box_dimensions_only('s_19861', 'S-19861 Box 12 x 6 x 4', 12, 6, 4),
            self::future_box_dimensions_only('s_15032', 'S-15032 Box 12 x 6 x 5', 12, 6, 5),
            self::future_box_dimensions_only('s_4128', 'S-4128 Box 12 x 6 x 6', 12, 6, 6),
            self::future_box_dimensions_only('s_19063', 'S-19063 Box 12 x 6 x 6', 12, 6, 6),
            self::future_box_dimensions_only('s_4709', 'S-4709 Box 12 x 6 x 6', 12, 6, 6),
            self::future_box_dimensions_only('s_4784', 'S-4784 Box 12 x 6 x 6', 12, 6, 6),
            self::future_box_dimensions_only('s_4710', 'S-4710 Box 12 x 6 x 8', 12, 6, 8),
            self::future_box_dimensions_only('s_12592', 'S-12592 Box 12 x 6 x 12', 12, 6, 12),
            self::future_box_dimensions_only('s_22180', 'S-22180 Box 12 x 7 x 4', 12, 7, 4),
            self::future_box_dimensions_only('s_13293', 'S-13293 Box 12 x 7 x 5', 12, 7, 5),
            self::future_box_dimensions_only('s_18386', 'S-18386 Box 12 x 7 x 7', 12, 7, 7),
            self::future_box_dimensions_only('s_23954', 'S-23954 Box 12 x 8 x 2', 12, 8, 2),
            self::future_box_dimensions_only('s_16724', 'S-16724 Box 12 x 8 x 3', 12, 8, 3),
            self::future_box_dimensions_only('s_4117', 'S-4117 Box 12 x 8 x 4', 12, 8, 4),
            self::future_box_dimensions_only('s_21016', 'S-21016 Box 12 x 8 x 4', 12, 8, 4),
            self::future_box_dimensions_only('s_4711', 'S-4711 Box 12 x 8 x 5', 12, 8, 5),
            self::future_box_dimensions_only('s_4121', 'S-4121 Box 12 x 8 x 6', 12, 8, 6),
            self::future_box_dimensions_only('s_19064', 'S-19064 Box 12 x 8 x 6', 12, 8, 6),
            self::future_box_dimensions_only('s_4979', 'S-4979 Box 12 x 8 x 7', 12, 8, 7),
            self::future_box_dimensions_only('s_4129', 'S-4129 Box 12 x 8 x 8', 12, 8, 8),
            self::future_box_dimensions_only('s_19065', 'S-19065 Box 12 x 8 x 8', 12, 8, 8),
            self::future_box_dimensions_only('s_22633', 'S-22633 Box 12 x 8 x 10', 12, 8, 10),
            self::future_box_dimensions_only('s_18387', 'S-18387 Box 12 x 8 x 12', 12, 8, 12),
            self::future_box_dimensions_only('s_4118', 'S-4118 Box 12 x 9 x 2', 12, 9, 2),
            self::future_box_dimensions_only('s_4312', 'S-4312 Box 12 x 9 x 3', 12, 9, 3),
            self::future_box_dimensions_only('s_23289', 'S-23289 Box 12 x 9 x 3', 12, 9, 3),
            self::future_box_dimensions_only('s_19066', 'S-19066 Box 12 x 9 x 4', 12, 9, 4),
            self::future_box_dimensions_only('s_14262', 'S-14262 Box 12 x 9 x 4', 12, 9, 4),
            self::future_box_dimensions_only('s_4487', 'S-4487 Box 12 x 9 x 5', 12, 9, 5),
            self::future_box_dimensions_only('s_22181', 'S-22181 Box 12 x 9 x 5', 12, 9, 5),
            self::future_box_dimensions_only('s_18339', 'S-18339 Box 12 x 9 x 6', 12, 9, 6),
            self::future_box_dimensions_only('s_4421', 'S-4421 Box 12 x 9 x 6', 12, 9, 6),
            self::future_box_dimensions_only('s_19771', 'S-19771 Box 12 x 9 x 6', 12, 9, 6),
            self::future_box_dimensions_only('s_4488', 'S-4488 Box 12 x 9 x 7', 12, 9, 7),
            self::future_box_dimensions_only('s_22634', 'S-22634 Box 12 x 9 x 7', 12, 9, 7),
            self::future_box_dimensions_only('s_4853', 'S-4853 Box 12 x 9 x 8', 12, 9, 8),
            self::future_box_dimensions_only('s_4335', 'S-4335 Box 12 x 9 x 9', 12, 9, 9),
            self::future_box_dimensions_only('s_19852', 'S-19852 Box 12 x 9 x 9', 12, 9, 9),
            self::future_box_dimensions_only('s_21534', 'S-21534 Box 12 x 9 x 9', 12, 9, 9),
            self::future_box_dimensions_only('s_4612', 'S-4612 Box 12 x 9 x 10', 12, 9, 10),
            self::future_box_dimensions_only('s_4884', 'S-4884 Box 12 x 9 x 12', 12, 9, 12),
            self::future_box_dimensions_only('s_21017', 'S-21017 Box 12 x 9 x 12', 12, 9, 12),
            self::future_box_dimensions_only('s_4980', 'S-4980 Box 12 x 10 x 2', 12, 10, 2),
            self::future_box_dimensions_only('s_4409', 'S-4409 Box 12 x 10 x 3', 12, 10, 3),
            self::future_box_dimensions_only('s_4119', 'S-4119 Box 12 x 10 x 4', 12, 10, 4),
            self::future_box_dimensions_only('s_19853', 'S-19853 Box 12 x 10 x 4', 12, 10, 4),
            self::future_box_dimensions_only('s_4522', 'S-4522 Box 12 x 10 x 5', 12, 10, 5),
            self::future_box_dimensions_only('s_22635', 'S-22635 Box 12 x 10 x 5', 12, 10, 5),
            self::future_box_dimensions_only('s_4130', 'S-4130 Box 12 x 10 x 6', 12, 10, 6),
            self::future_box_dimensions_only('s_18340', 'S-18340 Box 12 x 10 x 6', 12, 10, 6),
            self::future_box_dimensions_only('s_15033', 'S-15033 Box 12 x 10 x 6', 12, 10, 6),
            self::future_box_dimensions_only('s_14227', 'S-14227 Box 12 x 10 x 6', 12, 10, 6),
            self::future_box_dimensions_only('s_4885', 'S-4885 Box 12 x 10 x 7', 12, 10, 7),
            self::future_box_dimensions_only('s_18341', 'S-18341 Box 12 x 10 x 8', 12, 10, 8),
            self::future_box_dimensions_only('s_15034', 'S-15034 Box 12 x 10 x 8', 12, 10, 8),
            self::future_box_dimensions_only('s_19777', 'S-19777 Box 12 x 10 x 8', 12, 10, 8),
            self::future_box_dimensions_only('s_15035', 'S-15035 Box 12 x 10 x 8', 12, 10, 8),
            self::future_box_dimensions_only('s_4336', 'S-4336 Box 12 x 10 x 9', 12, 10, 9),
            self::future_box_dimensions_only('s_4123', 'S-4123 Box 12 x 10 x 10', 12, 10, 10),
            self::future_box_dimensions_only('s_19067', 'S-19067 Box 12 x 10 x 10', 12, 10, 10),
            self::future_box_dimensions_only('s_18182', 'S-18182 Box 12 x 10 x 10', 12, 10, 10),
            self::future_box_dimensions_only('s_12593', 'S-12593 Box 12 x 10 x 12', 12, 10, 12),
            self::future_box_dimensions_only('s_21564', 'S-21564 Box 12 x 10 x 14', 12, 10, 14),
            self::future_box_dimensions_only('s_22636', 'S-22636 Box 12 x 11 x 11', 12, 11, 11),
            self::future_box_dimensions_only('s_4561', 'S-4561 Box 12 x 12 x 2', 12, 12, 2),
            self::future_box_dimensions_only('s_4460', 'S-4460 Box 12 x 12 x 3', 12, 12, 3),
            self::future_box_dimensions_only('s_23290', 'S-23290 Box 12 x 12 x 3', 12, 12, 3),
            self::future_box_dimensions_only('s_4215', 'S-4215 Box 12 x 12 x 4', 12, 12, 4),
            self::future_box_dimensions_only('s_19068', 'S-19068 Box 12 x 12 x 4', 12, 12, 4),
            self::future_box_dimensions_only('s_19778', 'S-19778 Box 12 x 12 x 4', 12, 12, 4),
            self::future_box_dimensions_only('s_23959', 'S-23959 Box 12 x 12 x 4', 12, 12, 4),
            self::future_box_dimensions_only('s_4489', 'S-4489 Box 12 x 12 x 5', 12, 12, 5),
            self::future_box_dimensions_only('s_23301', 'S-23301 Box 12 x 12 x 5', 12, 12, 5),
            self::future_box_dimensions_only('s_18342', 'S-18342 Box 12 x 12 x 6', 12, 12, 6),
            self::future_box_dimensions_only('s_4341', 'S-4341 Box 12 x 12 x 6', 12, 12, 6),
            self::future_box_dimensions_only('s_4932', 'S-4932 Box 12 x 12 x 6', 12, 12, 6),
            self::future_box_dimensions_only('s_4770', 'S-4770 Box 12 x 12 x 6', 12, 12, 6),
            self::future_box_dimensions_only('s_14228', 'S-14228 Box 12 x 12 x 6', 12, 12, 6),
            self::future_box_dimensions_only('s_4613', 'S-4613 Box 12 x 12 x 7', 12, 12, 7),
            self::future_box_dimensions_only('s_22637', 'S-22637 Box 12 x 12 x 7', 12, 12, 7),
            self::future_box_dimensions_only('s_18343', 'S-18343 Box 12 x 12 x 8', 12, 12, 8),
            self::future_box_dimensions_only('s_13294', 'S-13294 Box 12 x 12 x 8', 12, 12, 8),
            self::future_box_dimensions_only('s_12594', 'S-12594 Box 12 x 12 x 8', 12, 12, 8),
            self::future_box_dimensions_only('s_4337', 'S-4337 Box 12 x 12 x 9', 12, 12, 9),
            self::future_box_dimensions_only('s_21565', 'S-21565 Box 12 x 12 x 9', 12, 12, 9),
            self::future_box_dimensions_only('s_4126', 'S-4126 Box 12 x 12 x 10', 12, 12, 10),
            self::future_box_dimensions_only('s_19069', 'S-19069 Box 12 x 12 x 10', 12, 12, 10),
            self::future_box_dimensions_only('s_16456', 'S-16456 Box 12 x 12 x 10', 12, 12, 10),
            self::future_box_dimensions_only('s_15036', 'S-15036 Box 12 x 12 x 10', 12, 12, 10),
            self::future_box_dimensions_only('s_4981', 'S-4981 Box 12 x 12 x 11', 12, 12, 11),
            self::future_box_dimensions_only('s_18344', 'S-18344 Box 12 x 12 x 12', 12, 12, 12),
            self::future_box_dimensions_only('s_536', 'S-536 Box 12 x 12 x 12', 12, 12, 12),
            self::future_box_dimensions_only('s_4431', 'S-4431 Box 12 x 12 x 12', 12, 12, 12),
            self::future_box_dimensions_only('s_4475', 'S-4475 Box 12 x 12 x 12', 12, 12, 12),
            self::future_box_dimensions_only('s_18916', 'S-18916 Box 12 x 12 x 12', 12, 12, 12),
            self::future_box_dimensions_only('s_4712', 'S-4712 Box 12 x 12 x 12', 12, 12, 12),
            self::future_box_dimensions_only('s_18978', 'S-18978 Box 12 x 12 x 12', 12, 12, 12),
            self::future_box_dimensions_only('s_20485', 'S-20485 Box 12 x 12 x 13', 12, 12, 13),
            self::future_box_dimensions_only('s_4616', 'S-4616 Box 12 x 12 x 14', 12, 12, 14),
            self::future_box_dimensions_only('s_4523', 'S-4523 Box 12 x 12 x 15', 12, 12, 15),
            self::future_box_dimensions_only('s_4524', 'S-4524 Box 12 x 12 x 16', 12, 12, 16),
            self::future_box_dimensions_only('s_18919', 'S-18919 Box 12 x 12 x 16', 12, 12, 16),
            self::future_box_dimensions_only('s_14269', 'S-14269 Box 12 x 12 x 18', 12, 12, 18),
            self::future_box_dimensions_only('s_4490', 'S-4490 Box 12 x 12 x 18', 12, 12, 18),
            self::future_box_dimensions_only('s_16516', 'S-16516 Box 12 x 12 x 18', 12, 12, 18),
            self::future_box_dimensions_only('s_4467', 'S-4467 Box 12 x 12 x 20', 12, 12, 20),
            self::future_box_dimensions_only('s_4455', 'S-4455 Box 12 x 12 x 24', 12, 12, 24),
            self::future_box_dimensions_only('s_4617', 'S-4617 Box 12 x 12 x 30', 12, 12, 30),
            self::future_box_dimensions_only('s_4491', 'S-4491 Box 12 x 12 x 36', 12, 12, 36),
            self::future_box_dimensions_only('s_4618', 'S-4618 Box 12 x 12 x 40', 12, 12, 40),
            self::future_box_dimensions_only('s_4525', 'S-4525 Box 12 x 12 x 48', 12, 12, 48),
            self::future_box_dimensions_only('s_13295', 'S-13295 Box 12 x 12 x 48', 12, 12, 48),
            self::future_box_dimensions_only('s_4983', 'S-4983 Box 12 x 12 x 90', 12, 12, 90),
            self::future_box_dimensions_only('s_4713', 'S-4713 Box 12 x 12 x 52', 12, 12, 52),
            self::future_box_dimensions_only('s_4692', 'S-4692 Box 12 x 12 x 60', 12, 12, 60),
            self::future_box_dimensions_only('s_4693', 'S-4693 Box 12 x 12 x 72', 12, 12, 72),
            self::future_box_dimensions_only('s_18238', 'S-18238 Box 12 x 12 x 132', 12, 12, 132),
            self::future_box_dimensions_only('s_4935', 'S-4935 Box 12.125 x 12.125 x 13.5625', 12.125, 12.125, 13.5625),
            self::future_box_dimensions_only('s_19833', 'S-19833 Box 12.25 x 9.25 x 6', 12.25, 9.25, 6),
            self::future_box_dimensions_only('s_4955', 'S-4955 Box 12.25 x 9.25 x 9', 12.25, 9.25, 9),
            self::future_box_dimensions_only('s_14270', 'S-14270 Box 12.25 x 9.25 x 12', 12.25, 9.25, 12),
            self::future_box_dimensions_only('s_558', 'S-558 Box 12.25 x 9.25 x 12', 12.25, 9.25, 12),
            self::future_box_dimensions_only('s_18942', 'S-18942 Box 12.375 x 9.25 x 4.125', 12.375, 9.25, 4.125),
            self::future_box_dimensions_only('s_4754', 'S-4754 Box 12.5 x 12.5 x 6', 12.5, 12.5, 6),
            self::future_box_dimensions_only('s_4562', 'S-4562 Box 12.5 x 12.5 x 12', 12.5, 12.5, 12),
            self::future_box_dimensions_only('s_4936', 'S-4936 Box 12.5 x 12.5 x 15', 12.5, 12.5, 15),
            self::future_box_dimensions_only('s_4615', 'S-4615 Box 12.75 x 12.75 x 13.5', 12.75, 12.75, 13.5),
            self::future_box_dimensions_only('s_19087', 'S-19087 Box 13 x 3 x 3', 13, 3, 3),
            self::future_box_dimensions_only('s_17999', 'S-17999 Box 13 x 3 x 30', 13, 3, 30),
            self::future_box_dimensions_only('s_24051', 'S-24051 Box 13 x 5 x 5', 13, 5, 5),
            self::future_box_dimensions_only('s_22645', 'S-22645 Box 13 x 6 x 9', 13, 6, 9),
            self::future_box_dimensions_only('s_4924', 'S-4924 Box 13 x 7 x 7', 13, 7, 7),
            self::future_box_dimensions_only('s_22168', 'S-22168 Box 13 x 8 x 6', 13, 8, 6),
            self::future_box_dimensions_only('s_19088', 'S-19088 Box 13 x 8 x 8', 13, 8, 8),
            self::future_box_dimensions_only('s_18391', 'S-18391 Box 13 x 9 x 4', 13, 9, 4),
            self::future_box_dimensions_only('s_23955', 'S-23955 Box 13 x 9 x 5', 13, 9, 5),
            self::future_box_dimensions_only('s_4817', 'S-4817 Box 13 x 9 x 6', 13, 9, 6),
            self::future_box_dimensions_only('s_14271', 'S-14271 Box 13 x 9 x 7', 13, 9, 7),
            self::future_box_dimensions_only('s_21053', 'S-21053 Box 13 x 9 x 8', 13, 9, 8),
            self::future_box_dimensions_only('s_11373', 'S-11373 Box 13 x 9 x 9', 13, 9, 9),
            self::future_box_dimensions_only('s_4837', 'S-4837 Box 13 x 9 x 11', 13, 9, 11),
            self::future_box_dimensions_only('s_4715', 'S-4715 Box 13 x 10 x 2', 13, 10, 2),
            self::future_box_dimensions_only('s_24736', 'S-24736 Box 13 x 10 x 3', 13, 10, 3),
            self::future_box_dimensions_only('s_4716', 'S-4716 Box 13 x 10 x 4', 13, 10, 4),
            self::future_box_dimensions_only('s_19834', 'S-19834 Box 13 x 10 x 4', 13, 10, 4),
            self::future_box_dimensions_only('s_4854', 'S-4854 Box 13 x 10 x 5', 13, 10, 5),
            self::future_box_dimensions_only('s_13296', 'S-13296 Box 13 x 10 x 6', 13, 10, 6),
            self::future_box_dimensions_only('s_23310', 'S-23310 Box 13 x 10 x 6', 13, 10, 6),
            self::future_box_dimensions_only('s_4982', 'S-4982 Box 13 x 10 x 7', 13, 10, 7),
            self::future_box_dimensions_only('s_4838', 'S-4838 Box 13 x 10 x 8', 13, 10, 8),
            self::future_box_dimensions_only('s_22646', 'S-22646 Box 13 x 10 x 9', 13, 10, 9),
            self::future_box_dimensions_only('s_4785', 'S-4785 Box 13 x 10 x 10', 13, 10, 10),
            self::future_box_dimensions_only('s_21571', 'S-21571 Box 13 x 10 x 10', 13, 10, 10),
            self::future_box_dimensions_only('s_22169', 'S-22169 Box 13 x 10 x 12', 13, 10, 12),
            self::future_box_dimensions_only('s_19835', 'S-19835 Box 13 x 10 x 13', 13, 10, 13),
            self::future_box_dimensions_only('s_21064', 'S-21064 Box 13 x 10 x 15', 13, 10, 15),
            self::future_box_dimensions_only('s_21572', 'S-21572 Box 13 x 11 x 2', 13, 11, 2),
            self::future_box_dimensions_only('s_4131', 'S-4131 Box 13 x 11 x 5', 13, 11, 5),
            self::future_box_dimensions_only('s_24052', 'S-24052 Box 13 x 11 x 6', 13, 11, 6),
            self::future_box_dimensions_only('s_4717', 'S-4717 Box 13 x 11 x 7', 13, 11, 7),
            self::future_box_dimensions_only('s_23311', 'S-23311 Box 13 x 11 x 8', 13, 11, 8),
            self::future_box_dimensions_only('s_14272', 'S-14272 Box 13 x 11 x 9', 13, 11, 9),
            self::future_box_dimensions_only('s_4886', 'S-4886 Box 13 x 13 x 2', 13, 13, 2),
            self::future_box_dimensions_only('s_19090', 'S-19090 Box 13 x 13 x 3', 13, 13, 3),
            self::future_box_dimensions_only('s_4526', 'S-4526 Box 13 x 13 x 4', 13, 13, 4),
            self::future_box_dimensions_only('s_4887', 'S-4887 Box 13 x 13 x 5', 13, 13, 5),
            self::future_box_dimensions_only('s_13297', 'S-13297 Box 13 x 13 x 6', 13, 13, 6),
            self::future_box_dimensions_only('s_4563', 'S-4563 Box 13 x 13 x 7', 13, 13, 7),
            self::future_box_dimensions_only('s_4984', 'S-4984 Box 13 x 13 x 8', 13, 13, 8),
            self::future_box_dimensions_only('s_19089', 'S-19089 Box 13 x 13 x 9', 13, 13, 9),
            self::future_box_dimensions_only('s_4694', 'S-4694 Box 13 x 13 x 10', 13, 13, 10),
            self::future_box_dimensions_only('s_23291', 'S-23291 Box 13 x 13 x 10', 13, 13, 10),
            self::future_box_dimensions_only('s_19836', 'S-19836 Box 13 x 13 x 12', 13, 13, 12),
            self::future_box_dimensions_only('s_4317', 'S-4317 Box 13 x 13 x 13', 13, 13, 13),
            self::future_box_dimensions_only('s_19070', 'S-19070 Box 13 x 13 x 13', 13, 13, 13),
            self::future_box_dimensions_only('s_4444', 'S-4444 Box 13 x 13 x 13', 13, 13, 13),
            self::future_box_dimensions_only('s_15037', 'S-15037 Box 13 x 13 x 13', 13, 13, 13),
            self::future_box_dimensions_only('s_11374', 'S-11374 Box 13 x 13 x 15', 13, 13, 15),
            self::future_box_dimensions_only('s_18392', 'S-18392 Box 13 x 13 x 17', 13, 13, 17),
            self::future_box_dimensions_only('s_4956', 'S-4956 Box 13.25 x 10.25 x 9', 13.25, 10.25, 9),
            self::future_box_dimensions_only('s_4895', 'S-4895 Box 13.25 x 10.25 x 12', 13.25, 10.25, 12),
            self::future_box_dimensions_only('s_18943', 'S-18943 Box 13.375 x 10 x 5.375', 13.375, 10, 5.375),
            self::future_box_dimensions_only('s_4388', 'S-4388 Box 13.5 x 13.5 x 7.5', 13.5, 13.5, 7.5),
            self::future_box_dimensions_only('s_4828', 'S-4828 Box 27 x 14 x 9', 27, 14, 9),
            self::future_box_dimensions_only('s_21037', 'S-21037 Box 28 x 4 x 4', 28, 4, 4),
            self::future_box_dimensions_only('s_4805', 'S-4805 Box 28 x 4 x 24', 28, 4, 24),
            self::future_box_dimensions_only('s_4768', 'S-4768 Box 28 x 5.5 x 38', 28, 5.5, 38),
            self::future_box_dimensions_only('s_4452', 'S-4452 Box 28 x 6 x 6', 28, 6, 6),
            self::future_box_dimensions_only('s_16336', 'S-16336 Box 28 x 6 x 20', 28, 6, 20),
            self::future_box_dimensions_only('s_10659', 'S-10659 Box 28 x 6 x 52', 28, 6, 52),
            self::future_box_dimensions_only('s_4913', 'S-4913 Box 28 x 8 x 8', 28, 8, 8),
            self::future_box_dimensions_only('s_25899', 'S-25899 Box 28 x 10 x 6', 28, 10, 6),
            self::future_box_dimensions_only('s_18374', 'S-18374 Box 28 x 10 x 10', 28, 10, 10),
            self::future_box_dimensions_only('s_4946', 'S-4946 Box 28 x 12 x 6', 28, 12, 6),
            self::future_box_dimensions_only('s_16782', 'S-16782 Box 28 x 12 x 8', 28, 12, 8),
            self::future_box_dimensions_only('s_22659', 'S-22659 Box 28 x 12 x 10', 28, 12, 10),
            self::future_box_dimensions_only('s_4914', 'S-4914 Box 28 x 12 x 12', 28, 12, 12),
            self::future_box_dimensions_only('s_16783', 'S-16783 Box 28 x 14 x 14', 28, 14, 14),
            self::future_box_dimensions_only('s_23329', 'S-23329 Box 28 x 15 x 15', 28, 15, 15),
            self::future_box_dimensions_only('s_19828', 'S-19828 Box 28 x 16 x 5', 28, 16, 5),
            self::future_box_dimensions_only('s_4743', 'S-4743 Box 28 x 16 x 7', 28, 16, 7),
            self::future_box_dimensions_only('s_4915', 'S-4915 Box 28 x 16 x 10', 28, 16, 10),
            self::future_box_dimensions_only('s_4408', 'S-4408 Box 28 x 16 x 12', 28, 16, 12),
            self::future_box_dimensions_only('s_4508', 'S-4508 Box 28 x 16 x 14', 28, 16, 14),
            self::future_box_dimensions_only('s_4766', 'S-4766 Box 28 x 17 x 5', 28, 17, 5),
            self::future_box_dimensions_only('s_19829', 'S-19829 Box 28 x 18 x 6', 28, 18, 6),
            self::future_box_dimensions_only('s_18375', 'S-18375 Box 28 x 18 x 8', 28, 18, 8),
            self::future_box_dimensions_only('s_20539', 'S-20539 Box 28 x 18 x 10', 28, 18, 10),
            self::future_box_dimensions_only('s_11383', 'S-11383 Box 28 x 18 x 12', 28, 18, 12),
            self::future_box_dimensions_only('s_26642', 'S-26642 Box 28 x 18 x 16', 28, 18, 16),
            self::future_box_dimensions_only('s_4962', 'S-4962 Box 28 x 18 x 18', 28, 18, 18),
            self::future_box_dimensions_only('s_18168', 'S-18168 Box 28 x 18 x 18', 28, 18, 18),
            self::future_box_dimensions_only('s_25900', 'S-25900 Box 28 x 20 x 6', 28, 20, 6),
            self::future_box_dimensions_only('s_22183', 'S-22183 Box 28 x 20 x 10', 28, 20, 10),
            self::future_box_dimensions_only('s_12611', 'S-12611 Box 28 x 20 x 12', 28, 20, 12),
            self::future_box_dimensions_only('s_16784', 'S-16784 Box 28 x 20 x 20', 28, 20, 20),
            self::future_box_dimensions_only('s_4806', 'S-4806 Box 28 x 20 x 24', 28, 20, 24),
            self::future_box_dimensions_only('s_4665', 'S-4665 Box 28 x 20 x 25', 28, 20, 25),
            self::future_box_dimensions_only('s_4459', 'S-4459 Box 28 x 24 x 6', 28, 24, 6),
            self::future_box_dimensions_only('s_22660', 'S-22660 Box 28 x 24 x 12', 28, 24, 12),
            self::future_box_dimensions_only('s_16785', 'S-16785 Box 28 x 24 x 20', 28, 24, 20),
            self::future_box_dimensions_only('s_4829', 'S-4829 Box 28 x 28 x 6', 28, 28, 6),
            self::future_box_dimensions_only('s_20543', 'S-20543 Box 28 x 28 x 8', 28, 28, 8),
            self::future_box_dimensions_only('s_19086', 'S-19086 Box 28 x 28 x 10', 28, 28, 10),
            self::future_box_dimensions_only('s_4666', 'S-4666 Box 28 x 28 x 12', 28, 28, 12),
            self::future_box_dimensions_only('s_21541', 'S-21541 Box 28 x 28 x 12', 28, 28, 12),
            self::future_box_dimensions_only('s_4866', 'S-4866 Box 28 x 28 x 20', 28, 28, 20),
            self::future_box_dimensions_only('s_4433', 'S-4433 Box 28 x 28 x 28', 28, 28, 28),
            self::future_box_dimensions_only('s_16461', 'S-16461 Box 28 x 28 x 28', 28, 28, 28),
            self::future_box_dimensions_only('s_14284', 'S-14284 Box 29 x 17 x 3', 29, 17, 3),
            self::future_box_dimensions_only('s_11384', 'S-11384 Box 29 x 17 x 5', 29, 17, 5),
            self::future_box_dimensions_only('s_13328', 'S-13328 Box 29 x 17 x 7', 29, 17, 7),
            self::future_box_dimensions_only('s_4807', 'S-4807 Box 29 x 17 x 9', 29, 17, 9),
            self::future_box_dimensions_only('s_14285', 'S-14285 Box 29 x 17 x 9', 29, 17, 9),
            self::future_box_dimensions_only('s_4808', 'S-4808 Box 29 x 17 x 12', 29, 17, 12),
            self::future_box_dimensions_only('s_4701', 'S-4701 Box 29 x 17 x 15', 29, 17, 15),
            self::future_box_dimensions_only('s_4767', 'S-4767 Box 29 x 17 x 20', 29, 17, 20),
            self::future_box_dimensions_only('s_4832', 'S-4832 Box 30 x 3.5 x 40', 30, 3.5, 40),
            self::future_box_dimensions_only('s_4553', 'S-4553 Box 30 x 5 x 24', 30, 5, 24),
            self::future_box_dimensions_only('s_4744', 'S-4744 Box 30 x 6 x 6', 30, 6, 6),
            self::future_box_dimensions_only('s_18320', 'S-18320 Box 30 x 6 x 24', 30, 6, 24),
            self::future_box_dimensions_only('s_12612', 'S-12612 Box 30 x 6 x 44', 30, 6, 44),
            self::future_box_dimensions_only('s_10660', 'S-10660 Box 30 x 6 x 30', 30, 6, 30),
            self::future_box_dimensions_only('s_18321', 'S-18321 Box 30 x 6 x 40', 30, 6, 40),
            self::future_box_dimensions_only('s_18377', 'S-18377 Box 30 x 8 x 8', 30, 8, 8),
            self::future_box_dimensions_only('s_4939', 'S-4939 Box 30 x 10 x 10', 30, 10, 10),
            self::future_box_dimensions_only('s_18378', 'S-18378 Box 30 x 12 x 4', 30, 12, 4),
            self::future_box_dimensions_only('s_21051', 'S-21051 Box 30 x 12 x 6', 30, 12, 6),
            self::future_box_dimensions_only('s_16774', 'S-16774 Box 30 x 12 x 12', 30, 12, 12),
            self::future_box_dimensions_only('s_23964', 'S-23964 Box 30 x 12 x 12', 30, 12, 12),
            self::future_box_dimensions_only('s_13329', 'S-13329 Box 30 x 13 x 13', 30, 13, 13),
            self::future_box_dimensions_only('s_4809', 'S-4809 Box 30 x 14 x 7', 30, 14, 7),
            self::future_box_dimensions_only('s_4403', 'S-4403 Box 30 x 14 x 10', 30, 14, 10),
            self::future_box_dimensions_only('s_4551', 'S-4551 Box 30 x 15 x 15', 30, 15, 15),
            self::future_box_dimensions_only('s_21542', 'S-21542 Box 30 x 15 x 15', 30, 15, 15),
            self::future_box_dimensions_only('s_4404', 'S-4404 Box 30 x 17 x 16', 30, 17, 16),
            self::future_box_dimensions_only('s_18982', 'S-18982 Box 30 x 17 x 16', 30, 17, 16),
            self::future_box_dimensions_only('s_4964', 'S-4964 Box 30 x 17 x 17', 30, 17, 17),
            self::future_box_dimensions_only('s_21052', 'S-21052 Box 30 x 18 x 16', 30, 18, 16),
            self::future_box_dimensions_only('s_11385', 'S-11385 Box 30 x 18 x 18', 30, 18, 18),
            self::future_box_dimensions_only('s_21039', 'S-21039 Box 30 x 18 x 18', 30, 18, 18),
            self::future_box_dimensions_only('s_23995', 'S-23995 Box 30 x 20 x 4', 30, 20, 4),
            self::future_box_dimensions_only('s_11386', 'S-11386 Box 30 x 20 x 6', 30, 20, 6),
            self::future_box_dimensions_only('s_19091', 'S-19091 Box 30 x 20 x 8', 30, 20, 8),
            self::future_box_dimensions_only('s_4667', 'S-4667 Box 30 x 20 x 10', 30, 20, 10),
            self::future_box_dimensions_only('s_4928', 'S-4928 Box 30 x 20 x 12', 30, 20, 12),
            self::future_box_dimensions_only('s_15081', 'S-15081 Box 30 x 20 x 18', 30, 20, 18),
            self::future_box_dimensions_only('s_4229', 'S-4229 Box 30 x 20 x 20', 30, 20, 20),
            self::future_box_dimensions_only('s_4001', 'S-4001 Box 30 x 20 x 20', 30, 20, 20),
            self::future_box_dimensions_only('s_4775', 'S-4775 Box 30 x 20 x 20', 30, 20, 20),
            self::future_box_dimensions_only('s_22695', 'S-22695 Box 30 x 24 x 6', 30, 24, 6),
            self::future_box_dimensions_only('s_13330', 'S-13330 Box 30 x 24 x 10', 30, 24, 10),
            self::future_box_dimensions_only('s_4417', 'S-4417 Box 30 x 24 x 12', 30, 24, 12),
            self::future_box_dimensions_only('s_11248', 'S-11248 Box 30 x 24 x 12', 30, 24, 12),
            self::future_box_dimensions_only('s_12613', 'S-12613 Box 30 x 24 x 12', 30, 24, 12),
            self::future_box_dimensions_only('s_4961', 'S-4961 Box 30 x 24 x 20', 30, 24, 20),
            self::future_box_dimensions_only('s_4509', 'S-4509 Box 30 x 24 x 24', 30, 24, 24),
            self::future_box_dimensions_only('s_16775', 'S-16775 Box 30 x 26 x 24', 30, 26, 24),
            self::future_box_dimensions_only('s_14301', 'S-14301 Box 30 x 30 x 6', 30, 30, 6),
            self::future_box_dimensions_only('s_4845', 'S-4845 Box 30 x 30 x 8', 30, 30, 8),
            self::future_box_dimensions_only('s_15082', 'S-15082 Box 30 x 30 x 10', 30, 30, 10),
            self::future_box_dimensions_only('s_10661', 'S-10661 Box 30 x 30 x 12', 30, 30, 12),
            self::future_box_dimensions_only('s_20414', 'S-20414 Box 30 x 30 x 12', 30, 30, 12),
            self::future_box_dimensions_only('s_4668', 'S-4668 Box 30 x 30 x 16', 30, 30, 16),
            self::future_box_dimensions_only('s_20415', 'S-20415 Box 30 x 30 x 16', 30, 30, 16),
            self::future_box_dimensions_only('s_16776', 'S-16776 Box 30 x 30 x 20', 30, 30, 20),
            self::future_box_dimensions_only('s_21034', 'S-21034 Box 30 x 30 x 20', 30, 30, 20),
            self::future_box_dimensions_only('s_4366', 'S-4366 Box 30 x 30 x 30', 30, 30, 30),
            self::future_box_dimensions_only('s_4867', 'S-4867 Box 30 x 30 x 30', 30, 30, 30),
            self::future_box_dimensions_only('s_11301', 'S-11301 Box 30 x 30 x 30', 30, 30, 30),
            self::future_box_dimensions_only('s_23297', 'S-23297 Box 30 x 30 x 74', 30, 30, 74),
            self::future_box_dimensions_only('s_4746', 'S-4746 Box 31 x 16 x 9', 31, 16, 9),
            self::future_box_dimensions_only('s_4669', 'S-4669 Box 32 x 6 x 6', 32, 6, 6),
            self::future_box_dimensions_only('s_4810', 'S-4810 Box 32 x 8 x 8', 32, 8, 8),
            self::future_box_dimensions_only('s_4304', 'S-4304 Box 32 x 10 x 6.5', 32, 10, 6.5),
            self::future_box_dimensions_only('s_18379', 'S-18379 Box 32 x 10 x 10', 32, 10, 10),
            self::future_box_dimensions_only('s_19092', 'S-19092 Box 32 x 12 x 10', 32, 12, 10),
            self::future_box_dimensions_only('s_4868', 'S-4868 Box 32 x 12 x 12', 32, 12, 12),
            self::future_box_dimensions_only('s_16777', 'S-16777 Box 32 x 16 x 16', 32, 16, 16),
            self::future_box_dimensions_only('s_19827', 'S-19827 Box 32 x 18 x 8', 32, 18, 8),
            self::future_box_dimensions_only('s_10662', 'S-10662 Box 32 x 18 x 12', 32, 18, 12),
            self::future_box_dimensions_only('s_18380', 'S-18380 Box 32 x 18 x 18', 32, 18, 18),
            self::future_box_dimensions_only('s_15083', 'S-15083 Box 32 x 24 x 24', 32, 24, 24),
            self::future_box_dimensions_only('s_4453', 'S-4453 Box 32 x 24 x 24', 32, 24, 24),
            self::future_box_dimensions_only('s_11257', 'S-11257 Box 32 x 24 x 24', 32, 24, 24),
            self::future_box_dimensions_only('s_20544', 'S-20544 Box 32 x 32 x 8', 32, 32, 8),
            self::future_box_dimensions_only('s_16778', 'S-16778 Box 32 x 32 x 12', 32, 32, 12),
            self::future_box_dimensions_only('s_4434', 'S-4434 Box 32 x 32 x 32', 32, 32, 32),
            self::future_box_dimensions_only('s_16462', 'S-16462 Box 32 x 32 x 32', 32, 32, 32),
            self::future_box_dimensions_only('s_14258', 'S-14258 Box 33 x 8.5 x 5', 33, 8.5, 5),
            self::future_box_dimensions_only('s_4670', 'S-4670 Box 34 x 10 x 6', 34, 10, 6),
            self::future_box_dimensions_only('s_4846', 'S-4846 Box 34 x 21 x 6', 34, 21, 6),
            self::future_box_dimensions_only('s_4671', 'S-4671 Box 36 x 4 x 4', 36, 4, 4),
            self::future_box_dimensions_only('s_4947', 'S-4947 Box 36 x 5 x 24', 36, 5, 24),
            self::future_box_dimensions_only('s_4554', 'S-4554 Box 36 x 5 x 30', 36, 5, 30),
            self::future_box_dimensions_only('s_4680', 'S-4680 Box 36 x 5.5 x 48', 36, 5.5, 48),
            self::future_box_dimensions_only('s_4367', 'S-4367 Box 36 x 6 x 6', 36, 6, 6),
            self::future_box_dimensions_only('s_10663', 'S-10663 Box 36 x 6 x 36', 36, 6, 36),
            self::future_box_dimensions_only('s_4679', 'S-4679 Box 36 x 6 x 42', 36, 6, 42),
            self::future_box_dimensions_only('s_18427', 'S-18427 Box 36 x 8 x 4', 36, 8, 4),
            self::future_box_dimensions_only('s_4361', 'S-4361 Box 36 x 8 x 8', 36, 8, 8),
            self::future_box_dimensions_only('s_18928', 'S-18928 Box 36 x 8 x 8', 36, 8, 8),
            self::future_box_dimensions_only('s_11212', 'S-11212 Box 36 x 8 x 30', 36, 8, 30),
            self::future_box_dimensions_only('s_25904', 'S-25904 Box 36 x 10 x 4', 36, 10, 4),
            self::future_box_dimensions_only('s_18428', 'S-18428 Box 36 x 10 x 6', 36, 10, 6),
            self::future_box_dimensions_only('s_4672', 'S-4672 Box 36 x 10 x 10', 36, 10, 10),
            self::future_box_dimensions_only('s_4830', 'S-4830 Box 36 x 12 x 4', 36, 12, 4),
            self::future_box_dimensions_only('s_14302', 'S-14302 Box 36 x 12 x 6', 36, 12, 6),
            self::future_box_dimensions_only('s_21566', 'S-21566 Box 36 x 12 x 8', 36, 12, 8),
            self::future_box_dimensions_only('s_4673', 'S-4673 Box 36 x 12 x 10', 36, 12, 10),
            self::future_box_dimensions_only('s_4418', 'S-4418 Box 36 x 12 x 12', 36, 12, 12),
            self::future_box_dimensions_only('s_11258', 'S-11258 Box 36 x 12 x 12', 36, 12, 12),
            self::future_box_dimensions_only('s_21066', 'S-21066 Box 36 x 12 x 16', 36, 12, 16),
            self::future_box_dimensions_only('s_21567', 'S-21567 Box 36 x 14 x 6', 36, 14, 6),
            self::future_box_dimensions_only('s_4232', 'S-4232 Box 36 x 14 x 10', 36, 14, 10),
            self::future_box_dimensions_only('s_21035', 'S-21035 Box 36 x 14 x 14', 36, 14, 14),
            self::future_box_dimensions_only('s_16779', 'S-16779 Box 36 x 16 x 5', 36, 16, 5),
            self::future_box_dimensions_only('s_22699', 'S-22699 Box 36 x 16 x 7', 36, 16, 7),
            self::future_box_dimensions_only('s_4405', 'S-4405 Box 36 x 16 x 16', 36, 16, 16),
            self::future_box_dimensions_only('s_16463', 'S-16463 Box 36 x 16 x 16', 36, 16, 16),
            self::future_box_dimensions_only('s_15084', 'S-15084 Box 36 x 18 x 6', 36, 18, 6),
            self::future_box_dimensions_only('s_4702', 'S-4702 Box 36 x 18 x 12', 36, 18, 12),
            self::future_box_dimensions_only('s_19775', 'S-19775 Box 36 x 18 x 12', 36, 18, 12),
            self::future_box_dimensions_only('s_13332', 'S-13332 Box 36 x 18 x 18', 36, 18, 18),
            self::future_box_dimensions_only('s_18169', 'S-18169 Box 36 x 18 x 18', 36, 18, 18),
            self::future_box_dimensions_only('s_18429', 'S-18429 Box 36 x 20 x 9', 36, 20, 9),
            self::future_box_dimensions_only('s_12771', 'S-12771 Box 36 x 20 x 12', 36, 20, 12),
            self::future_box_dimensions_only('s_4769', 'S-4769 Box 36 x 20 x 15', 36, 20, 15),
            self::future_box_dimensions_only('s_4965', 'S-4965 Box 36 x 21 x 10', 36, 21, 10),
            self::future_box_dimensions_only('s_4368', 'S-4368 Box 36 x 21 x 20', 36, 21, 20),
            self::future_box_dimensions_only('s_4682', 'S-4682 Box 36 x 22 x 22', 36, 22, 22),
            self::future_box_dimensions_only('s_4747', 'S-4747 Box 36 x 24 x 4', 36, 24, 4),
            self::future_box_dimensions_only('s_4811', 'S-4811 Box 36 x 24 x 6', 36, 24, 6),
            self::future_box_dimensions_only('s_4917', 'S-4917 Box 36 x 24 x 8', 36, 24, 8),
            self::future_box_dimensions_only('s_21543', 'S-21543 Box 36 x 24 x 8', 36, 24, 8),
            self::future_box_dimensions_only('s_19194', 'S-19194 Box 36 x 24 x 10', 36, 24, 10),
            self::future_box_dimensions_only('s_4454', 'S-4454 Box 36 x 24 x 12', 36, 24, 12),
            self::future_box_dimensions_only('s_14234', 'S-14234 Box 36 x 24 x 12', 36, 24, 12),
            self::future_box_dimensions_only('s_16765', 'S-16765 Box 36 x 24 x 18', 36, 24, 18),
            self::future_box_dimensions_only('s_22119', 'S-22119 Box 36 x 24 x 18', 36, 24, 18),
            self::future_box_dimensions_only('s_4192', 'S-4192 Box 36 x 24 x 20', 36, 24, 20),
            self::future_box_dimensions_only('s_4674', 'S-4674 Box 36 x 24 x 24', 36, 24, 24),
            self::future_box_dimensions_only('s_13333', 'S-13333 Box 36 x 24 x 24', 36, 24, 24),
            self::future_box_dimensions_only('s_19870', 'S-19870 Box 36 x 30 x 12', 36, 30, 12),
            self::future_box_dimensions_only('s_4510', 'S-4510 Box 36 x 36 x 12', 36, 36, 12),
            self::future_box_dimensions_only('s_18170', 'S-18170 Box 36 x 36 x 12', 36, 36, 12),
            self::future_box_dimensions_only('s_4457', 'S-4457 Box 36 x 36 x 18', 36, 36, 18),
            self::future_box_dimensions_only('s_22594', 'S-22594 Box 36 x 36 x 18', 36, 36, 18),
            self::future_box_dimensions_only('s_4478', 'S-4478 Box 36 x 36 x 24', 36, 36, 24),
            self::future_box_dimensions_only('s_4681', 'S-4681 Box 36 x 36 x 24', 36, 36, 24),
            self::future_box_dimensions_only('s_15061', 'S-15061 Box 36 x 36 x 24', 36, 36, 24),
            self::future_box_dimensions_only('s_4373', 'S-4373 Box 36 x 36 x 36', 36, 36, 36),
            self::future_box_dimensions_only('s_4966', 'S-4966 Box 36 x 36 x 36', 36, 36, 36),
            self::future_box_dimensions_only('s_11300', 'S-11300 Box 36 x 36 x 36', 36, 36, 36),
            self::future_box_dimensions_only('s_11300b', 'S-11300B Box 36 x 36 x 36', 36, 36, 36),
            self::future_box_dimensions_only('s_15109', 'S-15109 Box 36 x 36 x 76', 36, 36, 76),
            self::future_box_dimensions_only('s_4778', 'S-4778 Box 37 x 4 x 60', 37, 4, 60),
            self::future_box_dimensions_only('s_15214', 'S-15214 Box 38 x 8 x 26', 38, 8, 26),
            self::future_box_dimensions_only('s_10664', 'S-10664 Box 39 x 8 x 75', 39, 8, 75),
            self::future_box_dimensions_only('s_4929', 'S-4929 Box 40 x 5 x 45', 40, 5, 45),
            self::future_box_dimensions_only('s_18430', 'S-18430 Box 40 x 6 x 6', 40, 6, 6),
            self::future_box_dimensions_only('s_4748', 'S-4748 Box 40 x 8 x 8', 40, 8, 8),
            self::future_box_dimensions_only('s_11213', 'S-11213 Box 40 x 8 x 50', 40, 8, 50),
            self::future_box_dimensions_only('s_19195', 'S-19195 Box 40 x 10 x 10', 40, 10, 10),
            self::future_box_dimensions_only('s_10665', 'S-10665 Box 40 x 12 x 12', 40, 12, 12),
            self::future_box_dimensions_only('s_21544', 'S-21544 Box 40 x 12 x 12', 40, 12, 12),
            self::future_box_dimensions_only('s_21033', 'S-21033 Box 40 x 14 x 14', 40, 14, 14),
            self::future_box_dimensions_only('s_12772', 'S-12772 Box 40 x 18 x 8', 40, 18, 8),
            self::future_box_dimensions_only('s_23336', 'S-23336 Box 40 x 20 x 10', 40, 20, 10),
            self::future_box_dimensions_only('s_15110', 'S-15110 Box 40 x 20 x 20', 40, 20, 20),
            self::future_box_dimensions_only('s_18929', 'S-18929 Box 40 x 20 x 20', 40, 20, 20),
            self::future_box_dimensions_only('s_23337', 'S-23337 Box 40 x 24 x 12', 40, 24, 12),
            self::future_box_dimensions_only('s_16766', 'S-16766 Box 40 x 30 x 30', 40, 30, 30),
            self::future_box_dimensions_only('s_19776', 'S-19776 Box 40 x 30 x 30', 40, 30, 30),
            self::future_box_dimensions_only('s_4967', 'S-4967 Box 40 x 30 x 30', 40, 30, 30),
            self::future_box_dimensions_only('s_4967b', 'S-4967B Box 40 x 30 x 30', 40, 30, 30),
            self::future_box_dimensions_only('s_18973', 'S-18973 Box 40 x 40 x 40', 40, 40, 40),
            self::future_box_dimensions_only('s_18973b', 'S-18973B Box 40 x 40 x 40', 40, 40, 40),
            self::future_box_dimensions_only('s_4683', 'S-4683 Box 41 x 28.75 x 25.5', 41, 28.75, 25.5),
            self::future_box_dimensions_only('s_4847', 'S-4847 Box 42 x 11 x 6', 42, 11, 6),
            self::future_box_dimensions_only('s_11214', 'S-11214 Box 44 x 6 x 35', 44, 6, 35),
            self::future_box_dimensions_only('s_16769', 'S-16769 Box 44 x 12 x 12', 44, 12, 12),
            self::future_box_dimensions_only('s_13976', 'S-13976 Box 46 x 8 x 30', 46, 8, 30),
            self::future_box_dimensions_only('s_16770', 'S-16770 Box 46 x 20 x 12', 46, 20, 12),
            self::future_box_dimensions_only('s_14247', 'S-14247 Box 46 x 38 x 24', 46, 38, 24),
            self::future_box_dimensions_only('s_12707', 'S-12707 Box 46 x 38 x 36', 46, 38, 36),
            self::future_box_dimensions_only('s_4675', 'S-4675 Box 48 x 4 x 4', 48, 4, 4),
            self::future_box_dimensions_only('s_4940', 'S-4940 Box 48 x 6 x 6', 48, 6, 6),
            self::future_box_dimensions_only('s_11251', 'S-11251 Box 48 x 6 x 72', 48, 6, 72),
            self::future_box_dimensions_only('s_4574', 'S-4574 Box 48 x 8 x 8', 48, 8, 8),
            self::future_box_dimensions_only('s_12773', 'S-12773 Box 48 x 8 x 24', 48, 8, 24),
            self::future_box_dimensions_only('s_10666', 'S-10666 Box 48 x 10 x 10', 48, 10, 10),
            self::future_box_dimensions_only('s_19096', 'S-19096 Box 48 x 12 x 6', 48, 12, 6),
            self::future_box_dimensions_only('s_4419', 'S-4419 Box 48 x 12 x 12', 48, 12, 12),
            self::future_box_dimensions_only('s_4941', 'S-4941 Box 48 x 12 x 12', 48, 12, 12),
            self::future_box_dimensions_only('s_16464', 'S-16464 Box 48 x 12 x 12', 48, 12, 12),
            self::future_box_dimensions_only('s_13334', 'S-13334 Box 48 x 16 x 16', 48, 16, 16),
            self::future_box_dimensions_only('s_20416', 'S-20416 Box 48 x 16 x 16', 48, 16, 16),
            self::future_box_dimensions_only('s_22595', 'S-22595 Box 48 x 20 x 20', 48, 20, 20),
            self::future_box_dimensions_only('s_19831', 'S-19831 Box 48 x 24 x 8', 48, 24, 8),
            self::future_box_dimensions_only('s_4848', 'S-4848 Box 48 x 24 x 12', 48, 24, 12),
            self::future_box_dimensions_only('s_15062', 'S-15062 Box 48 x 24 x 12', 48, 24, 12),
            self::future_box_dimensions_only('s_4749', 'S-4749 Box 48 x 24 x 24', 48, 24, 24),
            self::future_box_dimensions_only('s_14235', 'S-14235 Box 48 x 24 x 24', 48, 24, 24),
            self::future_box_dimensions_only('s_18974', 'S-18974 Box 48 x 24 x 28', 48, 24, 28),
            self::future_box_dimensions_only('s_18974b', 'S-18974B Box 48 x 24 x 28', 48, 24, 28),
            self::future_box_dimensions_only('s_18935', 'S-18935 Box 48 x 40 x 24', 48, 40, 24),
            self::future_box_dimensions_only('s_18935b', 'S-18935B Box 48 x 40 x 24', 48, 40, 24),
            self::future_box_dimensions_only('s_20427', 'S-20427 Box 48 x 40 x 24', 48, 40, 24),
            self::future_box_dimensions_only('s_20427b', 'S-20427B Box 48 x 40 x 24', 48, 40, 24),
            self::future_box_dimensions_only('s_4480', 'S-4480 Box 48 x 40 x 36', 48, 40, 36),
            self::future_box_dimensions_only('s_4480b', 'S-4480B Box 48 x 40 x 36', 48, 40, 36),
            self::future_box_dimensions_only('s_4812', 'S-4812 Box 48 x 40 x 36', 48, 40, 36),
            self::future_box_dimensions_only('s_4931', 'S-4931 Box 48 x 40 x 36', 48, 40, 36),
            self::future_box_dimensions_only('s_4931b', 'S-4931B Box 48 x 40 x 36', 48, 40, 36),
            self::future_box_dimensions_only('s_16338', 'S-16338 Box 48 x 40 x 36', 48, 40, 36),
            self::future_box_dimensions_only('s_4479', 'S-4479 Box 48 x 40 x 68', 48, 40, 68),
            self::future_box_dimensions_only('s_22574', 'S-22574 Box 48 x 40 x 48', 48, 40, 48),
            self::future_box_dimensions_only('s_22574b', 'S-22574B Box 48 x 40 x 48', 48, 40, 48),
            self::future_box_dimensions_only('s_17996', 'S-17996 Box 48 x 40 x 48', 48, 40, 48),
            self::future_box_dimensions_only('s_17996b', 'S-17996B Box 48 x 40 x 48', 48, 40, 48),
            self::future_box_dimensions_only('s_16337', 'S-16337 Box 48 x 48 x 36', 48, 48, 36),
            self::future_box_dimensions_only('s_16337b', 'S-16337B Box 48 x 48 x 36', 48, 48, 36),
            self::future_box_dimensions_only('s_14246', 'S-14246 Box 48 x 48 x 48', 48, 48, 48),
            self::future_box_dimensions_only('s_14246b', 'S-14246B Box 48 x 48 x 48', 48, 48, 48),
            self::future_box_dimensions_only('s_18335', 'S-18335 Box 50 x 12 x 12', 50, 12, 12),
            self::future_box_dimensions_only('s_4878', 'S-4878 Box 54 x 8 x 28', 54, 8, 28),
            self::future_box_dimensions_only('s_10667', 'S-10667 Box 54 x 8 x 75', 54, 8, 75),
            self::future_box_dimensions_only('s_13977', 'S-13977 Box 56 x 8 x 36', 56, 8, 36),
            self::future_box_dimensions_only('s_15111', 'S-15111 Box 56 x 10 x 32', 56, 10, 32),
            self::future_box_dimensions_only('s_4684', 'S-4684 Box 58 x 41 x 45', 58, 41, 45),
            self::future_box_dimensions_only('s_21568', 'S-21568 Box 60 x 6 x 6', 60, 6, 6),
            self::future_box_dimensions_only('s_20536', 'S-20536 Box 60 x 8 x 8', 60, 8, 8),
            self::future_box_dimensions_only('s_21569', 'S-21569 Box 60 x 10 x 10', 60, 10, 10),
            self::future_box_dimensions_only('s_19097', 'S-19097 Box 60 x 12 x 12', 60, 12, 12),
            self::future_box_dimensions_only('s_20417', 'S-20417 Box 60 x 12 x 12', 60, 12, 12),
            self::future_box_dimensions_only('s_22598', 'S-22598 Box 60 x 24 x 24', 60, 24, 24),
            self::future_box_dimensions_only('s_23296', 'S-23296 Box 60 x 24 x 24', 60, 24, 24),
            self::future_box_dimensions_only('s_15215', 'S-15215 Box 64 x 8 x 40', 64, 8, 40),
            self::future_box_dimensions_only('s_19761', 'S-19761 Box 70 x 8 x 42', 70, 8, 42),
            self::future_box_dimensions_only('s_10668', 'S-10668 Box 81 x 11 x 80', 81, 11, 80),
            self::future_box_dimensions_only('s_23318', 'S-23318 Box 82 x 8 x 50', 82, 8, 50),
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
     * @param array<int,array<string,mixed>> $saved
     * @return array<int,array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string,max_weight_oz:string}>
     */
    private static function merge_builtin_future_package_presets(array $saved): array
    {
        $out = [];
        foreach (array_merge(self::default_future_package_presets(), $saved) as $preset) {
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
            'packing_slip_format' => self::choice(strtolower((string) ($settings['packing_slip_format'] ?? 'pdf')), ['pdf', 'zpl'], 'pdf'),
            'confirmation' => self::choice(
                strtolower((string) ($settings['confirmation'] ?? 'delivery')),
                ['none', 'delivery', 'signature', 'adult_signature', 'direct_signature'],
                'delivery'
            ),
            'insurance_mode' => self::choice((string) ($settings['insurance_mode'] ?? 'none'), ['none', 'declared_value'], 'none'),
            'after_purchase_status' => self::text($settings['after_purchase_status'] ?? ''),
            'show_debug_fields' => !empty($settings['show_debug_fields']) ? '1' : '0',
            'exclude_globalpost' => !empty($settings['exclude_globalpost']) ? '1' : '0',
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
     * Dimension-only audit box for Uline rows where the pasted source includes
     * price tiers but not empty package weight or rated load. The packing
     * service treats blank max weight as its carrier-default capacity.
     *
     * @return array{id:string,name:string,kind:string,package_code:string,length:string,width:string,height:string,weight_oz:string,max_weight_oz:string}
     */
    private static function future_box_dimensions_only(
        string $id,
        string $name,
        float $length,
        float $width,
        float $height
    ): array {
        return [
            'id' => sanitize_key($id),
            'name' => $name,
            'kind' => 'box',
            'package_code' => 'package',
            'length' => self::decimal_text((string) $length),
            'width' => self::decimal_text((string) $width),
            'height' => self::decimal_text((string) $height),
            'weight_oz' => '',
            'max_weight_oz' => '',
        ];
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
