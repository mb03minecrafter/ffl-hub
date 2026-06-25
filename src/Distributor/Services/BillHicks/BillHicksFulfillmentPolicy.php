<?php

namespace FFLHub\Distributor\Services\BillHicks;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bill Hicks fulfillment policy.
 *
 * Bill Hicks' catalog does not expose a direct "can dropship" flag. We mirror
 * the Sports South fulfillment policy shape: SOT rows are blocked, known
 * no-fulfillment manufacturers are blocked, and factory-approval brands are
 * blocked unless the matching distributor setting allows them.
 */
final class BillHicksFulfillmentPolicy
{
    private const BLOCK_REASON_NO_FULFILLMENT = 'manufacturer_policy=no_fulfillment';
    private const BLOCK_REASON_FACTORY_APPROVAL = 'manufacturer_policy=factory_approval_required';
    private const BLOCK_REASON_NFA_OR_SOT = 'nfa_or_sot';
    private const FACTORY_SETTING_PREFIX = 'factory_approval_';

    /**
     * Sports South no-fulfillment list plus Bill Hicks spelling variants.
     *
     * Short aliases such as HK/FN/P80 are exact-only; longer aliases may match
     * prefixes. This prevents Bill Hicks brands like HKS and SIGHTMARK from
     * being blocked accidentally.
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
        'HANDK',
        'HECKLERKOCH',
        'HECKLERANDKOCH',
        'IWI',
        'IWIUSA',
        'IWIUSINC',
        'MOULTRIE',
        'OSIGHT',
        'OLIGHT',
        'OUTDOOREDGE',
        'RUGER',
        'STURMRUGER',
        'STURMRUGERCO',
        'SMITHWESSON',
        'SMITHANDWESSON',
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

    /**
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
                'description' => 'Allow Bill Hicks fulfillment for ' . $label . ' rows that otherwise require factory approval.',
                'default' => '0',
            ];
        }

        return $fields;
    }

    public static function apply_to_table(string $table_name): int
    {
        global $wpdb;

        $table_name = trim($table_name);
        if ($table_name === '' || strpos($table_name, (string) $wpdb->prefix) !== 0) {
            return 0;
        }

        $reason = self::block_reason_sql('manufacturer_norm', 'sot_required');
        $sql = "
            UPDATE {$table_name}
            SET
                dropship_block_reason = {$reason},
                dropship_enabled = CASE
                    WHEN {$reason} IS NULL OR {$reason} = '' THEN '1'
                    ELSE '0'
                END
            WHERE upc <> ''
        ";

        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function apply_to_row(array $row): array
    {
        $reason = self::block_reason_for_row($row);
        $row['dropship_block_reason'] = $reason;
        $row['dropship_enabled'] = $reason === '' ? '1' : '0';

        return $row;
    }

    private static function block_reason_for_row(array $row): string
    {
        if (SigDropshipApproval::row_is_nfa_or_sot($row)) {
            return self::BLOCK_REASON_NFA_OR_SOT;
        }

        $manufacturer = self::normalize_manufacturer((string) ($row['manufacturer_norm'] ?? $row['manufacturer'] ?? ''));
        if ($manufacturer === '') {
            return '';
        }

        if (self::matches_any($manufacturer, self::NO_FULFILLMENT_MANUFACTURERS)) {
            return self::BLOCK_REASON_NO_FULFILLMENT;
        }

        $factory_group = self::factory_approval_group_for_manufacturer($manufacturer);
        if ($factory_group !== null && !self::is_factory_group_approved($factory_group, $row)) {
            return self::BLOCK_REASON_FACTORY_APPROVAL . ':' . $factory_group;
        }

        return '';
    }

    private static function block_reason_sql(string $manufacturer_column, string $sot_column): string
    {
        $manufacturer = self::quote_identifier($manufacturer_column);
        $sot = self::quote_identifier($sot_column);

        $no_fulfillment_sql = self::matches_any_sql($manufacturer, self::NO_FULFILLMENT_MANUFACTURERS);
        $factory_cases = [];
        foreach (self::FACTORY_APPROVAL_GROUPS as $group => $def) {
            if (self::is_factory_group_approved($group, [])) {
                continue;
            }

            $aliases = isset($def['aliases']) && is_array($def['aliases']) ? $def['aliases'] : [];
            $match_sql = self::matches_any_sql($manufacturer, array_map('strval', $aliases));
            if ($match_sql === '') {
                continue;
            }

            $factory_cases[] = "WHEN {$match_sql} THEN '" . esc_sql(self::BLOCK_REASON_FACTORY_APPROVAL . ':' . $group) . "'";
        }

        return "
            CASE
                WHEN COALESCE({$sot}, 0) = 1 THEN '" . esc_sql(self::BLOCK_REASON_NFA_OR_SOT) . "'
                WHEN {$no_fulfillment_sql} THEN '" . esc_sql(self::BLOCK_REASON_NO_FULFILLMENT) . "'
                " . implode("\n                ", $factory_cases) . "
                ELSE ''
            END
        ";
    }

    /**
     * @param string[] $needles
     */
    private static function matches_any(string $manufacturer, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (self::matches_one($manufacturer, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function matches_one(string $manufacturer, string $needle): bool
    {
        $needle = self::normalize_manufacturer($needle);
        if ($needle === '') {
            return false;
        }

        if ($manufacturer === $needle) {
            return true;
        }

        if (strlen($needle) <= 3) {
            return false;
        }

        return strpos($manufacturer, $needle) === 0;
    }

    /**
     * @param string[] $needles
     */
    private static function matches_any_sql(string $column, array $needles): string
    {
        $parts = [];
        foreach ($needles as $needle) {
            $needle = self::normalize_manufacturer((string) $needle);
            if ($needle === '') {
                continue;
            }

            $escaped = esc_sql($needle);
            $parts[] = "{$column} = '{$escaped}'";
            if (strlen($needle) > 3) {
                $parts[] = "{$column} LIKE '{$escaped}%'";
            }
        }

        if (empty($parts)) {
            return '0 = 1';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private static function factory_approval_group_for_manufacturer(string $manufacturer): ?string
    {
        foreach (self::FACTORY_APPROVAL_GROUPS as $group => $def) {
            $aliases = isset($def['aliases']) && is_array($def['aliases']) ? $def['aliases'] : [];
            if (self::matches_any($manufacturer, array_map('strval', $aliases))) {
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
            if (empty($row)) {
                return SigDropshipApproval::is_distributor_sig_approved('bill_hicks');
            }

            return SigDropshipApproval::should_force_row('bill_hicks', $row);
        }

        return Options::get_distributor_option('bill_hicks', self::setting_key($group), '0') === '1';
    }

    private static function setting_key(string $group): string
    {
        return self::FACTORY_SETTING_PREFIX . $group;
    }

    public static function normalize_manufacturer(string $manufacturer): string
    {
        $normalized = str_replace('&', 'AND', strtoupper(trim($manufacturer)));
        $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized);

        return is_string($normalized) ? $normalized : '';
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
