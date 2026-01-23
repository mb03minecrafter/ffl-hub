<?php

namespace FFLHub\CLI;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Plugin;
use FFLHub\Settings\Options;

use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;

/**
 * Cart compliance audit generator (dummy carts + vote-style validation).
 *
 * SAFETY:
 * - NEVER calls place_order().
 * - Only calls validate_order_request() (and optional filter_lines_for_validation()).
 *
 * What it tests:
 * - Builds ONE DOR per cart distributor from random UPCs in live tables
 * - Feeds that DOR into ALL enabled distributors as "voters"
 * - If a voter implements filter_lines_for_validation(), we only validate lines that voter can carry/map
 *
 * Output:
 * - Summary CSV: one row per (cart_dist_id, order_index, voter_id)
 * - Failures CSV: one row per validated line that contributed to a block (optional)
 */
class ValidateOrderAuditCommand
{
    // ---- Customer Ship-To (non-FFL) dummy ----
    private const DUMMY_CUSTOMER_NAME    = 'Matthew Bickham';
    private const DUMMY_CUSTOMER_COMPANY = 'FFLHub Test';
    private const DUMMY_CUSTOMER_ADDR1   = '1282 Felosa Drive';
    private const DUMMY_CUSTOMER_ADDR2   = '';
    private const DUMMY_CUSTOMER_CITY    = 'Los Angeles';
    private const DUMMY_CUSTOMER_STATE   = 'CA';
    private const DUMMY_CUSTOMER_ZIP     = '90036';
    private const DUMMY_CUSTOMER_PHONE   = '213-760-5794';
    private const DUMMY_CUSTOMER_EMAIL   = 'mattbick2003@gmail.com';

    // ---- Receiving FFL Ship-To dummy ----
    private const DUMMY_FFL_NAME    = 'Bickham Firearms LLC';
    private const DUMMY_FFL_COMPANY = 'Bickham Firearms LLC';
    private const DUMMY_FFL_ADDR1   = '10322 Black Road';
    private const DUMMY_FFL_ADDR2   = '';
    private const DUMMY_FFL_CITY    = 'Zachary';
    private const DUMMY_FFL_STATE   = 'LA';
    private const DUMMY_FFL_ZIP     = '70791';
    private const DUMMY_FFL_PHONE   = '2256781533';
    private const DUMMY_FFL_EMAIL   = 'mattbick2003@gmail.com';

    private const DUMMY_RECEIVING_FFL_NUMBER = '572033078C08115';

    /**
     * Usage:
     *  wp fflhub audit-cart-compliance --distributors=rsr,lipseys --orders=100 --pool=400 --max-lines=6 --dest-state=CA --out=C:\temp\cart_compliance.csv --out-failures=C:\temp\cart_compliance_failures.csv
     *
     * Notes:
     * - Each "order" is a dummy cart for a single cart distributor (like your old audit),
     *   but it is validated by ALL enabled distributors (vote model).
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args)
    {
        if (!class_exists('\\WP_CLI')) {
            return;
        }

        global $wpdb;

        $plugin  = Plugin::instance();
        $handler = $plugin->distributor_handler ?? null;

        if (!$handler || !method_exists($handler, 'get_distributor_by_id')) {
            \WP_CLI::error('Distributor handler is not available.');
            return;
        }

        $out      = isset($assoc_args['out']) ? (string)$assoc_args['out'] : '';
        $out_fail = isset($assoc_args['out-failures']) ? (string)$assoc_args['out-failures'] : '';

        $orders    = isset($assoc_args['orders']) ? max(1, (int)$assoc_args['orders']) : 50;
        $pool      = isset($assoc_args['pool']) ? max(10, (int)$assoc_args['pool']) : 200;
        $max_lines = isset($assoc_args['max-lines']) ? max(1, (int)$assoc_args['max-lines']) : 6;

        $dest_state  = isset($assoc_args['dest-state']) ? strtoupper(trim((string)$assoc_args['dest-state'])) : self::DUMMY_CUSTOMER_STATE;
        $merchant_id = isset($assoc_args['merchant-id']) ? (string)$assoc_args['merchant-id'] : 'DUMMY-CART-COMPLIANCE';

        $dist_arg = isset($assoc_args['distributors']) ? (string)$assoc_args['distributors'] : 'rsr,lipseys';
        $dist_ids = array_values(array_filter(array_map('trim', explode(',', strtolower($dist_arg)))));

        if ($out === '') {
            \WP_CLI::error('Missing required --out=PATH (CSV output path).');
            return;
        }

        if (!preg_match('/^[A-Z]{2}$/', $dest_state)) {
            \WP_CLI::error('Invalid --dest-state. Must be 2-letter code (e.g. CA).');
            return;
        }

        // Supported distributors (for pulling random real UPC pool from tables)
        $supported = [
            'rsr' => [
                'label'             => 'RSR',
                'live_table_prefix' => $wpdb->prefix . 'fflhub_rsr_fulfillment_v',
                'upc_col'           => 'upc',
                'qty_col'           => 'inventory_quantity',
                'ffl_col'           => null,
            ],
            'lipseys' => [
                'label'             => 'Lipseys',
                'live_table_prefix' => $wpdb->prefix . 'fflhub_lipseys_fulfillment_v',
                'upc_col'           => 'upc',
                'qty_col'           => 'inventory_quantity',
                'ffl_col'           => 'ffl_required',
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

        // Build enabled voter list ONCE (these are the distributors that will "vote" on every dummy cart).
        $voters = $this->resolve_enabled_voters($handler);
        if (empty($voters)) {
            \WP_CLI::error('No enabled distributors with validate_order_request found.');
            return;
        }

        // Dummy ship-to objects
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

            // cart context
            'cart_dist_id',
            'cart_dist_label',
            'cart_live_table',
            'order_index',
            'merchant_order_id',
            'dest_state',

            // generated cart line counts
            'cart_lines_total',
            'cart_ffl_lines',
            'cart_non_ffl_lines',

            // voter context
            'voter_id',
            'voter_label',

            // validation subset (after filter_lines_for_validation)
            'voter_lines_validated',
            'voter_ffl_lines_validated',
            'voter_non_ffl_lines_validated',
            'voter_filter_applied',

            // result
            'result', // ok|blocked|skipped
            'codes',
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
                'cart_dist_id',
                'voter_id',
                'order_index',
                'merchant_order_id',
                'dest_state',
                'raw_upc',
                'normalized_upc',
                'quantity',
                'ffl_required',
                'included_in_voter_validation',
                'codes',
                'message',
            ]);
        }

        // Global progress bar: total validations = sum(targets) * orders * voters
        $total_steps = count($targets) * $orders * count($voters);
        $progress = \WP_CLI\Utils\make_progress_bar('CartCompliance audit', $total_steps);

        $run_ts = gmdate('c');

        foreach ($targets as $cart_dist_id => $cfg) {
            $cart_live_table = $this->find_latest_existing_table($cfg['live_table_prefix']);
            if ($cart_live_table === null) {
                \WP_CLI::warning("{$cfg['label']}: No live table found for prefix {$cfg['live_table_prefix']}");
                // still tick through steps? skip cleanly
                for ($i = 1; $i <= $orders; $i++) {
                    foreach ($voters as $_) {
                        $progress->tick();
                    }
                }
                continue;
            }

            $pool_rows = $this->fetch_random_in_stock_rows(
                $cart_live_table,
                $cfg['upc_col'],
                $cfg['qty_col'],
                $cfg['ffl_col'],
                $pool
            );

            if (empty($pool_rows)) {
                \WP_CLI::warning("{$cfg['label']}: No in-stock rows found in {$cart_live_table}");
                for ($i = 1; $i <= $orders; $i++) {
                    foreach ($voters as $_) {
                        $progress->tick();
                    }
                }
                continue;
            }

            for ($i = 1; $i <= $orders; $i++) {
                $line_count = mt_rand(1, $max_lines);

                // Build the cart lines ONCE for this dummy cart
                $cart_lines = [];
                for ($k = 0; $k < $line_count; $k++) {
                    $pick = $pool_rows[array_rand($pool_rows)];
                    $upc_raw   = (string)$pick['upc'];
                    $qty_avail = (int)$pick['qty'];
                    $qty = ($qty_avail > 1) ? mt_rand(1, min(3, $qty_avail)) : 1;

                    $ffl = isset($pick['ffl_required'])
                        ? ((int)$pick['ffl_required'] === 1)
                        : (mt_rand(0, 1) === 1);

                    // occasionally flip to exercise mixed carts
                    if (mt_rand(1, 10) <= 2) {
                        $ffl = !$ffl;
                    }

                    $cart_lines[] = new DistributorOrderLine(
                        $this->make_sloppy_upc($upc_raw),
                        $qty,
                        $ffl
                    );
                }

                $merchant_order_id = $merchant_id . '-' . $cart_dist_id . '-' . $i;

                // Base DOR (what would come from the cart distributor)
                $base_req = new DistributorOrderRequest(
                    $cart_lines,
                    $ship_customer,
                    $ship_ffl,
                    $merchant_order_id,
                    $dest_state,
                    self::DUMMY_RECEIVING_FFL_NUMBER,
                    'FFLHub CartCompliance audit dummy order (vote model)'
                );

                $cart_ffl = count($base_req->ffl_lines());
                $cart_non = count($base_req->non_ffl_lines());

                foreach ($voters as $voter_id => $voter) {
                    $voter_inst  = $voter['instance'];
                    $voter_label = $voter['label'];

                    // Apply per-voter filtering if available (prevents "RSR cannot map UPC..." from blocking unrelated carts)
                    $filter_applied = false;
                    $lines_for_voter = $cart_lines;

                    if (method_exists($voter_inst, 'filter_lines_for_validation')) {
                        $filter_applied = true;
                        try {
                            $filtered = $voter_inst->filter_lines_for_validation($cart_lines);
                            $lines_for_voter = is_array($filtered) ? $filtered : [];
                        } catch (\Throwable $e) {
                            // If filter blows up, be conservative: validate nothing (skip) but record it.
                            $lines_for_voter = [];
                        }
                    }

                    $result = 'skipped';
                    $codes  = '';
                    $msg    = 'Skipped: no lines to validate for this distributor.';
                    $v_ok   = null;

                    $voter_req = null;
                    $voter_ffl = 0;
                    $voter_non = 0;

                    if (!empty($lines_for_voter)) {
                        // Build voter-specific DOR using only the lines they can carry/map.
                        $voter_req = new DistributorOrderRequest(
                            $lines_for_voter,
                            $ship_customer,
                            $ship_ffl,
                            $merchant_order_id,
                            $dest_state,
                            self::DUMMY_RECEIVING_FFL_NUMBER,
                            'FFLHub CartCompliance audit dummy order (vote model; filtered lines)'
                        );

                        $voter_ffl = count($voter_req->ffl_lines());
                        $voter_non = count($voter_req->non_ffl_lines());

                        try {
                            /** @var DistributorOrderValidationResult $res */
                            $res = $voter_inst->validate_order_request($voter_req);

                            $v_ok = (is_object($res) && property_exists($res, 'ok')) ? (bool)$res->ok : false;
                            $msg  = (is_object($res) && property_exists($res, 'message')) ? (string)$res->message : 'No message';
                            $carr = (is_object($res) && property_exists($res, 'codes') && is_array($res->codes)) ? $res->codes : [];
                            $codes = !empty($carr) ? implode('|', array_map('strval', $carr)) : '';

                            if ($msg === '') {
                                $msg = $v_ok ? 'OK' : 'Blocked';
                            }

                            $result = $v_ok ? 'ok' : 'blocked';
                        } catch (\Throwable $e) {
                            $result = 'blocked';
                            $msg = 'Validate exception: ' . $e->getMessage();
                            $codes = 'FFLHUB_VALIDATE_EXCEPTION';
                            $v_ok = false;
                        }
                    }

                    // Write failure lines only when voter actually validated and blocked
                    if ($fh_fail && $result === 'blocked') {
                        $this->write_validation_failures_for_voter(
                            $fh_fail,
                            $cart_dist_id,
                            $voter_id,
                            $i,
                            $merchant_order_id,
                            $dest_state,
                            $cart_lines,
                            $lines_for_voter,
                            $codes,
                            $msg
                        );
                    }

                    fputcsv($fh, [
                        $run_ts,

                        $cart_dist_id,
                        $cfg['label'],
                        $cart_live_table,
                        $i,
                        $merchant_order_id,
                        $dest_state,

                        count($cart_lines),
                        $cart_ffl,
                        $cart_non,

                        $voter_id,
                        $voter_label,

                        !empty($lines_for_voter) ? count($lines_for_voter) : 0,
                        $voter_ffl,
                        $voter_non,
                        $filter_applied ? '1' : '0',

                        $result,
                        $codes,
                        $msg,
                    ]);

                    $progress->tick();
                }
            }

            \WP_CLI::log("{$cfg['label']}: generated {$orders} dummy carts; validated by " . count($voters) . " voters.");
        }

        $progress->finish();

        fclose($fh);
        if ($fh_fail) {
            fclose($fh_fail);
        }

        \WP_CLI::success('Done: ' . $out);
    }

    /**
     * Build enabled voter list from handler. These are the distributors that "vote" on every cart DOR.
     *
     * @return array<string,array{instance:object,label:string}>
     */
    private function resolve_enabled_voters($handler): array
    {
        $out = [];

        if (!method_exists($handler, 'get_distributors')) {
            // fallback: try common patterns if you have them, otherwise just use get_distributor_by_id via known ids.
            return $out;
        }

        foreach ((array)$handler->get_distributors() as $id => $maybe) {
            $id = strtolower(trim((string)$id));
            if ($id === '') {
                continue;
            }

            if (!Options::is_distributor_enabled($id)) {
                continue;
            }

            $d = $handler->get_distributor_by_id($id);
            if (!$d || !method_exists($d, 'validate_order_request')) {
                continue;
            }

            $out[$id] = [
                'instance' => $d,
                'label'    => method_exists($d, 'label') ? (string)$d->label() : $id,
            ];
        }

        return $out;
    }

    private function write_validation_failures_for_voter(
        $fh_fail,
        string $cart_dist_id,
        string $voter_id,
        int $order_index,
        string $merchant_order_id,
        string $dest_state,
        array $cart_lines,
        array $lines_for_voter,
        string $codes,
        string $message
    ): void {
        if (!is_resource($fh_fail)) {
            return;
        }

        // Build a quick identity map so we can mark which lines were actually validated.
        $validated_keys = [];
        foreach ($lines_for_voter as $l) {
            if (!($l instanceof DistributorOrderLine)) {
                continue;
            }
            $key = $this->line_identity_key($l);
            $validated_keys[$key] = true;
        }

        foreach ($cart_lines as $l) {
            if (!($l instanceof DistributorOrderLine)) {
                continue;
            }

            $raw = trim((string)$l->upc);
            $norm = $this->normalize_upc_digits_only($raw);

            $included = isset($validated_keys[$this->line_identity_key($l)]) ? '1' : '0';

            // Only write rows for lines that were actually validated by this voter.
            if ($included !== '1') {
                continue;
            }

            fputcsv($fh_fail, [
                gmdate('c'),
                $cart_dist_id,
                $voter_id,
                $order_index,
                $merchant_order_id,
                $dest_state,
                $raw,
                $norm ?? '',
                (int)$l->quantity,
                $l->ffl_required ? '1' : '0',
                $included,
                $codes,
                $message,
            ]);
        }
    }

    private function line_identity_key(DistributorOrderLine $l): string
    {
        // normalize in a way that is stable across "sloppy UPC" variants
        $norm = $this->normalize_upc_digits_only((string)$l->upc) ?? '';
        return $norm . '|' . ((int)$l->quantity) . '|' . ($l->ffl_required ? '1' : '0');
    }

    /**
     * Turn a clean UPC into a “sloppy” variant to exercise trimming/cleanup paths.
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
        $pad_left  = (mt_rand(0, 1) === 1) ? ' ' : '';
        $pad_right = (mt_rand(0, 1) === 1) ? ' ' : '';

        return $pad_left . $digits . $pad_right;
    }

    /**
     * Digits-only UPC normalization.
     */
    private function normalize_upc_digits_only(string $upc): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string)$upc);
        $normalized = is_string($normalized) ? $normalized : '';

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * Finds the latest existing table whose name begins with a prefix like "..._v"
     * by checking v1..vMAX.
     */
    private function find_latest_existing_table(string $prefix_with_v): ?string
    {
        global $wpdb;

        $latest = '';
        $max = 200;

        for ($i = 1; $i <= $max; $i++) {
            $table = $prefix_with_v . (string)$i;

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
     * Returns:
     *  [
     *    ['upc' => '...', 'qty' => 10, 'ffl_required' => 0/1?],
     *    ...
     *  ]
     */
    private function fetch_random_in_stock_rows(
        string $table,
        string $upc_col,
        string $qty_col,
        ?string $ffl_col,
        int $limit
    ): array {
        global $wpdb;

        $limit = max(1, (int)$limit);

        $cols = [
            "{$upc_col} AS upc",
            "{$qty_col} AS qty",
        ];

        if ($ffl_col) {
            $cols[] = "{$ffl_col} AS ffl_required";
        }

        $col_sql = implode(', ', $cols);

        // ORDER BY RAND() is fine here: audit use only.
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
