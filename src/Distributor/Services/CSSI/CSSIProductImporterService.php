<?php

namespace FFLHub\Distributor\Services\CSSI;

use FFLHub\Distributor\Services\SigDropshipApproval;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports CSSI product-feed CSV rows into the staging table.
 *
 * Fast path:
 * - CSV -> normalized TSV (schema column order) -> LOAD DATA LOCAL INFILE
 *
 * Fallback:
 * - CSV parser + batched insert_rows_into_staging()
 */
class CSSIProductImporterService
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][CSSIImporter]';
    private const SHIPPING_NON_FFL_RATE = 8.95;
    private const SHIPPING_NON_FFL_WEIGHT_LBS = 8.0;
    private const SHIPPING_FFL_RATE = 14.95;
    private const SHIPPING_FFL_WEIGHT_LBS = 30.0;
    private const SHIPPING_MINIMUM_ORDER_FEE = 7.50;
    private const SHIPPING_MINIMUM_ORDER_THRESHOLD = 50.0;
    private const SHIPPING_INSURANCE_PER_100 = 1.00;
    private const DEALER_SHIP_FREE_THRESHOLD = 750.0;
    private const DEALER_SHIP_NON_FFL_RATE = 11.95;
    private const DEALER_SHIP_FFL_RATE = 16.95;

    private DoubleBufferedProductTable $table;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    public function import_from_csv_file(string $filePath): int
    {
        $tStart = microtime(true);
        $memStart = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $this->log('---- IMPORT START ----', [
            'file_path' => $filePath,
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb' => $memStart > 0 ? (int) round($memStart / 1024) : 0,
        ]);

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->finalize($tStart, $memStart, 'ERROR (missing/unreadable file)', [
                'file_path' => $filePath,
            ]);
            return 0;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $tSchema = microtime(true);
        $schemaOk = true;
        try {
            $this->table->createTables();
        } catch (\Throwable $e) {
            $schemaOk = false;
            $this->log('Schema ensure failed before CSSI import; proceeding with existing tables.', [
                'error' => $e->getMessage(),
            ]);
        }
        $this->profile('ensure CSSI table schema', $tSchema, [
            'ok' => $schemaOk ? 1 : 0,
        ]);

        if ($this->can_use_load_data_local_infile()) {
            $tLoadPath = microtime(true);
            $rows = $this->import_from_csv_file_via_load_data($filePath);
            $this->profile('load-data pipeline', $tLoadPath, [
                'ok' => ($rows >= 0) ? 1 : 0,
                'inserted_rows' => max(0, $rows),
            ]);

            if ($rows >= 0) {
                $status = $rows > 0 ? 'SUCCESS (LOAD DATA)' : 'NO ROWS (LOAD DATA)';
                $this->finalize($tStart, $memStart, $status, [
                    'inserted_rows' => $rows,
                ]);
                return $rows;
            }

            $this->log('LOAD DATA path failed, falling back to PHP importer.', [
                'file_path' => $filePath,
            ]);
        } else {
            $this->log('LOAD DATA not available; using PHP importer.', []);
        }

        $tPhp = microtime(true);
        $rows = $this->import_from_csv_file_via_php($filePath);
        $this->profile('php importer pipeline', $tPhp, [
            'inserted_rows' => $rows,
        ]);

        $status = $rows > 0 ? 'SUCCESS (PHP)' : 'NO ROWS (PHP)';
        $this->finalize($tStart, $memStart, $status, [
            'inserted_rows' => $rows,
        ]);

        return $rows;
    }

    private function can_use_load_data_local_infile(): bool
    {
        global $wpdb;

        $row = $wpdb->get_row("SHOW VARIABLES LIKE 'local_infile'");
        $mysqlOk = $row && isset($row->Value) && in_array(strtolower((string) $row->Value), ['on', '1', 'true'], true);

        $mysqli = ini_get('mysqli.allow_local_infile');
        $pdo = ini_get('pdo_mysql.allow_local_infile');

        $phpOk = false;
        if ($mysqli !== false && $this->ini_truthy((string) $mysqli)) {
            $phpOk = true;
        }
        if ($pdo !== false && $this->ini_truthy((string) $pdo)) {
            $phpOk = true;
        }

        $result = ($mysqlOk && $phpOk);

        $this->log('PROFILE: LOAD DATA capability check', [
            'mysql_ok' => $mysqlOk ? 'true' : 'false',
            'php_ok' => $phpOk ? 'true' : 'false',
            'result' => $result ? 'true' : 'false',
        ]);

        return $result;
    }

    /**
     * @return int >= 0 inserted rows, -1 on failure
     */
    private function import_from_csv_file_via_load_data(string $filePath): int
    {
        $columns = $this->table->get_schema()->get_insert_columns();
        if (empty($columns)) {
            $this->log('LOAD DATA transform failed: schema insert columns empty.', []);
            return -1;
        }

        if ($this->csv_header_supports_direct_load($filePath)) {
            $tDirectLoad = microtime(true);
            $rows = $this->import_csv_directly_via_load_data($filePath);
            $this->profile('LOAD DATA direct product CSV', $tDirectLoad, [
                'ok' => ($rows >= 0) ? 1 : 0,
                'inserted_rows' => max(0, $rows),
                'file_path' => $filePath,
            ]);

            if ($rows >= 0) {
                return $rows;
            }

            $this->log('Direct LOAD DATA path failed; falling back to normalized TSV path.', [
                'file_path' => $filePath,
            ]);
        } else {
            $this->log('CSSI CSV header does not match direct LOAD DATA mapping; using normalized TSV path.', [
                'file_path' => $filePath,
            ]);
        }

        $tTransform = microtime(true);
        $transform = $this->transform_csv_to_normalized_tsv($filePath, $columns);
        $this->profile('transform CSV -> normalized TSV', $tTransform, [
            'ok' => (bool) ($transform['ok'] ?? false) ? 1 : 0,
            'rows_seen' => (int) ($transform['rows_seen'] ?? 0),
            'rows_written' => (int) ($transform['rows_written'] ?? 0),
            'rows_skipped' => (int) ($transform['rows_skipped'] ?? 0),
            'rows_missing_upc' => (int) ($transform['rows_missing_upc'] ?? 0),
            'rows_dupe_upc' => (int) ($transform['rows_dupe_upc'] ?? 0),
            'tsv_path' => (string) ($transform['tsv_path'] ?? ''),
            'error' => (string) ($transform['error'] ?? ''),
        ]);

        if (!(bool) ($transform['ok'] ?? false)) {
            return -1;
        }

        $tsvPath = (string) ($transform['tsv_path'] ?? '');
        if ($tsvPath === '') {
            $this->log('LOAD DATA transform failed: missing tsv_path.', []);
            return -1;
        }

        $tLoad = microtime(true);
        $rows = $this->import_normalized_tsv_via_load_data($tsvPath, $columns);
        $this->profile('LOAD DATA normalized TSV', $tLoad, [
            'ok' => ($rows >= 0) ? 1 : 0,
            'rows_loaded' => max(0, $rows),
            'tsv_path' => $tsvPath,
        ]);

        return $rows;
    }

    private function csv_header_supports_direct_load(string $csvPath): bool
    {
        $handle = fopen($csvPath, 'r');
        if (!is_resource($handle)) {
            return false;
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        fclose($handle);

        if (!is_array($header)) {
            return false;
        }

        $expected = [
            'sku',
            'item name',
            'quantity in stock',
            'price',
            'upc',
            'web item name',
            'web item description',
            'drop ship flag',
            'drop ship price',
            'category',
            'ship weight',
            'image location',
            'manufacturer',
            'manufacturer item number',
            'length',
            'width',
            'height',
            'map',
            'msrp',
            'available drop ship delivery options',
            'allocated item?',
            'retail map',
        ];

        $normalized = array_map(static function ($value): string {
            $value = (string) $value;
            $value = (string) preg_replace('/^\xEF\xBB\xBF/', '', $value);
            return strtolower(trim($value));
        }, $header);

        return $normalized === $expected;
    }

    private function import_csv_directly_via_load_data(string $csvPath): int
    {
        global $wpdb;

        if (!file_exists($csvPath) || !is_readable($csvPath)) {
            $this->log('Direct LOAD DATA input CSV missing or unreadable.', [
                'csv_path' => $csvPath,
            ]);
            return -1;
        }

        $tableName = $this->table->get_staging_table_name();
        if ($tableName === '') {
            $this->log('Direct LOAD DATA failed: staging table name missing.', []);
            return -1;
        }

        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            $this->log('Direct LOAD DATA failed: truncate_staging exception.', [
                'error' => $e->getMessage(),
            ]);
            return -1;
        }

        $trim = static function (string $var): string {
            return "TRIM(BOTH '\\t' FROM TRIM(BOTH '\\r' FROM TRIM({$var})))";
        };
        $money = static function (string $var) use ($trim): string {
            return "NULLIF(REPLACE(REPLACE({$trim($var)}, '$', ''), ',', ''), '')";
        };
        $decimal = static function (string $var) use ($trim): string {
            return "NULLIF(REGEXP_REPLACE({$trim($var)}, '[^0-9.\\\\-]', ''), '')";
        };
        $flag = static function (string $var) use ($trim): string {
            $value = "UPPER({$trim($var)})";
            return "CASE WHEN {$value} IN ('1','Y','YES','TRUE','T','ON') OR ({$trim($var)} REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' AND CAST({$trim($var)} AS DECIMAL(12,4)) > 0) THEN 1 ELSE 0 END";
        };

        $inventoryExpr = "CASE
            WHEN CAST(COALESCE(NULLIF(REPLACE({$trim('@quantity')}, ',', ''), ''), '0') AS DECIMAL(12,4)) < 0 THEN '0'
            ELSE CAST(CAST(ROUND(CAST(COALESCE(NULLIF(REPLACE({$trim('@quantity')}, ',', ''), ''), '0') AS DECIMAL(12,4)), 0) AS UNSIGNED) AS CHAR)
        END";
        $inStockFlagExpr = '0';
        $dropShipFlagExpr = $flag('@drop_ship_flag');
        $allocatedFlagExpr = $flag('@allocated_item');
        $sigManufacturerExpr = "UPPER({$trim('@manufacturer')}) = 'SIG SAUER'";
        $retailMapExpr = "COALESCE({$money('@retail_map')}, '')";
        $retailMsrpExpr = "COALESCE({$money('@msrp')}, NULLIF({$retailMapExpr}, ''), '')";
        $priceDecimalExpr = "CAST(COALESCE({$money('@price')}, '0') AS DECIMAL(12,4))";
        $weightPoundsExpr = "CAST(COALESCE({$decimal('@ship_weight')}, '0') AS DECIMAL(12,4))";
        $weightOuncesExpr = "CASE WHEN {$weightPoundsExpr} < 0 THEN 0 ELSE {$weightPoundsExpr} * 16 END";
        $freightWeightExpr = "CASE WHEN {$weightPoundsExpr} > 0 THEN {$weightPoundsExpr} ELSE 1 END";
        $categoryExpr = $trim('@category');
        $fflRequiredCategoryExpr = CSSIRegulatoryCategoryRules::ffl_required_category_sql($categoryExpr);
        $sotRequiredCategoryExpr = CSSIRegulatoryCategoryRules::sot_required_category_sql($categoryExpr);
        $sigApproved = SigDropshipApproval::is_distributor_sig_approved('cssi');
        $dropshipEnabledExpr = $sigApproved
            ? "CASE WHEN {$sigManufacturerExpr} AND NOT ({$sotRequiredCategoryExpr}) THEN 1 WHEN {$sigManufacturerExpr} THEN 0 ELSE {$dropShipFlagExpr} END"
            : "CASE WHEN {$sigManufacturerExpr} THEN 0 ELSE {$dropShipFlagExpr} END";
        $dropshipBlockReasonExpr = $sigApproved
            ? "CASE WHEN {$sigManufacturerExpr} AND NOT ({$sotRequiredCategoryExpr}) THEN '' WHEN {$sigManufacturerExpr} THEN 'manufacturer_policy=sig_sauer_no_dropship' WHEN {$dropShipFlagExpr} = 1 THEN '' ELSE 'drop_ship_flag=0' END"
            : "CASE WHEN {$sigManufacturerExpr} THEN 'manufacturer_policy=sig_sauer_no_dropship' WHEN {$dropShipFlagExpr} = 1 THEN '' ELSE 'drop_ship_flag=0' END";
        $freightChargeExpr = sprintf(
            "
            CASE
                WHEN {$fflRequiredCategoryExpr} THEN %F * GREATEST(1, CEIL(({$freightWeightExpr}) / %F))
                ELSE %F * GREATEST(1, CEIL(({$freightWeightExpr}) / %F))
            END
        ",
            self::SHIPPING_FFL_RATE,
            self::SHIPPING_FFL_WEIGHT_LBS,
            self::SHIPPING_NON_FFL_RATE,
            self::SHIPPING_NON_FFL_WEIGHT_LBS
        );
        $dropshipShippingRawExpr = "(
            ({$freightChargeExpr})
            + CASE WHEN {$priceDecimalExpr} > 0 THEN CEIL({$priceDecimalExpr} / 100.0) * " . self::SHIPPING_INSURANCE_PER_100 . " ELSE 0 END
            + CASE WHEN {$priceDecimalExpr} > 0 AND {$priceDecimalExpr} < " . self::SHIPPING_MINIMUM_ORDER_THRESHOLD . ' THEN ' . self::SHIPPING_MINIMUM_ORDER_FEE . " ELSE 0 END
        )";
        $dealerShippingRawExpr = sprintf(
            "
            CASE
                WHEN {$priceDecimalExpr} >= %F THEN 0
                WHEN {$fflRequiredCategoryExpr} THEN %F
                ELSE %F
            END
        ",
            self::DEALER_SHIP_FREE_THRESHOLD,
            self::DEALER_SHIP_FFL_RATE,
            self::DEALER_SHIP_NON_FFL_RATE
        );
        $shippingRawExpr = "
            CASE
                WHEN {$dropshipEnabledExpr} = 1 THEN ({$dropshipShippingRawExpr})
                ELSE ({$dealerShippingRawExpr})
            END
        ";
        $shippingExpr = "REPLACE(FORMAT({$shippingRawExpr}, 2), ',', '')";
        $descriptionExpr = "CASE
            WHEN LEFT({$trim('@web_description')}, 1) = '\"' THEN REPLACE({$trim('@web_description')}, '\"', '')
            ELSE {$trim('@web_description')}
        END";

        $sql = "
            LOAD DATA LOCAL INFILE %s
            IGNORE INTO TABLE {$tableName}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY ','
            OPTIONALLY ENCLOSED BY ''
            ESCAPED BY '\\\\'
            LINES TERMINATED BY '\n'
            IGNORE 1 LINES
            (
                @sku,
                @item_name,
                @quantity,
                @price,
                @upc,
                @web_name,
                @web_description,
                @drop_ship_flag,
                @drop_ship_price,
                @category,
                @ship_weight,
                @image_location,
                @manufacturer,
                @manufacturer_item_number,
                @length,
                @width,
                @height,
                @map,
                @msrp,
                @drop_ship_delivery_options,
                @allocated_item,
                @retail_map
            )
            SET
                upc = REGEXP_REPLACE({$trim('@upc')}, '[^0-9]', ''),
                cssi_item_number = {$trim('@sku')},
                inventory_quantity = {$inventoryExpr},
                in_stock_flag = {$inStockFlagExpr},
                allocation_status = CASE
                    WHEN {$allocatedFlagExpr} = 1 THEN 'allocated'
                    WHEN CAST({$inventoryExpr} AS UNSIGNED) > 0 OR {$inStockFlagExpr} = 1 THEN 'in_stock'
                    ELSE 'out_of_stock'
                END,
                distributor_price = COALESCE({$money('@price')}, ''),
                shipping_cost = {$shippingExpr},
                retail_map = {$retailMapExpr},
                retail_msrp = {$retailMsrpExpr},
                drop_ship_price = COALESCE({$money('@drop_ship_price')}, ''),
                product_name = {$trim('@web_name')},
                product_description = {$descriptionExpr},
                manufacturer = {$trim('@manufacturer')},
                model = '',
                mfg_model_number = {$trim('@manufacturer_item_number')},
                caliber_gauge = '',
                item_type = {$categoryExpr},
                serialized_flag = CASE WHEN {$fflRequiredCategoryExpr} THEN 1 ELSE 0 END,
                ffl_required = CASE WHEN {$fflRequiredCategoryExpr} THEN 1 ELSE 0 END,
                sot_required = CASE WHEN {$sotRequiredCategoryExpr} THEN 1 ELSE 0 END,
                dropship_enabled = {$dropshipEnabledExpr},
                dropship_block_reason = {$dropshipBlockReasonExpr},
                drop_ship_delivery_options = {$trim('@drop_ship_delivery_options')},
                shipping_weight = {$weightOuncesExpr},
                shipping_length_in = {$trim('@length')},
                shipping_width_in = {$trim('@width')},
                shipping_height_in = {$trim('@height')},
                image_location = {$trim('@image_location')},
                last_seen_utc = ''
        ";

        try {
            $result = $wpdb->query($wpdb->prepare($sql, $csvPath));
            if ($result === false) {
                $this->log('Direct LOAD DATA query failed.', [
                    'error' => (string) $wpdb->last_error,
                ]);
                return -1;
            }

            $wpdb->query(
                "
                DELETE FROM {$tableName}
                WHERE
                    upc IS NULL
                    OR TRIM(upc) = ''
                    OR LOWER(TRIM(upc)) = 'null'
                "
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

            $sigApprovedForced = SigDropshipApproval::apply_to_table('cssi', $tableName);
            if ($sigApprovedForced > 0) {
                $this->log('Direct LOAD DATA SIG approval override applied.', [
                    'rows_forced' => (int) $sigApprovedForced,
                ]);
            }
        } catch (\Throwable $e) {
            $this->log('Direct LOAD DATA exception.', [
                'error' => $e->getMessage(),
            ]);
            return -1;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tableName}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * @param array<int,string> $columns
     * @return array<string,mixed>
     */
    private function transform_csv_to_normalized_tsv(string $csvPath, array $columns): array
    {
        $csvHandle = fopen($csvPath, 'r');
        if (!is_resource($csvHandle)) {
            return [
                'ok' => false,
                'error' => 'Could not open CSV file.',
            ];
        }

        $header = fgetcsv($csvHandle, 0, ',', '"', '\\');
        if (!is_array($header) || empty($header)) {
            fclose($csvHandle);
            return [
                'ok' => false,
                'error' => 'Missing CSV header row.',
            ];
        }

        $parser = new CSSIProductParser();
        $headerMap = $parser->build_header_map($header);

        if (!$parser->has_required_columns($headerMap)) {
            fclose($csvHandle);
            return [
                'ok' => false,
                'error' => 'CSV header missing required columns.',
            ];
        }

        $tsvPath = $csvPath . '.normalized.tsv';
        $tsvHandle = fopen($tsvPath, 'wb');
        if (!is_resource($tsvHandle)) {
            fclose($csvHandle);
            return [
                'ok' => false,
                'error' => 'Could not open normalized TSV output file.',
                'tsv_path' => $tsvPath,
            ];
        }

        $rowsSeen = 0;
        $rowsWritten = 0;
        $rowsSkipped = 0;
        $rowsMissingUpc = 0;
        $rowsDupeUpc = 0;
        $seenUpcs = [];

        while (($csv = fgetcsv($csvHandle, 0, ',', '"', '\\')) !== false) {
            $rowsSeen++;

            $row = $parser->parse_csv_row($csv, $headerMap);
            if (!is_array($row)) {
                $rowsSkipped++;
                continue;
            }

            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '' || strtolower($upc) === 'null') {
                $rowsMissingUpc++;
                continue;
            }

            if (isset($seenUpcs[$upc])) {
                $rowsDupeUpc++;
                continue;
            }
            $seenUpcs[$upc] = true;

            $ordered = [];
            foreach ($columns as $column) {
                $ordered[] = array_key_exists($column, $row) ? (string) $row[$column] : '';
            }

            $written = fputcsv($tsvHandle, $ordered, "\t", '"', '\\');
            if ($written === false) {
                fclose($csvHandle);
                fclose($tsvHandle);
                @unlink($tsvPath);
                return [
                    'ok' => false,
                    'error' => 'Failed writing normalized TSV row.',
                    'tsv_path' => $tsvPath,
                    'rows_seen' => $rowsSeen,
                    'rows_written' => $rowsWritten,
                ];
            }

            $rowsWritten++;
        }

        fclose($csvHandle);
        fclose($tsvHandle);

        return [
            'ok' => true,
            'tsv_path' => $tsvPath,
            'rows_seen' => $rowsSeen,
            'rows_written' => $rowsWritten,
            'rows_skipped' => $rowsSkipped,
            'rows_missing_upc' => $rowsMissingUpc,
            'rows_dupe_upc' => $rowsDupeUpc,
        ];
    }

    /**
     * @param array<int,string> $columns
     * @return int >= 0 inserted rows, -1 on failure
     */
    private function import_normalized_tsv_via_load_data(string $tsvPath, array $columns): int
    {
        global $wpdb;

        if (!file_exists($tsvPath) || !is_readable($tsvPath)) {
            $this->log('LOAD DATA input TSV missing or unreadable.', [
                'tsv_path' => $tsvPath,
            ]);
            return -1;
        }

        $tableName = $this->table->get_staging_table_name();
        if ($tableName === '') {
            $this->log('LOAD DATA failed: staging table name missing.', []);
            return -1;
        }

        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            $this->log('LOAD DATA failed: truncate_staging exception.', [
                'error' => $e->getMessage(),
            ]);
            return -1;
        }

        $columnList = implode(', ', array_map(static function (string $c): string {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $columns));

        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$tableName}
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY '\t'
            OPTIONALLY ENCLOSED BY '\"'
            ESCAPED BY '\\\\'
            LINES TERMINATED BY '\n'
            ({$columnList})
        ";

        try {
            $prepared = $wpdb->prepare($sql, $tsvPath);
            $result = $wpdb->query($prepared);
            if ($result === false) {
                $this->log('LOAD DATA query failed.', [
                    'error' => (string) $wpdb->last_error,
                ]);
                return -1;
            }

            $wpdb->query(
                "
                DELETE FROM {$tableName}
                WHERE
                    upc IS NULL
                    OR TRIM(upc) = ''
                    OR LOWER(TRIM(upc)) = 'null'
                "
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sigApprovedForced = SigDropshipApproval::apply_to_table('cssi', $tableName);
            if ($sigApprovedForced > 0) {
                $this->log('LOAD DATA SIG approval override applied.', [
                    'rows_forced' => (int) $sigApprovedForced,
                ]);
            }
        } catch (\Throwable $e) {
            $this->log('LOAD DATA exception.', [
                'error' => $e->getMessage(),
            ]);
            return -1;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tableName}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function import_from_csv_file_via_php(string $filePath): int
    {
        $handle = fopen($filePath, 'r');
        if (!is_resource($handle)) {
            $this->log('PHP importer: fopen failed.', [
                'file_path' => $filePath,
            ]);
            return 0;
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header) || empty($header)) {
            fclose($handle);
            $this->log('PHP importer: missing header.', [
                'file_path' => $filePath,
            ]);
            return 0;
        }

        $parser = new CSSIProductParser();
        $headerMap = $parser->build_header_map($header);

        if (!$parser->has_required_columns($headerMap)) {
            fclose($handle);
            $this->log('PHP importer: required columns missing.', []);
            return 0;
        }

        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            fclose($handle);
            $this->log('PHP importer: truncate_staging failed.', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $batchSize = 1000;
        $batchRows = [];
        $totalInserted = 0;
        $rowsSeen = 0;
        $rowsSkipped = 0;
        $rowsMissingUpc = 0;
        $rowsDupeUpc = 0;
        $batchFlushes = 0;
        $batchFailures = 0;
        $seenUpcs = [];
        $tParseTotal = 0.0;
        $tInsertTotal = 0.0;

        $flushBatch = function () use (&$batchRows, &$totalInserted, &$batchFlushes, &$batchFailures, &$tInsertTotal): void {
            if (empty($batchRows)) {
                return;
            }

            $batchFlushes++;
            $tIns = microtime(true);

            try {
                $inserted = (int) $this->table->insert_rows_into_staging($batchRows);
                $totalInserted += $inserted;
            } catch (\Throwable $e) {
                $batchFailures++;
                $this->log('Batch insert failed.', [
                    'batch_size' => count($batchRows),
                    'error' => $e->getMessage(),
                ]);
            }

            $tInsertTotal += (microtime(true) - $tIns);
            $batchRows = [];
        };

        while (($csv = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowsSeen++;

            $tParse = microtime(true);
            $row = $parser->parse_csv_row($csv, $headerMap);
            $tParseTotal += (microtime(true) - $tParse);

            if (!is_array($row)) {
                $rowsSkipped++;
                continue;
            }

            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '' || strtolower($upc) === 'null') {
                $rowsMissingUpc++;
                continue;
            }

            if (isset($seenUpcs[$upc])) {
                $rowsDupeUpc++;
                continue;
            }
            $seenUpcs[$upc] = true;

            $batchRows[] = $row;
            if (count($batchRows) >= $batchSize) {
                $flushBatch();
            }
        }

        fclose($handle);

        if (!empty($batchRows)) {
            $flushBatch();
        }

        $this->log('PHP importer stats', [
            'rows_seen' => $rowsSeen,
            'inserted_rows' => $totalInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_missing_upc' => $rowsMissingUpc,
            'rows_dupe_upc' => $rowsDupeUpc,
            'batch_flushes' => $batchFlushes,
            'batch_failures' => $batchFailures,
            'parse_total_ms' => number_format($tParseTotal * 1000, 2, '.', ''),
            'insert_total_ms' => number_format($tInsertTotal * 1000, 2, '.', ''),
        ]);

        return (int) $totalInserted;
    }

    private function ini_truthy(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000, 2);
        $this->log('PROFILE: ' . $label, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize(float $tStart, int $memStart, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $this->profile('Total CSSI import', $tStart, $ctx);

        $memEnd = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($memStart > 0 && $memEnd > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($memStart / 1024),
                'end_kb' => (int) round($memEnd / 1024),
                'delta_kb' => (int) round(($memEnd - $memStart) / 1024),
            ]);
        }

        $this->log('---- IMPORT END (' . $status . ') ----');
    }
}
