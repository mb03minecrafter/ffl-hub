<?php

namespace FFLHub\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;

class OrderSplitAuditCommand
{
    /**
     * Edit these defaults freely for your test environment.
     * The command never calls place_order(), so these are safe dummy values.
     */

    // ---- Customer Ship-To (non-FFL) dummy ----
    private const DUMMY_CUSTOMER_NAME    = 'Test Customer';
    private const DUMMY_CUSTOMER_COMPANY = 'FFLHub Test';
    private const DUMMY_CUSTOMER_ADDR1   = '123 Test St';
    private const DUMMY_CUSTOMER_ADDR2   = 'Apt 1';
    private const DUMMY_CUSTOMER_CITY    = 'Baton Rouge';
    private const DUMMY_CUSTOMER_STATE   = 'LA';
    private const DUMMY_CUSTOMER_ZIP     = '70808';
    private const DUMMY_CUSTOMER_PHONE   = '2255551212';
    private const DUMMY_CUSTOMER_EMAIL   = 'test@example.com';

    // ---- Receiving FFL Ship-To dummy ----
    private const DUMMY_FFL_NAME    = 'Test Receiving FFL';
    private const DUMMY_FFL_COMPANY = 'Test Gun Shop LLC';
    private const DUMMY_FFL_ADDR1   = '456 FFL Rd';
    private const DUMMY_FFL_ADDR2   = '';
    private const DUMMY_FFL_CITY    = 'Baton Rouge';
    private const DUMMY_FFL_STATE   = 'LA';
    private const DUMMY_FFL_ZIP     = '70809';
    private const DUMMY_FFL_PHONE   = '2255552323';
    private const DUMMY_FFL_EMAIL   = 'ffl@example.com';

    // Dummy FFL number (used only to populate request field)
    private const DUMMY_RECEIVING_FFL_NUMBER = '1234567890';

    /**
     * Usage examples:
     *  wp fflhub audit-order-splitting --distributors=rsr,lipseys --orders=100 --pool=200 --max-lines=6 --out=C:\temp\order_split_audit.csv --out-failures=C:\temp\order_split_failures.csv
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args)
    {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        global $wpdb;

        $out         = isset($assoc_args['out']) ? (string) $assoc_args['out'] : '';
        $out_fail    = isset($assoc_args['out-failures']) ? (string) $assoc_args['out-failures'] : '';

        $orders      = isset($assoc_args['orders']) ? max(1, (int) $assoc_args['orders']) : 50;
        $pool        = isset($assoc_args['pool']) ? max(10, (int) $assoc_args['pool']) : 200;
        $max_lines   = isset($assoc_args['max-lines']) ? max(1, (int) $assoc_args['max-lines']) : 6;
        $dest_state  = isset($assoc_args['dest-state']) ? strtoupper(trim((string) $assoc_args['dest-state'])) : self::DUMMY_CUSTOMER_STATE;
        $merchant_id = isset($assoc_args['merchant-id']) ? (string) $assoc_args['merchant-id'] : 'DUMMY-WC-ORDER';

        $dist_arg = isset($assoc_args['distributors']) ? (string) $assoc_args['distributors'] : 'rsr,lipseys';
        $dist_ids = array_values(array_filter(array_map('trim', explode(',', strtolower($dist_arg)))));

        if ($out === '') {
            \WP_CLI::error('Missing required --out=PATH (CSV output path).');
            return;
        }

        // Supported distributors for this audit
        // IMPORTANT: table prefixes are "..._v" so find_latest_existing_table() can probe v1..v200
        $supported = [
            'rsr' => [
                'label'             => 'RSR',
                'live_table_prefix' => $wpdb->prefix . 'fflhub_rsr_product_v',
                'upc_col'           => 'upc',
                'qty_col'           => 'inventory_quantity',
                'ffl_col'           => null,
                'map_key_col'       => 'rsr_stock_number',
                'items_builder'     => function (string $mapped_key, int $qty, string $normalized_upc): array {
                    return [
                        'UPCcode' => $normalized_upc,
                        'WishQty' => $qty,
                        'PartNum' => $mapped_key,
                    ];
                },
            ],
            'lipseys' => [
                'label'             => 'Lipseys',
                'live_table_prefix' => $wpdb->prefix . 'fflhub_lipseys_product_v',
                'upc_col'           => 'upc',
                'qty_col'           => 'inventory_quantity',
                'ffl_col'           => 'ffl_required',
                'map_key_col'       => 'lipseys_item_number',
                'items_builder'     => function (string $mapped_key, int $qty, string $normalized_upc): array {
                    return [
                        'ItemNo'   => $mapped_key,
                        'Quantity' => $qty,
                    ];
                },
            ],
        ];

        $targets = [];
        foreach ($dist_ids as $id) {
            if (isset($supported[$id])) {
                $targets[$id] = $supported[$id];
            } else {
                \WP_CLI::warning("Skipping unsupported distributor '{$id}'.");
            }
        }

        if (empty($targets)) {
            \WP_CLI::error('No supported distributors specified.');
            return;
        }

        // Build dummy ship-to objects
        $ship_customer = new DistributorShipTo(
            self::DUMMY_CUSTOMER_NAME,
            self::DUMMY_CUSTOMER_COMPANY,
            self::DUMMY_CUSTOMER_ADDR1,
            self::DUMMY_CUSTOMER_ADDR2,
            self::DUMMY_CUSTOMER_CITY,
            $dest_state,
            self::DUMMY_CUSTOMER_ZIP,
            self::DUMMY_CUSTOMER_PHONE,
            self::DUMMY_CUSTOMER_EMAIL
        );

        $ship_ffl = new DistributorShipTo(
            self::DUMMY_FFL_NAME,
            self::DUMMY_FFL_COMPANY,
            self::DUMMY_FFL_ADDR1,
            self::DUMMY_FFL_ADDR2,
            self::DUMMY_FFL_CITY,
            self::DUMMY_FFL_STATE,
            self::DUMMY_FFL_ZIP,
            self::DUMMY_FFL_PHONE,
            self::DUMMY_FFL_EMAIL
        );

        // Open summary CSV
        $fh = @fopen($out, 'w');
        if (!$fh) {
            \WP_CLI::error('Failed to open CSV for writing: ' . $out);
            return;
        }

        fputcsv($fh, [
            'timestamp_utc',
            'distributor_id',
            'distributor_label',
            'live_table',
            'order_index',
            'merchant_order_id',
            'lines_total',
            'ffl_lines',
            'non_ffl_lines',
            'split_ok',
            'map_ffl_ok',
            'map_non_ok',
            'items_ffl_count',
            'items_non_count',
            'ok',
            'message',
        ]);

        // Open failures CSV (optional)
        $fh_fail = null;
        if ($out_fail !== '') {
            $fh_fail = @fopen($out_fail, 'w');
            if (!$fh_fail) {
                fclose($fh);
                \WP_CLI::error('Failed to open failures CSV: ' . $out_fail);
                return;
            }

            fputcsv($fh_fail, [
                'timestamp_utc',
                'distributor_id',
                'order_index',
                'LANE',
                'raw_upc',
                'normalized_upc',
                'quantity',
                'ffl_required',
                'reason',
            ]);
        }

        $run_ts = gmdate('c');

        foreach ($targets as $dist_id => $cfg) {
            $live_table = $this->find_latest_existing_table($cfg['live_table_prefix']);
            if ($live_table === null) {
                \WP_CLI::warning("{$cfg['label']}: No live table found for prefix {$cfg['live_table_prefix']}");
                continue;
            }

            $pool_rows = $this->fetch_random_in_stock_rows(
                $live_table,
                $cfg['upc_col'],
                $cfg['qty_col'],
                $cfg['ffl_col'],
                $cfg['map_key_col'],
                $pool
            );

            if (empty($pool_rows)) {
                \WP_CLI::warning("{$cfg['label']}: No in-stock rows found in {$live_table}");
                continue;
            }

            $map_by_upc = [];
            foreach ($pool_rows as $r) {
                $upc = (string) ($r['upc'] ?? '');
                $norm = $this->normalize_upc_digits_only($upc);
                if ($norm && !empty($r['mapped_key'])) {
                    $map_by_upc[$norm] = trim((string) $r['mapped_key']);
                }
            }

            // Progress bar for this distributor
            $progress = \WP_CLI\Utils\make_progress_bar("{$cfg['label']} audit orders", $orders);

            for ($i = 1; $i <= $orders; $i++) {
                $line_count = mt_rand(1, $max_lines);
                $lines = [];

                for ($k = 0; $k < $line_count; $k++) {
                    $pick = $pool_rows[array_rand($pool_rows)];
                    $upc_raw = (string) $pick['upc'];
                    $qty_avail = (int) $pick['qty'];

                    $qty = ($qty_avail > 1) ? mt_rand(1, min(3, $qty_avail)) : 1;

                    $ffl = isset($pick['ffl_required'])
                        ? ((int) $pick['ffl_required'] === 1)
                        : (mt_rand(0, 1) === 1);

                    if (mt_rand(1, 10) <= 3) {
                        $ffl = !$ffl;
                    }

                    $lines[] = new DistributorOrderLine(
                        $this->make_sloppy_upc($upc_raw),
                        $qty,
                        $ffl
                    );
                }

                $req = new DistributorOrderRequest(
                    $lines,
                    $ship_customer,
                    $ship_ffl,
                    $merchant_id . '-' . $dist_id . '-' . $i,
                    $dest_state,
                    self::DUMMY_RECEIVING_FFL_NUMBER,
                    'FFLHub split audit dummy order'
                );

                $split_ok = true;
                $map_ffl_ok = true;
                $map_non_ok = true;
                $msg_parts = [];

                $ffl_lines = $req->ffl_lines();
                $non_lines = $req->non_ffl_lines();

                $items_ffl = $this->map_lines_to_items(
                    $ffl_lines,
                    $map_by_upc,
                    $cfg['items_builder'],
                    $msg_parts,
                    $map_ffl_ok,
                    $fh_fail,
                    $dist_id,
                    $i,
                    'direct_ship_ffl'
                );

                $items_non = $this->map_lines_to_items(
                    $non_lines,
                    $map_by_upc,
                    $cfg['items_builder'],
                    $msg_parts,
                    $map_non_ok,
                    $fh_fail,
                    $dist_id,
                    $i,
                    'direct_ship_non_ffl'
                );

                $ok = ($split_ok && $map_ffl_ok && $map_non_ok);
                $msg = implode(' | ', array_unique(array_filter($msg_parts)));
                if ($msg === '') {
                    $msg = 'OK';
                }

                fputcsv($fh, [
                    $run_ts,
                    $dist_id,
                    $cfg['label'],
                    $live_table,
                    $i,
                    $req->merchant_order_id,
                    count($lines),
                    count($ffl_lines),
                    count($non_lines),
                    $split_ok ? '1' : '0',
                    $map_ffl_ok ? '1' : '0',
                    $map_non_ok ? '1' : '0',
                    is_array($items_ffl) ? count($items_ffl) : 0,
                    is_array($items_non) ? count($items_non) : 0,
                    $ok ? '1' : '0',
                    $msg,
                ]);

                $progress->tick();
            }

            $progress->finish();

            \WP_CLI::success("{$cfg['label']}: wrote {$orders} audit rows.");
        }

        fclose($fh);
        if ($fh_fail) {
            fclose($fh_fail);
        }

        \WP_CLI::success('Done: ' . $out);
    }

    private function map_lines_to_items(
        array $lines,
        array $map_by_upc,
        callable $items_builder,
        array &$msg_parts,
        bool &$ok,
        $fh_fail,
        string $dist_id,
        int $order_index,
        string $lane
    ): array {
        $items = [];

        foreach ($lines as $l) {
            if (!($l instanceof DistributorOrderLine)) {
                continue;
            }

            $raw_upc = trim((string) $l->upc);
            $norm = $this->normalize_upc_digits_only($raw_upc);

            if ($norm === null) {
                $ok = false;
                $msg_parts[] = 'Invalid UPC after normalization.';
                $this->write_failure($fh_fail, $dist_id, $order_index, $lane, $l, $raw_upc, null, 'invalid_upc');
                continue;
            }

            $mapped = trim((string) ($map_by_upc[$norm] ?? ''));

            if ($mapped === '') {
                $ok = false;
                $msg_parts[] = "Cannot map UPC {$raw_upc}";
                $this->write_failure($fh_fail, $dist_id, $order_index, $lane, $l, $raw_upc, $norm, 'missing_map_key');
                continue;
            }

            $qty = max(1, (int) $l->quantity);
            $items[] = $items_builder($mapped, $qty, $norm);
        }

        return $items;
    }

    private function write_failure($fh, string $dist, int $order, string $lane, DistributorOrderLine $l, string $raw_upc, ?string $norm, string $reason): void
    {
        if (!is_resource($fh)) {
            return;
        }

        fputcsv($fh, [
            gmdate('c'),
            $dist,
            $order,
            $lane,
            $raw_upc,
            $norm ?? '',
            (int) $l->quantity,
            $l->ffl_required ? '1' : '0',
            $reason,
        ]);
    }

    /**
     * Turn a clean UPC into a "sloppy" variant to exercise trimming/cleanup paths.
     */
    private function make_sloppy_upc(string $upc): string
    {
        $upc = trim($upc);
        if ($upc === '') {
            return $upc;
        }

        $digits = preg_replace('/\D+/', '', $upc);
        $digits = is_string($digits) ? $digits : $upc;

        // sometimes insert dashes
        if (strlen($digits) >= 10 && mt_rand(1, 10) <= 5) {
            $digits = substr($digits, 0, 4) . '-' . substr($digits, 4, 4) . '-' . substr($digits, 8);
        }

        // add padding
        $pad_left = (mt_rand(0, 1) === 1) ? ' ' : '';
        $pad_right = (mt_rand(0, 1) === 1) ? ' ' : '';

        return $pad_left . $digits . $pad_right;
    }

    /**
     * Digits-only UPC normalization (mirrors your DistributorBase normalize_upc behavior).
     */
    private function normalize_upc_digits_only(string $upc): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $upc);
        $normalized = is_string($normalized) ? $normalized : '';

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * Finds the latest existing table whose name begins with a prefix like "..._v"
     * by checking v1..vMAX.
     *
     * Example prefix: "{$wpdb->prefix}fflhub_rsr_product_live_v"
     * It will check: ..._v1, ..._v2, ..._v3, ...
     */
    private function find_latest_existing_table(string $prefix_with_v): ?string
    {
        global $wpdb;

        $latest = '';

        // keep this aligned with your other audit command
        $max = 200;

        for ($i = 1; $i <= $max; $i++) {
            $table = $prefix_with_v . (string) $i;

            // SHOW TABLES LIKE expects a pattern; here we want exact match.
            $sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table);
            $exists = $wpdb->get_var($sql);

            if (is_string($exists) && $exists === $table) {
                $latest = $table;
            }
        }

        return ($latest !== '') ? $latest : null;
    }

    /**
     * Fetch random in-stock rows from a live table.
     *
     * Returns an array of:
     *  [
     *    ['upc' => '...', 'qty' => 10, 'ffl_required' => 0/1?, 'mapped_key' => '...'],
     *    ...
     *  ]
     */
    private function fetch_random_in_stock_rows(
        string $table,
        string $upc_col,
        string $qty_col,
        ?string $ffl_col,
        string $map_key_col,
        int $limit
    ): array {
        global $wpdb;

        $limit = max(1, (int) $limit);

        $cols = [
            "{$upc_col} AS upc",
            "{$qty_col} AS qty",
            "{$map_key_col} AS mapped_key",
        ];

        if ($ffl_col) {
            $cols[] = "{$ffl_col} AS ffl_required";
        }

        $col_sql = implode(', ', $cols);

        // Note: ORDER BY RAND() is fine here because limit is small (audit use only).
        $sql = "
            SELECT {$col_sql}
            FROM {$table}
            WHERE {$qty_col} > 0
              AND {$upc_col} IS NOT NULL
              AND {$upc_col} <> ''
            ORDER BY RAND()
            LIMIT %d
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}

