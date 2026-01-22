<?php
declare(strict_types=1);

namespace FFLHub\CLI;

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Distributor\Product\UpcLookupResult;
use FFLHub\Distributor\Product\DistributorOffer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Audit UPC lookup pipeline: ensure lookups return valid, non-null, usable payload data.
 *
 * This is a "big test" for refactors: it does NOT validate formatting,
 * it validates presence + basic sanity of returned offer/product payload.
 *
 * OPTIONS
 * [--source=<src>]
 * : rsr|lipseys|both (default: both)
 *
 * [--limit=<n>]
 * : Max UPCs tested (default: 0 = all)
 *
 * [--offset=<n>]
 * : Offset into UPC list (default: 0)
 *
 * [--min-upc-len=<n>]
 * : Minimum digits-only UPC length (default: 8)
 *
 * [--max-upc-len=<n>]
 * : Maximum digits-only UPC length (default: 14)
 *
 * [--require-offer]
 * : If set, treat "missing offer" as a failure (otherwise it's recorded but not failed).
 *
 * [--require-from-own-table]
 * : If set, for UPCs sourced from a distributor's table, missing offer for that same distributor is a failure.
 *   (Recommended)
 *
 * [--csv=<path>]
 * : Write results to CSV (recommended)
 *
 * [--progress]
 * : Show progress bar
 *
 * [--fail-fast]
 * : Stop on first failure
 *
 * [--include-images]
 * : Pass include_images=true into lookup pipeline (default: off for speed)
 *
 * EXAMPLES
 *   wp fflhub audit-upc-lookup --source=both --require-from-own-table --progress --csv="C:\temp\audit.csv"
 */
final class LookupAuditCommand
{
    public function __invoke(array $args, array $assoc_args): void
    {
        if (! class_exists('\WP_CLI')) {
            return;
        }

        global $wpdb;

        $t0 = microtime(true);

        $source   = isset($assoc_args['source']) ? strtolower((string) $assoc_args['source']) : 'both';
        $limit    = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
        $offset   = isset($assoc_args['offset']) ? (int) $assoc_args['offset'] : 0;

        $min_len  = isset($assoc_args['min-upc-len']) ? (int) $assoc_args['min-upc-len'] : 8;
        $max_len  = isset($assoc_args['max-upc-len']) ? (int) $assoc_args['max-upc-len'] : 14;

        $require_offer = isset($assoc_args['require-offer']);
        $require_from_own_table = isset($assoc_args['require-from-own-table']);
        $progress = isset($assoc_args['progress']);
        $fail_fast = isset($assoc_args['fail-fast']);
        $include_images = isset($assoc_args['include-images']);

        $csv_path = isset($assoc_args['csv']) ? (string) $assoc_args['csv'] : '';
        $csv_fh = null;

        if (! in_array($source, ['rsr', 'lipseys', 'both'], true)) {
            \WP_CLI::error("Invalid --source={$source}. Use rsr|lipseys|both.");
            return;
        }

        // Detect tables (v1..v200 scanning)
        $rsr_table     = $this->find_latest_existing_table($wpdb, $wpdb->prefix . 'fflhub_rsr_fulfillment_v');
        $lipseys_table = $this->find_latest_existing_table($wpdb, $wpdb->prefix . 'fflhub_lipseys_fulfillment_v');

        if (($source === 'rsr' || $source === 'both') && $rsr_table === '') {
            \WP_CLI::warning('RSR fulfillment table not found.');
        }
        if (($source === 'lipseys' || $source === 'both') && $lipseys_table === '') {
            \WP_CLI::warning("Lipsey's fulfillment table not found.");
        }

        // Build UPC set + provenance (which distributor table it came from)
        // provenance[$upc_digits] = ['rsr' => true, 'lipseys' => true]
        $provenance = [];
        $upcs = [];

        if (($source === 'rsr' || $source === 'both') && $rsr_table !== '') {
            foreach ($this->fetch_distinct_upcs($wpdb, $rsr_table, $min_len, $max_len) as $u) {
                $upcs[] = $u;
                $provenance[$u]['rsr'] = true;
            }
        }
        if (($source === 'lipseys' || $source === 'both') && $lipseys_table !== '') {
            foreach ($this->fetch_distinct_upcs($wpdb, $lipseys_table, $min_len, $max_len) as $u) {
                $upcs[] = $u;
                $provenance[$u]['lipseys'] = true;
            }
        }

        $upcs = array_values(array_unique($upcs));
        sort($upcs, SORT_STRING);

        if ($offset > 0) {
            $upcs = array_slice($upcs, $offset);
        }
        if ($limit > 0) {
            $upcs = array_slice($upcs, 0, $limit);
        }

        $total_upcs = count($upcs);
        if ($total_upcs <= 0) {
            \WP_CLI::warning('No UPCs to audit.');
            return;
        }

        \WP_CLI::log("Auditing {$total_upcs} UPC(s) (source={$source}) include_images=" . ($include_images ? '1' : '0'));

        if ($csv_path !== '') {
            $csv_fh = @fopen($csv_path, 'w');
            if (! is_resource($csv_fh)) {
                \WP_CLI::warning("Could not open CSV for writing: {$csv_path}");
                $csv_fh = null;
            } else {
                fputcsv($csv_fh, [
                    'upc',
                    'distributor',
                    'from_table',         // 1 if UPC came from this distributor's fulfillment table
                    'lookup_ok',          // 1 if offer exists and payload looks valid (for our rules)
                    'failure',            // 1 if we treated it as a failure
                    'reason',             // short reason
                    'missing_fields',      // pipe-delimited missing fields
                    'sku',
                    'name_len',
                    'desc_len',
                    'price',
                    'map',
                    'msrp',
                    'quantity',
                    'shipping_cost',
                    'true_cost',
                    'ffl_required',
                    'has_raw',
                ]);
            }
        }

        $bar = null;
        if ($progress && class_exists('\cli\progress\Bar')) {
            $bar = new \cli\progress\Bar('Progress', $total_upcs);
        }

        // Stats
        $checks_total = 0;
        $ok_total = 0;
        $fail_total = 0;
        $missing_offer_total = 0;
        $invalid_payload_total = 0;

        $fail_samples = []; // store first ~25 failures for console
        $fail_sample_cap = 25;

        $targets = $this->targets_for_source($source);

        foreach ($upcs as $upc) {
            $upc = $this->digits_only((string) $upc);
            if ($upc === '') {
                if ($bar) { $bar->tick(); }
                continue;
            }

            // Run actual lookup pipeline
            $res = DistributorProductHelper::get_upc_lookup_result_from_distributors($upc, (bool) $include_images);

            // If lookup itself fails, record "missing offer" for all targets
            if (! ($res instanceof UpcLookupResult)) {
                foreach ($targets as $dist_id) {
                    $checks_total++;

                    $from_table = isset($provenance[$upc][$dist_id]) ? 1 : 0;

                    $failure = 0;
                    $reason = 'lookup_result_null';

                    // Failure policy
                    if ($require_offer) {
                        $failure = 1;
                    }
                    if ($require_from_own_table && $from_table === 1) {
                        $failure = 1;
                    }

                    $missing_offer_total++;

                    $this->write_csv_row(
                        $csv_fh,
                        $upc,
                        $dist_id,
                        $from_table,
                        0,
                        $failure,
                        $reason,
                        'offer',
                        null
                    );

                    if ($failure) {
                        $fail_total++;
                        $this->maybe_sample_fail($fail_samples, $fail_sample_cap, "{$upc} {$dist_id}: {$reason}");
                        if ($fail_fast) {
                            if ($bar) { $bar->finish(); }
                            $this->close_csv($csv_fh);
                            \WP_CLI::error("Fail-fast: {$upc} {$dist_id}: {$reason}");
                            return;
                        }
                    } else {
                        $ok_total++;
                    }
                }

                if ($bar) { $bar->tick(); }
                continue;
            }

            $offers = $res->offers(); // array<string,DistributorOffer>

            foreach ($targets as $dist_id) {
                $checks_total++;

                $from_table = isset($provenance[$upc][$dist_id]) ? 1 : 0;

                /** @var mixed $offer */
                $offer = $offers[$dist_id] ?? null;

                if (! ($offer instanceof DistributorOffer)) {
                    $missing_offer_total++;

                    $failure = 0;
                    $reason = 'missing_offer';

                    if ($require_offer) {
                        $failure = 1;
                    }
                    if ($require_from_own_table && $from_table === 1) {
                        $failure = 1;
                    }

                    $this->write_csv_row(
                        $csv_fh,
                        $upc,
                        $dist_id,
                        $from_table,
                        0,
                        $failure,
                        $reason,
                        'offer',
                        null
                    );

                    if ($failure) {
                        $fail_total++;
                        $this->maybe_sample_fail($fail_samples, $fail_sample_cap, "{$upc} {$dist_id}: {$reason}");
                        if ($fail_fast) {
                            if ($bar) { $bar->finish(); }
                            $this->close_csv($csv_fh);
                            \WP_CLI::error("Fail-fast: {$upc} {$dist_id}: {$reason}");
                            return;
                        }
                    } else {
                        $ok_total++;
                    }

                    continue;
                }

                // Validate payload fields (non-null usability)
                $payload = $offer->product ?? null;

                $missing = [];
                $ok = true;
                $reason = 'OK';

                if (! is_object($payload)) {
                    $ok = false;
                    $missing[] = 'product';
                    $reason = 'payload_null';
                } else {
                    // Required-ish fields for "usable offer"
                    $p_upc = $this->digits_only((string) ($payload->upc ?? ''));
                    if ($p_upc === '') { $ok = false; $missing[] = 'upc'; }

                    // SKU may be legitimately empty for some dist, but track it
                    $p_sku = trim((string) ($payload->sku ?? ''));

                    // Name/description can be empty if dist gives garbage, but your app expects at least a name
                    $p_name = trim((string) ($payload->name ?? ''));
                    if ($p_name === '') { $ok = false; $missing[] = 'name'; }

                    // Price should be set (0.0 is allowed but track as suspicious)
                    if (! isset($payload->price)) { $ok = false; $missing[] = 'price'; }

                    // Quantity default should be 0 (must exist)
                    if (! isset($payload->quantity)) { $ok = false; $missing[] = 'quantity'; }

                    // Shipping/true_cost should exist (even 0)
                    if (! isset($payload->shipping_cost)) { $ok = false; $missing[] = 'shipping_cost'; }
                    if (! isset($payload->true_cost)) { $ok = false; $missing[] = 'true_cost'; }

                    // Raw should be present (array or object) for debugging
                    if (! isset($payload->raw) || $payload->raw === null) { $missing[] = 'raw'; /* not fatal */ }

                    // ffl_required should exist (bool-ish)
                    if (! isset($payload->ffl_required)) { $missing[] = 'ffl_required'; /* not fatal */ }

                    if (! $ok) {
                        $reason = 'invalid_payload';
                    }

                    // If you want “raw is required” to be fatal, uncomment:
                    // if (in_array('raw', $missing, true)) { $ok = false; $reason = 'raw_missing'; }
                }

                $failure = 0;
                if (! $ok) {
                    $invalid_payload_total++;

                    // invalid payload is always a failure (this is the whole point)
                    $failure = 1;
                } else {
                    // valid payload
                    $ok_total++;
                }

                if ($failure) {
                    $fail_total++;
                    $this->maybe_sample_fail($fail_samples, $fail_sample_cap, "{$upc} {$dist_id}: {$reason} (" . implode('|', $missing) . ")");
                    if ($fail_fast) {
                        if ($bar) { $bar->finish(); }
                        $this->close_csv($csv_fh);
                        \WP_CLI::error("Fail-fast: {$upc} {$dist_id}: {$reason}");
                        return;
                    }
                }

                $this->write_csv_row(
                    $csv_fh,
                    $upc,
                    $dist_id,
                    $from_table,
                    $ok ? 1 : 0,
                    $failure,
                    $reason,
                    implode('|', $missing),
                    $offer
                );
            }

            if ($bar) { $bar->tick(); }
        }

        if ($bar) { $bar->finish(); }
        $this->close_csv($csv_fh);

        $elapsed_ms = (microtime(true) - $t0) * 1000.0;

        \WP_CLI::log("Audit complete.");
        \WP_CLI::log("Checks: total={$checks_total} ok={$ok_total} fail={$fail_total}");
        \WP_CLI::log("Missing offers: {$missing_offer_total}");
        \WP_CLI::log("Invalid payloads: {$invalid_payload_total}");
        \WP_CLI::log(sprintf("Timing: total=%.2f ms avg=%.2f ms/check", $elapsed_ms, $elapsed_ms / max(1, $checks_total)));

        if (! empty($fail_samples)) {
            \WP_CLI::warning("Sample failures:");
            foreach ($fail_samples as $line) {
                \WP_CLI::log(" - " . $line);
            }
        }

        if ($csv_path !== '' && is_string($csv_path)) {
            \WP_CLI::log("CSV: {$csv_path}");
        }

        if ($fail_total > 0) {
            \WP_CLI::warning("Audit found failures. See CSV for details.");
        } else {
            \WP_CLI::success("Audit passed: all checked offers had valid payload data.");
        }
    }

    /** @return string[] */
    private function targets_for_source(string $source): array
    {
        if ($source === 'rsr') { return ['rsr']; }
        if ($source === 'lipseys') { return ['lipseys']; }
        return ['rsr', 'lipseys'];
    }

    private function write_csv_row(
        $csv_fh,
        string $upc,
        string $dist,
        int $from_table,
        int $lookup_ok,
        int $failure,
        string $reason,
        string $missing_fields,
        ?DistributorOffer $offer
    ): void {
        if (! is_resource($csv_fh)) {
            return;
        }

        $sku = '';
        $name_len = '';
        $desc_len = '';
        $price = '';
        $map = '';
        $msrp = '';
        $qty = '';
        $ship = '';
        $true = '';
        $ffl = '';
        $has_raw = '';

        if ($offer instanceof DistributorOffer && is_object($offer->product ?? null)) {
            $p = $offer->product;

            $sku = (string) ($p->sku ?? '');
            $name_len = (string) strlen((string) ($p->name ?? ''));
            $desc_len = (string) strlen((string) ($p->description ?? ''));

            $price = (string) ($p->price ?? '');
            $map = (string) ($p->map ?? '');
            $msrp = (string) ($p->msrp ?? '');
            $qty = (string) ($p->quantity ?? '');
            $ship = (string) ($p->shipping_cost ?? '');
            $true = (string) ($p->true_cost ?? '');
            $ffl = isset($p->ffl_required) ? ((bool) $p->ffl_required ? '1' : '0') : '';
            $has_raw = (isset($p->raw) && $p->raw !== null) ? '1' : '0';
        }

        fputcsv($csv_fh, [
            $upc,
            $dist,
            $from_table,
            $lookup_ok,
            $failure,
            $reason,
            $missing_fields,
            $sku,
            $name_len,
            $desc_len,
            $price,
            $map,
            $msrp,
            $qty,
            $ship,
            $true,
            $ffl,
            $has_raw,
        ]);
    }

    private function maybe_sample_fail(array &$samples, int $cap, string $msg): void
    {
        if (count($samples) >= $cap) {
            return;
        }
        $samples[] = $msg;
    }

    private function close_csv($csv_fh): void
    {
        if (is_resource($csv_fh)) {
            @fclose($csv_fh);
        }
    }

    private function digits_only(string $s): string
    {
        $d = preg_replace('/\D+/', '', (string) $s);
        return is_string($d) ? $d : '';
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

    /**
     * Fetch distinct UPCs from a table, normalized to digits-only and filtered by length.
     *
     * @return string[]
     */
    private function fetch_distinct_upcs(\wpdb $wpdb, string $table, int $min_len, int $max_len): array
    {
        $out = [];

        $rows = $wpdb->get_col("SELECT DISTINCT upc FROM {$table} WHERE upc IS NOT NULL AND upc != ''");
        if (! is_array($rows)) {
            return [];
        }

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
