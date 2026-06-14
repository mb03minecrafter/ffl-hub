<?php

declare(strict_types=1);

namespace FFLHub\CLI;

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stress test: pick random in-stock UPCs from distributor fulfillment tables,
 * run lookup pipeline, select cheapest-in-stock, and create Woo products.
 *
 * OPTIONS
 * [--source=<src>]
 * : rsr|lipseys|zanders|both|all (default: both)
 *   - both = rsr + lipseys (legacy)
 *   - all  = rsr + lipseys + zanders
 *
 * [--count=<n>]
 * : Number of products to attempt to create (default: 50)
 *
 * [--pool=<n>]
 * : Number of random UPC candidates to sample from tables before processing (default: 500)
 *   (Larger pool => more variety / better chance to hit "not already created".)
 *
 * [--min-qty=<n>]
 * : Minimum quantity in fulfillment table to consider "in stock" (default: 1)
 *
 * [--min-upc-len=<n>]
 * : Minimum digits-only UPC length (default: 8)
 *
 * [--max-upc-len=<n>]
 * : Maximum digits-only UPC length (default: 14)
 *
 * [--include-images]
 * : include_images=true in lookup pipeline (default: off for speed)
 *
 * [--dry-run]
 * : Do not create products; only print the selected offer and planned action
 *
 * [--skip-existing]
 * : Skip UPCs that already have a Woo product (default: on)
 *
 * [--no-skip-existing]
 * : Disable skip-existing behavior
 *
 * [--csv=<path>]
 * : Write results to CSV
 *
 * [--progress]
 * : Show progress bar
 *
 * EXAMPLES
 *   wp fflhub stress-create-products --source=all --count=200 --pool=2000 --progress --csv="C:\temp\stress.csv"
 *   wp fflhub stress-create-products --source=zanders --count=50 --dry-run
 */
final class StressCreateProductsCommand
{
    public function __invoke(array $args, array $assoc_args): void
    {
        if (! class_exists('\WP_CLI')) {
            return;
        }

        global $wpdb;

        $t0 = microtime(true);

        $source = isset($assoc_args['source']) ? strtolower((string) $assoc_args['source']) : 'both';

        // Normalize legacy aliases:
        // - both => rsr + lipseys
        // - all  => rsr + lipseys + zanders
        if ($source === 'all') {
            $source = 'all';
        } elseif ($source === 'both') {
            $source = 'both';
        }

        if (! in_array($source, ['rsr', 'lipseys', 'zanders', 'both', 'all'], true)) {
            \WP_CLI::error("Invalid --source={$source}. Use rsr|lipseys|zanders|both|all.");
            return;
        }

        $count = isset($assoc_args['count']) ? max(1, (int) $assoc_args['count']) : 50;
        $pool  = isset($assoc_args['pool']) ? max($count, (int) $assoc_args['pool']) : 500;

        $min_qty = isset($assoc_args['min-qty']) ? max(0, (int) $assoc_args['min-qty']) : 1;

        $min_len = isset($assoc_args['min-upc-len']) ? (int) $assoc_args['min-upc-len'] : 8;
        $max_len = isset($assoc_args['max-upc-len']) ? (int) $assoc_args['max-upc-len'] : 14;

        $include_images = isset($assoc_args['include-images']);
        $dry_run = isset($assoc_args['dry-run']);

        // default skip existing = on, unless explicitly disabled
        $skip_existing = true;
        if (isset($assoc_args['no-skip-existing'])) {
            $skip_existing = false;
        } elseif (isset($assoc_args['skip-existing'])) {
            $skip_existing = true;
        }

        $progress = isset($assoc_args['progress']);
        $csv_path = isset($assoc_args['csv']) ? (string) $assoc_args['csv'] : '';

        // Detect latest fulfillment tables
        $rsr_table     = $this->find_latest_existing_table($wpdb, $wpdb->prefix . 'fflhub_rsr_product_v');
        $lipseys_table = $this->find_latest_existing_table($wpdb, $wpdb->prefix . 'fflhub_lipseys_product_v');
        $zanders_table = $this->find_latest_existing_table($wpdb, $wpdb->prefix . 'fflhub_zanders_product_v');

        $want_rsr     = ($source === 'rsr' || $source === 'both' || $source === 'all');
        $want_lipseys = ($source === 'lipseys' || $source === 'both' || $source === 'all');
        $want_zanders = ($source === 'zanders' || $source === 'all');

        if ($want_rsr && $rsr_table === '') {
            \WP_CLI::warning('RSR product table not found.');
        }
        if ($want_lipseys && $lipseys_table === '') {
            \WP_CLI::warning("Lipsey's product table not found.");
        }
        if ($want_zanders && $zanders_table === '') {
            \WP_CLI::warning("Zanders fulfillment table not found.");
        }

        if (
            ($want_rsr && $rsr_table === '' && !$want_lipseys && !$want_zanders) ||
            ($want_lipseys && $lipseys_table === '' && !$want_rsr && !$want_zanders) ||
            ($want_zanders && $zanders_table === '' && !$want_rsr && !$want_lipseys) ||
            (($source === 'both') && $rsr_table === '' && $lipseys_table === '') ||
            (($source === 'all') && $rsr_table === '' && $lipseys_table === '' && $zanders_table === '')
        ) {
            \WP_CLI::error('No usable fulfillment tables found for requested --source.');
            return;
        }

        // Build random UPC candidate pool
        $candidates = [];

        if ($want_rsr && $rsr_table !== '') {
            $candidates = array_merge(
                $candidates,
                $this->fetch_random_instock_upcs($wpdb, $rsr_table, $pool, $min_qty, $min_len, $max_len)
            );
        }

        if ($want_lipseys && $lipseys_table !== '') {
            $candidates = array_merge(
                $candidates,
                $this->fetch_random_instock_upcs($wpdb, $lipseys_table, $pool, $min_qty, $min_len, $max_len)
            );
        }

        if ($want_zanders && $zanders_table !== '') {
            $candidates = array_merge(
                $candidates,
                $this->fetch_random_instock_upcs($wpdb, $zanders_table, $pool, $min_qty, $min_len, $max_len)
            );
        }

        $candidates = array_values(array_unique(array_filter(array_map([$this, 'digits_only'], $candidates))));
        if (count($candidates) === 0) {
            \WP_CLI::error('No UPC candidates found (after filtering).');
            return;
        }

        // Shuffle to randomize processing order
        shuffle($candidates);

        \WP_CLI::log(sprintf(
            "Stress create: source=%s count=%d pool=%d candidates=%d min_qty=%d include_images=%d dry_run=%d skip_existing=%d",
            $source,
            $count,
            $pool,
            count($candidates),
            $min_qty,
            $include_images ? 1 : 0,
            $dry_run ? 1 : 0,
            $skip_existing ? 1 : 0
        ));

        // CSV
        $csv_fh = null;
        if ($csv_path !== '') {
            $csv_fh = @fopen($csv_path, 'w');
            if (! is_resource($csv_fh)) {
                \WP_CLI::warning("Could not open CSV for writing: {$csv_path}");
                $csv_fh = null;
            } else {
                fputcsv($csv_fh, [
                    'upc',
                    'action',              // created|existing|skipped_existing|lookup_error|no_offer|bad_payload|dry_run|create_error
                    'product_id',
                    'selected_dist_id',
                    'selected_label',
                    'selected_qty',
                    'selected_true_cost',
                    'selected_dealer_price',
                    'selected_map',
                    'selected_msrp',
                    'selected_shipping_cost',
                    'recommended_price',
                    'lookup_offers_count',
                    'lookup_ms',
                    'create_ms',
                    'error',
                ]);
            }
        }

        $bar = null;
        if ($progress && class_exists('\cli\progress\Bar')) {
            $bar = new \cli\progress\Bar('Progress', $count);
        }

        // Stats
        $attempted = 0;
        $created = 0;
        $existing = 0;
        $skipped_existing_n = 0;
        $lookup_error = 0;
        $no_offer = 0;
        $bad_payload = 0;

        $lookup_ms_sum = 0.0;
        $create_ms_sum = 0.0;

        // Iterate candidates until we hit $count attempts (not necessarily $count created)
        foreach ($candidates as $upc) {
            if ($attempted >= $count) {
                break;
            }

            $upc = $this->digits_only((string) $upc);
            if ($upc === '') {
                continue;
            }

            // optional: skip if already exists
            if ($skip_existing) {
                $existing_id = $this->find_existing_product_id_by_upc($upc);
                if ($existing_id !== null) {
                    $skipped_existing_n++;
                    $attempted++;

                    $this->write_csv($csv_fh, [
                        $upc,
                        'skipped_existing',
                        (string) $existing_id,
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        ''
                    ]);

                    if ($bar) {
                        $bar->tick();
                    }
                    continue;
                }
            }

            $t_lookup0 = microtime(true);
            $res = DistributorProductHelper::get_upc_lookup_result_from_distributors(
                Plugin::instance()->distributor_handler,
                $upc,
                (bool) $include_images
            );
            $lookup_ms = (microtime(true) - $t_lookup0) * 1000.0;
            $lookup_ms_sum += $lookup_ms;

            if (is_wp_error($res)) {
                $lookup_error++;
                $attempted++;

                $this->write_csv($csv_fh, [
                    $upc,
                    'lookup_error',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    (string) number_format($lookup_ms, 2, '.', ''),
                    '',
                    $res->get_error_message()
                ]);

                if ($bar) {
                    $bar->tick();
                }
                continue;
            }

            if (! ($res instanceof UpcLookupResult)) {
                $lookup_error++;
                $attempted++;

                $this->write_csv($csv_fh, [
                    $upc,
                    'lookup_error',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    (string) number_format($lookup_ms, 2, '.', ''),
                    '',
                    'lookup returned unexpected type'
                ]);

                if ($bar) {
                    $bar->tick();
                }
                continue;
            }

            // Select offer: cheapest_in_stock -> cheapest_any -> first offer
            $offers = $res->offers();
            $selected_offer = $res->cheapest_in_stock() ?: $res->cheapest_any();
            if (! ($selected_offer instanceof DistributorOffer)) {
                $first = reset($offers);
                $selected_offer = ($first instanceof DistributorOffer) ? $first : null;
            }

            if (! ($selected_offer instanceof DistributorOffer)) {
                $no_offer++;
                $attempted++;

                $this->write_csv($csv_fh, [
                    $upc,
                    'no_offer',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    (string) count($offers),
                    (string) number_format($lookup_ms, 2, '.', ''),
                    '',
                    'no selectable offer'
                ]);

                if ($bar) {
                    $bar->tick();
                }
                continue;
            }

            $payload = $selected_offer->product ?? null;
            if (! ($payload instanceof DistributorProductPayload)) {
                $bad_payload++;
                $attempted++;

                $this->write_csv($csv_fh, [
                    $upc,
                    'bad_payload',
                    '',
                    (string) $selected_offer->distributor_id,
                    (string) $selected_offer->label,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    (string) count($offers),
                    (string) number_format($lookup_ms, 2, '.', ''),
                    '',
                    'selected payload missing/invalid'
                ]);

                if ($bar) {
                    $bar->tick();
                }
                continue;
            }

            // Recommended price based on helper logic (what creation uses)
            $recommended = DistributorProductHelper::get_recommended_price_from_payload($payload);

            if ($dry_run) {
                $attempted++;

                \WP_CLI::log(sprintf(
                    "[DRY RUN] UPC=%s selected=%s qty=%s rec_price=%s offers=%d",
                    $upc,
                    (string) $selected_offer->distributor_id,
                    (string) ($payload->quantity ?? ''),
                    is_numeric($recommended) ? (string) $recommended : 'null',
                    count($offers)
                ));

                $this->write_csv($csv_fh, [
                    $upc,
                    'dry_run',
                    '',
                    (string) $selected_offer->distributor_id,
                    (string) $selected_offer->label,
                    (string) ($payload->quantity ?? ''),
                    (string) ($payload->true_cost ?? ''),
                    (string) ($payload->price ?? ''),
                    (string) ($payload->map ?? ''),
                    (string) ($payload->msrp ?? ''),
                    (string) ($payload->shipping_cost ?? ''),
                    is_numeric($recommended) ? (string) $recommended : '',
                    (string) count($offers),
                    (string) number_format($lookup_ms, 2, '.', ''),
                    '',
                    ''
                ]);

                if ($bar) {
                    $bar->tick();
                }
                continue;
            }

            // Create
            $t_create0 = microtime(true);
            $create_res = DistributorProductHelper::create_woo_product_from_payload(
                $upc,
                $payload,
                (string) $selected_offer->distributor_id,
                $offers
            );
            $create_ms = (microtime(true) - $t_create0) * 1000.0;
            $create_ms_sum += $create_ms;

            $attempted++;

            // Helper returns array on success/warn OR WP_Error on failure
            if (is_wp_error($create_res)) {
                $lookup_error++; // treat as "create_error LANE"

                $this->write_csv($csv_fh, [
                    $upc,
                    'create_error',
                    '',
                    (string) $selected_offer->distributor_id,
                    (string) $selected_offer->label,
                    (string) ($payload->quantity ?? ''),
                    (string) ($payload->true_cost ?? ''),
                    (string) ($payload->price ?? ''),
                    (string) ($payload->map ?? ''),
                    (string) ($payload->msrp ?? ''),
                    (string) ($payload->shipping_cost ?? ''),
                    is_numeric($recommended) ? (string) $recommended : '',
                    (string) count($offers),
                    (string) number_format($lookup_ms, 2, '.', ''),
                    (string) number_format($create_ms, 2, '.', ''),
                    $create_res->get_error_message()
                ]);

                if ($bar) {
                    $bar->tick();
                }
                continue;
            }

            $action = 'created';
            $product_id = '';

            if (is_array($create_res) && isset($create_res['type']) && $create_res['type'] === 'warning') {
                $action = 'existing';
                $existing++;
            } else {
                $created++;
            }

            $this->write_csv($csv_fh, [
                $upc,
                $action,
                $product_id,
                (string) $selected_offer->distributor_id,
                (string) $selected_offer->label,
                (string) ($payload->quantity ?? ''),
                (string) ($payload->true_cost ?? ''),
                (string) ($payload->price ?? ''),
                (string) ($payload->map ?? ''),
                (string) ($payload->msrp ?? ''),
                (string) ($payload->shipping_cost ?? ''),
                is_numeric($recommended) ? (string) $recommended : '',
                (string) count($offers),
                (string) number_format($lookup_ms, 2, '.', ''),
                (string) number_format($create_ms, 2, '.', ''),
                ''
            ]);

            if ($bar) {
                $bar->tick();
            }
        }

        if ($bar) {
            $bar->finish();
        }
        if (is_resource($csv_fh)) {
            @fclose($csv_fh);
        }

        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        \WP_CLI::log("Done.");
        \WP_CLI::log(sprintf(
            "Attempted=%d Created=%d Existing=%d SkippedExisting=%d Lookup/Create Errors=%d NoOffer=%d BadPayload=%d",
            $attempted,
            $created,
            $existing,
            $skipped_existing_n,
            $lookup_error,
            $no_offer,
            $bad_payload
        ));
        \WP_CLI::log(sprintf(
            "Timing: total=%.2f ms avg_lookup=%.2f ms avg_create=%.2f ms",
            $elapsed_ms,
            $attempted > 0 ? ($lookup_ms_sum / max(1, $attempted)) : 0.0,
            ($dry_run ? 0.0 : ($attempted > 0 ? ($create_ms_sum / max(1, $attempted)) : 0.0))
        ));

        if ($csv_path !== '') {
            \WP_CLI::log("CSV: {$csv_path}");
        }

        if ($created > 0) {
            \WP_CLI::success("Stress create completed.");
        } else {
            \WP_CLI::warning("Stress create ran, but no products were created (likely due to skip-existing / lookup errors / offers missing).");
        }
    }

    private function fetch_random_instock_upcs(\wpdb $wpdb, string $table, int $pool, int $min_qty, int $min_len, int $max_len): array
    {
        $pool = max(1, (int) $pool);

        $kind = $this->detect_stock_filter($wpdb, $table)[0];

        // Lipsey's: inventory_quantity varchar(32)
        if ($kind === 'LIPSEYS') {
            $sql = $wpdb->prepare(
                "SELECT upc
                 FROM {$table}
                 WHERE upc IS NOT NULL AND upc != ''
                   AND inventory_quantity IS NOT NULL AND inventory_quantity != ''
                   AND CAST(inventory_quantity AS UNSIGNED) >= %d
                 ORDER BY RAND()
                 LIMIT %d",
                $min_qty,
                $pool
            );

            $rows = $wpdb->get_col($sql);
            return is_array($rows) ? $this->filter_upcs($rows, $min_len, $max_len) : [];
        }

        // RSR: inventory_quantity varchar(32)
        if ($kind === 'RSR') {
            $sql = $wpdb->prepare(
                "SELECT upc
                 FROM {$table}
                 WHERE upc IS NOT NULL AND upc != ''
                   AND inventory_quantity IS NOT NULL AND inventory_quantity != ''
                   AND CAST(inventory_quantity AS UNSIGNED) >= %d
                 ORDER BY RAND()
                 LIMIT %d",
                $min_qty,
                $pool
            );

            $rows = $wpdb->get_col($sql);
            return is_array($rows) ? $this->filter_upcs($rows, $min_len, $max_len) : [];
        }

        // Zanders: "available" column is the quantity from the CSV you showed.
        // It may be numeric or string; CAST handles both.
        // Zanders: inventory_quantity varchar(32) (normalized from "available")
        if ($kind === 'ZANDERS') {
            $sql = $wpdb->prepare(
                "SELECT upc
         FROM {$table}
         WHERE upc IS NOT NULL AND upc != ''
           AND inventory_quantity IS NOT NULL AND inventory_quantity != ''
           AND CAST(inventory_quantity AS UNSIGNED) >= %d
         ORDER BY RAND()
         LIMIT %d",
                $min_qty,
                $pool
            );

            $rows = $wpdb->get_col($sql);
            return is_array($rows) ? $this->filter_upcs($rows, $min_len, $max_len) : [];
        }

        // Fallback: just random UPCs
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT upc FROM {$table} WHERE upc IS NOT NULL AND upc != '' ORDER BY RAND() LIMIT %d",
            $pool
        ));
        return is_array($rows) ? $this->filter_upcs($rows, $min_len, $max_len) : [];
    }

    private function detect_stock_filter(\wpdb $wpdb, string $table): array
    {
        // Return: [kind, params[]]
        // kind is a small string that indicates how to interpret stock columns.

        if (strpos($table, 'fflhub_lipseys_product_') !== false) {
            return ['LIPSEYS', []];
        }

        if (strpos($table, 'fflhub_rsr_product_') !== false) {
            return ['RSR', []];
        }

        if (strpos($table, 'fflhub_zanders_product_') !== false) {
            return ['ZANDERS', []];
        }

        return ['', []];
    }

    /** @return string[] */
    private function filter_upcs(array $rows, int $min_len, int $max_len): array
    {
        $out = [];
        foreach ($rows as $raw) {
            $upc = $this->digits_only((string) $raw);
            if ($upc === '') {
                continue;
            }
            $len = strlen($upc);
            if ($len < $min_len || $len > $max_len) {
                continue;
            }
            $out[] = $upc;
        }
        return $out;
    }

    private function digits_only(string $s): string
    {
        $d = preg_replace('/\D+/', '', (string) $s);
        return is_string($d) ? $d : '';
    }

    private function write_csv($csv_fh, array $row): void
    {
        if (! is_resource($csv_fh)) {
            return;
        }
        fputcsv($csv_fh, $row);
    }

    private function find_existing_product_id_by_upc(string $upc): ?int
    {
        $existing = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'meta_key'       => '_global_unique_id',
            'meta_value'     => $upc,
            'fields'         => 'ids',
        ]);

        if (empty($existing)) {
            return null;
        }
        return (int) $existing[0];
    }

    /**
     * Finds the latest existing table whose name begins with a prefix like "..._v"
     * by checking v1..v200.
     */
    private function find_latest_existing_table(\wpdb $wpdb, string $prefix_with_v): string
    {
        $latest = '';
        for ($i = 1; $i <= 200; $i++) {
            $table = $prefix_with_v . (string) $i;
            $sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table);
            $exists = $wpdb->get_var($sql);
            if ($exists === $table) {
                $latest = $table;
            }
        }
        return $latest;
    }
}

