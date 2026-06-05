<?php

namespace FFLHub\Distributor\Services\SportsSouth;

use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInventoryClient;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistent cache for Sports South brand/category reference data.
 *
 * The product importer still receives the same PHP arrays it received from
 * direct BrandUpdate/CategoryUpdate parsing. This class only changes where
 * those maps are loaded from on normal runs.
 */
final class SportsSouthReferenceMapCache
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthReferenceMapCache]';

    private const BRAND_TABLE_SUFFIX = 'fflhub_sports_south_brand_reference';
    private const BRAND_STAGE_TABLE_SUFFIX = 'fflhub_sports_south_brand_reference_stage';
    private const CATEGORY_TABLE_SUFFIX = 'fflhub_sports_south_category_reference';
    private const CATEGORY_STAGE_TABLE_SUFFIX = 'fflhub_sports_south_category_reference_stage';

    private const OPT_BRAND_LAST_SUCCESS_TS = 'fflhub_sports_south_brand_map_last_success_ts';
    private const OPT_CATEGORY_LAST_SUCCESS_TS = 'fflhub_sports_south_category_map_last_success_ts';
    private const OPT_REFRESH_INTERVAL = 'fflhub_sports_south_brand_category_refresh_interval';

    private const MIN_BRAND_ROWS = 100;
    private const MIN_CATEGORY_ROWS = 20;

    /** @var string[] */
    private array $artifact_paths = [];

    /**
     * @return array{
     *     ok:bool,
     *     brand_map:array<string,array<string,mixed>>,
     *     category_map:array<string,array<string,mixed>>,
     *     refreshed:int,
     *     stale_cache_used:int,
     *     force_refresh:int,
     *     brand_count:int,
     *     category_count:int,
     *     artifacts:string[],
     *     error?:string
     * }
     */
    public function get_maps(
        SportsSouthInventoryClient $client,
        SportsSouthProductParser $parser,
        string $artifact_dir,
        bool $force_refresh = false
    ): array {
        $this->artifact_paths = [];
        $this->ensure_tables();

        $force_refresh = $force_refresh || $this->consume_force_refresh_option();
        $counts_before = $this->reference_counts();
        $should_refresh = $this->should_refresh($counts_before, $force_refresh);
        $refreshed = false;
        $stale_cache_used = false;
        $refresh_error = '';

        if ($should_refresh) {
            $refresh = $this->refresh($client, $parser, $artifact_dir);
            $refreshed = !empty($refresh['ok']);
            $refresh_error = (string) ($refresh['error'] ?? '');

            if (!$refreshed) {
                $counts_after_failure = $this->reference_counts();
                if ($counts_after_failure['brand'] > 0 && $counts_after_failure['category'] > 0) {
                    $stale_cache_used = true;
                    $this->log('Sports South reference refresh failed; using existing cache.', [
                        'error' => $refresh_error,
                        'brand_rows' => (int) $counts_after_failure['brand'],
                        'category_rows' => (int) $counts_after_failure['category'],
                    ]);
                } else {
                    return [
                        'ok' => false,
                        'brand_map' => [],
                        'category_map' => [],
                        'refreshed' => 0,
                        'stale_cache_used' => 0,
                        'force_refresh' => $force_refresh ? 1 : 0,
                        'brand_count' => 0,
                        'category_count' => 0,
                        'artifacts' => $this->artifact_paths,
                        'error' => $refresh_error !== '' ? $refresh_error : 'Reference cache refresh failed and no existing cache is available.',
                    ];
                }
            }
        } else {
            $this->log('Sports South reference refresh skipped due to cooldown.', [
                'brand_rows' => (int) $counts_before['brand'],
                'category_rows' => (int) $counts_before['category'],
                'brand_last_success_ts' => (int) get_option(self::OPT_BRAND_LAST_SUCCESS_TS, 0),
                'category_last_success_ts' => (int) get_option(self::OPT_CATEGORY_LAST_SUCCESS_TS, 0),
                'refresh_interval' => $this->refresh_interval_seconds(),
            ]);
        }

        $brand_map = $this->load_brand_map();
        $category_map = $this->load_category_map();

        return [
            'ok' => !empty($brand_map) && !empty($category_map),
            'brand_map' => $brand_map,
            'category_map' => $category_map,
            'refreshed' => $refreshed ? 1 : 0,
            'stale_cache_used' => $stale_cache_used ? 1 : 0,
            'force_refresh' => $force_refresh ? 1 : 0,
            'brand_count' => count($brand_map),
            'category_count' => count($category_map),
            'artifacts' => $this->artifact_paths,
            'error' => empty($brand_map) || empty($category_map) ? 'Reference cache returned empty maps.' : '',
        ];
    }

    private function ensure_tables(): void
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset = $wpdb->get_charset_collate();
        foreach ([$this->brand_table(), $this->brand_stage_table()] as $table) {
            dbDelta(
                "CREATE TABLE {$table} (
brand_number VARCHAR(64) NOT NULL,
brand_name VARCHAR(255) NOT NULL,
brand_url VARCHAR(1024) NULL,
item_count INT NULL,
raw_json LONGTEXT NULL,
updated_utc VARCHAR(64) NULL,
PRIMARY KEY  (brand_number),
KEY brand_name (brand_name(191))
) {$charset};"
            );
        }

        $category_columns = [
            'category_id VARCHAR(64) NOT NULL',
            'category_description VARCHAR(255) NULL',
            'department_id VARCHAR(64) NULL',
            'department_name VARCHAR(255) NULL',
            'ffl_required TINYINT(1) NOT NULL DEFAULT 0',
            'sot_required TINYINT(1) NOT NULL DEFAULT 0',
        ];
        for ($i = 1; $i <= 20; $i++) {
            $category_columns[] = 'attr' . $i . '_label VARCHAR(128) NULL';
        }
        $category_columns[] = 'attributes_json LONGTEXT NULL';
        $category_columns[] = 'raw_json LONGTEXT NULL';
        $category_columns[] = 'updated_utc VARCHAR(64) NULL';
        $category_columns[] = 'PRIMARY KEY  (category_id)';
        $category_columns[] = 'KEY ffl_required (ffl_required)';
        $category_columns[] = 'KEY sot_required (sot_required)';
        $category_columns[] = 'KEY category_description (category_description(191))';

        foreach ([$this->category_table(), $this->category_stage_table()] as $table) {
            dbDelta("CREATE TABLE {$table} (\n" . implode(",\n", $category_columns) . "\n) {$charset};");
        }
    }

    /**
     * @param array{brand:int,category:int} $counts
     */
    private function should_refresh(array $counts, bool $force_refresh): bool
    {
        if ($force_refresh) {
            return true;
        }

        if ($counts['brand'] <= 0 || $counts['category'] <= 0) {
            return true;
        }

        $last_brand = (int) get_option(self::OPT_BRAND_LAST_SUCCESS_TS, 0);
        $last_category = (int) get_option(self::OPT_CATEGORY_LAST_SUCCESS_TS, 0);
        $oldest = min($last_brand, $last_category);

        return $oldest <= 0 || time() >= ($oldest + $this->refresh_interval_seconds());
    }

    private function refresh_interval_seconds(): int
    {
        $interval = (int) get_option(self::OPT_REFRESH_INTERVAL, WEEK_IN_SECONDS);

        return max(HOUR_IN_SECONDS, $interval);
    }

    /**
     * @return array{brand:int,category:int}
     */
    private function reference_counts(): array
    {
        global $wpdb;

        return [
            'brand' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->brand_table()}"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'category' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->category_table()}"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        ];
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    private function refresh(SportsSouthInventoryClient $client, SportsSouthProductParser $parser, string $artifact_dir): array
    {
        $t_total = microtime(true);
        $brand_path = $this->build_artifact_path($artifact_dir, 'brand_update');
        $category_path = $this->build_artifact_path($artifact_dir, 'category_update');
        if ($brand_path === '' || $category_path === '') {
            return ['ok' => false, 'error' => 'Failed to build reference artifact paths.'];
        }

        $t_brand_request = microtime(true);
        $brand_response = $client->brand_update_to_file($brand_path);
        $this->remember_artifact_paths($brand_path, (string) ($brand_response['raw_path'] ?? ''));
        $this->log('PROFILE: BrandUpdate reference request', [
            'ok' => empty($brand_response['ok']) ? 0 : 1,
            'status' => (int) ($brand_response['status'] ?? 0),
            'xml_path' => $brand_path,
            'xml_bytes' => (int) ($brand_response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($brand_response['body_bytes'] ?? 0),
            'raw_path' => (string) ($brand_response['raw_path'] ?? ''),
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_brand_request) * 1000.0),
        ]);
        if (empty($brand_response['ok'])) {
            return ['ok' => false, 'error' => 'BrandUpdate failed: ' . (string) ($brand_response['error'] ?? '')];
        }

        $t_category_request = microtime(true);
        $category_response = $client->category_update_to_file($category_path);
        $this->remember_artifact_paths($category_path, (string) ($category_response['raw_path'] ?? ''));
        $this->log('PROFILE: CategoryUpdate reference request', [
            'ok' => empty($category_response['ok']) ? 0 : 1,
            'status' => (int) ($category_response['status'] ?? 0),
            'xml_path' => $category_path,
            'xml_bytes' => (int) ($category_response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($category_response['body_bytes'] ?? 0),
            'raw_path' => (string) ($category_response['raw_path'] ?? ''),
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_category_request) * 1000.0),
        ]);
        if (empty($category_response['ok'])) {
            return ['ok' => false, 'error' => 'CategoryUpdate failed: ' . (string) ($category_response['error'] ?? '')];
        }

        $t_parse = microtime(true);
        $brand_map = $this->parse_brand_map($parser, $brand_path);
        $category_map = $this->parse_category_map($parser, $category_path);
        $this->log('PROFILE: reference map parse', [
            'brand_count' => count($brand_map),
            'category_count' => count($category_map),
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_parse) * 1000.0),
        ]);

        if (count($brand_map) < self::MIN_BRAND_ROWS) {
            return ['ok' => false, 'error' => 'Brand reference count suspiciously low: ' . count($brand_map)];
        }
        if (count($category_map) < self::MIN_CATEGORY_ROWS) {
            return ['ok' => false, 'error' => 'Category reference count suspiciously low: ' . count($category_map)];
        }

        $t_write = microtime(true);
        $brand_stage_diffs = $this->replace_brand_reference($brand_map);
        $category_stage_diffs = $this->replace_category_reference($category_map);
        $this->log('PROFILE: reference table replace', [
            'brand_count' => count($brand_map),
            'brand_stage_diffs' => $brand_stage_diffs,
            'category_count' => count($category_map),
            'category_stage_diffs' => $category_stage_diffs,
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_write) * 1000.0),
        ]);

        if ($brand_stage_diffs !== 0 || $category_stage_diffs !== 0) {
            return ['ok' => false, 'error' => "Reference stage validation failed: brand_diffs={$brand_stage_diffs}, category_diffs={$category_stage_diffs}"];
        }

        $loaded_brand_map = $this->load_brand_map();
        $loaded_category_map = $this->load_category_map();
        $brand_diffs = $this->count_map_diffs($brand_map, $loaded_brand_map);
        $category_diffs = $this->count_map_diffs($category_map, $loaded_category_map);
        $this->log('Sports South reference cache validation.', [
            'brand_parsed_count' => count($brand_map),
            'brand_cached_count' => count($loaded_brand_map),
            'brand_diffs' => $brand_diffs,
            'category_parsed_count' => count($category_map),
            'category_cached_count' => count($loaded_category_map),
            'category_diffs' => $category_diffs,
        ]);

        if ($brand_diffs !== 0 || $category_diffs !== 0) {
            return ['ok' => false, 'error' => "Reference cache validation failed: brand_diffs={$brand_diffs}, category_diffs={$category_diffs}"];
        }

        $now = time();
        update_option(self::OPT_BRAND_LAST_SUCCESS_TS, $now, false);
        update_option(self::OPT_CATEGORY_LAST_SUCCESS_TS, $now, false);

        $this->log('Sports South reference cache refresh complete.', [
            'brand_count' => count($brand_map),
            'category_count' => count($category_map),
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_total) * 1000.0),
        ]);

        return ['ok' => true];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function parse_brand_map(SportsSouthProductParser $parser, string $path): array
    {
        $brand_map = [];
        $parser->each_brand_row($path, function (array $brand) use (&$brand_map): void {
            $brand_number = trim((string) ($brand['brand_number'] ?? ''));
            $brand_name = trim((string) ($brand['brand_name'] ?? ''));
            if ($brand_number === '' || $brand_name === '') {
                return;
            }

            $brand_map[$brand_number] = $brand;
        });

        ksort($brand_map);
        return $brand_map;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function parse_category_map(SportsSouthProductParser $parser, string $path): array
    {
        $category_map = [];
        $parser->each_category_row($path, function (array $category) use (&$category_map): void {
            $category_id = trim((string) ($category['category_id'] ?? ''));
            if ($category_id === '') {
                return;
            }

            $category_map[$category_id] = $category;
        });

        ksort($category_map);
        return $category_map;
    }

    /**
     * @param array<string,array<string,mixed>> $brand_map
     */
    private function replace_brand_reference(array $brand_map): int
    {
        global $wpdb;

        $stage = $this->brand_stage_table();
        $live = $this->brand_table();
        $wpdb->query("TRUNCATE TABLE {$stage}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $columns = ['brand_number', 'brand_name', 'brand_url', 'item_count', 'raw_json', 'updated_utc'];
        $rows = [];
        foreach ($brand_map as $brand_number => $brand) {
            $rows[] = [
                'brand_number' => (string) $brand_number,
                'brand_name' => (string) ($brand['brand_name'] ?? ''),
                'brand_url' => (string) ($brand['brand_url'] ?? ''),
                'item_count' => (int) ($brand['item_count'] ?? 0),
                'raw_json' => $this->encode_json($brand),
                'updated_utc' => gmdate('Y-m-d H:i:s'),
            ];
        }

        $this->insert_rows($stage, $columns, $rows);
        $stage_diffs = $this->count_map_diffs($brand_map, $this->load_brand_map($stage));
        if ($stage_diffs !== 0) {
            return $stage_diffs;
        }

        $wpdb->query('START TRANSACTION');
        $wpdb->query("DELETE FROM {$live}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $column_sql = implode(', ', array_map([$this, 'quote_identifier'], $columns));
        $wpdb->query("INSERT INTO {$live} ({$column_sql}) SELECT {$column_sql} FROM {$stage}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('COMMIT');

        return 0;
    }

    /**
     * @param array<string,array<string,mixed>> $category_map
     */
    private function replace_category_reference(array $category_map): int
    {
        global $wpdb;

        $stage = $this->category_stage_table();
        $live = $this->category_table();
        $wpdb->query("TRUNCATE TABLE {$stage}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $columns = [
            'category_id',
            'category_description',
            'department_id',
            'department_name',
            'ffl_required',
            'sot_required',
        ];
        for ($i = 1; $i <= 20; $i++) {
            $columns[] = 'attr' . $i . '_label';
        }
        $columns[] = 'attributes_json';
        $columns[] = 'raw_json';
        $columns[] = 'updated_utc';

        $rows = [];
        foreach ($category_map as $category_id => $category) {
            $attributes = isset($category['attributes']) && is_array($category['attributes']) ? $category['attributes'] : [];
            $policy_row = SportsSouthCategoryPolicy::apply_to_row(['category_id' => (string) $category_id], [(string) $category_id => $category]);
            $row = [
                'category_id' => (string) $category_id,
                'category_description' => (string) ($category['category_description'] ?? ''),
                'department_id' => (string) ($category['department_id'] ?? ''),
                'department_name' => (string) ($category['department_name'] ?? ''),
                'ffl_required' => (int) ($policy_row['ffl_required'] ?? 0),
                'sot_required' => (int) ($policy_row['sot_required'] ?? 0),
            ];

            for ($i = 1; $i <= 20; $i++) {
                $key = $i === 10 ? 'ATTR0' : 'ATTR' . $i;
                $fallback = 'ATTR' . $i;
                $row['attr' . $i . '_label'] = (string) ($attributes[$key] ?? $attributes[$fallback] ?? '');
            }

            $row['attributes_json'] = $this->encode_json($attributes);
            $row['raw_json'] = $this->encode_json($category);
            $row['updated_utc'] = gmdate('Y-m-d H:i:s');
            $rows[] = $row;
        }

        $this->insert_rows($stage, $columns, $rows);
        $stage_diffs = $this->count_map_diffs($category_map, $this->load_category_map($stage));
        if ($stage_diffs !== 0) {
            return $stage_diffs;
        }

        $wpdb->query('START TRANSACTION');
        $wpdb->query("DELETE FROM {$live}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $column_sql = implode(', ', array_map([$this, 'quote_identifier'], $columns));
        $wpdb->query("INSERT INTO {$live} ({$column_sql}) SELECT {$column_sql} FROM {$stage}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('COMMIT');

        return 0;
    }

    /**
     * @param string[] $columns
     * @param array<int,array<string,mixed>> $rows
     */
    private function insert_rows(string $table, array $columns, array $rows): void
    {
        global $wpdb;

        $batch_size = 250;
        $column_sql = implode(', ', array_map([$this, 'quote_identifier'], $columns));
        foreach (array_chunk($rows, $batch_size) as $batch) {
            $values = [];
            $placeholders = [];
            foreach ($batch as $row) {
                $row_placeholders = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    if (is_int($value)) {
                        $row_placeholders[] = '%d';
                    } else {
                        $row_placeholders[] = '%s';
                    }
                    $values[] = $value;
                }
                $placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
            }

            $sql = "INSERT INTO {$table} ({$column_sql}) VALUES " . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function load_brand_map(?string $table = null): array
    {
        global $wpdb;

        $table = $table ?: $this->brand_table();
        $rows = $wpdb->get_results("SELECT brand_number, brand_name, brand_url, item_count FROM {$table} ORDER BY brand_number ASC", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $brand_number = trim((string) ($row['brand_number'] ?? ''));
            if ($brand_number === '') {
                continue;
            }

            $map[$brand_number] = [
                'brand_number' => $brand_number,
                'brand_name' => (string) ($row['brand_name'] ?? ''),
                'brand_url' => (string) ($row['brand_url'] ?? ''),
                'item_count' => (int) ($row['item_count'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function load_category_map(?string $table = null): array
    {
        global $wpdb;

        $table = $table ?: $this->category_table();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY category_id ASC", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $category_id = trim((string) ($row['category_id'] ?? ''));
            if ($category_id === '') {
                continue;
            }

            $attributes = json_decode((string) ($row['attributes_json'] ?? ''), true);
            if (!is_array($attributes)) {
                $attributes = [];
                for ($i = 1; $i <= 20; $i++) {
                    $key = $i === 10 ? 'ATTR0' : 'ATTR' . $i;
                    $value = trim((string) ($row['attr' . $i . '_label'] ?? ''));
                    if ($value !== '') {
                        $attributes[$key] = $value;
                    }
                }
            }

            $map[$category_id] = [
                'category_id' => $category_id,
                'category_description' => (string) ($row['category_description'] ?? ''),
                'department_id' => (string) ($row['department_id'] ?? ''),
                'department_name' => (string) ($row['department_name'] ?? ''),
                'attributes' => $attributes,
            ];
        }

        return $map;
    }

    /**
     * @param array<string,array<string,mixed>> $expected
     * @param array<string,array<string,mixed>> $actual
     */
    private function count_map_diffs(array $expected, array $actual): int
    {
        $diffs = 0;
        $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
        foreach ($keys as $key) {
            $left = $this->normalize_for_compare($expected[$key] ?? null);
            $right = $this->normalize_for_compare($actual[$key] ?? null);
            if ($left !== $right) {
                $diffs++;
            }
        }

        return $diffs;
    }

    /**
     * @param mixed $value
     */
    private function normalize_for_compare($value): string
    {
        if (is_array($value)) {
            ksort($value);
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    ksort($v);
                    $value[$k] = $v;
                }
            }
        }

        return $this->encode_json($value);
    }

    private function build_artifact_path(string $dir, string $prefix): string
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '' || (!is_dir($dir) && !wp_mkdir_p($dir))) {
            return '';
        }

        return $dir . '/' . $prefix . '_' . gmdate('Ymd_His') . '.xml';
    }

    private function consume_force_refresh_option(): bool
    {
        $value = (string) get_option('fflhub_sports_south_force_reference_refresh', '0');
        if (!in_array(strtolower(trim($value)), ['1', 'yes', 'true', 'on'], true)) {
            return false;
        }

        update_option('fflhub_sports_south_force_reference_refresh', '0', false);
        return true;
    }

    private function remember_artifact_paths(string ...$paths): void
    {
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path !== '') {
                $this->artifact_paths[] = $path;
            }
        }
    }

    private function brand_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::BRAND_TABLE_SUFFIX);
    }

    private function brand_stage_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::BRAND_STAGE_TABLE_SUFFIX);
    }

    private function category_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::CATEGORY_TABLE_SUFFIX);
    }

    private function category_stage_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::CATEGORY_STAGE_TABLE_SUFFIX);
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param mixed $value
     */
    private function encode_json($value): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }

    private function format_ms(float $ms): string
    {
        return number_format($ms, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log_if(true, self::LOG_PREFIX, $message, self::DEBUG_FLAG);
            return;
        }

        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, $message, $ctx, self::DEBUG_FLAG);
    }
}
