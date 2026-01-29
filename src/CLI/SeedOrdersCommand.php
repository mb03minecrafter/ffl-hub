<?php

namespace FFLHub\CLI;
use WP_CLI\Utils;

if (!defined('ABSPATH')) {
    exit;
}

final class SeedOrdersCommand
{
    // ============================================================
    // Defaults (EDIT THESE ONCE)
    // ============================================================

    private const DEFAULT_RECEIVING_FFL_NUMBER = '5-72-033-07-8C-08115';

    private const DEFAULT_BILLING_SHIPPING = [
        'first_name' => 'Matthew',
        'last_name'  => 'Bickham',
        'company'    => 'FFLHub QA',
        'email'      => 'mattbick2003@gmail.com',
        'phone'      => '2256781533',
        'address_1'  => '9570 Oliphant Road',
        'address_2'  => '',
        'city'       => 'Baton Rouge',
        'state'      => 'LA',
        'postcode'   => '70809',
        'country'    => 'US',
    ];

    /**
     * Default final status to set LAST (commonly triggers placement pipeline).
     */
    private const DEFAULT_FINAL_STATUS = 'processing';

    /**
     * WARNING: If your pipeline uses fflhub_place_pipeline_started as a guard,
     * setting it here could cause your system to SKIP running.
     */
    private const DEFAULT_SET_PIPELINE_META = false;

    // Meta keys (from your screenshot)
    private const META_PIPELINE_STARTED    = 'fflhub_place_pipeline_started';
    private const META_PIPELINE_STARTED_AT = 'fflhub_place_pipeline_started_at';
    private const META_PIPELINE_STARTED_BY = 'fflhub_place_pipeline_started_by';

    private const META_RECEIVING_FFL       = 'fflhub_receiving_ffl_number';
    private const META_VAT_EXEMPT          = 'is_vat_exempt';

    /**
     * Usage:
     *  wp fflhub seed-orders --count=100 --max-lines=3
     *  wp fflhub seed-orders --count=25 --status=processing --ffl="5-72-033-07-8C-08115"
     *  wp fflhub seed-orders --count=10 --dry-run=1
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args)
    {
        if (!class_exists('\\WP_CLI')) {
            return;
        }
        if (!class_exists('\\WooCommerce')) {
            \WP_CLI::error('WooCommerce is not active.');
            return;
        }

        $count        = isset($assoc_args['count']) ? max(1, (int) $assoc_args['count']) : 10;
        $max_lines    = isset($assoc_args['max-lines']) ? max(1, (int) $assoc_args['max-lines']) : 3;
        $max_qty      = isset($assoc_args['max-qty']) ? max(1, (int) $assoc_args['max-qty']) : 2;
        $status       = isset($assoc_args['status']) ? sanitize_key((string) $assoc_args['status']) : self::DEFAULT_FINAL_STATUS;
        $ffl          = isset($assoc_args['ffl']) ? (string) $assoc_args['ffl'] : self::DEFAULT_RECEIVING_FFL_NUMBER;
        $product_pool = isset($assoc_args['product-pool']) ? max(5, (int) $assoc_args['product-pool']) : 50;
        $dry_run      = isset($assoc_args['dry-run']) ? ((int) $assoc_args['dry-run'] === 1) : false;

        $set_pipeline = isset($assoc_args['set-pipeline-meta'])
            ? ((int) $assoc_args['set-pipeline-meta'] === 1)
            : self::DEFAULT_SET_PIPELINE_META;

        // Allow overriding address fields via CLI args if desired (optional)
        $addr = self::DEFAULT_BILLING_SHIPPING;

        $addr['first_name'] = isset($assoc_args['first-name']) ? (string) $assoc_args['first-name'] : $addr['first_name'];
        $addr['last_name']  = isset($assoc_args['last-name']) ? (string) $assoc_args['last-name'] : $addr['last_name'];
        $addr['company']    = isset($assoc_args['company']) ? (string) $assoc_args['company'] : $addr['company'];
        $addr['email']      = isset($assoc_args['email']) ? (string) $assoc_args['email'] : $addr['email'];
        $addr['phone']      = isset($assoc_args['phone']) ? (string) $assoc_args['phone'] : $addr['phone'];
        $addr['address_1']  = isset($assoc_args['address1']) ? (string) $assoc_args['address1'] : $addr['address_1'];
        $addr['address_2']  = isset($assoc_args['address2']) ? (string) $assoc_args['address2'] : $addr['address_2'];
        $addr['city']       = isset($assoc_args['city']) ? (string) $assoc_args['city'] : $addr['city'];
        $addr['state']      = isset($assoc_args['state']) ? strtoupper(trim((string) $assoc_args['state'])) : $addr['state'];
        $addr['postcode']   = isset($assoc_args['postcode']) ? (string) $assoc_args['postcode'] : $addr['postcode'];
        $addr['country']    = isset($assoc_args['country']) ? strtoupper(trim((string) $assoc_args['country'])) : $addr['country'];

        if ($ffl === '') {
            \WP_CLI::error('FFL number cannot be empty. Provide --ffl=... or set DEFAULT_RECEIVING_FFL_NUMBER.');
            return;
        }
        if ($addr['email'] === '') {
            \WP_CLI::error('Billing email cannot be empty. Provide --email=... or set DEFAULT_BILLING_SHIPPING[email].');
            return;
        }

        $product_ids = $this->get_random_product_ids($product_pool);
        if (empty($product_ids)) {
            \WP_CLI::error('No purchasable products found.');
            return;
        }

        \WP_CLI::log(sprintf(
            'Seeding %d order(s) | status=%s | max_lines=%d | max_qty=%d | product_pool=%d | pipeline_meta=%s | dry_run=%s',
            $count,
            $status,
            $max_lines,
            $max_qty,
            $product_pool,
            $set_pipeline ? 'yes' : 'no',
            $dry_run ? 'yes' : 'no'
        ));

        $progress = Utils\make_progress_bar('Creating orders', $count);

        $created_ids = [];
        $failures = 0;

        for ($i = 1; $i <= $count; $i++) {
            $line_count = random_int(1, $max_lines);

            $picked = [];
            for ($j = 0; $j < $line_count; $j++) {
                $picked[] = (int) $product_ids[array_rand($product_ids)];
            }

            if ($dry_run) {
                // simulate work
                usleep(10_000);
                $progress->tick();
                continue;
            }

            try {
                $order = wc_create_order();
                if (!$order || !is_a($order, '\\WC_Order')) {
                    throw new \RuntimeException('wc_create_order() failed.');
                }

                // Fixed billing/shipping
                $order->set_address($addr, 'billing');
                $order->set_address($addr, 'shipping');

                // Add products
                foreach ($picked as $pid) {
                    $qty = random_int(1, $max_qty);
                    $this->add_random_product_to_order($order, $pid, $qty);
                }

                // Meta expected by your pipeline
                $order->update_meta_data(self::META_RECEIVING_FFL, $ffl);
                $order->update_meta_data(self::META_VAT_EXEMPT, 'no');

                // Optional screenshot pipeline meta
                if ($set_pipeline) {
                    $order->update_meta_data(self::META_PIPELINE_STARTED, '1');
                    $order->update_meta_data(self::META_PIPELINE_STARTED_AT, gmdate('c'));
                    $order->update_meta_data(self::META_PIPELINE_STARTED_BY, 'status_processing');
                }

                $order->calculate_totals();

                // Set status last (likely triggers your placement hooks)
                $order->update_status($status, 'FFLHub seed-orders (WP-CLI)', true);
                $order->save();

                $created_ids[] = $order->get_id();
            } catch (\Throwable $e) {
                $failures++;
                \WP_CLI::warning("Order {$i} failed: " . $e->getMessage());
            }

            $progress->tick();
        }

        $progress->finish();

        if ($dry_run) {
            \WP_CLI::success('Dry run complete (no orders created).');
            return;
        }

        if (!empty($created_ids)) {
            \WP_CLI::log('Created order IDs: ' . implode(', ', $created_ids));
        }

        if ($failures > 0) {
            \WP_CLI::warning("Failures: {$failures}");
        }

        \WP_CLI::success('Done.');
    }

    private function get_random_product_ids(int $limit): array
    {
        $q = new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'orderby'        => 'rand',
            'no_found_rows'  => true,
        ]);

        $out = [];
        foreach ((array) $q->posts as $id) {
            $id = (int) $id;
            $p  = wc_get_product($id);
            if (!$p || !$p->is_purchasable()) {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }

    private function add_random_product_to_order(\WC_Order $order, int $product_id, int $qty): void
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        if ($product->is_type('variable')) {
            $children = $product->get_children();
            if (!empty($children)) {
                $variation_id = (int) $children[array_rand($children)];
                $variation    = wc_get_product($variation_id);
                if ($variation && $variation->is_purchasable()) {
                    $order->add_product($variation, $qty);
                }
            }
            return;
        }

        $order->add_product($product, $qty);
    }
}