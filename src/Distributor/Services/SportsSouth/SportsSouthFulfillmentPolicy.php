<?php

namespace FFLHub\Distributor\Services\SportsSouth;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sports South does not expose a clean drop-ship flag in DailyItemUpdate.
 * This policy mirrors the fulfillment restriction list provided by Sports South.
 */
final class SportsSouthFulfillmentPolicy
{
    private const BLOCK_REASON_NO_FULFILLMENT = 'manufacturer_policy=no_fulfillment';
    private const BLOCK_REASON_FACTORY_APPROVAL = 'manufacturer_policy=factory_approval_required';
    private const BLOCK_REASON_SIZE_WEIGHT = 'sports_south_size_weight_restricted';
    private const BLOCK_REASON_NFA_OR_SOT = 'nfa_or_sot';

    /**
     * Manufacturer lines Sports South says cannot process through fulfillment.
     *
     * @var string[]
     */
    private const NO_FULFILLMENT_MANUFACTURERS = [
        'BERETTAUSA',
        'FN',
        'FNAMERICA',
        'FNH',
        'FNHUSA',
        'GLOCK',
        'GLOCKINC',
        'HK',
        'HECKLERKOCH',
        'HECKLERANDKOCH',
        'IWIUSA',
        'MOULTRIE',
        'OSIGHT',
        'OLIGHT',
        'OUTDOOREDGE',
        'RUGER',
        'STURMRUGER',
        'STURMRUGERCO',
        'SMITHWESSON',
        'SPRINGFIELD',
        'SPRINGFIELDARMORY',
        'TIKKA',
        'THERMACELL',
        'TCFIREARMS',
        'THOMPSONCENTER',
        'THOMPSONCENTERARMS',
        'TRIJICONELECTROOPTICS',
        'WALTHERARMS',
        'WALTHER',
        'GARMIN',
        'FOXPROINC',
        'FOXPRO',
        'GUMCREEKCUSTOMS',
        'P80KITS',
        'P80',
        'POLYMER80',
        'HEAVYMETALHEVISHOT',
        'HEVISHOT',
    ];

    private const FACTORY_SETTING_PREFIX = 'factory_approval_';

    /**
     * Manufacturer lines requiring factory approval before fulfillment.
     * SIG SAUER uses the existing shared Sig Approved setting instead of a
     * second Sports South-specific checkbox.
     *
     * @var array<string,array{label:string,aliases:string[]}>
     */
    private const FACTORY_APPROVAL_GROUPS = [
        '1791_leather' => [
            'label' => '1791 Leather',
            'aliases' => ['1791LEATHER', '1791GUNLEATHER', '1791GUNLEATHERLLC'],
        ],
        'iray_usa' => [
            'label' => 'IRAY USA',
            'aliases' => ['IRAYUSA', 'IRAY', 'INFIRAY', 'INFIRAYOUTDOOR'],
        ],
        'leupold' => [
            'label' => 'Leupold',
            'aliases' => ['LEUPOLD', 'LEUPOLDSTEVENS', 'LEUPOLDANDSTEVENS'],
        ],
        'sig_sauer' => [
            'label' => 'SIG SAUER',
            'aliases' => ['SIG', 'SIGSAUER', 'SIGARMS'],
        ],
        'trijicon' => [
            'label' => 'Trijicon',
            'aliases' => ['TRIJICON'],
        ],
        'wise_foods' => [
            'label' => 'Wise Foods',
            'aliases' => ['WISEFOODS', 'WISEFOOD', 'READYWISE'],
        ],
        'columbia_river_knife_tool' => [
            'label' => 'Columbia River Knife & Tool',
            'aliases' => ['COLUMBIARIVERKNIFETOOL', 'COLUMBIARIVERKNIFEANDTOOL', 'CRKT'],
        ],
        'surefire_laser_products' => [
            'label' => 'SureFire Laser Products',
            'aliases' => ['SUREFIRELASERPRODUCTS', 'SUREFIRELASEPRODUCTS', 'SUREFIRE'],
        ],
        'hogue_grips' => [
            'label' => 'Hogue Grips',
            'aliases' => ['HOGUEGRIPS', 'HOGUEINC', 'HOGUE'],
        ],
        'retay_usa' => [
            'label' => 'Retay USA',
            'aliases' => ['RETAYUSA', 'RETAY'],
        ],
        'alien_gear_holsters' => [
            'label' => 'Alien Gear Holsters',
            'aliases' => ['ALIENGEARHOLSTERS', 'ALIENGEAR'],
        ],
        'pelican_storm_cases' => [
            'label' => 'Pelican Storm Cases',
            'aliases' => ['PELICANSTORMCASES', 'PELICANSTORM', 'PELICAN'],
        ],
        'holosun_technologies' => [
            'label' => 'Holosun Technologies INC',
            'aliases' => ['HOLOSUNTECHNOLOGIESINC', 'HOLOSONTECHNOLOGIESINC', 'HOLOSUNTECHNOLOGIES', 'HOLOSONTECHNOLOGIES', 'HOLOSUN', 'HOLOSON'],
        ],
    ];

    /**
     * Sports South item numbers blocked by weight/size restriction.
     *
     * @var array<string,bool>
     */
    private const SIZE_WEIGHT_RESTRICTED_ITEMS = [
        '22872' => true,
        '53980' => true,
        '38768' => true,
        '135573' => true,
    ];

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function apply_to_row(array $row): array
    {
        if (SigDropshipApproval::row_is_nfa_or_sot($row)) {
            return self::block($row, self::BLOCK_REASON_NFA_OR_SOT);
        }

        $item_number = self::normalize_item_number((string) ($row['sports_south_item_number'] ?? $row['remote_identifier'] ?? ''));
        if ($item_number !== '' && isset(self::SIZE_WEIGHT_RESTRICTED_ITEMS[$item_number])) {
            return self::block($row, self::BLOCK_REASON_SIZE_WEIGHT);
        }

        $manufacturer = self::normalize_manufacturer((string) ($row['manufacturer'] ?? ''));
        if ($manufacturer === '') {
            return $row;
        }

        if (self::matches_any($manufacturer, self::NO_FULFILLMENT_MANUFACTURERS)) {
            return self::block($row, self::BLOCK_REASON_NO_FULFILLMENT);
        }

        $factory_group = self::factory_approval_group_for_manufacturer($manufacturer);
        if ($factory_group !== null && !self::is_factory_group_approved($factory_group, $row)) {
            return self::block($row, self::BLOCK_REASON_FACTORY_APPROVAL . ':' . $factory_group);
        }

        return $row;
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function settings_schema_fields(): array
    {
        $fields = [];
        foreach (self::FACTORY_APPROVAL_GROUPS as $group => $def) {
            if ($group === 'sig_sauer') {
                continue;
            }

            $label = (string) ($def['label'] ?? $group);
            $fields[self::setting_key($group)] = [
                'label' => $label . ' Factory Approval',
                'type' => 'checkbox',
                'description' => 'Allow Sports South fulfillment for ' . $label . ' rows that otherwise require factory approval.',
                'default' => '0',
            ];
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function is_policy_blocked_row(array $row): bool
    {
        $reason = (string) ($row['dropship_block_reason'] ?? '');

        return strpos($reason, 'manufacturer_policy=') === 0
            || $reason === self::BLOCK_REASON_SIZE_WEIGHT
            || $reason === self::BLOCK_REASON_NFA_OR_SOT;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function block(array $row, string $reason): array
    {
        $row['dropship_enabled'] = '0';
        $row['dropship_block_reason'] = $reason;

        return $row;
    }

    /**
     * @param string[] $needles
     */
    private static function matches_any(string $manufacturer, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($manufacturer === $needle || strpos($manufacturer, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function factory_approval_group_for_manufacturer(string $manufacturer): ?string
    {
        foreach (self::FACTORY_APPROVAL_GROUPS as $group => $def) {
            $aliases = isset($def['aliases']) && is_array($def['aliases']) ? $def['aliases'] : [];
            if (self::matches_any($manufacturer, $aliases)) {
                return (string) $group;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function is_factory_group_approved(string $group, array $row): bool
    {
        if ($group === 'sig_sauer') {
            return SigDropshipApproval::should_force_row('sports_south', $row);
        }

        return Options::get_distributor_option('sports_south', self::setting_key($group), '0') === '1';
    }

    private static function setting_key(string $group): string
    {
        return self::FACTORY_SETTING_PREFIX . $group;
    }

    private static function normalize_manufacturer(string $manufacturer): string
    {
        $normalized = str_replace('&', 'AND', strtoupper(trim($manufacturer)));
        $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized);

        return is_string($normalized) ? $normalized : '';
    }

    private static function normalize_item_number(string $item_number): string
    {
        $digits = preg_replace('/\D+/', '', trim($item_number));

        return is_string($digits) ? $digits : '';
    }
}
