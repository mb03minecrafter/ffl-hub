<?php

namespace FFLHub\Distributor\Services;

use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared SIG SAUER dropship override for distributor feeds.
 */
final class SigDropshipApproval
{
    public static function is_distributor_sig_approved(string $distributor_id): bool
    {
        return Options::is_distributor_sig_approved($distributor_id);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function should_force_row(string $distributor_id, array $row): bool
    {
        if (!self::is_distributor_sig_approved($distributor_id)) {
            return false;
        }

        if (self::row_is_nfa_or_sot($row)) {
            return false;
        }

        return self::row_is_sig_sauer($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function apply_to_row(string $distributor_id, array $row): array
    {
        if (!self::should_force_row($distributor_id, $row)) {
            return $row;
        }

        $row['dropship_enabled'] = '1';
        $row['dropship_block_reason'] = '';

        return $row;
    }

    /**
     * Force matching SIG SAUER rows in a distributor product table.
     */
    public static function apply_to_table(string $distributor_id, string $table_name): int
    {
        global $wpdb;

        if (!self::is_distributor_sig_approved($distributor_id)) {
            return 0;
        }

        $table_name = trim($table_name);
        $prefix = (string) ($wpdb->prefix ?? '');
        if ($table_name === '' || $prefix === '' || strpos($table_name, $prefix) !== 0) {
            return 0;
        }

        $where_parts = [];
        foreach (self::existing_sig_source_columns($table_name) as $column) {
            $where_parts[] = '(' . self::sql_sig_sauer_where(self::sql_normalize_expr(self::quote_identifier($column))) . ')';
        }

        if (empty($where_parts)) {
            return 0;
        }

        $where = '(' . implode(' OR ', $where_parts) . ')';
        $restriction_where = self::sql_nfa_or_sot_where($table_name);
        if ($restriction_where !== '') {
            $where .= " AND NOT ({$restriction_where})";
        }

        $quoted_table = self::quote_identifier($table_name);
        $sql = "
            UPDATE {$quoted_table}
            SET dropship_enabled = '1',
                dropship_block_reason = ''
            WHERE {$where}
        ";

        $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function row_is_sig_sauer(array $row): bool
    {
        foreach (['manufacturer', 'brand', 'bound_book_manufacturer', 'manufacturer_name', 'mfg', 'vendor', 'vendor_name'] as $key) {
            if (array_key_exists($key, $row) && self::is_sig_sauer_name((string) $row[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function row_is_nfa_or_sot(array $row): bool
    {
        foreach (['sot_required', 'sotRequired', 'sot', 'requires_sot'] as $key) {
            if (array_key_exists($key, $row) && self::truthy_flag($row[$key])) {
                return true;
            }
        }

        foreach ([
            'item_type',
            'itemType',
            'type',
            'item_group',
            'itemGroup',
            'family',
            'product_description',
            'description',
            'description1',
            'description2',
            'bound_book_type',
            'boundBookType',
        ] as $key) {
            if (array_key_exists($key, $row) && self::text_indicates_nfa((string) $row[$key])) {
                return true;
            }
        }

        return false;
    }

    public static function is_sig_sauer_name(string $name): bool
    {
        $normalized = self::normalize_name($name);
        if ($normalized === '') {
            return false;
        }

        return $normalized === 'SIG'
            || $normalized === 'SIGSAUER'
            || $normalized === 'SIGARMS'
            || strpos($normalized, 'SIGSAUER') === 0
            || strpos($normalized, 'SIGARMS') === 0;
    }

    private static function normalize_name(string $name): string
    {
        $normalized = preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($name)));
        return is_string($normalized) ? $normalized : '';
    }

    private static function sql_normalize_expr(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM({$column})),' ',''),'&',''),'/',''),'-',''),'.','')";
    }

    private static function sql_sig_sauer_where(string $expr): string
    {
        return "{$expr} = 'SIG'"
            . " OR {$expr} = 'SIGSAUER'"
            . " OR {$expr} = 'SIGARMS'"
            . " OR {$expr} LIKE 'SIGSAUER%'"
            . " OR {$expr} LIKE 'SIGARMS%'";
    }

    private static function sql_nfa_or_sot_where(string $table_name): string
    {
        $parts = [];
        $columns = self::existing_columns($table_name);

        foreach (['sot_required', 'sotRequired', 'sot', 'requires_sot'] as $column) {
            if (in_array($column, $columns, true)) {
                $quoted = self::quote_identifier($column);
                $parts[] = "{$quoted} = 1 OR UPPER(TRIM({$quoted})) IN ('1','Y','YES','TRUE')";
            }
        }

        foreach ([
            'item_type',
            'itemType',
            'type',
            'item_group',
            'itemGroup',
            'family',
            'product_description',
            'description',
            'description1',
            'description2',
            'bound_book_type',
            'boundBookType',
        ] as $column) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $expr = self::sql_normalize_expr(self::quote_identifier($column));
            $parts[] = "{$expr} LIKE '%NFA%'"
                . " OR {$expr} LIKE '%SOT%'"
                . " OR {$expr} LIKE '%SILENCER%'"
                . " OR {$expr} LIKE '%SUPPRESSOR%'"
                . " OR {$expr} LIKE '%CLASSIII%'"
                . " OR {$expr} LIKE '%SHORTBARRELRIFLE%'"
                . " OR {$expr} LIKE '%SHORTBARRELSHOTGUN%'";
        }

        $parts = array_values(array_filter(array_map('trim', $parts)));
        if (empty($parts)) {
            return '';
        }

        return '(' . implode(') OR (', $parts) . ')';
    }

    /**
     * @return string[]
     */
    private static function existing_sig_source_columns(string $table_name): array
    {
        global $wpdb;

        $available = array_fill_keys(array_map('strtolower', self::existing_columns($table_name)), true);
        $candidates = [
            'manufacturer',
            'brand',
            'bound_book_manufacturer',
            'manufacturer_name',
            'mfg',
            'vendor',
            'vendor_name',
        ];

        return array_values(array_filter($candidates, static function (string $column) use ($available): bool {
            return isset($available[strtolower($column)]);
        }));
    }

    /**
     * @return string[]
     */
    private static function existing_columns(string $table_name): array
    {
        global $wpdb;

        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . self::quote_identifier($table_name), 0); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($columns)) {
            return [];
        }

        return array_values(array_map('strval', $columns));
    }

    /**
     * @param mixed $value
     */
    private static function truthy_flag($value): bool
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['1', 'Y', 'YES', 'TRUE'], true);
    }

    private static function text_indicates_nfa(string $text): bool
    {
        $normalized = self::normalize_name($text);
        if ($normalized === '') {
            return false;
        }

        foreach ([
            'NFA',
            'SOT',
            'SILENCER',
            'SUPPRESSOR',
            'CLASSIII',
            'SHORTBARRELRIFLE',
            'SHORTBARRELSHOTGUN',
        ] as $needle) {
            if (strpos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
