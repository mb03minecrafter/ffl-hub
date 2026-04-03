<?php

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Distributor\Product\DistributorProductHelper;
use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\Product\ProductMeta;
use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
use FFLHub\Util\DebugLogUtil;
use FFLHub\Woo\Emails\FFLHubQuoteOffer;
use FFLHub\Woo\Emails\Models\QuoteOfferEmailContext;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Polls due quote-email jobs every minute and iterates each due row.
 */
final class QuoteEmailJobsCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_quote_email_jobs_poll';

    private const BATCH_LIMIT = 100;
    private const DEBUG_CONST = 'FFLHUB_DEBUG_QUOTE_EMAIL_CRON';
    private const LOG_PREFIX = '[FFLHub][QuoteEmailCron]';
    private const BUSINESS_HOURS_TZ = 'America/Chicago';
    private const BUSINESS_HOUR_START = 7;  // 7:00 local
    private const BUSINESS_HOUR_END = 18;   // 18:00 local (end-exclusive)
    private const REP_NAMES = [
        'Matthew Bickham',
        'Thomas Bickham',
        'Michelle Bickham',
        'Rebecca Kent',
    ];

    private QuoteEmailJobsTable $jobs_table;

    public function __construct()
    {
        $schema = new QuoteEmailJobsSchema();
        $this->jobs_table = new QuoteEmailJobsTable($schema);
    }

    protected function get_interval_seconds(): int
    {
        return 60;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 60;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_quote_email';
    }

    public function run(): void
    {
        global $wpdb;

        $run_started = microtime(true);
        $table_name = $this->jobs_table->get_table_name();
        $now_utc = (string) current_time('mysql', true);
        $limit = max(1, (int) self::BATCH_LIMIT);
        $force_no_delay = self::force_no_delay_mode();
        $hours_ctx = self::business_hours_context();

        self::debug_ctx('run start', [
            'table' => $table_name,
            'now_utc' => $now_utc,
            'limit' => $limit,
            'force_no_delay' => $force_no_delay ? 1 : 0,
            'business_hours_open' => !empty($hours_ctx['is_open']) ? 1 : 0,
            'business_hours_now_local' => (string) ($hours_ctx['now_local'] ?? ''),
            'business_hours_tz' => self::BUSINESS_HOURS_TZ,
        ]);

        if (!$force_no_delay && empty($hours_ctx['is_open'])) {
            self::debug_ctx('run skipped: outside business hours', [
                'now_local' => (string) ($hours_ctx['now_local'] ?? ''),
                'hour_local' => (int) ($hours_ctx['hour_local'] ?? -1),
                'start_hour' => self::BUSINESS_HOUR_START,
                'end_hour' => self::BUSINESS_HOUR_END,
                'tz' => self::BUSINESS_HOURS_TZ,
            ]);
            return;
        }

        if ($force_no_delay) {
            $sql = $wpdb->prepare(
                "SELECT id, request_first_name, request_last_name, request_email, quote_upc, quote_product_name, submitted_at, random_delay_minutes, email_sent
                 FROM {$table_name}
                 WHERE email_sent = 0
                 ORDER BY submitted_at ASC, id ASC
                 LIMIT %d",
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id, request_first_name, request_last_name, request_email, quote_upc, quote_product_name, submitted_at, random_delay_minutes, email_sent
                 FROM {$table_name}
                 WHERE email_sent = 0
                   AND DATE_ADD(submitted_at, INTERVAL random_delay_minutes MINUTE) <= %s
                 ORDER BY submitted_at ASC, id ASC
                 LIMIT %d",
                $now_utc,
                $limit
            );
        }

        $due_jobs = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($due_jobs)) {
            self::debug_ctx('query failed', [
                'last_error' => (string) $wpdb->last_error,
            ]);
            return;
        }

        if (empty($due_jobs)) {
            self::debug('run complete: no due jobs');
            return;
        }

        $rows_seen = 0;
        $rows_sent = 0;
        $rows_skipped = 0;
        $status_counts = [];

        foreach ($due_jobs as $job_row) {
            $rows_seen++;

            if (!is_array($job_row)) {
                $rows_skipped++;
                $status_counts['invalid_row'] = (int) ($status_counts['invalid_row'] ?? 0) + 1;
                self::debug_ctx('skip row: invalid row type', ['row_index' => $rows_seen]);
                continue;
            }

            $status = $this->handle_due_job($job_row);
            $status_counts[$status] = (int) ($status_counts[$status] ?? 0) + 1;

            if ($status === 'sent') {
                $rows_sent++;
            } else {
                $rows_skipped++;
            }
        }

        self::debug_ctx('run complete', [
            'rows_seen' => $rows_seen,
            'rows_sent' => $rows_sent,
            'rows_skipped' => $rows_skipped,
            'status_counts' => $status_counts,
            'elapsed_ms' => round((microtime(true) - $run_started) * 1000, 2),
        ]);
    }

    /**
     * @param array<string,mixed> $job_row
     */
    private function handle_due_job(array $job_row): string
    {
        /**
         * Keep this action for extension points and custom instrumentation.
         */
        do_action('fflhub_quote_email_job_due', $job_row);

        $job_id = isset($job_row['id']) ? (int) $job_row['id'] : 0;
        $recipient = sanitize_email((string) ($job_row['request_email'] ?? ''));
        $quote_upc = trim((string) ($job_row['quote_upc'] ?? ''));
        $quote_product_name = trim((string) ($job_row['quote_product_name'] ?? ''));

        self::debug_ctx('processing job', [
            'job_id' => $job_id,
            'recipient' => $recipient,
            'quote_upc' => $quote_upc,
            'quote_product_name' => $quote_product_name,
        ]);

        if ($job_id <= 0 || $recipient === '' || !is_email($recipient)) {
            self::debug_ctx('skip job: invalid recipient/job id', [
                'job_id' => $job_id,
                'recipient' => $recipient,
            ]);
            return 'skip_invalid_recipient_or_job_id';
        }

        $product = $this->resolve_product_from_job_row($job_row);
        if (!($product instanceof WC_Product)) {
            self::debug_ctx('skip job: product not resolved', [
                'job_id' => $job_id,
                'quote_upc' => $quote_upc,
                'quote_product_name' => $quote_product_name,
            ]);
            return 'skip_product_not_found';
        }

        $pricing = $this->quote_coupon_pricing_for_product($product);
        $coupon_amount = (float) ($pricing['difference'] ?? 0.0);
        if ($coupon_amount <= 0.0) {
            self::debug_ctx('skip job: coupon amount not positive', [
                'job_id' => $job_id,
                'product_id' => (int) $product->get_id(),
                'product_name' => (string) $product->get_name(),
                'recommended_price' => (float) ($pricing['recommended'] ?? 0.0),
                'listed_sale_price' => (float) ($pricing['listed_sale_price'] ?? 0.0),
                'listed_source' => (string) ($pricing['listed_source'] ?? 'unknown'),
                'difference' => (float) ($pricing['difference'] ?? 0.0),
                'markup_mode' => (int) $product->get_meta(ProductMeta::FFLHUB_MARKUP_MODE_META, true),
            ]);
            return 'skip_coupon_amount_not_positive';
        }

        $coupon_payload = $this->create_or_refresh_quote_coupon($job_row, $product, $coupon_amount);
        if (!is_array($coupon_payload) || empty($coupon_payload['code'])) {
            self::debug_ctx('skip job: coupon creation failed', [
                'job_id' => $job_id,
                'product_id' => (int) $product->get_id(),
                'coupon_amount' => $coupon_amount,
            ]);
            return 'skip_coupon_creation_failed';
        }

        $context = $this->build_quote_email_context($job_row, $product, $coupon_payload);
        if (!($context instanceof QuoteOfferEmailContext)) {
            self::debug_ctx('skip job: context build failed', [
                'job_id' => $job_id,
                'product_id' => (int) $product->get_id(),
            ]);
            return 'skip_context_build_failed';
        }

        $sent = $this->dispatch_quote_offer_email($context);
        if (!$sent) {
            self::debug_ctx('send failed', [
                'job_id' => $job_id,
                'recipient' => $context->recipient_email,
                'subject' => $context->subject,
                'variant_index' => $context->variant_index,
            ]);
            return 'send_failed';
        }

        $marked = $this->mark_job_email_sent($job_id);
        if (!$marked) {
            self::debug_ctx('send succeeded but mark sent failed', [
                'job_id' => $job_id,
            ]);
            return 'sent_but_mark_failed';
        }

        self::debug_ctx('send succeeded', [
            'job_id' => $job_id,
            'recipient' => $context->recipient_email,
            'coupon_code' => $context->coupon_code,
        ]);

        return 'sent';
    }

    private function resolve_product_from_job_row(array $job_row): ?WC_Product
    {
        $upc = trim((string) ($job_row['quote_upc'] ?? ''));
        $product_name = trim((string) ($job_row['quote_product_name'] ?? ''));

        $product_id = 0;
        if ($upc !== '') {
            $product_id = $this->find_product_id_by_upc($upc);
        }
        if ($product_id <= 0 && $product_name !== '') {
            $product_id = $this->find_product_id_by_exact_name($product_name);
        }
        if ($product_id <= 0) {
            return null;
        }

        $product = wc_get_product($product_id);
        self::debug_ctx('resolved product', [
            'product_id' => $product_id,
            'upc' => $upc,
            'product_name' => $product_name,
        ]);
        return ($product instanceof WC_Product) ? $product : null;
    }

    private function find_product_id_by_upc(string $upc): int
    {
        global $wpdb;

        $upc = trim($upc);
        if ($upc === '') {
            return 0;
        }

        $meta_keys = [
            ProductMeta::FFLHUB_UPC_META,
            '_upc',
            'upc',
        ];

        foreach ($meta_keys as $meta_key) {
            $sql = $wpdb->prepare(
                "SELECT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND pm.meta_value = %s
                   AND p.post_type = 'product'
                   AND p.post_status IN ('publish', 'private')
                 ORDER BY pm.post_id DESC
                 LIMIT 1",
                $meta_key,
                $upc
            );

            $found_id = (int) $wpdb->get_var($sql);
            if ($found_id > 0) {
                return $found_id;
            }
        }

        return 0;
    }

    private function find_product_id_by_exact_name(string $product_name): int
    {
        global $wpdb;

        $product_name = trim($product_name);
        if ($product_name === '') {
            return 0;
        }

        $sql = $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ('publish', 'private')
               AND post_title = %s
             ORDER BY ID DESC
             LIMIT 1",
            $product_name
        );

        return (int) $wpdb->get_var($sql);
    }

    private function compute_coupon_amount_for_product(WC_Product $product): float
    {
        $pricing = $this->quote_coupon_pricing_for_product($product);
        $difference = (float) ($pricing['difference'] ?? 0.0);
        return ($difference > 0.0) ? $difference : 0.0;
    }

    /**
     * @return array{recommended:float,listed_sale_price:float,listed_source:string,difference:float}
     */
    private function quote_coupon_pricing_for_product(WC_Product $product): array
    {
        $recommended = $this->recommended_price_for_product($product);
        $listed_details = $this->listed_sale_price_details_for_product($product);
        $listed_sale_price = (float) ($listed_details['listed_sale_price'] ?? 0.0);
        $listed_source = (string) ($listed_details['source'] ?? 'unknown');
        if ($recommended <= 0.0 || $listed_sale_price <= 0.0) {
            return [
                'recommended' => $recommended,
                'listed_sale_price' => $listed_sale_price,
                'listed_source' => $listed_source,
                'difference' => 0.0,
            ];
        }

        $difference = round($listed_sale_price - $recommended, 2);
        return [
            'recommended' => $recommended,
            'listed_sale_price' => $listed_sale_price,
            'listed_source' => $listed_source,
            'difference' => $difference,
        ];
    }

    /**
     * @param array<string,mixed> $job_row
     * @return array<string,mixed>|null
     */
    private function create_or_refresh_quote_coupon(array $job_row, WC_Product $product, float $coupon_amount): ?array
    {
        $first_name = (string) ($job_row['request_first_name'] ?? '');
        $last_name = (string) ($job_row['request_last_name'] ?? '');
        $email = sanitize_email((string) ($job_row['request_email'] ?? ''));
        if ($email === '' || !is_email($email)) {
            return null;
        }

        $coupon_code = $this->build_coupon_code($first_name, $last_name, $email);
        if ($coupon_code === '') {
            return null;
        }

        $existing_id = function_exists('wc_get_coupon_id_by_code') ? (int) wc_get_coupon_id_by_code($coupon_code) : 0;
        $coupon = ($existing_id > 0) ? new \WC_Coupon($existing_id) : new \WC_Coupon();

        $expires_ts = (int) current_time('timestamp', true) + (48 * HOUR_IN_SECONDS);
        $product_name = (string) $product->get_name();

        self::debug_ctx('creating/updating coupon', [
            'product_id' => (int) $product->get_id(),
            'product_name' => $product_name,
            'coupon_code' => $coupon_code,
            'existing_id' => $existing_id,
            'coupon_amount' => $coupon_amount,
            'recipient_email' => $email,
            'expires_ts' => $expires_ts,
        ]);

        $coupon->set_code($coupon_code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount($coupon_amount);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions([$email]);
        $coupon->set_date_expires($expires_ts);
        $coupon->set_description(
            sprintf(
                'Quote coupon for %s (%s)',
                $product_name,
                gmdate('Y-m-d H:i:s')
            )
        );

        $product_id = (int) $product->get_id();
        if ($product_id > 0) {
            $coupon->set_product_ids([$product_id]);
        }

        if (method_exists($coupon, 'set_usage_count')) {
            $coupon->set_usage_count(0);
        } elseif ($existing_id > 0) {
            update_post_meta($existing_id, 'usage_count', 0);
        }

        try {
            $coupon_id = (int) $coupon->save();
        } catch (\Throwable $e) {
            self::debug_ctx('coupon save exception', [
                'coupon_code' => $coupon_code,
                'exception_class' => get_class($e),
                'exception_message' => (string) $e->getMessage(),
            ]);
            return null;
        }

        if ($coupon_id <= 0) {
            self::debug_ctx('coupon save failed: invalid id', [
                'coupon_code' => $coupon_code,
                'coupon_id' => $coupon_id,
            ]);
            return null;
        }

        self::debug_ctx('coupon ready', [
            'coupon_code' => (string) $coupon->get_code(),
            'coupon_id' => $coupon_id,
        ]);

        return [
            'code' => (string) $coupon->get_code(),
            'amount' => (float) $coupon_amount,
            'expires_ts' => $expires_ts,
        ];
    }

    private function build_coupon_code(string $first_name, string $last_name, string $email): string
    {
        $seed = strtolower(trim($first_name . $last_name));
        $seed = preg_replace('/[^a-z0-9]+/', '', $seed);
        if (!is_string($seed) || $seed === '') {
            $email_local = strtolower((string) strstr($email, '@', true));
            $seed = preg_replace('/[^a-z0-9]+/', '', $email_local);
        }
        if (!is_string($seed) || $seed === '') {
            return '';
        }

        return $seed;
    }

    /**
     * @param array<string,mixed> $job_row
     * @param array<string,mixed> $coupon_payload
     */
    private function build_quote_email_context(array $job_row, WC_Product $product, array $coupon_payload): ?QuoteOfferEmailContext
    {
        $recipient = sanitize_email((string) ($job_row['request_email'] ?? ''));
        if ($recipient === '' || !is_email($recipient)) {
            return null;
        }

        $job_id = isset($job_row['id']) ? (int) $job_row['id'] : 0;
        $variant_index = ($job_id > 0) ? ($job_id % 6) : 0;
        $rep_index = ($job_id > 0) ? ($job_id % count(self::REP_NAMES)) : 0;

        $first_name = trim((string) ($job_row['request_first_name'] ?? ''));
        $product_name = (string) $product->get_name();
        $product_url = get_permalink((int) $product->get_id());
        if (!is_string($product_url) || $product_url === '') {
            $product_url = home_url('/');
        }

        $coupon_code = (string) ($coupon_payload['code'] ?? '');
        if ($coupon_code === '') {
            return null;
        }

        $coupon_amount = (float) ($coupon_payload['amount'] ?? 0.0);
        $expires_ts = (int) ($coupon_payload['expires_ts'] ?? 0);
        $expires_display = ($expires_ts > 0)
            ? wp_date('F j, Y g:i A T', $expires_ts)
            : __('48 hours from now', 'ffl-hub');
        $final_price_amount = $this->final_price_amount_for_product($product, $coupon_amount);
        $final_price_display = $this->final_price_display_for_amount($final_price_amount);
        $shipping_phrase = $this->shipping_phrase_for_quote_product($product, $final_price_amount);

        $subject = sprintf(__('Quote for %s', 'ffl-hub'), $product_name);
        $rep_name = self::REP_NAMES[$rep_index] ?? self::REP_NAMES[0];
        $coupon_amount_display = wp_strip_all_tags(wc_price($coupon_amount));

        self::debug_ctx('email context built', [
            'job_id' => isset($job_row['id']) ? (int) $job_row['id'] : 0,
            'recipient' => $recipient,
            'subject' => $subject,
            'variant_index' => $variant_index,
            'rep_name' => $rep_name,
            'coupon_code' => $coupon_code,
            'final_price' => $final_price_display,
            'shipping_phrase' => $shipping_phrase,
        ]);

        return new QuoteOfferEmailContext(
            $recipient,
            $subject,
            $variant_index,
            $first_name,
            $rep_name,
            $product_name,
            $product_url,
            $coupon_code,
            $coupon_amount_display,
            $final_price_display,
            $shipping_phrase,
            $expires_display
        );
    }

    private function recommended_price_for_product(WC_Product $product): float
    {
        $map_real_price = DistributorProductHelper::get_map_real_price_for_product($product);
        if (is_numeric($map_real_price) && (float) $map_real_price > 0.0) {
            return (float) $map_real_price;
        }

        $recommended = (float) $product->get_meta(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, true);
        if ($recommended <= 0.0) {
            $recommended = (float) $product->get_regular_price();
        }
        if ($recommended <= 0.0) {
            $recommended = (float) $product->get_price();
        }

        return ($recommended > 0.0) ? $recommended : 0.0;
    }

    private function listed_sale_price_for_product(WC_Product $product): float
    {
        $details = $this->listed_sale_price_details_for_product($product);
        return (float) ($details['listed_sale_price'] ?? 0.0);
    }

    /**
     * @return array{listed_sale_price:float,source:string}
     */
    private function listed_sale_price_details_for_product(WC_Product $product): array
    {
        $sale_price = (float) $product->get_sale_price();
        if ($sale_price > 0.0) {
            return [
                'listed_sale_price' => $sale_price,
                'source' => 'sale_price',
            ];
        }

        $active_price = (float) $product->get_price();
        if ($active_price > 0.0) {
            return [
                'listed_sale_price' => $active_price,
                'source' => 'active_price',
            ];
        }

        $regular_price = (float) $product->get_regular_price();
        if ($regular_price > 0.0) {
            return [
                'listed_sale_price' => $regular_price,
                'source' => 'regular_price',
            ];
        }

        return [
            'listed_sale_price' => 0.0,
            'source' => 'missing',
        ];
    }

    private function final_price_amount_for_product(WC_Product $product, float $coupon_amount): float
    {
        $final_price = $this->recommended_price_for_product($product);
        if ($final_price <= 0.0) {
            $listed_sale_price = $this->listed_sale_price_for_product($product);
            if ($listed_sale_price > 0.0 && $coupon_amount > 0.0) {
                $derived = round($listed_sale_price - $coupon_amount, 2);
                if ($derived > 0.0) {
                    $final_price = $derived;
                }
            }
        }

        return ($final_price > 0.0) ? $final_price : 0.0;
    }

    private function final_price_display_for_amount(float $final_price): string
    {
        if ($final_price <= 0.0) {
            return __('See checkout for final product price', 'ffl-hub');
        }

        return wp_strip_all_tags(wc_price($final_price));
    }

    private function shipping_phrase_for_quote_product(WC_Product $product, float $line_revenue): string
    {
        $is_free_shipping = $this->is_free_shipping_for_quote_product($product, $line_revenue);

        return $is_free_shipping
            ? __('with free shipping', 'ffl-hub')
            : __('+ shipping', 'ffl-hub');
    }

    private function is_free_shipping_for_quote_product(WC_Product $product, float $line_revenue): bool
    {
        $shipping_cost_total = $this->estimate_shipping_cost_total_for_quote_product($product);
        if ($shipping_cost_total <= 0.0) {
            return true;
        }

        $fee_percent = (float) get_option('fflhub_payment_processor_fee_percent', '2.9');
        $f = $fee_percent / 100.0;
        if ($f < 0.0) {
            $f = 0.0;
        }
        if ($f >= 0.99) {
            $f = 0.99;
        }

        $true_cost = $this->to_non_negative_float(
            $product->get_meta(ProductMeta::FFLHUB_LAST_TRUE_COST_META, true),
            0.0
        );
        $profit_net_total = ($line_revenue - $true_cost) * (1.0 - $f);

        $customer_charge = 0.0;
        $free_threshold = 0.5 * (float) $profit_net_total;
        if ($profit_net_total > 0.0 && $shipping_cost_total < $free_threshold) {
            $customer_charge = 0.0;
        } else {
            $customer_charge = ($f >= 0.99)
                ? $shipping_cost_total
                : ($shipping_cost_total / (1.0 - $f));
        }

        $shipping_settings = $this->shipping_method_settings_snapshot();
        $min_cart_ship = (float) ($shipping_settings['min_shipping'] ?? 0.0);
        $max_cart_ship = (float) ($shipping_settings['max_shipping'] ?? 0.0);

        $customer_charge = max($min_cart_ship, $customer_charge);
        if ($max_cart_ship > 0.0) {
            $customer_charge = min($max_cart_ship, $customer_charge);
        }

        return $customer_charge <= 0.0001;
    }

    private function estimate_shipping_cost_total_for_quote_product(WC_Product $product): float
    {
        $shipping_settings = $this->shipping_method_settings_snapshot();
        $fallback_ship = (float) ($shipping_settings['fallback_shipping'] ?? 15.0);

        $dist_id = strtolower(trim((string) $product->get_meta(ProductMeta::FFLHUB_SOURCE_DISTRIBUTOR_META, true)));
        if ($dist_id === '') {
            return max(0.0, $fallback_ship);
        }

        $ffl_required_raw = $product->get_meta(ProductMeta::FFLHUB_FFL_REQUIRED_META, true);
        $ffl_required = !empty($ffl_required_raw) && (string) $ffl_required_raw !== '0';
        $dropship_enabled = $this->to_boolish(
            $product->get_meta(ProductMeta::FFLHUB_DROPSHIP_ENABLED_META, true),
            true
        );

        $ship_raw = $product->get_meta(ProductMeta::FFLHUB_LAST_SHIPPING_COST_META, true);
        $dist_lane_fee = $this->to_non_negative_float($ship_raw, $fallback_ship);
        $weight_oz = $this->to_non_negative_float(
            $product->get_meta(ProductMeta::FFLHUB_SHIPPING_WEIGHT_META, true),
            0.0
        );

        $plan = DealerFulfillmentRoutingPlanner::find_cheapest_plan([
            [
                'line_id' => 'quote_line',
                'dist_id' => $dist_id,
                'qty' => 1,
                'weight_oz' => $weight_oz,
                'ffl_required' => $ffl_required ? 1 : 0,
                'dropship_enabled' => $dropship_enabled ? 1 : 0,
                'dist_lane_fee' => $dist_lane_fee,
            ],
        ]);

        return max(0.0, (float) ($plan['total_cost'] ?? 0.0));
    }

    /**
     * @return array{fallback_shipping:float,min_shipping:float,max_shipping:float}
     */
    private function shipping_method_settings_snapshot(): array
    {
        static $snapshot = null;
        if (is_array($snapshot)) {
            /** @var array{fallback_shipping:float,min_shipping:float,max_shipping:float} $snapshot */
            return $snapshot;
        }

        $defaults = [
            'fallback_shipping' => 15.0,
            'min_shipping' => 0.0,
            'max_shipping' => 0.0,
        ];

        $settings = null;
        global $wpdb;

        if (isset($wpdb) && $wpdb) {
            $like = $wpdb->esc_like('woocommerce_fflhub_shipping_') . '%_settings';
            $option_names = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name
                     FROM {$wpdb->options}
                     WHERE option_name LIKE %s
                     ORDER BY option_name ASC",
                    $like
                )
            );

            if (is_array($option_names)) {
                foreach ($option_names as $option_name) {
                    if (!is_string($option_name) || $option_name === '') {
                        continue;
                    }
                    $value = get_option($option_name, null);
                    if (is_array($value)) {
                        $settings = $value;
                        break;
                    }
                }
            }
        }

        if (!is_array($settings)) {
            $legacy = get_option('woocommerce_fflhub_shipping_settings', null);
            if (is_array($legacy)) {
                $settings = $legacy;
            }
        }

        if (!is_array($settings)) {
            $snapshot = $defaults;
            return $snapshot;
        }

        $snapshot = [
            'fallback_shipping' => $this->to_non_negative_float($settings['fallback_shipping'] ?? null, $defaults['fallback_shipping']),
            'min_shipping' => $this->to_non_negative_float($settings['min_shipping'] ?? null, $defaults['min_shipping']),
            'max_shipping' => $this->to_non_negative_float($settings['max_shipping'] ?? null, $defaults['max_shipping']),
        ];

        return $snapshot;
    }

    /**
     * @param mixed $value
     */
    private function to_boolish($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $default;
        }

        if (in_array($raw, ['1', 'true', 't', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }

        if (is_numeric($raw)) {
            return ((float) $raw) !== 0.0;
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    private function to_non_negative_float($value, float $default = 0.0): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return max(0.0, $default);
        }

        $num = $raw;
        if (!is_numeric($num)) {
            $num = trim((string) preg_replace('/[^0-9\.\-]/', '', $raw));
        }

        if ($num === '' || !is_numeric($num)) {
            return max(0.0, $default);
        }

        $v = (float) $num;
        if (!is_finite($v) || $v < 0.0) {
            return max(0.0, $default);
        }

        return $v;
    }

    private function dispatch_quote_offer_email(QuoteOfferEmailContext $context): bool
    {
        if (!function_exists('WC')) {
            self::debug('dispatch skipped: WC() unavailable');
            return false;
        }

        $woo = WC();
        if (!$woo || !method_exists($woo, 'mailer')) {
            self::debug('dispatch skipped: mailer unavailable');
            return false;
        }

        $mailer = $woo->mailer();
        if (!$mailer || !method_exists($mailer, 'get_emails')) {
            self::debug('dispatch skipped: get_emails unavailable');
            return false;
        }

        $emails = $mailer->get_emails();
        $email = $emails[FFLHubQuoteOffer::class] ?? null;
        if (!($email instanceof FFLHubQuoteOffer)) {
            $email = new FFLHubQuoteOffer();
        }

        $sent = $email->trigger($context);
        self::debug_ctx('dispatch attempted', [
            'recipient' => $context->recipient_email,
            'subject' => $context->subject,
            'variant_index' => $context->variant_index,
            'sent' => $sent ? 1 : 0,
        ]);

        return $sent;
    }

    private function mark_job_email_sent(int $job_id): bool
    {
        global $wpdb;

        if ($job_id <= 0) {
            return false;
        }

        $updated = $wpdb->update(
            $this->jobs_table->get_table_name(),
            ['email_sent' => 1],
            ['id' => $job_id],
            ['%d'],
            ['%d']
        );

        if ($updated === false) {
            self::debug_ctx('mark sent failed', [
                'job_id' => $job_id,
                'last_error' => (string) $wpdb->last_error,
            ]);
            return false;
        }

        if ((int) $updated < 1) {
            self::debug_ctx('mark sent returned no updated rows', [
                'job_id' => $job_id,
            ]);
            return false;
        }

        return true;
    }

    private static function force_no_delay_mode(): bool
    {
        return defined('FFLHUB_QUOTE_EMAIL_FORCE_NO_DELAY') && (bool) constant('FFLHUB_QUOTE_EMAIL_FORCE_NO_DELAY');
    }

    /**
     * @return array{is_open:bool,now_local:string,hour_local:int}
     */
    private static function business_hours_context(): array
    {
        try {
            $tz = new \DateTimeZone(self::BUSINESS_HOURS_TZ);
        } catch (\Throwable $e) {
            return [
                'is_open' => true,
                'now_local' => '',
                'hour_local' => -1,
            ];
        }

        $now_local = new \DateTimeImmutable('now', $tz);
        $hour_local = (int) $now_local->format('G');
        $is_open = ($hour_local >= self::BUSINESS_HOUR_START) && ($hour_local < self::BUSINESS_HOUR_END);

        return [
            'is_open' => $is_open,
            'now_local' => $now_local->format('Y-m-d H:i:s T'),
            'hour_local' => $hour_local,
        ];
    }

    private static function debug(string $message): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $message);
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function debug_ctx(string $message, array $context): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $message, $context);
    }
}
