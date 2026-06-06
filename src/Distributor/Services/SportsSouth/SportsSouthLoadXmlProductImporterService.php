<?php

namespace FFLHub\Distributor\Services\SportsSouth;

use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Experimental Sports South product LOAD XML importer.
 *
 * This class is intentionally shadow-only for now. It builds a separate
 * normalized table from the same DailyItemUpdate XML and compares it with the
 * legacy staging table; it does not participate in live-table swaps.
 */
final class SportsSouthLoadXmlProductImporterService
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthLoadXmlProductImporter]';

    private const RAW_STAGE_SUFFIX = 'fflhub_sports_south_product_raw_stage';
    private const SHADOW_SUFFIX = 'fflhub_sports_south_product_loadxml_shadow';
    private const MANUFACTURER_POLICY_SUFFIX = 'fflhub_sports_south_fulfillment_manufacturer_policy_stage';
    private const ITEM_POLICY_SUFFIX = 'fflhub_sports_south_fulfillment_item_policy_stage';

    private const IMAGE_BASE = 'https://media.server.theshootingwarehouse.com';
    private const NON_FFL_SHIPPING_COST = '7.95';
    private const FFL_SHIPPING_COST = '8.95';
    private const DEFAULT_MAX_XML_BYTES = 52428800; // 50 MB; full Sports South files hang MariaDB LOAD XML.

    private DoubleBufferedProductTable $table;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    /**
     * Build the shadow table and compare it to the legacy staging table.
     *
     * @return array<string,mixed>
     */
    public function run_shadow_validation(string $xmlFilePath, string $legacyStagingTable): array
    {
        $t_total = microtime(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!is_readable($xmlFilePath)) {
            return [
                'ok' => false,
                'error' => 'XML file missing/unreadable',
            ];
        }

        $xml_bytes = (int) filesize($xmlFilePath);
        $max_bytes = (int) apply_filters('fflhub_sports_south_loadxml_shadow_max_bytes', self::DEFAULT_MAX_XML_BYTES, $xmlFilePath, $this);
        if ($max_bytes > 0 && $xml_bytes > $max_bytes) {
            $ctx = [
                'ok' => false,
                'skipped' => true,
                'reason' => 'xml_too_large_for_mariadb_load_xml_shadow',
                'xml_path' => $xmlFilePath,
                'xml_bytes' => $xml_bytes,
                'max_bytes' => $max_bytes,
            ];
            $this->log('Sports South product LOAD XML shadow skipped.', $ctx);
            return $ctx;
        }

        $t_ensure = microtime(true);
        $this->log('Sports South product LOAD XML shadow phase start.', [
            'phase' => 'ensure_tables',
        ]);
        $this->ensure_tables();
        $ensure_ms = $this->format_ms((microtime(true) - $t_ensure) * 1000.0);

        $t_policy = microtime(true);
        $this->log('Sports South product LOAD XML shadow phase start.', [
            'phase' => 'refresh_policy_tables',
        ]);
        $this->refresh_policy_tables();
        $policy_ms = $this->format_ms((microtime(true) - $t_policy) * 1000.0);

        $raw_table = $this->raw_stage_table();
        $shadow_table = $this->shadow_table();

        $t_detect = microtime(true);
        $this->log('Sports South product LOAD XML shadow phase start.', [
            'phase' => 'detect_row_tag',
        ]);
        $row_tag = $this->detect_row_tag($xmlFilePath);
        $detect_ms = $this->format_ms((microtime(true) - $t_detect) * 1000.0);

        global $wpdb;

        $t_drop_indexes = microtime(true);
        $this->drop_raw_helper_indexes($raw_table);
        $drop_index_ms = $this->format_ms((microtime(true) - $t_drop_indexes) * 1000.0);

        $t_load = microtime(true);
        $this->log('Sports South product LOAD XML shadow phase start.', [
            'phase' => 'load_raw_xml',
            'row_tag' => $row_tag,
            'xml_path' => $xmlFilePath,
            'xml_bytes' => file_exists($xmlFilePath) ? (int) filesize($xmlFilePath) : 0,
            'drop_raw_helper_indexes_ms' => $drop_index_ms,
        ]);
        $wpdb->query("TRUNCATE TABLE {$raw_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $load_result = $this->load_raw_xml($raw_table, $xmlFilePath, $row_tag);
        $raw_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$raw_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $load_ms = $this->format_ms((microtime(true) - $t_load) * 1000.0);
        if ($load_result === false || $raw_rows <= 0) {
            $ctx = [
                'ok' => false,
                'row_tag' => $row_tag,
                'load_result' => $load_result === false ? 'false' : (int) $load_result,
                'raw_rows_loaded' => $raw_rows,
                'xml_path' => $xmlFilePath,
                'xml_bytes' => file_exists($xmlFilePath) ? (int) filesize($xmlFilePath) : 0,
                'load_xml_ms' => $load_ms,
                'error' => (string) $wpdb->last_error,
            ];
            $this->log('Sports South product LOAD XML raw load failed.', $ctx);
            return $ctx;
        }

        $t_norm = microtime(true);
        $this->log('Sports South product LOAD XML shadow phase start.', [
            'phase' => 'normalize_raw_stage',
            'raw_rows_loaded' => $raw_rows,
        ]);
        $this->normalize_raw_stage($raw_table);
        $norm_ms = $this->format_ms((microtime(true) - $t_norm) * 1000.0);

        $t_add_indexes = microtime(true);
        $this->ensure_raw_helper_indexes($raw_table);
        $add_index_ms = $this->format_ms((microtime(true) - $t_add_indexes) * 1000.0);

        $t_transform = microtime(true);
        $this->log('Sports South product LOAD XML shadow phase start.', [
            'phase' => 'insert_shadow_rows',
            'raw_rows_loaded' => $raw_rows,
            'normalize_ms' => $norm_ms,
            'add_raw_helper_indexes_ms' => $add_index_ms,
        ]);
        $wpdb->query("TRUNCATE TABLE {$shadow_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->insert_shadow_rows($raw_table, $shadow_table);
        $rows_before_accessories = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadow_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $accessories_only_skipped = 0;
        if (SportsSouthAccessoriesOnlyPolicy::is_enabled()) {
            $accessories_only_skipped = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadow_table} WHERE ffl_required = 1 OR sot_required = 1"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DELETE FROM {$shadow_table} WHERE ffl_required = 1 OR sot_required = 1"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $shadow_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadow_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $transform_ms = $this->format_ms((microtime(true) - $t_transform) * 1000.0);

        $t_validate = microtime(true);
        $this->log('Sports South product LOAD XML shadow phase start.', [
            'phase' => 'validate_shadow',
            'shadow_rows' => $shadow_rows,
        ]);
        $validation = $this->validate_shadow($legacyStagingTable, $shadow_table, $raw_table);
        $validation_ms = $this->format_ms((microtime(true) - $t_validate) * 1000.0);

        $blank_upc_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$raw_table} WHERE upc_norm = '' OR LOWER(upc_norm) = 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $distinct_upc_rows = (int) $wpdb->get_var("SELECT COUNT(DISTINCT upc_norm) FROM {$raw_table} WHERE upc_norm <> '' AND LOWER(upc_norm) <> 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $nonblank_upc_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$raw_table} WHERE upc_norm <> '' AND LOWER(upc_norm) <> 'null'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $duplicate_upc_rows = max(0, $nonblank_upc_rows - $distinct_upc_rows);

        $stats = array_merge([
            'ok' => true,
            'mode' => 'loadxml_shadow',
            'xml_path' => $xmlFilePath,
            'xml_bytes' => file_exists($xmlFilePath) ? (int) filesize($xmlFilePath) : 0,
            'row_tag' => $row_tag,
            'load_result_untrusted' => is_numeric($load_result) ? (int) $load_result : 0,
            'raw_rows_loaded' => $raw_rows,
            'blank_upc_rows' => $blank_upc_rows,
            'distinct_upc_rows' => $distinct_upc_rows,
            'duplicate_upc_rows' => $duplicate_upc_rows,
            'rows_before_accessories_filter' => $rows_before_accessories,
            'accessories_only_enabled' => SportsSouthAccessoriesOnlyPolicy::is_enabled() ? 1 : 0,
            'accessories_only_skipped' => $accessories_only_skipped,
            'shadow_rows' => $shadow_rows,
            'legacy_staging_table' => $legacyStagingTable,
            'shadow_table' => $shadow_table,
            'raw_stage_table' => $raw_table,
            'ensure_tables_ms' => $ensure_ms,
            'refresh_policy_tables_ms' => $policy_ms,
            'detect_row_tag_ms' => $detect_ms,
            'drop_raw_helper_indexes_ms' => $drop_index_ms,
            'load_xml_ms' => $load_ms,
            'normalize_ms' => $norm_ms,
            'add_raw_helper_indexes_ms' => $add_index_ms,
            'transform_ms' => $transform_ms,
            'validation_ms' => $validation_ms,
            'elapsed_ms' => $this->format_ms((microtime(true) - $t_total) * 1000.0),
        ], $validation);

        $this->log('Sports South product LOAD XML shadow validation complete.', $stats);

        return $stats;
    }

    private function ensure_tables(): void
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset = $wpdb->get_charset_collate();
        $raw_columns = [
            'raw_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        ];

        foreach ($this->raw_feed_columns() as $name => $type) {
            $raw_columns[] = $this->quote_identifier($name) . ' ' . $type . ' NULL';
        }

        $raw_columns[] = "upc_norm VARCHAR(32) NOT NULL DEFAULT ''";
        $raw_columns[] = "item_number_norm VARCHAR(64) NOT NULL DEFAULT ''";
        $raw_columns[] = "brand_number_norm VARCHAR(64) NOT NULL DEFAULT ''";
        $raw_columns[] = "category_id_norm VARCHAR(64) NOT NULL DEFAULT ''";
        $raw_columns[] = 'PRIMARY KEY  (raw_id)';
        dbDelta("CREATE TABLE {$this->raw_stage_table()} (\n" . implode(",\n", $raw_columns) . "\n) {$charset};");

        $schema = $this->table->get_schema();
        $product_columns = [];
        foreach ($schema->get_column_definitions() as $name => $def) {
            $product_columns[] = $this->quote_identifier((string) $name) . ' ' . $def;
        }
        foreach ($schema->get_index_definitions() as $index) {
            $product_columns[] = $index;
        }
        dbDelta("CREATE TABLE {$this->shadow_table()} (\n" . implode(",\n", $product_columns) . "\n) {$charset};");

        dbDelta(
            "CREATE TABLE {$this->manufacturer_policy_table()} (
manufacturer_key VARCHAR(191) NOT NULL,
policy_type VARCHAR(64) NOT NULL,
block_reason VARCHAR(255) NOT NULL,
factory_group VARCHAR(128) NOT NULL DEFAULT '',
approved TINYINT(1) NOT NULL DEFAULT 0,
sig_group TINYINT(1) NOT NULL DEFAULT 0,
priority TINYINT UNSIGNED NOT NULL DEFAULT 9,
PRIMARY KEY  (manufacturer_key, policy_type, factory_group),
KEY priority (priority),
KEY sig_group (sig_group),
KEY approved (approved)
) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$this->item_policy_table()} (
sports_south_item_number VARCHAR(64) NOT NULL,
block_reason VARCHAR(255) NOT NULL,
PRIMARY KEY  (sports_south_item_number)
) {$charset};"
        );
    }

    private function refresh_policy_tables(): void
    {
        global $wpdb;

        $manufacturer_table = $this->manufacturer_policy_table();
        $item_table = $this->item_policy_table();
        $wpdb->query("TRUNCATE TABLE {$manufacturer_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("TRUNCATE TABLE {$item_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $rows = SportsSouthFulfillmentPolicy::sql_policy_reference_rows();
        if (!empty($rows)) {
            $values = [];
            $placeholders = [];
            foreach ($rows as $row) {
                $policy_type = (string) ($row['policy_type'] ?? '');
                $priority = $policy_type === 'no_fulfillment' ? 1 : 2;
                $placeholders[] = '(%s, %s, %s, %s, %d, %d, %d)';
                $values[] = (string) ($row['manufacturer_key'] ?? '');
                $values[] = $policy_type;
                $values[] = (string) ($row['block_reason'] ?? '');
                $values[] = (string) ($row['factory_group'] ?? '');
                $values[] = (int) ($row['approved'] ?? 0);
                $values[] = (int) ($row['sig_group'] ?? 0);
                $values[] = $priority;
            }
            $sql = "INSERT IGNORE INTO {$manufacturer_table} (manufacturer_key, policy_type, block_reason, factory_group, approved, sig_group, priority) VALUES " . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $item_rows = SportsSouthFulfillmentPolicy::size_weight_restricted_item_rows();
        if (!empty($item_rows)) {
            $values = [];
            $placeholders = [];
            foreach ($item_rows as $item_number => $reason) {
                $placeholders[] = '(%s, %s)';
                $values[] = (string) $item_number;
                $values[] = (string) $reason;
            }
            $sql = "INSERT IGNORE INTO {$item_table} (sports_south_item_number, block_reason) VALUES " . implode(', ', $placeholders);
            $wpdb->query($wpdb->prepare($sql, $values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private function detect_row_tag(string $xmlFilePath): string
    {
        $candidates = ['Table', 'Item', 'ITEM', 'Product', 'DailyItem', 'InventoryItem'];
        $lookup = array_fill_keys($candidates, true);
        if (!class_exists('\XMLReader')) {
            return 'Table';
        }

        $reader = new \XMLReader();
        if (!$reader->open($xmlFilePath, null, LIBXML_NONET | LIBXML_NOCDATA)) {
            return 'Table';
        }

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && isset($lookup[$reader->localName])) {
                $tag = $reader->localName;
                $reader->close();
                return $tag;
            }
        }

        $reader->close();
        return 'Table';
    }

    /**
     * @return int|false
     */
    private function load_raw_xml(string $rawTable, string $xmlFilePath, string $rowTag)
    {
        global $wpdb;

        $sql = "
            LOAD XML LOCAL INFILE %s
            INTO TABLE {$rawTable}
            CHARACTER SET utf8mb4
            ROWS IDENTIFIED BY %s
        ";

        return $wpdb->query($wpdb->prepare($sql, $xmlFilePath, '<' . $rowTag . '>')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function normalize_raw_stage(string $rawTable): void
    {
        global $wpdb;

        $upc = $this->normalize_digits_expr($this->first_expr('R', ['UPC', 'ITUPC', 'U', 'BARCODE', 'GTIN']));
        $item = $this->clean_text_expr($this->first_expr('R', ['ITEMNO', 'ITEMNUMBER', 'I']));
        $brand = $this->clean_text_expr($this->first_expr('R', ['ITBRDNO', 'BRDNO', 'BRANDNO']));
        $category = $this->clean_text_expr($this->first_expr('R', ['CATID', 'CATEGORYID', 'CAT']));

        $sql = "
            UPDATE {$rawTable} R
            SET
                upc_norm = {$upc},
                item_number_norm = {$item},
                brand_number_norm = {$brand},
                category_id_norm = {$category}
        ";
        $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function drop_raw_helper_indexes(string $rawTable): void
    {
        global $wpdb;

        foreach ($this->raw_helper_indexes() as $index => $column) {
            unset($column);
            if (!$this->index_exists($rawTable, (string) $index)) {
                continue;
            }

            $wpdb->query('DROP INDEX ' . $this->quote_identifier((string) $index) . " ON {$rawTable}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private function ensure_raw_helper_indexes(string $rawTable): void
    {
        global $wpdb;

        foreach ($this->raw_helper_indexes() as $index => $column) {
            if ($this->index_exists($rawTable, (string) $index)) {
                continue;
            }

            $wpdb->query(
                'ALTER TABLE ' . $rawTable
                . ' ADD INDEX ' . $this->quote_identifier((string) $index)
                . ' (' . $this->quote_identifier((string) $column) . ')'
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    private function index_exists(string $table, string $index): bool
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $found !== null;
    }

    /**
     * @return array<string,string>
     */
    private function raw_helper_indexes(): array
    {
        return [
            'upc_norm' => 'upc_norm',
            'item_number_norm' => 'item_number_norm',
            'brand_number_norm' => 'brand_number_norm',
            'category_id_norm' => 'category_id_norm',
        ];
    }

    private function insert_shadow_rows(string $rawTable, string $shadowTable): void
    {
        global $wpdb;

        $brand_ref = $this->quote_identifier($wpdb->prefix . 'fflhub_sports_south_brand_reference');
        $category_ref = $this->quote_identifier($wpdb->prefix . 'fflhub_sports_south_category_reference');
        $manufacturer_policy = $this->manufacturer_policy_table();
        $item_policy = $this->item_policy_table();

        $columns = $this->table->get_schema()->get_insert_columns();
        $column_sql = implode(', ', array_map([$this, 'quote_identifier'], $columns));

        $item_number = 'R.item_number_norm';
        $upc = 'R.upc_norm';
        $brand_number = 'R.brand_number_norm';
        $category_id = 'R.category_id_norm';
        $raw_name = $this->clean_text_expr($this->first_expr('R', ['ITDESC', 'DESC', 'DESCRIPTION', 'IDESC', 'ITEMDESC', 'NAME']));
        $raw_description = $this->clean_text_expr($this->first_expr('R', ['SHDESC', 'LONGDESC', 'LONGDESCRIPTION', 'TEXT', 'DESCRIPTION', 'IDESC', 'ITDESC']));
        $description = "CASE WHEN {$raw_description} <> '' THEN {$raw_description} ELSE {$raw_name} END";
        $raw_item_type = $this->clean_text_expr($this->first_expr('R', ['ITYPE', 'CATDESC', 'CATEGORY', 'TYPE', 'ITEMTYPE', 'DEPT']));
        $item_type = "CASE WHEN COALESCE(CAT.category_description, '') <> '' THEN CAT.category_description ELSE {$raw_item_type} END";
        $raw_manufacturer = $this->clean_text_expr($this->first_expr('R', ['BRAND', 'BRDNAM', 'MFG', 'MANUFACTURER', 'ITBRD']));
        $manufacturer = "CASE WHEN COALESCE(BR.brand_name, '') <> '' THEN BR.brand_name ELSE {$raw_manufacturer} END";
        $manufacturer_norm = $this->normalize_manufacturer_expr($manufacturer);
        $mfg_part = $this->clean_text_expr($this->first_expr('R', ['MFGINO', 'MFGITEMNO', 'MFGNO', 'MFGITEM', 'ITMFGNO', 'M', 'MANUFACTURERPARTNUMBER']));
        $model = $this->clean_text_expr($this->first_expr('R', ['IMODEL', 'MODEL', 'ITMODEL']));
        $caliber = $this->clean_text_expr($this->first_expr('R', ['CALIBER', 'GAUGE', 'CALGAUGE']));
        $quantity = $this->quantity_expr($this->first_expr('R', ['QTYOH', 'ONHAND', 'QTY', 'QUANTITY']));
        $catalog_price = $this->money_expr($this->first_expr('R', ['PRC1', 'P', 'CATALOGPRICE', 'CATALOG_PRICE', 'LISTPRICE']));
        $customer_price_raw = $this->money_expr($this->first_expr('R', ['CPRC', 'C', 'CUSTOMERPRICE', 'CUSTOMER_PRICE', 'PRICE']));
        $distributor_price = "CASE WHEN {$customer_price_raw} <> '' THEN {$customer_price_raw} ELSE {$catalog_price} END";
        $explicit_map = $this->money_expr($this->first_expr('R', ['MAP', 'ITMAP', 'MINADVERTISEDPRICE']));
        $mf_price = $this->money_expr($this->first_expr('R', ['MFPRC']));
        $mf_price_type = "UPPER({$this->clean_text_expr($this->first_expr('R', ['MFPRTYP']))})";
        $map_price = "CASE WHEN {$explicit_map} <> '' THEN {$explicit_map} WHEN {$mf_price_type} = 'M' AND {$mf_price} <> '' AND CAST({$mf_price} AS DECIMAL(12,2)) > 0 THEN {$mf_price} ELSE '' END";
        $explicit_msrp = $this->money_expr($this->first_expr('R', ['MSRP', 'ITMSRP', 'RETAIL']));
        $msrp_price = "CASE WHEN {$explicit_msrp} <> '' THEN {$explicit_msrp} WHEN {$mf_price_type} <> 'M' THEN {$mf_price} ELSE '' END";
        $ffl_required = 'COALESCE(CAT.ffl_required, 0)';
        $sot_required = 'COALESCE(CAT.sot_required, 0)';
        $shipping_cost = "CASE WHEN {$ffl_required} = 1 THEN '" . self::FFL_SHIPPING_COST . "' ELSE '" . self::NON_FFL_SHIPPING_COST . "' END";
        $image_ref_raw = $this->clean_text_expr($this->first_expr('R', ['PICREF', 'PICTURE', 'IMAGE']));
        $image_ref = "CASE WHEN {$image_ref_raw} <> '' THEN {$image_ref_raw} ELSE {$item_number} END";
        $image_url = "CASE WHEN {$image_ref} <> '' THEN CONCAT('" . self::IMAGE_BASE . "/large/', {$image_ref}, '.jpg') ELSE '' END";
        $image_urls_json = "CASE WHEN {$image_ref} <> '' THEN JSON_ARRAY(CONCAT('" . self::IMAGE_BASE . "/large/', {$image_ref}, '.jpg'), CONCAT('" . self::IMAGE_BASE . "/small/', {$image_ref}, '.jpg'), CONCAT('" . self::IMAGE_BASE . "/thumbnail/', {$image_ref}, '.jpg'), CONCAT('" . self::IMAGE_BASE . "/hires/', {$image_ref}, '.png')) ELSE '[]' END";
        $restricted_states = $this->clean_text_expr($this->first_expr('R', ['RESTRICTEDSTATES', 'STATE_RESTRICTIONS', 'STATES']));
        $text_ref = $this->clean_text_expr($this->first_expr('R', ['TXTREF', 'TEXTREF']));
        $shipping_weight = $this->weight_ounces_expr($this->first_expr('R', ['WTPBX', 'WEIGHT', 'WT', 'SHPWT']));
        $shipping_length = $this->dimension_expr($this->first_expr('R', ['LENGTH', 'LEN', 'SHPLEN']));
        $shipping_width = $this->dimension_expr($this->first_expr('R', ['WIDTH', 'WID', 'SHPWID']));
        $shipping_height = $this->dimension_expr($this->first_expr('R', ['HEIGHT', 'HGT', 'SHPHGT']));
        $feed_dropship = $this->feed_dropship_expr($this->first_expr('R', ['DROPSHIP', 'DROPSHIPENABLED', 'FULFILLMENT', 'CANSHIPDIRECT']));
        $nfa_text = $this->normalize_manufacturer_expr("CONCAT_WS(' ', {$item_type}, {$raw_name}, {$description})");
        $is_nfa_or_sot = "({$sot_required} = 1 OR {$nfa_text} LIKE '%NFA%' OR {$nfa_text} LIKE '%SOT%' OR {$nfa_text} LIKE '%SILENCER%' OR {$nfa_text} LIKE '%SUPPRESSOR%' OR {$nfa_text} LIKE '%CLASSIII%' OR {$nfa_text} LIKE '%SHORTBARRELRIFLE%' OR {$nfa_text} LIKE '%SHORTBARRELSHOTGUN%')";
        $size_reason = "(SELECT IP.block_reason FROM {$item_policy} IP WHERE IP.sports_south_item_number = {$item_number} LIMIT 1)";
        $no_fulfillment_reason = "(SELECT MP.block_reason FROM {$manufacturer_policy} MP WHERE MP.policy_type = 'no_fulfillment' AND ({$manufacturer_norm} = MP.manufacturer_key OR {$manufacturer_norm} LIKE CONCAT(MP.manufacturer_key, '%')) ORDER BY CHAR_LENGTH(MP.manufacturer_key) DESC LIMIT 1)";
        $factory_reason = "(SELECT MP.block_reason FROM {$manufacturer_policy} MP WHERE MP.policy_type = 'factory_approval' AND MP.approved = 0 AND ({$manufacturer_norm} = MP.manufacturer_key OR {$manufacturer_norm} LIKE CONCAT(MP.manufacturer_key, '%')) ORDER BY CHAR_LENGTH(MP.manufacturer_key) DESC LIMIT 1)";
        $sig_factory_reason = "(SELECT MP.block_reason FROM {$manufacturer_policy} MP WHERE MP.policy_type = 'factory_approval' AND MP.sig_group = 1 AND MP.approved = 1 AND {$is_nfa_or_sot} AND ({$manufacturer_norm} = MP.manufacturer_key OR {$manufacturer_norm} LIKE CONCAT(MP.manufacturer_key, '%')) ORDER BY CHAR_LENGTH(MP.manufacturer_key) DESC LIMIT 1)";
        $sig_force = "EXISTS (SELECT 1 FROM {$manufacturer_policy} MP WHERE MP.policy_type = 'factory_approval' AND MP.sig_group = 1 AND MP.approved = 1 AND NOT {$is_nfa_or_sot} AND ({$manufacturer_norm} = MP.manufacturer_key OR {$manufacturer_norm} LIKE CONCAT(MP.manufacturer_key, '%')))";
        $block_reason = "COALESCE({$size_reason}, {$no_fulfillment_reason}, {$sig_factory_reason}, {$factory_reason}, '')";
        $dropship_enabled = "CASE WHEN {$sig_force} THEN 1 WHEN {$block_reason} <> '' THEN 0 ELSE {$feed_dropship} END";
        $dropship_block_reason = "CASE WHEN {$sig_force} THEN '' WHEN {$block_reason} <> '' THEN {$block_reason} WHEN {$feed_dropship} = 0 THEN 'feed_flag' ELSE '' END";

        $selects = [
            'upc' => $upc,
            'sports_south_item_number' => $item_number,
            'remote_identifier' => $item_number,
            'inventory_quantity' => "CAST({$quantity} AS CHAR)",
            'allocation_status' => "CASE WHEN {$quantity} > 0 THEN 'in_stock' ELSE 'out_of_stock' END",
            'distributor_price' => $distributor_price,
            'shipping_cost' => $shipping_cost,
            'catalog_price' => $catalog_price,
            'retail_map' => $map_price,
            'retail_msrp' => $msrp_price,
            'product_name' => $raw_name,
            'product_description' => $description,
            'manufacturer' => $manufacturer,
            'brand_number' => $brand_number,
            'model' => $model,
            'manufacturer_part_number' => $mfg_part,
            'category_id' => $category_id,
            'item_type' => $item_type,
            'caliber_gauge' => $caliber,
            'attributes_json' => "''",
            'ffl_required' => $ffl_required,
            'sot_required' => $sot_required,
            'dropship_enabled' => $dropship_enabled,
            'dropship_block_reason' => $dropship_block_reason,
            'restricted_states' => $restricted_states,
            'shipping_weight' => "NULLIF({$shipping_weight}, '')",
            'shipping_length_in' => $shipping_length,
            'shipping_width_in' => $shipping_width,
            'shipping_height_in' => $shipping_height,
            'image_ref' => $image_ref,
            'image_url' => $image_url,
            'image_urls_json' => $image_urls_json,
            'text_ref' => $text_ref,
            'last_seen_utc' => "'" . esc_sql(gmdate('Y-m-d H:i:s')) . "'",
            'last_onhand_utc' => "''",
            'raw_item_json' => "''",
        ];

        $select_sql = [];
        foreach ($columns as $column) {
            $select_sql[] = ($selects[$column] ?? "''") . ' AS ' . $this->quote_identifier($column);
        }

        $sql = "
            INSERT INTO {$shadowTable} ({$column_sql})
            SELECT " . implode(",\n                   ", $select_sql) . "
            FROM {$rawTable} R
            INNER JOIN (
                SELECT upc_norm, MIN(raw_id) AS raw_id
                FROM {$rawTable}
                WHERE upc_norm <> '' AND LOWER(upc_norm) <> 'null'
                GROUP BY upc_norm
            ) FU ON FU.raw_id = R.raw_id
            LEFT JOIN {$brand_ref} BR
                ON BR.brand_number = R.brand_number_norm
            LEFT JOIN {$category_ref} CAT
                ON CAT.category_id = R.category_id_norm
        ";

        $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @return array<string,mixed>
     */
    private function validate_shadow(string $legacyTable, string $shadowTable, string $rawTable): array
    {
        global $wpdb;

        $legacy_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$legacyTable}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $shadow_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadowTable}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $missing_new = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$legacyTable} L LEFT JOIN {$shadowTable} N ON N.upc = L.upc WHERE N.upc IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $extra_new = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadowTable} N LEFT JOIN {$legacyTable} L ON L.upc = N.upc WHERE L.upc IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $critical_drift = (int) $wpdb->get_var($this->critical_drift_count_sql($legacyTable, $shadowTable)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $unsafe_sig_sot = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shadowTable} WHERE dropship_enabled = 1 AND sot_required = 1 AND (LOWER(manufacturer) LIKE '%sig%' OR LOWER(manufacturer) LIKE '%sauer%')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $missing_brand_refs = (int) $wpdb->get_var("SELECT COUNT(DISTINCT R.brand_number_norm) FROM {$rawTable} R LEFT JOIN " . $this->quote_identifier($wpdb->prefix . 'fflhub_sports_south_brand_reference') . " BR ON BR.brand_number = R.brand_number_norm WHERE R.brand_number_norm <> '' AND BR.brand_number IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $missing_category_refs = (int) $wpdb->get_var("SELECT COUNT(DISTINCT R.category_id_norm) FROM {$rawTable} R LEFT JOIN " . $this->quote_identifier($wpdb->prefix . 'fflhub_sports_south_category_reference') . " CAT ON CAT.category_id = R.category_id_norm WHERE R.category_id_norm <> '' AND CAT.category_id IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return [
            'legacy_rows' => $legacy_rows,
            'shadow_rows_validated' => $shadow_rows,
            'missing_in_shadow' => $missing_new,
            'extra_in_shadow' => $extra_new,
            'critical_field_drift' => $critical_drift,
            'unsafe_sig_sot_enabled' => $unsafe_sig_sot,
            'missing_brand_reference_keys' => $missing_brand_refs,
            'missing_category_reference_keys' => $missing_category_refs,
            'clean_match' => ($legacy_rows === $shadow_rows && $missing_new === 0 && $extra_new === 0 && $critical_drift === 0 && $unsafe_sig_sot === 0) ? 1 : 0,
        ];
    }

    private function critical_drift_count_sql(string $legacyTable, string $shadowTable): string
    {
        $fields = [
            'sports_south_item_number',
            'product_name',
            'product_description',
            'manufacturer',
            'brand_number',
            'category_id',
            'item_type',
            'distributor_price',
            'catalog_price',
            'retail_map',
            'retail_msrp',
            'inventory_quantity',
            'ffl_required',
            'sot_required',
            'dropship_enabled',
            'dropship_block_reason',
            'shipping_cost',
            'shipping_weight',
            'shipping_length_in',
            'shipping_width_in',
            'shipping_height_in',
            'image_ref',
            'image_url',
        ];

        $legacy_parts = [];
        $shadow_parts = [];
        foreach ($fields as $field) {
            $legacy_parts[] = "COALESCE(L." . $this->quote_identifier($field) . ", '')";
            $shadow_parts[] = "COALESCE(N." . $this->quote_identifier($field) . ", '')";
        }

        return "
            SELECT COUNT(*)
            FROM {$legacyTable} L
            INNER JOIN {$shadowTable} N ON N.upc = L.upc
            WHERE MD5(CONCAT_WS('|', " . implode(', ', $legacy_parts) . ")) <> MD5(CONCAT_WS('|', " . implode(', ', $shadow_parts) . "))
        ";
    }

    /**
     * @return array<string,string>
     */
    private function raw_feed_columns(): array
    {
        $varchar64 = ['ITEMNO', 'ITEMNUMBER', 'I', 'CATID', 'CATEGORYID', 'CAT', 'ITBRDNO', 'BRDNO', 'BRANDNO'];
        $varchar32 = ['UPC', 'ITUPC', 'U', 'BARCODE', 'GTIN', 'QTYOH', 'ONHAND', 'QTY', 'QUANTITY', 'CPRC', 'C', 'CUSTOMERPRICE', 'CUSTOMER_PRICE', 'PRICE', 'PRC1', 'P', 'CATALOGPRICE', 'CATALOG_PRICE', 'LISTPRICE', 'MAP', 'ITMAP', 'MINADVERTISEDPRICE', 'MSRP', 'ITMSRP', 'RETAIL', 'MFPRTYP', 'MFPRC', 'WTPBX', 'WEIGHT', 'WT', 'SHPWT', 'LENGTH', 'LEN', 'SHPLEN', 'WIDTH', 'WID', 'SHPWID', 'HEIGHT', 'HGT', 'SHPHGT', 'DROPSHIP', 'DROPSHIPENABLED', 'FULFILLMENT', 'CANSHIPDIRECT'];
        $varchar128 = ['ITYPE', 'MFGINO', 'MFGITEMNO', 'MFGNO', 'MFGITEM', 'ITMFGNO', 'M', 'MANUFACTURERPARTNUMBER', 'CALIBER', 'GAUGE', 'CALGAUGE', 'TXTREF', 'TEXTREF'];
        $varchar255 = ['CATDESC', 'CATEGORY', 'TYPE', 'ITEMTYPE', 'DEPT', 'BRAND', 'BRDNAM', 'MFG', 'MANUFACTURER', 'ITBRD', 'IMODEL', 'MODEL', 'ITMODEL', 'PICREF', 'PICTURE', 'IMAGE', 'RESTRICTEDSTATES', 'STATE_RESTRICTIONS', 'STATES'];
        $text = ['DESC', 'DESCRIPTION', 'IDESC', 'ITEMDESC', 'NAME', 'SHDESC'];
        $medium = ['ITDESC', 'LONGDESC', 'LONGDESCRIPTION', 'TEXT'];

        $columns = [];
        foreach ($varchar64 as $column) {
            $columns[$column] = 'VARCHAR(64)';
        }
        foreach ($varchar32 as $column) {
            $columns[$column] = 'VARCHAR(32)';
        }
        foreach ($varchar128 as $column) {
            $columns[$column] = 'VARCHAR(128)';
        }
        foreach ($varchar255 as $column) {
            $columns[$column] = 'VARCHAR(255)';
        }
        foreach ($text as $column) {
            $columns[$column] = 'TEXT';
        }
        foreach ($medium as $column) {
            $columns[$column] = 'MEDIUMTEXT';
        }
        for ($i = 1; $i <= 20; $i++) {
            $columns['ATR' . $i] = 'TEXT';
            $columns['ITATR' . $i] = 'TEXT';
        }
        $columns['ATR0'] = 'TEXT';
        $columns['ITATR0'] = 'TEXT';

        ksort($columns);
        return $columns;
    }

    /**
     * @param string[] $columns
     */
    private function first_expr(string $alias, array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = 'NULLIF(TRIM(COALESCE(' . $alias . '.' . $this->quote_identifier($column) . ", '')), '')";
        }

        return 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    private function normalize_digits_expr(string $expr): string
    {
        return "COALESCE(REGEXP_REPLACE(TRIM(BOTH '#' FROM TRIM({$expr})), '[^0-9]', ''), '')";
    }

    private function normalize_manufacturer_expr(string $expr): string
    {
        return "COALESCE(REGEXP_REPLACE(REPLACE(UPPER(TRIM({$expr})), '&', 'AND'), '[^A-Z0-9]+', ''), '')";
    }

    private function clean_text_expr(string $expr): string
    {
        return "TRIM(REGEXP_REPLACE(COALESCE({$expr}, ''), '[[:space:]]+', ' '))";
    }

    private function money_expr(string $expr): string
    {
        $clean = "REGEXP_REPLACE(TRIM(COALESCE({$expr}, '')), '[^0-9.-]', '')";
        return "CASE WHEN {$clean} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' AND CAST({$clean} AS DECIMAL(12,4)) >= 0 THEN REPLACE(FORMAT(CAST({$clean} AS DECIMAL(12,4)), 2), ',', '') ELSE '' END";
    }

    private function quantity_expr(string $expr): string
    {
        $clean = "REGEXP_REPLACE(TRIM(COALESCE({$expr}, '')), '[^0-9-]', '')";
        return "CASE WHEN {$clean} REGEXP '^-?[0-9]+$' THEN GREATEST(CAST({$clean} AS SIGNED), 0) ELSE 0 END";
    }

    private function dimension_expr(string $expr): string
    {
        $clean = "REGEXP_REPLACE(TRIM(COALESCE({$expr}, '')), '[^0-9.-]', '')";
        return "CASE WHEN {$clean} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' AND CAST({$clean} AS DECIMAL(12,4)) > 0 THEN TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(CAST({$clean} AS DECIMAL(12,4)) AS CHAR))) ELSE '' END";
    }

    private function weight_ounces_expr(string $expr): string
    {
        $clean = "REGEXP_REPLACE(TRIM(COALESCE({$expr}, '')), '[^0-9.-]', '')";
        return "CASE WHEN {$clean} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' AND CAST({$clean} AS DECIMAL(12,4)) > 0 THEN REPLACE(FORMAT(CAST({$clean} AS DECIMAL(12,4)) * 16.0, 2), ',', '') ELSE '' END";
    }

    private function feed_dropship_expr(string $expr): string
    {
        return "CASE WHEN UPPER(TRIM(COALESCE({$expr}, ''))) = '' THEN 1 WHEN UPPER(TRIM({$expr})) IN ('0','N','NO','FALSE','F') THEN 0 ELSE 1 END";
    }

    private function raw_stage_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::RAW_STAGE_SUFFIX);
    }

    private function shadow_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::SHADOW_SUFFIX);
    }

    private function manufacturer_policy_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::MANUFACTURER_POLICY_SUFFIX);
    }

    private function item_policy_table(): string
    {
        global $wpdb;
        return $this->quote_identifier($wpdb->prefix . self::ITEM_POLICY_SUFFIX);
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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
