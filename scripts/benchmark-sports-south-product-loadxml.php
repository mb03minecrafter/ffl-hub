<?php
/**
 * Benchmark Sports South DailyItemUpdate XML import strategies.
 *
 * Run on the VPS with WP-CLI:
 *
 *   wp --path=/var/www/deerforddefense.com --allow-root --skip-themes eval-file \
 *     /var/www/deerforddefense.com/wp-content/plugins/ffl-hub/scripts/benchmark-sports-south-product-loadxml.php -- \
 *     load-xml-timeout=180
 *
 * Optional args:
 *   xml=/path/to/decoded-daily-item-update.xml  Reuse an existing decoded XML file.
 *   download=0                                Require xml=... instead of downloading.
 *   limit=10000                               Create a same-input sample with N <Table> rows.
 *   output-dir=/tmp/fflhub-ss-loadxml-bench   Where downloaded/sample files are written.
 *   load-xml-timeout=180                      MariaDB max_statement_time for LOAD XML. 0 disables.
 *
 * This script uses dedicated benchmark tables only. It does not touch live
 * distributor tables, WooCommerce posts, or Action Scheduler jobs.
 */

use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInventoryClient;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductParser;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run through WP-CLI eval-file.\n");
    exit(1);
}

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script is intended for WP-CLI only.\n");
    exit(1);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

/**
 * @param mixed $default
 * @return mixed
 */
function fflhub_ss_bench_arg(array $args, string $key, $default = null)
{
    foreach ($args as $arg) {
        if ($arg === $key) {
            return true;
        }
        if (strpos((string) $arg, $key . '=') === 0) {
            return substr((string) $arg, strlen($key) + 1);
        }
    }

    return $default;
}

/**
 * @param mixed $value
 */
function fflhub_ss_bench_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on'], true);
}

function fflhub_ss_bench_line(string $message = ''): void
{
    WP_CLI::line($message);
}

function fflhub_ss_bench_ms(float $start): float
{
    return round((microtime(true) - $start) * 1000.0, 3);
}

function fflhub_ss_bench_peak_mb(): float
{
    return round(memory_get_peak_usage(true) / 1048576, 2);
}

/**
 * @return array<string,mixed>
 */
function fflhub_ss_bench_db_diagnostics(): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT VERSION() AS mariadb_version,
                @@local_infile AS local_infile,
                @@secure_file_priv AS secure_file_priv,
                @@max_allowed_packet AS max_allowed_packet,
                @@max_statement_time AS max_statement_time",
        ARRAY_A
    );

    return is_array($rows) && isset($rows[0]) ? $rows[0] : [];
}

/**
 * @return list<string>
 */
function fflhub_ss_bench_columns(): array
{
    return [
        'upc',
        'sports_south_item_number',
        'remote_identifier',
        'inventory_quantity',
        'allocation_status',
        'distributor_price',
        'shipping_cost',
        'catalog_price',
        'retail_map',
        'retail_msrp',
        'product_name',
        'product_description',
        'manufacturer',
        'brand_number',
        'model',
        'manufacturer_part_number',
        'category_id',
        'item_type',
        'caliber_gauge',
        'ffl_required',
        'sot_required',
        'dropship_enabled',
        'dropship_block_reason',
        'restricted_states',
        'shipping_weight',
        'shipping_length_in',
        'shipping_width_in',
        'shipping_height_in',
        'image_ref',
        'image_url',
        'text_ref',
        'last_seen_utc',
    ];
}

function fflhub_ss_bench_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function fflhub_ss_bench_create_table(string $table): void
{
    global $wpdb;

    $charset = $wpdb->get_charset_collate();
    $sql = "
        CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            upc VARCHAR(32) NULL,
            sports_south_item_number VARCHAR(64) NULL,
            remote_identifier VARCHAR(128) NULL,
            inventory_quantity VARCHAR(32) NULL,
            allocation_status VARCHAR(64) NULL,
            distributor_price VARCHAR(32) NULL,
            shipping_cost VARCHAR(32) NULL,
            catalog_price VARCHAR(32) NULL,
            retail_map VARCHAR(32) NULL,
            retail_msrp VARCHAR(32) NULL,
            product_name VARCHAR(255) NULL,
            product_description LONGTEXT NULL,
            manufacturer VARCHAR(255) NULL,
            brand_number VARCHAR(64) NULL,
            model VARCHAR(255) NULL,
            manufacturer_part_number VARCHAR(128) NULL,
            category_id VARCHAR(64) NULL,
            item_type VARCHAR(128) NULL,
            caliber_gauge VARCHAR(128) NULL,
            ffl_required TINYINT(1) NOT NULL DEFAULT 0,
            sot_required TINYINT(1) NOT NULL DEFAULT 0,
            dropship_enabled TINYINT(1) NOT NULL DEFAULT 1,
            dropship_block_reason VARCHAR(255) NULL,
            restricted_states VARCHAR(255) NULL,
            shipping_weight DECIMAL(10,2) NULL,
            shipping_length_in VARCHAR(32) NULL,
            shipping_width_in VARCHAR(32) NULL,
            shipping_height_in VARCHAR(32) NULL,
            image_ref VARCHAR(128) NULL,
            image_url VARCHAR(1024) NULL,
            text_ref VARCHAR(128) NULL,
            last_seen_utc VARCHAR(64) NULL,
            PRIMARY KEY (id)
        ) {$charset}
    ";

    $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    if ($result === false) {
        throw new RuntimeException('CREATE TABLE failed for ' . $table . ': ' . (string) $wpdb->last_error);
    }
}

function fflhub_ss_bench_add_indexes(string $table): void
{
    global $wpdb;

    $sql = "
        ALTER TABLE {$table}
            ADD KEY upc (upc),
            ADD KEY sports_south_item_number (sports_south_item_number),
            ADD KEY category_id (category_id),
            ADD KEY brand_number (brand_number),
            ADD KEY dropship_enabled (dropship_enabled)
    ";

    $result = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    if ($result === false) {
        throw new RuntimeException('ADD INDEX failed for ' . $table . ': ' . (string) $wpdb->last_error);
    }
}

function fflhub_ss_bench_recreate_table(string $table): float
{
    global $wpdb;

    $t = microtime(true);
    $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    if ($wpdb->last_error !== '') {
        throw new RuntimeException('DROP TABLE failed for ' . $table . ': ' . (string) $wpdb->last_error);
    }

    fflhub_ss_bench_create_table($table);

    return fflhub_ss_bench_ms($t);
}

/**
 * @return array<string,mixed>
 */
function fflhub_ss_bench_xml_shape(string $xmlPath): array
{
    $reader = new XMLReader();
    $shape = [
        'readable' => is_readable($xmlPath),
        'row_tag' => '',
        'first_row_child_tags' => [],
        'first_row_child_count' => 0,
        'first_row_has_upc' => false,
        'first_row_has_item' => false,
    ];

    if (!$shape['readable'] || !$reader->open($xmlPath, null, LIBXML_NONET | LIBXML_NOCDATA)) {
        return $shape;
    }

    $candidateTags = array_fill_keys(['Table', 'Item', 'ITEM', 'Product', 'DailyItem', 'InventoryItem'], true);
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || !isset($candidateTags[$reader->localName])) {
            continue;
        }

        $shape['row_tag'] = $reader->localName;
        $outer = $reader->readOuterXML();
        if (is_string($outer) && $outer !== '') {
            $node = @simplexml_load_string($outer, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($node instanceof SimpleXMLElement) {
                $tags = [];
                foreach ($node->children() as $child) {
                    $tags[] = strtoupper((string) $child->getName());
                }
                $shape['first_row_child_tags'] = $tags;
                $shape['first_row_child_count'] = count($tags);
                $shape['first_row_has_upc'] = (bool) array_intersect($tags, ['UPC', 'ITUPC', 'U', 'BARCODE', 'GTIN']);
                $shape['first_row_has_item'] = (bool) array_intersect($tags, ['ITEMNO', 'ITEMNUMBER', 'I']);
            }
        }
        break;
    }
    $reader->close();

    return $shape;
}

function fflhub_ss_bench_make_sample_xml(string $sourcePath, string $destPath, int $limit): int
{
    $reader = new XMLReader();
    if (!$reader->open($sourcePath, null, LIBXML_NONET | LIBXML_NOCDATA)) {
        throw new RuntimeException('Failed to open source XML for sampling: ' . $sourcePath);
    }

    $out = fopen($destPath, 'wb');
    if (!$out) {
        $reader->close();
        throw new RuntimeException('Failed to open sample XML output: ' . $destPath);
    }

    $candidateTags = array_fill_keys(['Table', 'Item', 'ITEM', 'Product', 'DailyItem', 'InventoryItem'], true);
    fwrite($out, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<NewDataSet>\n");
    $count = 0;
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || !isset($candidateTags[$reader->localName])) {
            continue;
        }

        $outer = $reader->readOuterXML();
        if (!is_string($outer) || $outer === '') {
            continue;
        }

        fwrite($out, $outer . "\n");
        $count++;
        if ($count >= $limit) {
            break;
        }
    }
    fwrite($out, "</NewDataSet>\n");
    fclose($out);
    $reader->close();

    return $count;
}

function fflhub_ss_bench_download_xml(string $xmlPath, string $lastUpdate, int $lastItem): array
{
    $client = new SportsSouthInventoryClient(
        (string) Options::get_distributor_option('sports_south', 'customer_number', ''),
        (string) Options::get_distributor_option('sports_south', 'username', ''),
        (string) Options::get_distributor_option('sports_south', 'password', ''),
        (string) Options::get_distributor_option('sports_south', 'source', ''),
        (string) Options::get_distributor_option('sports_south', 'inventory_api_base_url', SportsSouthInventoryClient::DEFAULT_BASE_URL),
        240
    );

    if (!$client->has_credentials()) {
        throw new RuntimeException('Missing Sports South credentials.');
    }

    $t = microtime(true);
    $response = $client->daily_item_update_to_file($xmlPath, $lastUpdate, $lastItem);
    $response['elapsed_ms'] = fflhub_ss_bench_ms($t);

    if (empty($response['ok'])) {
        throw new RuntimeException('DailyItemUpdate download failed: ' . (string) ($response['error'] ?? 'unknown error'));
    }

    return $response;
}

/**
 * @return array<string,mixed>
 */
function fflhub_ss_bench_run_load_xml(string $xmlPath, string $table, int $timeoutSeconds): array
{
    global $wpdb;

    $summary = [
        'success' => false,
        'rows_imported' => 0,
        'setup_seconds' => 0.0,
        'load_seconds' => 0.0,
        'index_seconds' => 0.0,
        'total_seconds' => 0.0,
        'peak_php_memory_mb' => 0.0,
        'mariadb_error' => '',
        'table' => $table,
    ];

    $tTotal = microtime(true);

    try {
        $setupMs = fflhub_ss_bench_recreate_table($table);
        $summary['setup_seconds'] = round($setupMs / 1000.0, 3);

        if ($timeoutSeconds > 0) {
            $result = $wpdb->query($wpdb->prepare('SET SESSION max_statement_time = %f', (float) $timeoutSeconds));
            if ($result === false) {
                throw new RuntimeException('SET max_statement_time failed: ' . (string) $wpdb->last_error);
            }
        }

        $vars = [
            '@ITEMNO', '@ITEMNUMBER', '@I',
            '@UPC', '@ITUPC', '@U', '@BARCODE', '@GTIN',
            '@QTYOH', '@ONHAND', '@QTY', '@QUANTITY',
            '@CPRC', '@C', '@CUSTOMERPRICE', '@CUSTOMER_PRICE', '@PRICE',
            '@PRC1', '@P', '@CATALOGPRICE', '@CATALOG_PRICE', '@LISTPRICE',
            '@MAP', '@ITMAP', '@MINADVERTISEDPRICE',
            '@MFPRTYP', '@MFPRC', '@MSRP', '@ITMSRP', '@RETAIL',
            '@ITDESC', '@DESC', '@DESCRIPTION', '@IDESC', '@ITEMDESC', '@NAME',
            '@SHDESC', '@LONGDESC', '@LONGDESCRIPTION', '@TEXT',
            '@CATID', '@CATEGORYID', '@CAT',
            '@ITYPE', '@CATDESC', '@CATEGORY', '@TYPE', '@ITEMTYPE', '@DEPT',
            '@BRAND', '@BRDNAM', '@MFG', '@MANUFACTURER', '@ITBRD',
            '@ITBRDNO', '@BRDNO', '@BRANDNO',
            '@MFGINO', '@MFGITEMNO', '@MFGNO', '@MFGITEM', '@ITMFGNO', '@M', '@MANUFACTURERPARTNUMBER',
            '@IMODEL', '@MODEL', '@ITMODEL',
            '@CALIBER', '@GAUGE', '@CALGAUGE',
            '@RESTRICTEDSTATES', '@STATE_RESTRICTIONS', '@STATES',
            '@DROPSHIP', '@DROPSHIPENABLED', '@FULFILLMENT', '@CANSHIPDIRECT',
            '@WTPBX', '@WEIGHT', '@WT', '@SHPWT',
            '@LENGTH', '@LEN', '@SHPLEN',
            '@WIDTH', '@WID', '@SHPWID',
            '@HEIGHT', '@HGT', '@SHPHGT',
            '@PICREF', '@PICTURE', '@IMAGE',
            '@TXTREF', '@TEXTREF',
        ];
        $varList = implode(', ', $vars);
        $now = gmdate('Y-m-d H:i:s');

        $sql = "
            LOAD XML LOCAL INFILE %s
            INTO TABLE {$table}
            CHARACTER SET utf8mb4
            ROWS IDENTIFIED BY '<Table>'
            ({$varList})
            SET
                upc = REGEXP_REPLACE(COALESCE(NULLIF(TRIM(@UPC), ''), NULLIF(TRIM(@ITUPC), ''), NULLIF(TRIM(@U), ''), NULLIF(TRIM(@BARCODE), ''), NULLIF(TRIM(@GTIN), ''), ''), '[^0-9]', ''),
                sports_south_item_number = COALESCE(NULLIF(TRIM(@ITEMNO), ''), NULLIF(TRIM(@ITEMNUMBER), ''), NULLIF(TRIM(@I), ''), ''),
                remote_identifier = COALESCE(NULLIF(TRIM(@ITEMNO), ''), NULLIF(TRIM(@ITEMNUMBER), ''), NULLIF(TRIM(@I), ''), ''),
                inventory_quantity = CAST(GREATEST(CAST(COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(@QTYOH, @ONHAND, @QTY, @QUANTITY, ''), '[^0-9-]', ''), ''), '0') AS SIGNED), 0) AS CHAR),
                allocation_status = IF(CAST(COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(@QTYOH, @ONHAND, @QTY, @QUANTITY, ''), '[^0-9-]', ''), ''), '0') AS SIGNED) > 0, 'in_stock', 'out_of_stock'),
                distributor_price = NULLIF(REGEXP_REPLACE(COALESCE(@CPRC, @C, @CUSTOMERPRICE, @CUSTOMER_PRICE, @PRICE, ''), '[^0-9.-]', ''), ''),
                shipping_cost = '7.95',
                catalog_price = NULLIF(REGEXP_REPLACE(COALESCE(@PRC1, @P, @CATALOGPRICE, @CATALOG_PRICE, @LISTPRICE, ''), '[^0-9.-]', ''), ''),
                retail_map = IF(UPPER(TRIM(COALESCE(@MFPRTYP, ''))) = 'M', NULLIF(REGEXP_REPLACE(COALESCE(@MFPRC, ''), '[^0-9.-]', ''), ''), NULLIF(REGEXP_REPLACE(COALESCE(@MAP, @ITMAP, @MINADVERTISEDPRICE, ''), '[^0-9.-]', ''), '')),
                retail_msrp = IF(UPPER(TRIM(COALESCE(@MFPRTYP, ''))) = 'M', NULL, NULLIF(REGEXP_REPLACE(COALESCE(@MSRP, @ITMSRP, @RETAIL, @MFPRC, ''), '[^0-9.-]', ''), '')),
                product_name = COALESCE(NULLIF(TRIM(@ITDESC), ''), NULLIF(TRIM(@DESC), ''), NULLIF(TRIM(@DESCRIPTION), ''), NULLIF(TRIM(@IDESC), ''), NULLIF(TRIM(@ITEMDESC), ''), NULLIF(TRIM(@NAME), ''), ''),
                product_description = COALESCE(NULLIF(TRIM(@SHDESC), ''), NULLIF(TRIM(@LONGDESC), ''), NULLIF(TRIM(@LONGDESCRIPTION), ''), NULLIF(TRIM(@TEXT), ''), NULLIF(TRIM(@DESCRIPTION), ''), NULLIF(TRIM(@IDESC), ''), NULLIF(TRIM(@ITDESC), ''), ''),
                manufacturer = COALESCE(NULLIF(TRIM(@BRAND), ''), NULLIF(TRIM(@BRDNAM), ''), NULLIF(TRIM(@MFG), ''), NULLIF(TRIM(@MANUFACTURER), ''), NULLIF(TRIM(@ITBRD), ''), ''),
                brand_number = COALESCE(NULLIF(TRIM(@ITBRDNO), ''), NULLIF(TRIM(@BRDNO), ''), NULLIF(TRIM(@BRANDNO), ''), ''),
                model = COALESCE(NULLIF(TRIM(@IMODEL), ''), NULLIF(TRIM(@MODEL), ''), NULLIF(TRIM(@ITMODEL), ''), ''),
                manufacturer_part_number = COALESCE(NULLIF(TRIM(@MFGINO), ''), NULLIF(TRIM(@MFGITEMNO), ''), NULLIF(TRIM(@MFGNO), ''), NULLIF(TRIM(@MFGITEM), ''), NULLIF(TRIM(@ITMFGNO), ''), NULLIF(TRIM(@M), ''), NULLIF(TRIM(@MANUFACTURERPARTNUMBER), ''), ''),
                category_id = COALESCE(NULLIF(TRIM(@CATID), ''), NULLIF(TRIM(@CATEGORYID), ''), NULLIF(TRIM(@CAT), ''), ''),
                item_type = COALESCE(NULLIF(TRIM(@ITYPE), ''), NULLIF(TRIM(@CATDESC), ''), NULLIF(TRIM(@CATEGORY), ''), NULLIF(TRIM(@TYPE), ''), NULLIF(TRIM(@ITEMTYPE), ''), NULLIF(TRIM(@DEPT), ''), ''),
                caliber_gauge = COALESCE(NULLIF(TRIM(@CALIBER), ''), NULLIF(TRIM(@GAUGE), ''), NULLIF(TRIM(@CALGAUGE), ''), ''),
                ffl_required = 0,
                sot_required = 0,
                dropship_enabled = IF(UPPER(TRIM(COALESCE(@DROPSHIP, @DROPSHIPENABLED, @FULFILLMENT, @CANSHIPDIRECT, ''))) IN ('0', 'N', 'NO', 'FALSE', 'F'), 0, 1),
                dropship_block_reason = IF(UPPER(TRIM(COALESCE(@DROPSHIP, @DROPSHIPENABLED, @FULFILLMENT, @CANSHIPDIRECT, ''))) IN ('0', 'N', 'NO', 'FALSE', 'F'), 'feed_flag', ''),
                restricted_states = COALESCE(NULLIF(TRIM(@RESTRICTEDSTATES), ''), NULLIF(TRIM(@STATE_RESTRICTIONS), ''), NULLIF(TRIM(@STATES), ''), ''),
                shipping_weight = CASE WHEN NULLIF(REGEXP_REPLACE(COALESCE(@WTPBX, @WEIGHT, @WT, @SHPWT, ''), '[^0-9.-]', ''), '') IS NULL THEN NULL ELSE CAST(REGEXP_REPLACE(COALESCE(@WTPBX, @WEIGHT, @WT, @SHPWT, ''), '[^0-9.-]', '') AS DECIMAL(10,2)) * 16 END,
                shipping_length_in = NULLIF(REGEXP_REPLACE(COALESCE(@LENGTH, @LEN, @SHPLEN, ''), '[^0-9.-]', ''), ''),
                shipping_width_in = NULLIF(REGEXP_REPLACE(COALESCE(@WIDTH, @WID, @SHPWID, ''), '[^0-9.-]', ''), ''),
                shipping_height_in = NULLIF(REGEXP_REPLACE(COALESCE(@HEIGHT, @HGT, @SHPHGT, ''), '[^0-9.-]', ''), ''),
                image_ref = COALESCE(NULLIF(TRIM(@PICREF), ''), NULLIF(TRIM(@PICTURE), ''), NULLIF(TRIM(@IMAGE), ''), NULLIF(TRIM(@ITEMNO), ''), NULLIF(TRIM(@ITEMNUMBER), ''), NULLIF(TRIM(@I), ''), ''),
                image_url = IF(COALESCE(NULLIF(TRIM(@PICREF), ''), NULLIF(TRIM(@PICTURE), ''), NULLIF(TRIM(@IMAGE), ''), NULLIF(TRIM(@ITEMNO), ''), NULLIF(TRIM(@ITEMNUMBER), ''), NULLIF(TRIM(@I), '')) IS NULL, '', CONCAT('https://media.server.theshootingwarehouse.com/large/', COALESCE(NULLIF(TRIM(@PICREF), ''), NULLIF(TRIM(@PICTURE), ''), NULLIF(TRIM(@IMAGE), ''), NULLIF(TRIM(@ITEMNO), ''), NULLIF(TRIM(@ITEMNUMBER), ''), NULLIF(TRIM(@I), '')), '.jpg')),
                text_ref = COALESCE(NULLIF(TRIM(@TXTREF), ''), NULLIF(TRIM(@TEXTREF), ''), ''),
                last_seen_utc = '{$now}'
        ";

        $tLoad = microtime(true);
        $loadResult = $wpdb->query($wpdb->prepare($sql, $xmlPath)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $summary['load_seconds'] = round(fflhub_ss_bench_ms($tLoad) / 1000.0, 3);
        if ($loadResult === false) {
            throw new RuntimeException('LOAD XML failed: ' . (string) $wpdb->last_error);
        }

        $summary['rows_imported'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $tIndex = microtime(true);
        fflhub_ss_bench_add_indexes($table);
        $summary['index_seconds'] = round(fflhub_ss_bench_ms($tIndex) / 1000.0, 3);
        $summary['success'] = true;
    } catch (Throwable $e) {
        $summary['mariadb_error'] = $e->getMessage();
    } finally {
        $wpdb->query('SET SESSION max_statement_time = DEFAULT');
        $summary['total_seconds'] = round(fflhub_ss_bench_ms($tTotal) / 1000.0, 3);
        $summary['peak_php_memory_mb'] = fflhub_ss_bench_peak_mb();
    }

    return $summary;
}

/**
 * @return array<string,mixed>
 */
function fflhub_ss_bench_write_tsv(string $xmlPath, string $tsvPath, array $columns): array
{
    $parser = new SportsSouthProductParser();
    $handle = fopen($tsvPath, 'wb');
    if (!$handle) {
        throw new RuntimeException('Failed to open TSV path: ' . $tsvPath);
    }

    $stats = [
        'xml_rows_seen' => 0,
        'rows_written' => 0,
        'value_build_ms' => 0.0,
        'fputcsv_ms' => 0.0,
        'tsv_bytes' => 0,
    ];

    $tParse = microtime(true);
    $rowsSeen = $parser->each_catalog_row($xmlPath, function (array $row) use ($handle, $columns, &$stats): void {
        $row['shipping_cost'] = '7.95';
        $row['last_seen_utc'] = gmdate('Y-m-d H:i:s');

        $t = microtime(true);
        $values = [];
        foreach ($columns as $column) {
            $values[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
        }
        $stats['value_build_ms'] += (microtime(true) - $t) * 1000.0;

        $t = microtime(true);
        fputcsv($handle, $values, "\t", '"', '\\');
        $stats['fputcsv_ms'] += (microtime(true) - $t) * 1000.0;
        $stats['rows_written']++;
    });
    fclose($handle);

    $parseAndWriteMs = fflhub_ss_bench_ms($tParse);
    clearstatcache(true, $tsvPath);
    $stats['xml_rows_seen'] = (int) $rowsSeen;
    $stats['tsv_bytes'] = file_exists($tsvPath) ? (int) filesize($tsvPath) : 0;
    $stats['parse_write_seconds'] = round($parseAndWriteMs / 1000.0, 3);
    $stats['tsv_write_seconds'] = round((($stats['value_build_ms'] + $stats['fputcsv_ms']) / 1000.0), 3);
    $stats['parse_normalize_estimated_seconds'] = round(max(0.0, $parseAndWriteMs - $stats['value_build_ms'] - $stats['fputcsv_ms']) / 1000.0, 3);

    return $stats;
}

/**
 * @return array<string,mixed>
 */
function fflhub_ss_bench_run_tsv_load_data(string $xmlPath, string $tsvPath, string $table, array $columns): array
{
    global $wpdb;

    $summary = [
        'success' => false,
        'rows_imported' => 0,
        'parse_seconds' => 0.0,
        'tsv_write_seconds' => 0.0,
        'load_data_seconds' => 0.0,
        'index_seconds' => 0.0,
        'setup_seconds' => 0.0,
        'total_seconds' => 0.0,
        'peak_php_memory_mb' => 0.0,
        'error' => '',
        'table' => $table,
        'tsv_path' => $tsvPath,
        'tsv_bytes' => 0,
    ];

    $tTotal = microtime(true);

    try {
        $setupMs = fflhub_ss_bench_recreate_table($table);
        $summary['setup_seconds'] = round($setupMs / 1000.0, 3);

        $writeStats = fflhub_ss_bench_write_tsv($xmlPath, $tsvPath, $columns);
        $summary['parse_seconds'] = (float) $writeStats['parse_normalize_estimated_seconds'];
        $summary['tsv_write_seconds'] = (float) $writeStats['tsv_write_seconds'];
        $summary['parse_write_seconds'] = (float) $writeStats['parse_write_seconds'];
        $summary['xml_rows_seen'] = (int) $writeStats['xml_rows_seen'];
        $summary['rows_written'] = (int) $writeStats['rows_written'];
        $summary['tsv_bytes'] = (int) $writeStats['tsv_bytes'];

        $columnList = implode(', ', array_map('fflhub_ss_bench_identifier', $columns));
        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$table}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY '\t' ENCLOSED BY '\"' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\n'
            ({$columnList})
        ";

        $tLoad = microtime(true);
        $loadResult = $wpdb->query($wpdb->prepare($sql, $tsvPath)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $summary['load_data_seconds'] = round(fflhub_ss_bench_ms($tLoad) / 1000.0, 3);
        if ($loadResult === false) {
            throw new RuntimeException('LOAD DATA failed: ' . (string) $wpdb->last_error);
        }

        $summary['rows_imported'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $tIndex = microtime(true);
        fflhub_ss_bench_add_indexes($table);
        $summary['index_seconds'] = round(fflhub_ss_bench_ms($tIndex) / 1000.0, 3);
        $summary['success'] = true;
    } catch (Throwable $e) {
        $summary['error'] = $e->getMessage();
    } finally {
        $summary['total_seconds'] = round(fflhub_ss_bench_ms($tTotal) / 1000.0, 3);
        $summary['peak_php_memory_mb'] = fflhub_ss_bench_peak_mb();
    }

    return $summary;
}

$rawArgs = isset($args) && is_array($args) ? $args : [];
$outputDir = rtrim((string) fflhub_ss_bench_arg($rawArgs, 'output-dir', '/tmp/fflhub-ss-loadxml-benchmark'), '/\\');
$download = fflhub_ss_bench_bool(fflhub_ss_bench_arg($rawArgs, 'download', '1'));
$xmlPath = (string) fflhub_ss_bench_arg($rawArgs, 'xml', '');
$limit = max(0, (int) fflhub_ss_bench_arg($rawArgs, 'limit', '0'));
$loadXmlTimeout = max(0, (int) fflhub_ss_bench_arg($rawArgs, 'load-xml-timeout', '180'));
$lastUpdate = (string) fflhub_ss_bench_arg($rawArgs, 'last-update', '1/1/1990');
$lastItem = (int) fflhub_ss_bench_arg($rawArgs, 'last-item', '-1');

if (!is_dir($outputDir) && !wp_mkdir_p($outputDir)) {
    WP_CLI::error('Failed to create output dir: ' . $outputDir);
}

$stamp = gmdate('Ymd_His');
if ($xmlPath === '') {
    $xmlPath = $outputDir . '/sports-south-daily-item-update-' . $stamp . '.xml';
}

fflhub_ss_bench_line('Sports South DailyItemUpdate import benchmark');
fflhub_ss_bench_line('Output dir: ' . $outputDir);
fflhub_ss_bench_line('DB diagnostics: ' . wp_json_encode(fflhub_ss_bench_db_diagnostics(), JSON_UNESCAPED_SLASHES));

$downloadSummary = [
    'used_existing_xml' => !$download,
    'xml_path' => $xmlPath,
    'elapsed_seconds' => 0.0,
    'xml_bytes' => file_exists($xmlPath) ? (int) filesize($xmlPath) : 0,
];

try {
    if ($download) {
        fflhub_ss_bench_line('Downloading DailyItemUpdate XML...');
        $downloadResult = fflhub_ss_bench_download_xml($xmlPath, $lastUpdate, $lastItem);
        $downloadSummary = [
            'used_existing_xml' => false,
            'xml_path' => $xmlPath,
            'raw_path' => (string) ($downloadResult['raw_path'] ?? ''),
            'elapsed_seconds' => round(((float) ($downloadResult['elapsed_ms'] ?? 0)) / 1000.0, 3),
            'xml_bytes' => (int) ($downloadResult['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($downloadResult['body_bytes'] ?? 0),
            'status' => (int) ($downloadResult['status'] ?? 0),
        ];
    } elseif (!is_readable($xmlPath)) {
        WP_CLI::error('xml= path is not readable: ' . $xmlPath);
    }

    if ($limit > 0) {
        $samplePath = $outputDir . '/sports-south-daily-item-update-sample-' . $limit . '-' . $stamp . '.xml';
        $sampleRows = fflhub_ss_bench_make_sample_xml($xmlPath, $samplePath, $limit);
        $downloadSummary['original_xml_path'] = $xmlPath;
        $downloadSummary['sample_xml_path'] = $samplePath;
        $downloadSummary['sample_rows'] = $sampleRows;
        $xmlPath = $samplePath;
    }

    clearstatcache(true, $xmlPath);
    $shape = fflhub_ss_bench_xml_shape($xmlPath);
    $columns = fflhub_ss_bench_columns();

    global $wpdb;
    $prefix = $wpdb->prefix;
    $loadXmlTable = $prefix . 'fflhub_ss_bench_loadxml';
    $loadDataTable = $prefix . 'fflhub_ss_bench_loaddata';
    $tsvPath = $outputDir . '/sports-south-daily-item-update-' . $stamp . '.tsv';

    fflhub_ss_bench_line('XML shape: ' . wp_json_encode($shape, JSON_UNESCAPED_SLASHES));
    fflhub_ss_bench_line('Benchmark XML path: ' . $xmlPath);
    fflhub_ss_bench_line('Benchmark XML bytes: ' . (string) (file_exists($xmlPath) ? filesize($xmlPath) : 0));
    fflhub_ss_bench_line('');

    fflhub_ss_bench_line('Running LOAD XML benchmark...');
    $loadXmlSummary = fflhub_ss_bench_run_load_xml($xmlPath, $loadXmlTable, $loadXmlTimeout);
    fflhub_ss_bench_line('LOAD XML summary: ' . wp_json_encode($loadXmlSummary, JSON_UNESCAPED_SLASHES));
    if (!$loadXmlSummary['success']) {
        WP_CLI::warning('LOAD XML failed or timed out: ' . (string) $loadXmlSummary['mariadb_error']);
    }

    fflhub_ss_bench_line('');
    fflhub_ss_bench_line('Running XMLReader -> TSV -> LOAD DATA benchmark...');
    $loadDataSummary = fflhub_ss_bench_run_tsv_load_data($xmlPath, $tsvPath, $loadDataTable, $columns);
    fflhub_ss_bench_line('TSV + LOAD DATA summary: ' . wp_json_encode($loadDataSummary, JSON_UNESCAPED_SLASHES));
    if (!$loadDataSummary['success']) {
        WP_CLI::warning('TSV + LOAD DATA failed: ' . (string) $loadDataSummary['error']);
    }

    $recommendation = 'No winner: one or both paths failed.';
    if (!empty($loadXmlSummary['success']) && !empty($loadDataSummary['success'])) {
        $recommendation = ((float) $loadXmlSummary['total_seconds'] <= (float) $loadDataSummary['total_seconds'])
            ? 'LOAD XML was faster in this benchmark.'
            : 'XMLReader -> TSV -> LOAD DATA was faster in this benchmark.';
    } elseif (empty($loadXmlSummary['success']) && !empty($loadDataSummary['success'])) {
        $recommendation = 'LOAD XML failed; XMLReader -> TSV -> LOAD DATA is the viable path.';
    } elseif (!empty($loadXmlSummary['success']) && empty($loadDataSummary['success'])) {
        $recommendation = 'TSV + LOAD DATA failed; inspect its error before changing production code.';
    }

    $final = [
        'download' => $downloadSummary,
        'xml_shape' => $shape,
        'load_xml' => $loadXmlSummary,
        'tsv_load_data' => $loadDataSummary,
        'comparison' => [
            'recommendation' => $recommendation,
            'load_xml_timeout_seconds' => $loadXmlTimeout,
            'same_xml_file_used' => true,
            'tables_left_for_inspection' => [
                'load_xml' => $loadXmlTable,
                'tsv_load_data' => $loadDataTable,
            ],
        ],
    ];

    fflhub_ss_bench_line('');
    fflhub_ss_bench_line('Final comparison:');
    fflhub_ss_bench_line(wp_json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) {
    WP_CLI::error($e->getMessage());
}
