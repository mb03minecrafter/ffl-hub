<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parser for the RSR fulfillment catalog file (fulfillment-inv-new.txt).
 *
 * The file is semicolon-delimited, e.g.:
 * 17912WH-1-SBL-R;816161020234;1791 2 WAY IWB ...;...;20210420;48.99;;7.50;6.50;2.00;Y;;
 *
 * Column layout (simplified):
 *  0  RSR Stock Number
 *  1  UPC
 *  2  Product Description
 *  3  Dept #
 *  4  Manufacturer Id.
 *  5  Retail Price      (MSRP)
 *  6  RSR Regular Price (Distributor cost)
 *  7  Product Weight (oz)
 *  8  Inventory Quantity
 *  9  Model
 * 10  Full Manufacturer Name
 * 11  Manufacturer Part Number
 * 12  Allocated / Closeout / Deleted
 * 13  Expanded Product Description
 * 14  Image Name
 * 15..(15 + 51 - 1)  State flags: AK, AL, AR, ..., PH, RI, ..., WY (51 columns)
 * next 3 columns:
 *   Ground Shipments Only
 *   Adult Sig Required
 *   Blocked from Dropship
 * then:
 *   Date Entered
 *   Retail MAP
 *   Image Disclaimer
 *   Shipping Length (inches)
 *   Shipping Width (inches)
 *   Shipping Height (inches)
 *   Reserved for Future Use
 *
 * Some files have a couple of extra trailing semicolons; we simply ignore columns
 * beyond the ones we care about.
 */
class FFLHub_RSR_Fulfillment_Parser
{

    /**
     * Map one data line into a DB row.
     *
     * @param string $line        Raw line from the file.
     * @param int    $line_number 1-based line number (for header detection).
     * @return array<string,string>|null Row keyed to match DB columns, or null to skip.
     */
    public function parse_line(string $line, int $line_number): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $columns = explode(';', $line);

        // Skip header row if present.
        $first_col = isset($columns[0]) ? trim($columns[0]) : '';
        if (
            $line_number === 1 &&
            (stripos($first_col, 'RSR Stock') === 0 || stripos($first_col, 'RSR#') === 0)
        ) {
            return null;
        }

        // Require at least enough columns to reach date / map / dims.
        if (count($columns) < 70) {
            return null;
        }

        $get = static function (array $cols, int $idx): string {
            return isset($cols[$idx]) ? trim((string) $cols[$idx]) : '';
        };

        // Base fixed columns.
        $rsr_stock_number = $get($columns, 0);
        if ($rsr_stock_number === '') {
            // No stock number = unusable row.
            return null;
        }

        $upc                    = $get($columns, 1);
        $product_description    = $get($columns, 2);
        $dept_number            = $get($columns, 3);
        $manufacturer_id        = $get($columns, 4);
        $retail_msrp            = $get($columns, 5); // maps to retail_msrp in schema
        $distributor_price      = $get($columns, 6); // maps to distributor_price in schema
        $product_weight_oz      = $get($columns, 7);
        $inventory_quantity     = $get($columns, 8);
        $model                  = $get($columns, 9);
        $full_manufacturer_name = $get($columns, 10);
        $manufacturer_part_no   = $get($columns, 11);
        $allocation_status      = $get($columns, 12);
        $expanded_product_desc  = $get($columns, 13);
        $image_name             = $get($columns, 14);

        // State codes in order.
        $state_codes = array(
            'AK',
            'AL',
            'AR',
            'AZ',
            'CA',
            'CO',
            'CT',
            'DC',
            'DE',
            'FL',
            'GA',
            'HI',
            'IA',
            'ID',
            'IL',
            'IN',
            'KS',
            'KY',
            'LA',
            'MA',
            'MD',
            'ME',
            'MI',
            'MN',
            'MO',
            'MS',
            'MT',
            'NC',
            'ND',
            'NE',
            'NH',
            'NJ',
            'NM',
            'NV',
            'NY',
            'OH',
            'OK',
            'OR',
            'PH',
            'RI',
            'SC',
            'SD',
            'TN',
            'TX',
            'UT',
            'VA',
            'VT',
            'WA',
            'WI',
            'WV',
            'WY',
        );
        $state_base_index = 15; // Where AK starts.

        $state_flags = array();
        foreach ($state_codes as $offset => $code) {
            $raw = strtoupper($get($columns, $state_base_index + $offset));
            $state_flags['ship_' . strtolower($code)] = ($raw === 'Y') ? '1' : '0';
        }

        // After the 51 state columns come 3 shipping flags + the rest.
        $ground_index  = $state_base_index + count($state_codes);     // 15 + 51 = 66
        $adult_index   = $ground_index + 1;                              // 67
        $blocked_index = $ground_index + 2;                              // 68

        $date_index    = $ground_index + 3;                              // 69
        $map_index     = $ground_index + 4;                              // 70
        $imgdisc_index = $ground_index + 5;                              // 71
        $len_index     = $ground_index + 6;                              // 72
        $wid_index     = $ground_index + 7;                              // 73
        $ht_index      = $ground_index + 8;                              // 74
        $res_index     = $ground_index + 9;                              // 75

        $ground_shipments_raw = strtoupper($get($columns, $ground_index));
        $adult_sig_raw        = strtoupper($get($columns, $adult_index));
        $blocked_raw          = strtoupper($get($columns, $blocked_index));

        $ground_shipments_only = ($ground_shipments_raw === 'Y') ? '1' : '0';
        $adult_sig_required    = ($adult_sig_raw === 'Y') ? '1' : '0';
        $blocked_from_dropship = ($blocked_raw === 'Y') ? '1' : '0';

        $date_entered     = $get($columns, $date_index);
        $retail_map       = $get($columns, $map_index);
        $image_disclaimer = $get($columns, $imgdisc_index);
        $shipping_length  = $get($columns, $len_index);
        $shipping_width   = $get($columns, $wid_index);
        $shipping_height  = $get($columns, $ht_index);
        $reserved_future  = $get($columns, $res_index);

        // Build row keyed to the CURRENT schema (minus id).
        $row = array(
            'upc'                          => $upc,
            'rsr_stock_number'             => $rsr_stock_number,

            'product_description'          => $product_description,
            'dept_number'                  => $dept_number,
            'manufacturer_id'              => $manufacturer_id,

            // Pricing (new names)
            'inventory_quantity'           => $inventory_quantity,
            'allocation_status'            => $allocation_status,
            'distributor_price'            => $distributor_price,
            'retail_map'                   => $retail_map,
            'retail_msrp'                  => $retail_msrp,

            // Catalog
            'product_weight_oz'            => $product_weight_oz,
            'model'                        => $model,
            'full_manufacturer_name'       => $full_manufacturer_name,
            'manufacturer_part_number'     => $manufacturer_part_no,
            'expanded_product_description' => $expanded_product_desc,
            'image_name'                   => $image_name,
        );

        // Merge state flags.
        $row = array_merge($row, $state_flags);

        // Shipping / misc.
        $row['ground_shipments_only'] = $ground_shipments_only;
        $row['adult_sig_required']    = $adult_sig_required;
        $row['blocked_from_dropship'] = $blocked_from_dropship;
        $row['date_entered']          = $date_entered;
        $row['image_disclaimer']      = $image_disclaimer;
        $row['shipping_length_in']    = $shipping_length;
        $row['shipping_width_in']     = $shipping_width;
        $row['shipping_height_in']    = $shipping_height;
        $row['reserved_future']       = $reserved_future;

        return $row;
    }
}

/**
 * Coordinator for importing the RSR fulfillment catalog into the STAGING table.
 *
 * It:
 *  - reads a local file (semicolons),
 *  - truncates the staging table,
 *  - bulk-inserts rows.
 *
 * You can call FFLHub_RSR_Fulfillment_Importer::import_from_downloaded_file()
 * after your FTP cron has fetched the latest fulfillment-inv-new.txt.
 */
class FFLHub_RSR_Fulfillment_Importer
{

    /**
     * Convenience wrapper: import from the standard downloaded file location.
     *
     * @return int Number of rows inserted.
     */
    public static function import_from_downloaded_file(): int
    {
        $uploads   = wp_upload_dir();
        $base_dir  = trailingslashit($uploads['basedir']) . 'fflhub-rsr';
        $file_path = trailingslashit($base_dir) . 'fulfillment-inv-new.txt';

        if (! file_exists($file_path) || ! is_readable($file_path)) {
            error_log('[FFLHub] RSR fulfillment import: file missing or not readable at ' . $file_path);
            return 0;
        }

        // CHANGED: route through main importer (which may use LOAD DATA)
        return self::import_fulfillment_file($file_path); // CHANGED
    }

    /**
     * Check whether LOAD DATA LOCAL INFILE appears to be usable.
     *
     * @return bool
     */
    // NEW
    public static function can_use_load_data_local_infile(): bool
    {
        global $wpdb;

        // Check MySQL server variable.
        $row = $wpdb->get_row("SHOW VARIABLES LIKE 'local_infile'");
        $mysql_ok = false;

        if ($row && isset($row->Value)) {
            $val = strtolower((string) $row->Value);
            $mysql_ok = ($val === 'on' || $val === '1');
        }

        // Check PHP ini for mysqli.
        $ini_val = ini_get('mysqli.allow_local_infile');
        $php_ok  = ($ini_val === '1' || strtolower((string) $ini_val) === 'on');

        $result = ($mysql_ok && $php_ok);

        error_log(
            sprintf(
                '[FFLHub][RSR Import][DEBUG] can_use_load_data_local_infile: mysql_ok=%s, php_ok=%s, result=%s',
                $mysql_ok ? 'true' : 'false',
                $php_ok ? 'true' : 'false',
                $result ? 'true' : 'false'
            )
        );

        return $result;
    }

    /**
     * Import from a specific file path into the staging table.
     * Uses LOAD DATA LOCAL INFILE when available; falls back to PHP batch importer.
     *
     * @param string $file_path
     * @return int Number of rows inserted.
     */
    // CHANGED: orchestrator that chooses LOAD DATA or PHP importer
    public static function import_fulfillment_file(string $file_path): int
    { // CHANGED
        // Try fast path first.
        if (self::can_use_load_data_local_infile()) {
            $rows = self::import_fulfillment_file_via_load_data($file_path); // NEW
            if ($rows >= 0) {
                return $rows;
            }

            // If LOAD DATA failed, log and fall back to PHP path.
            error_log(
                '[FFLHub][RSR Import] LOAD DATA path failed, falling back to PHP batch importer.'
            );
        }

        // Fallback (or if LOAD DATA disabled): original PHP batch importer.
        return self::import_fulfillment_file_via_php($file_path); // NEW
    }

    /**
     * FAST PATH: Import via MySQL's LOAD DATA LOCAL INFILE directly into the staging table.
     *
     * Returns:
     *  - >= 0 : number of rows inserted
     *  - -1   : failure (caller should fall back to PHP importer)
     *
     * @param string $file_path
     * @return int
     */
    private static function import_fulfillment_file_via_load_data(string $file_path): int
    {
        global $wpdb;

        $t_start = microtime(true);

        if (! file_exists($file_path) || ! is_readable($file_path)) {
            error_log('[FFLHub][RSR Import][LOAD DATA] file missing or not readable at ' . $file_path);
            return -1;
        }

        if (! class_exists('FFLHub_RSR_Fulfillment_Table')) {
            error_log('[FFLHub][RSR Import][LOAD DATA] FFLHub_RSR_Fulfillment_Table class not found.');
            return -1;
        }

        $table_name = FFLHub_RSR_Fulfillment_Table::get_staging_table_name();

        // Allow long-running import if needed.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Detect if first line is a header (same logic as parser).
        $ignore_lines = 0;
        $fh = fopen($file_path, 'r');
        if ($fh) {
            $first_line = fgets($fh);
            fclose($fh);

            if ($first_line !== false) {
                $first_line = trim($first_line);
                if ($first_line !== '') {
                    $cols      = explode(';', $first_line);
                    $first_col = isset($cols[0]) ? trim($cols[0]) : '';
                    if (
                        stripos($first_col, 'RSR Stock') === 0 ||
                        stripos($first_col, 'RSR#') === 0
                    ) {
                        $ignore_lines = 1;
                    }
                }
            }
        }

        // Clean staging table first.
        $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        /**
         * Map each semicolon-separated column into @c0..@c75, then SET real columns.
         */
        $sql = "
            LOAD DATA LOCAL INFILE %s
            INTO TABLE {$table_name}
            FIELDS TERMINATED BY ';'
            LINES TERMINATED BY '\n'
            IGNORE {$ignore_lines} LINES
            (
                @c0,  @c1,  @c2,  @c3,  @c4,  @c5,  @c6,  @c7,  @c8,  @c9,
                @c10, @c11, @c12, @c13, @c14,
                @c15, @c16, @c17, @c18, @c19, @c20, @c21, @c22, @c23, @c24,
                @c25, @c26, @c27, @c28, @c29, @c30, @c31, @c32, @c33, @c34,
                @c35, @c36, @c37, @c38, @c39, @c40, @c41, @c42, @c43, @c44,
                @c45, @c46, @c47, @c48, @c49, @c50, @c51, @c52, @c53, @c54,
                @c55, @c56, @c57, @c58, @c59, @c60, @c61, @c62, @c63, @c64,
                @c65,
                @c66, @c67, @c68,
                @c69, @c70, @c71, @c72, @c73, @c74, @c75
            )
            SET
                upc                          = TRIM(TRIM(BOTH '\\r' FROM @c1)),
                rsr_stock_number             = TRIM(TRIM(BOTH '\\r' FROM @c0)),
                product_description          = TRIM(TRIM(BOTH '\\r' FROM @c2)),
                dept_number                  = TRIM(TRIM(BOTH '\\r' FROM @c3)),
                manufacturer_id              = TRIM(TRIM(BOTH '\\r' FROM @c4)),
                retail_msrp                  = TRIM(TRIM(BOTH '\\r' FROM @c5)),
                distributor_price            = TRIM(TRIM(BOTH '\\r' FROM @c6)),
                product_weight_oz            = TRIM(TRIM(BOTH '\\r' FROM @c7)),
                inventory_quantity           = TRIM(TRIM(BOTH '\\r' FROM @c8)),
                model                        = TRIM(TRIM(BOTH '\\r' FROM @c9)),
                full_manufacturer_name       = TRIM(TRIM(BOTH '\\r' FROM @c10)),
                manufacturer_part_number     = TRIM(TRIM(BOTH '\\r' FROM @c11)),
                allocation_status            = TRIM(TRIM(BOTH '\\r' FROM @c12)),
                expanded_product_description = TRIM(TRIM(BOTH '\\r' FROM @c13)),
                image_name                   = TRIM(TRIM(BOTH '\\r' FROM @c14)),

                ship_ak  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c15))) = 'Y' THEN '1' ELSE '0' END,
                ship_al  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c16))) = 'Y' THEN '1' ELSE '0' END,
                ship_ar  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c17))) = 'Y' THEN '1' ELSE '0' END,
                ship_az  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c18))) = 'Y' THEN '1' ELSE '0' END,
                ship_ca  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c19))) = 'Y' THEN '1' ELSE '0' END,
                ship_co  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c20))) = 'Y' THEN '1' ELSE '0' END,
                ship_ct  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c21))) = 'Y' THEN '1' ELSE '0' END,
                ship_dc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c22))) = 'Y' THEN '1' ELSE '0' END,
                ship_de  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c23))) = 'Y' THEN '1' ELSE '0' END,
                ship_fl  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c24))) = 'Y' THEN '1' ELSE '0' END,
                ship_ga  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c25))) = 'Y' THEN '1' ELSE '0' END,
                ship_hi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c26))) = 'Y' THEN '1' ELSE '0' END,
                ship_ia  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c27))) = 'Y' THEN '1' ELSE '0' END,
                ship_id  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c28))) = 'Y' THEN '1' ELSE '0' END,
                ship_il  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c29))) = 'Y' THEN '1' ELSE '0' END,
                ship_in  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c30))) = 'Y' THEN '1' ELSE '0' END,
                ship_ks  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c31))) = 'Y' THEN '1' ELSE '0' END,
                ship_ky  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c32))) = 'Y' THEN '1' ELSE '0' END,
                ship_la  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c33))) = 'Y' THEN '1' ELSE '0' END,
                ship_ma  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c34))) = 'Y' THEN '1' ELSE '0' END,
                ship_md  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c35))) = 'Y' THEN '1' ELSE '0' END,
                ship_me  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c36))) = 'Y' THEN '1' ELSE '0' END,
                ship_mi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c37))) = 'Y' THEN '1' ELSE '0' END,
                ship_mn  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c38))) = 'Y' THEN '1' ELSE '0' END,
                ship_mo  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c39))) = 'Y' THEN '1' ELSE '0' END,
                ship_ms  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c40))) = 'Y' THEN '1' ELSE '0' END,
                ship_mt  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c41))) = 'Y' THEN '1' ELSE '0' END,
                ship_nc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c42))) = 'Y' THEN '1' ELSE '0' END,
                ship_nd  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c43))) = 'Y' THEN '1' ELSE '0' END,
                ship_ne  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c44))) = 'Y' THEN '1' ELSE '0' END,
                ship_nh  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c45))) = 'Y' THEN '1' ELSE '0' END,
                ship_nj  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c46))) = 'Y' THEN '1' ELSE '0' END,
                ship_nm  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c47))) = 'Y' THEN '1' ELSE '0' END,
                ship_nv  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c48))) = 'Y' THEN '1' ELSE '0' END,
                ship_ny  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c49))) = 'Y' THEN '1' ELSE '0' END,
                ship_oh  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c50))) = 'Y' THEN '1' ELSE '0' END,
                ship_ok  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c51))) = 'Y' THEN '1' ELSE '0' END,
                ship_or  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c52))) = 'Y' THEN '1' ELSE '0' END,
                ship_ph  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c53))) = 'Y' THEN '1' ELSE '0' END,
                ship_ri  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c54))) = 'Y' THEN '1' ELSE '0' END,
                ship_sc  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c55))) = 'Y' THEN '1' ELSE '0' END,
                ship_sd  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c56))) = 'Y' THEN '1' ELSE '0' END,
                ship_tn  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c57))) = 'Y' THEN '1' ELSE '0' END,
                ship_tx  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c58))) = 'Y' THEN '1' ELSE '0' END,
                ship_ut  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c59))) = 'Y' THEN '1' ELSE '0' END,
                ship_va  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c60))) = 'Y' THEN '1' ELSE '0' END,
                ship_vt  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c61))) = 'Y' THEN '1' ELSE '0' END,
                ship_wa  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c62))) = 'Y' THEN '1' ELSE '0' END,
                ship_wi  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c63))) = 'Y' THEN '1' ELSE '0' END,
                ship_wv  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c64))) = 'Y' THEN '1' ELSE '0' END,
                ship_wy  = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c65))) = 'Y' THEN '1' ELSE '0' END,

                ground_shipments_only = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c66))) = 'Y' THEN '1' ELSE '0' END,
                adult_sig_required    = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c67))) = 'Y' THEN '1' ELSE '0' END,
                blocked_from_dropship = CASE WHEN UPPER(TRIM(TRIM(BOTH '\\r' FROM @c68))) = 'Y' THEN '1' ELSE '0' END,

                date_entered          = TRIM(TRIM(BOTH '\\r' FROM @c69)),
                retail_map            = TRIM(TRIM(BOTH '\\r' FROM @c70)),
                image_disclaimer      = TRIM(TRIM(BOTH '\\r' FROM @c71)),
                shipping_length_in    = TRIM(TRIM(BOTH '\\r' FROM @c72)),
                shipping_width_in     = TRIM(TRIM(BOTH '\\r' FROM @c73)),
                shipping_height_in    = TRIM(TRIM(BOTH '\\r' FROM @c74)),
                reserved_future       = TRIM(TRIM(BOTH '\\r' FROM @c75))
        ";

        $prepared   = $wpdb->prepare($sql, $file_path);
        $t_sql_start = microtime(true);
        $result      = $wpdb->query($prepared);
        $t_sql_ms    = (microtime(true) - $t_sql_start) * 1000;

        if ($result === false) {
            error_log(
                '[FFLHub][RSR Import][LOAD DATA] query failed: ' . $wpdb->last_error
            );
            return -1;
        }

        $wpdb->query("DELETE FROM {$table_name} WHERE upc IS NULL OR upc = ''");


        // Count rows actually loaded.
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        if ($rows > 0) {
            update_option('fflhub_rsr_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_rsr_fulfillment_last_import_count', $rows, false);
        }

        $t_total_ms = (microtime(true) - $t_start) * 1000;

        error_log(
            sprintf(
                '[FFLHub][RSR Import] import_fulfillment_file_via_load_data(): total=%.2f ms (sql=%.2f ms), rows=%d, ignore_lines=%d',
                $t_total_ms,
                $t_sql_ms,
                $rows,
                $ignore_lines
            )
        );

        return $rows;
    }
    /**
     * ORIGINAL PHP BATCH IMPORTER (fallback if LOAD DATA is unavailable or fails).
     *
     * @param string $file_path
     * @return int
     */
    // NEW: this is your previous import_fulfillment_file() body
    private static function import_fulfillment_file_via_php(string $file_path): int
    {
        global $wpdb;

        $t_import_start      = microtime(true);
        $t_parse_total       = 0.0;
        $t_flush_total       = 0.0;

        if (! file_exists($file_path) || ! is_readable($file_path)) {
            return 0;
        }

        if (! class_exists('FFLHub_RSR_Fulfillment_Table')) {
            error_log('[FFLHub] RSR fulfillment import: FFLHub_RSR_Fulfillment_Table class not found.');
            return 0;
        }

        if (! class_exists('FFLHub_RSR_Fulfillment_Schema')) {
            error_log('[FFLHub] RSR fulfillment import: FFLHub_RSR_Fulfillment_Schema class not found.');
            return 0;
        }

        $table_name = FFLHub_RSR_Fulfillment_Table::get_staging_table_name();

        // Allow long-running import if needed.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($file_path, 'r');
        if (! $handle) {
            error_log('[FFLHub] RSR fulfillment import: could not fopen ' . $file_path);
            return 0;
        }

        // Start with a clean staging table.
        $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $parser       = new FFLHub_RSR_Fulfillment_Parser();

        // Bigger batch = fewer INSERT statements.
        $batch_size   = 1000;
        $batch_rows   = array();
        $total_import = 0;
        $line_number  = 0;
        $skipped_missing_upc = 0;

        // Single source of truth for column order from the schema helper.
        $columns       = FFLHub_RSR_Fulfillment_Schema::get_insert_columns();
        $num_cols      = count($columns);
        $column_list   = implode(', ', $columns);

        // Precompute placeholders and insert prefix.
        $row_placeholder = '(' . implode(', ', array_fill(0, $num_cols, '%s')) . ')';
        $insert_prefix   = 'INSERT INTO ' . $table_name . ' (' . $column_list . ') VALUES ';

        // Wrap all inserts in a single transaction to avoid per-batch commit overhead.
        $wpdb->query('START TRANSACTION');

        $flush_batch = function () use (
            &$batch_rows,
            &$total_import,
            $wpdb,
            $columns,
            $num_cols,
            &$t_flush_total,
            $row_placeholder,
            $insert_prefix
        ) {
            if (empty($batch_rows)) {
                return;
            }

            $t0 = microtime(true);

            $placeholders = array();
            $values       = array();

            foreach ($batch_rows as $row) {
                // Reuse the precomputed row placeholder.
                $placeholders[] = $row_placeholder;

                foreach ($columns as $col) {
                    $values[] = isset($row[$col]) ? $row[$col] : '';
                }
            }

            $sql = $insert_prefix . implode(', ', $placeholders);

            // One big prepared statement per batch.
            $prepared = $wpdb->prepare($sql, $values);
            $result   = $wpdb->query($prepared);

            if ($result !== false) {
                $total_import += count($batch_rows);
            } else {
                error_log('[FFLHub][RSR Import] Batch INSERT failed: ' . $wpdb->last_error);
            }

            $batch_rows = array();

            $t_flush_total += (microtime(true) - $t0);
        };

        // Read + parse loop.
        while (($line = fgets($handle)) !== false) {
            $line_number++;

            $t0  = microtime(true);
            $row = $parser->parse_line($line, $line_number);
            $t1  = microtime(true);

            // Accumulate parsing (includes fgets + parse_line).
            $t_parse_total += ($t1 - $t0);

            // NEW: skip rows where UPC is missing/empty/'null'.
            $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
            if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                $skipped_missing_upc++;
                continue;
            }

            $batch_rows[] = $row;

            if (count($batch_rows) >= $batch_size) {
                $flush_batch();
            }
        }

        fclose($handle);

        // Flush any remaining rows.
        $flush_batch();

        // Commit all inserts as a single transaction.
        $wpdb->query('COMMIT');

        // Optional: store last-import info.
        if ($total_import > 0) {
            update_option('fflhub_rsr_fulfillment_last_import', current_time('mysql'), false);
            update_option('fflhub_rsr_fulfillment_last_import_count', $total_import, false);
        }

        // Timing logs for deeper insight.
        $t_import_total_ms = (microtime(true) - $t_import_start) * 1000;
        $t_parse_ms        = $t_parse_total * 1000;
        $t_flush_ms        = $t_flush_total * 1000;

        error_log(
            sprintf(
                '[FFLHub][RSR Import] import_fulfillment_file_via_php(): total=%.2f ms, parse+loop=%.2f ms, db_flush=%.2f ms, rows=%d, skipped_missing_upc=%d',
                $t_import_total_ms,
                $t_parse_ms,
                $t_flush_ms,
                $total_import,
                $skipped_missing_upc
            )
        );

        return $total_import;
    }
}
