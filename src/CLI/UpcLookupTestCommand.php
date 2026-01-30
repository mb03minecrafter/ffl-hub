<?php
declare(strict_types=1);

namespace FFLHub\CLI;

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Distributor\Models\UpcLookupResult;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class UpcLookupTestCommand
{
    /**
     * Test DistributorProductHelper::get_upc_lookup_result_from_distributors()
     * against all unique UPCs found in live RSR + Lipsey's fulfillment tables.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Limit total UPCs tested (default: 0 = no limit)
     *
     * [--offset=<n>]
     * : Offset into UPC list (default: 0)
     *
     * [--source=<src>]
     * : Which source UPC set to use: rsr|lipseys|both (default: both)
     *
     * [--min-upc-len=<n>]
     * : Minimum UPC length (digits) to include (default: 8)
     *
     * [--max-upc-len=<n>]
     * : Maximum UPC length (digits) to include (default: 14)
     *
     * [--csv=<path>]
     * : Write per-UPC results to CSV at path (optional)
     *
     * [--progress]
     * : Show a progress bar (default: off)
     *
     * ## EXAMPLES
     *
     *     wp fflhub test-upc-lookups --source=both --progress
     *     wp fflhub test-upc-lookups --source=rsr --limit=500 --csv=/tmp/rsr_lookup.csv
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        if (! class_exists('\WP_CLI')) {
            return;
        }

        global $wpdb;

        $t_start = microtime(true);

        $limit      = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
        $offset     = isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0;
        $source     = isset($assoc_args['source']) ? (string) $assoc_args['source'] : 'both';
        $min_len    = isset($assoc_args['min-upc-len']) ? (int) $assoc_args['min-upc-len'] : 8;
        $max_len    = isset($assoc_args['max-upc-len']) ? (int) $assoc_args['max-upc-len'] : 14;
        $csv_path   = isset($assoc_args['csv']) ? (string) $assoc_args['csv'] : '';
        $use_prog   = isset($assoc_args['progress']);

        $source = strtolower($source);
        if (! in_array($source, ['rsr', 'lipseys', 'both'], true)) {
            \WP_CLI::error("Invalid --source. Use rsr|lipseys|both");
            return;
        }

        // Discover current live tables by looking for the latest *vN* that exists.
        // (Adjust if you have a canonical place to read "live" table name.)
        $rsr_table     = $this->find_latest_existing_table($wpdb, $wpdb->prefix . 'fflhub_rsr_fulfillment_v');
        $lipseys_table = $this->find_latest_existing_table($wpdb, $wpdb->prefix . 'fflhub_lipseys_fulfillment_v');

        if ($source === 'rsr' || $source === 'both') {
            if ($rsr_table === '') {
                \WP_CLI::error("Could not find an existing RSR fulfillment table matching {$wpdb->prefix}fflhub_rsr_fulfillment_v*");
                return;
            }
        }
        if ($source === 'lipseys' || $source === 'both') {
            if ($lipseys_table === '') {
                \WP_CLI::error("Could not find an existing Lipsey's fulfillment table matching {$wpdb->prefix}fflhub_lipseys_fulfillment_v*");
                return;
            }
        }

        $upcs = [];

        if ($source === 'rsr' || $source === 'both') {
            $upcs = array_merge($upcs, $this->fetch_distinct_upcs($wpdb, $rsr_table, $min_len, $max_len));
        }
        if ($source === 'lipseys' || $source === 'both') {
            $upcs = array_merge($upcs, $this->fetch_distinct_upcs($wpdb, $lipseys_table, $min_len, $max_len));
        }

        // Unique + stable sort
        $upcs = array_values(array_unique($upcs));
        sort($upcs, SORT_STRING);

        if ($offset > 0) {
            $upcs = array_slice($upcs, $offset);
        }
        if ($limit > 0) {
            $upcs = array_slice($upcs, 0, $limit);
        }

        $total = count($upcs);
        if ($total === 0) {
            \WP_CLI::warning("No UPCs found to test. source={$source}");
            return;
        }

        \WP_CLI::log("Testing {$total} UPCs (source={$source})");
        if ($source === 'rsr' || $source === 'both') {
            \WP_CLI::log("RSR table: {$rsr_table}");
        }
        if ($source === 'lipseys' || $source === 'both') {
            \WP_CLI::log("Lipsey's table: {$lipseys_table}");
        }

        $csv_fp = null;
        if ($csv_path !== '') {
            $csv_fp = @fopen($csv_path, 'w');
            if (! $csv_fp) {
                \WP_CLI::error("Could not open CSV path for writing: {$csv_path}");
                return;
            }
            fputcsv($csv_fp, [
                'upc',
                'ok',
                'offers_count',
                'cheapest_any_dist',
                'cheapest_in_stock_dist',
                'selected_dist',
                'duration_ms',
            ]);
        }

        $progress = null;
        if ($use_prog) {
            $progress = \WP_CLI\Utils\make_progress_bar('Lookups', $total);
        }

        $stats = [
            'ok' => 0,
            'fail' => 0,
            'offers_sum' => 0,
            'offers_max' => 0,
            'ms_sum' => 0.0,
            'ms_max' => 0.0,
        ];

        $fails = [];

        foreach ($upcs as $upc) {
            $t0 = microtime(true);

            $lookup = null;
            try {
                $lookup = DistributorProductHelper::get_upc_lookup_result_from_distributors(Plugin::instance()->distributor_handler, $upc, false);
            } catch (\Throwable $e) {
                $lookup = null;
            }

            $ms = (microtime(true) - $t0) * 1000.0;

            $ok = ($lookup instanceof UpcLookupResult);

            $offers_count = 0;
            $cheapest_any_dist = '';
            $cheapest_in_stock_dist = '';
            $selected_dist = '';

            if ($ok) {
                $offers = $lookup->offers();
                $offers_count = is_array($offers) ? count($offers) : 0;

                $ca = $lookup->cheapest_any();
                if ($ca instanceof DistributorOffer) {
                    $cheapest_any_dist = (string) $ca->distributor_id;
                    $selected_dist = $cheapest_any_dist; // selection policy currently "cheapest_any"
                }

                $cis = $lookup->cheapest_in_stock();
                if ($cis instanceof DistributorOffer) {
                    $cheapest_in_stock_dist = (string) $cis->distributor_id;
                }

                $stats['ok']++;
                $stats['offers_sum'] += $offers_count;
                $stats['offers_max'] = max($stats['offers_max'], $offers_count);
            } else {
                $stats['fail']++;
                $fails[] = $upc;
            }

            $stats['ms_sum'] += $ms;
            $stats['ms_max'] = max($stats['ms_max'], $ms);

            if ($csv_fp) {
                fputcsv($csv_fp, [
                    $upc,
                    $ok ? 1 : 0,
                    $offers_count,
                    $cheapest_any_dist,
                    $cheapest_in_stock_dist,
                    $selected_dist,
                    sprintf('%.2f', $ms),
                ]);
            }

            if ($progress) {
                $progress->tick();
            }
        }

        if ($progress) {
            $progress->finish();
        }
        if ($csv_fp) {
            fclose($csv_fp);
            \WP_CLI::log("Wrote CSV: {$csv_path}");
        }

        $elapsed_ms = (microtime(true) - $t_start) * 1000.0;
        $avg_ms = $total > 0 ? ($stats['ms_sum'] / $total) : 0.0;
        $avg_offers = $stats['ok'] > 0 ? ($stats['offers_sum'] / $stats['ok']) : 0.0;

        \WP_CLI::log("");
        \WP_CLI::success("Done.");
        \WP_CLI::log("Totals: ok={$stats['ok']} fail={$stats['fail']} total={$total}");
        \WP_CLI::log(sprintf("Timing: total=%.2f ms avg=%.2f ms max=%.2f ms", $elapsed_ms, $avg_ms, $stats['ms_max']));
        \WP_CLI::log(sprintf("Offers: avg=%.2f max=%d (ok only)", $avg_offers, $stats['offers_max']));

        if (! empty($fails)) {
            $preview = array_slice($fails, 0, 25);
            \WP_CLI::warning("First failed UPCs: " . implode(', ', $preview) . (count($fails) > 25 ? ' ...' : ''));
        }
    }

    /**
     * Finds the latest existing table whose name begins with a prefix like "..._v"
     * by checking v1..v200.
     */
    private function find_latest_existing_table(\wpdb $wpdb, string $prefix_with_v): string
    {
        // If you know your max v, keep this tighter.
        $latest = '';
        for ($v = 1; $v <= 250; $v++) {
            $name = $prefix_with_v . $v;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $name));
            if ($exists === $name) {
                $latest = $name;
            }
        }
        return $latest;
    }

    /**
     * Fetch distinct UPCs from a table column named "upc".
     */
    private function fetch_distinct_upcs(\wpdb $wpdb, string $table, int $min_len, int $max_len): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_col("
            SELECT DISTINCT upc
            FROM {$table}
            WHERE upc IS NOT NULL
              AND upc <> ''
        ");

        $out = [];
        foreach ($rows as $raw) {
            $upc = preg_replace('/\D+/', '', (string) $raw);
            if (! is_string($upc) || $upc === '') {
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
}
