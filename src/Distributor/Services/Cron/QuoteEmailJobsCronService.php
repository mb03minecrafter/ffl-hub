<?php

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Checkout\QuoteCartLinkHandler;
use FFLHub\Distributor\Services\Routing\DealerFulfillmentRoutingPlanner;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
use FFLHub\Settings\Options;
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
    private const MIN_PROFIT_AFTER_FREE_SHIPPING = 0.01;
    private const BLOCKED_QUOTE_EMAILS = [
        'richard.smith9299@yahoo.com',
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

        self::debug_ctx('run start', [
            'table' => $table_name,
            'now_utc' => $now_utc,
            'limit' => $limit,
        ]);

        $sql = $wpdb->prepare(
            "SELECT id, request_first_name, request_last_name, request_email, quote_upc, quote_product_name, submitted_at, email_sent
             FROM {$table_name}
             WHERE email_sent = 0
             ORDER BY submitted_at ASC, id ASC
             LIMIT %d",
            $limit
        );

        $candidate_jobs = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($candidate_jobs)) {
            self::debug_ctx('query failed', [
                'last_error' => (string) $wpdb->last_error,
            ]);
            return;
        }

        if (empty($candidate_jobs)) {
            self::debug('run complete: no pending jobs');
            return;
        }

        $due_jobs = $candidate_jobs;
        $candidate_rows_seen = count($candidate_jobs);

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
            'candidate_rows_seen' => $candidate_rows_seen,
            'candidate_rows_available' => count($candidate_jobs),
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
        $request_first_name = trim((string) ($job_row['request_first_name'] ?? ''));
        $request_last_name = trim((string) ($job_row['request_last_name'] ?? ''));
        $quote_upc = trim((string) ($job_row['quote_upc'] ?? ''));
        $quote_product_name = trim((string) ($job_row['quote_product_name'] ?? ''));

        self::debug_ctx('processing job', [
            'job_id' => $job_id,
            'recipient' => $recipient,
            'quote_upc' => $quote_upc,
            'quote_product_name' => $quote_product_name,
        ]);

        if ($job_id <= 0 || $recipient === '' || !is_email($recipient)) {
            $marked = ($job_id > 0) ? $this->mark_job_email_sent($job_id) : false;
            self::debug_ctx('skip job: invalid recipient/job id', [
                'job_id' => $job_id,
                'recipient' => $recipient,
                'marked_sent' => $marked ? 1 : 0,
            ]);

            return $marked
                ? 'skip_invalid_recipient_or_job_id_marked'
                : 'skip_invalid_recipient_or_job_id_mark_failed';
        }

        if ($this->is_blocked_request_email($recipient)) {
            $warning_sent = $this->send_quote_processing_block_warning_email(
                $job_row,
                $recipient,
                'blocked_email_prohibited'
            );
            $marked = $this->mark_job_email_sent($job_id);

            self::debug_ctx('blocked job: request email disallowed', [
                'job_id' => $job_id,
                'recipient' => $recipient,
                'warning_sent' => $warning_sent ? 1 : 0,
                'marked_sent' => $marked ? 1 : 0,
            ]);

            if (!$marked) {
                return 'blocked_email_mark_failed';
            }

            return $warning_sent
                ? 'blocked_email'
                : 'blocked_email_warning_failed';
        }

        if ($this->is_blocked_request_name($request_first_name, $request_last_name)) {
            $warning_sent = $this->send_quote_processing_block_warning_email(
                $job_row,
                $recipient,
                'blocked_name_dennis_joe'
            );
            $marked = $this->mark_job_email_sent($job_id);

            self::debug_ctx('blocked job: request name disallowed', [
                'job_id' => $job_id,
                'recipient' => $recipient,
                'request_first_name' => $request_first_name,
                'request_last_name' => $request_last_name,
                'warning_sent' => $warning_sent ? 1 : 0,
                'marked_sent' => $marked ? 1 : 0,
            ]);

            if (!$marked) {
                return 'blocked_name_mark_failed';
            }

            return $warning_sent
                ? 'blocked_name'
                : 'blocked_name_warning_failed';
        }

        $product_context = $this->resolve_product_context_from_job_row($job_row);
        if (!is_array($product_context)) {
            self::debug_ctx('skip job: product not resolved', [
                'job_id' => $job_id,
                'quote_upc' => $quote_upc,
                'quote_product_name' => $quote_product_name,
            ]);
            return 'skip_product_not_found';
        }

        $product = $product_context['product'];
        $state_row = $product_context['state_row'];

        $pricing = $this->quote_coupon_pricing_for_product($product, $state_row);
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
                'pricing_mode' => is_array($state_row) ? (string) ($state_row['pricing_mode'] ?? '') : '',
            ]);
            return 'skip_coupon_amount_not_positive';
        }

        $coupon_payload = $this->create_or_refresh_quote_coupon($job_row, $product, $state_row, $coupon_amount);
        if (!is_array($coupon_payload) || empty($coupon_payload['code'])) {
            self::debug_ctx('skip job: coupon creation failed', [
                'job_id' => $job_id,
                'product_id' => (int) $product->get_id(),
                'coupon_amount' => $coupon_amount,
            ]);
            return 'skip_coupon_creation_failed';
        }

        $context = $this->build_quote_email_context($job_row, $product, $state_row, $coupon_payload);
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
                'force_plain_text' => $context->force_plain_text ? 1 : 0,
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
            'force_plain_text' => $context->force_plain_text ? 1 : 0,
        ]);

        return 'sent';
    }

    /**
     * @param array<string,mixed> $job_row
     * @return array{product:WC_Product,state_row:array<string,mixed>}|null
     */
    private function resolve_product_context_from_job_row(array $job_row): ?array
    {
        $upc = trim((string) ($job_row['quote_upc'] ?? ''));
        $product_name = trim((string) ($job_row['quote_product_name'] ?? ''));

        $product_id = 0;
        if ($upc !== '') {
            $product_id = $this->find_product_id_by_upc($upc);
            if ($product_id <= 0 && $product_name !== '') {
                self::debug_ctx('upc provided but product not resolved; falling back to exact name lookup', [
                    'upc' => $upc,
                    'quote_product_name' => $product_name,
                ]);
                $product_id = $this->find_product_id_by_exact_name($product_name);
            }
        } elseif ($product_name !== '') {
            $product_id = $this->find_product_id_by_exact_name($product_name);
        }
        if ($product_id <= 0) {
            return null;
        }

        $product = wc_get_product($product_id);
        if (!($product instanceof WC_Product)) {
            return null;
        }

        $state_row = ProductStateStore::get_row_for_product($product);
        if (!is_array($state_row)) {
            self::debug_ctx('product resolved but product state row missing', [
                'product_id' => $product_id,
                'upc' => $upc,
                'product_name' => $product_name,
            ]);
            return null;
        }

        self::debug_ctx('resolved product', [
            'product_id' => $product_id,
            'upc' => $upc,
            'product_name' => $product_name,
        ]);
        return [
            'product' => $product,
            'state_row' => $state_row,
        ];
    }

    private function find_product_id_by_upc(string $upc): int
    {
        global $wpdb;

        $upc = self::normalize_upc($upc);
        if ($upc === '') {
            return 0;
        }

        $state_table = ProductStateStore::table_name();
        $product_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT product_id FROM {$state_table} WHERE upc = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $upc
        ));

        if ($product_id <= 0) {
            return 0;
        }

        $sql = $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE ID = %d
               AND post_type = 'product'
               AND post_status IN ('publish', 'private')
             LIMIT 1",
            $product_id
        );

        $found_id = (int) $wpdb->get_var($sql);
        if ($found_id > 0) {
            return $found_id;
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

    /**
     * @param array<string,mixed> $state_row
     * @return array{recommended:float,listed_sale_price:float,listed_source:string,difference:float}
     */
    private function quote_coupon_pricing_for_product(WC_Product $product, array $state_row): array
    {
        $recommended = $this->recommended_price_for_product($product, $state_row);
        $listed_details = $this->listed_sale_price_details_for_product($product, $state_row);
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
     * @param array<string,mixed> $state_row
     * @return array<string,mixed>|null
     */
    private function create_or_refresh_quote_coupon(array $job_row, WC_Product $product, array $state_row, float $coupon_amount): ?array
    {
        $email = sanitize_email((string) ($job_row['request_email'] ?? ''));
        if ($email === '' || !is_email($email)) {
            return null;
        }

        $coupon_code = $this->generate_available_quote_coupon_code();
        if ($coupon_code === '') {
            return null;
        }

        $coupon = new \WC_Coupon();
        $customer_free_shipping = $this->map_real_price_free_shipping_override_enabled($state_row);

        $expires_ts = (int) current_time('timestamp', true) + (48 * HOUR_IN_SECONDS);
        $product_name = (string) $product->get_name();

        self::debug_ctx('creating/updating coupon', [
            'product_id' => (int) $product->get_id(),
            'product_name' => $product_name,
            'coupon_code' => $coupon_code,
            'coupon_amount' => $coupon_amount,
            'recipient_email' => $email,
            'expires_ts' => $expires_ts,
            'customer_free_shipping_product_state' => $customer_free_shipping ? 1 : 0,
            'coupon_free_shipping' => $customer_free_shipping ? 1 : 0,
        ]);

        $coupon->set_code($coupon_code);
        $coupon->set_discount_type('fixed_product');
        $coupon->set_amount($coupon_amount);
        // Allow stacking with other coupons for quote flows.
        $coupon->set_individual_use(false);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions([]);
        $coupon->set_date_expires($expires_ts);
        $coupon->set_free_shipping($customer_free_shipping);
        $coupon->set_description(
            sprintf(
                'Quote coupon for %s',
                $product_name
            )
        );

        $product_id = (int) $product->get_id();
        if ($product_id > 0) {
            $coupon->set_product_ids([$product_id]);
        }

        if (method_exists($coupon, 'set_usage_count')) {
            $coupon->set_usage_count(0);
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

    private function generate_available_quote_coupon_code(): string
    {
        $max_attempts = 100;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $candidate = 'quote-' . $this->random_coupon_code_segment(4) . '-' . $this->random_coupon_code_segment(4);
            $existing_id = function_exists('wc_get_coupon_id_by_code')
                ? (int) wc_get_coupon_id_by_code($candidate)
                : 0;
            if ($existing_id <= 0) {
                return $candidate;
            }
        }

        self::debug_ctx('coupon code allocation failed after max attempts', [
            'max_attempts' => $max_attempts,
        ]);

        return '';
    }

    private function random_coupon_code_segment(int $length): string
    {
        $length = max(1, $length);
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $max_index = strlen($alphabet) - 1;
        $segment = '';

        for ($i = 0; $i < $length; $i++) {
            $segment .= $alphabet[wp_rand(0, $max_index)];
        }

        return $segment;
    }

    /**
     * @param array<string,mixed> $job_row
     * @param array<string,mixed> $state_row
     * @param array<string,mixed> $coupon_payload
     */
    private function build_quote_email_context(array $job_row, WC_Product $product, array $state_row, array $coupon_payload): ?QuoteOfferEmailContext
    {
        $recipient = sanitize_email((string) ($job_row['request_email'] ?? ''));
        if ($recipient === '' || !is_email($recipient)) {
            return null;
        }

        $job_id = isset($job_row['id']) ? (int) $job_row['id'] : 0;
        $variant_index = 0;
        $rep_names = Options::get_quote_email_rep_names();
        $rep_count = max(1, count($rep_names));
        $rep_index = ($job_id > 0) ? ($job_id % $rep_count) : 0;

        $first_name = trim((string) ($job_row['request_first_name'] ?? ''));
        $resolved_upc_product_name = $this->resolve_upc_validated_product_name_for_job($job_row, $product, $state_row);
        $product_name = ($resolved_upc_product_name !== '')
            ? $resolved_upc_product_name
            : (string) __('requested product', 'ffl-hub');
        $product_url = get_permalink((int) $product->get_id());
        if (!is_string($product_url) || $product_url === '') {
            $product_url = home_url('/');
        }

        $coupon_code = (string) ($coupon_payload['code'] ?? '');
        if ($coupon_code === '') {
            return null;
        }
        $quote_cart_url = QuoteCartLinkHandler::build_url((int) $product->get_id(), $coupon_code, 'checkout');

        $coupon_amount = (float) ($coupon_payload['amount'] ?? 0.0);
        $final_price_amount = $this->final_price_amount_for_product($product, $state_row, $coupon_amount);
        $final_price_display = $this->final_price_display_for_amount($final_price_amount);
        $shipping_phrase = $this->shipping_phrase_for_quote_product($state_row, $final_price_amount);

        $subject = (string) __('Email Quote Ready', 'ffl-hub');
        $rep_name = $rep_names[$rep_index] ?? Options::default_quote_email_rep_names();
        $team_signature = Options::get_quote_email_team_signature();
        $coupon_amount_display = wp_strip_all_tags(wc_price($coupon_amount));
        $force_plain_text = false;

        self::debug_ctx('email context built', [
            'job_id' => isset($job_row['id']) ? (int) $job_row['id'] : 0,
            'recipient' => $recipient,
            'quote_upc' => trim((string) ($job_row['quote_upc'] ?? '')),
            'resolved_upc_product_name' => $resolved_upc_product_name,
            'display_product_name' => $product_name,
            'subject' => $subject,
            'variant_index' => $variant_index,
            'rep_name' => $rep_name,
            'team_signature' => $team_signature,
            'coupon_code' => $coupon_code,
            'quote_cart_url' => $quote_cart_url,
            'final_price' => $final_price_display,
            'shipping_phrase' => $shipping_phrase,
            'force_plain_text' => $force_plain_text ? 1 : 0,
        ]);

        return new QuoteOfferEmailContext(
            $recipient,
            $subject,
            $variant_index,
            $first_name,
            $rep_name,
            $product_name,
            $product_url,
            $quote_cart_url,
            $coupon_code,
            $coupon_amount_display,
            $final_price_display,
            $shipping_phrase,
            $force_plain_text,
            $team_signature
        );
    }

    /**
     * Resolve a product title only when it can be validated against the job UPC.
     *
     * If the job has a UPC and the resolved product does not match that UPC, return
     * an empty string so callers can safely fall back to a generic subject/body label.
     *
     * @param array<string,mixed> $job_row
     * @param array<string,mixed> $state_row
     */
    private function resolve_upc_validated_product_name_for_job(array $job_row, WC_Product $product, array $state_row): string
    {
        $name = trim((string) $product->get_name());
        if ($name === '') {
            return '';
        }

        $quote_upc = trim((string) ($job_row['quote_upc'] ?? ''));
        if ($quote_upc === '') {
            return $name;
        }

        return $this->product_matches_quote_upc($state_row, $quote_upc) ? $name : '';
    }

    /**
     * @param array<string,mixed> $state_row
     */
    private function product_matches_quote_upc(array $state_row, string $quote_upc): bool
    {
        $quote_upc = self::normalize_upc($quote_upc);
        if ($quote_upc === '') {
            return false;
        }

        $product_upc = self::normalize_upc((string) ($state_row['upc'] ?? ''));

        return $product_upc !== '' && $product_upc === $quote_upc;
    }

    /**
     * @param array<string,mixed> $state_row
     */
    private function recommended_price_for_product(WC_Product $product, array $state_row): float
    {
        $recommended = $this->to_non_negative_float($state_row['computed_sell_price'] ?? null, 0.0);
        if ($recommended <= 0.0) {
            $recommended = (float) $product->get_regular_price();
        }
        if ($recommended <= 0.0) {
            $recommended = (float) $product->get_price();
        }

        return ($recommended > 0.0) ? $recommended : 0.0;
    }

    /**
     * @param array<string,mixed> $state_row
     */
    private function listed_sale_price_for_product(WC_Product $product, array $state_row): float
    {
        $details = $this->listed_sale_price_details_for_product($product, $state_row);
        return (float) ($details['listed_sale_price'] ?? 0.0);
    }

    /**
     * @param array<string,mixed> $state_row
     * @return array{listed_sale_price:float,source:string}
     */
    private function listed_sale_price_details_for_product(WC_Product $product, array $state_row): array
    {
        $state_sale_price = $this->to_non_negative_float($state_row['public_sale_price'] ?? null, 0.0);
        if ($state_sale_price > 0.0) {
            return [
                'listed_sale_price' => $state_sale_price,
                'source' => 'product_state_public_sale_price',
            ];
        }

        $state_regular_price = $this->to_non_negative_float($state_row['public_regular_price'] ?? null, 0.0);
        if ($state_regular_price > 0.0) {
            return [
                'listed_sale_price' => $state_regular_price,
                'source' => 'product_state_public_regular_price',
            ];
        }

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

    /**
     * @param array<string,mixed> $state_row
     */
    private function final_price_amount_for_product(WC_Product $product, array $state_row, float $coupon_amount): float
    {
        $final_price = $this->recommended_price_for_product($product, $state_row);
        if ($final_price <= 0.0) {
            $listed_sale_price = $this->listed_sale_price_for_product($product, $state_row);
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

    /**
     * @param array<string,mixed> $state_row
     */
    private function shipping_phrase_for_quote_product(array $state_row, float $line_revenue): string
    {
        $is_free_shipping = $this->is_free_shipping_for_quote_product($state_row, $line_revenue);

        return $is_free_shipping
            ? __('with free shipping', 'ffl-hub')
            : __('+ shipping', 'ffl-hub');
    }

    /**
     * @param array<string,mixed> $state_row
     */
    private function is_free_shipping_for_quote_product(array $state_row, float $line_revenue): bool
    {
        if ($this->map_real_price_free_shipping_override_enabled($state_row)) {
            return true;
        }

        $shipping_cost_total = $this->estimate_shipping_cost_total_for_quote_product($state_row);
        if ($shipping_cost_total <= 0.0) {
            return true;
        }

        $fee_percent = Options::get_payment_processor_fee_percent();
        $f = $fee_percent / 100.0;
        if ($f < 0.0) {
            $f = 0.0;
        }
        if ($f >= 0.99) {
            $f = 0.99;
        }

        $true_cost = $this->to_non_negative_float($state_row['landed_cost'] ?? null, 0.0);
        $profit_net_total = ($line_revenue - $true_cost) * (1.0 - $f);

        $customer_charge = 0.0;
        $free_threshold = $this->free_shipping_cost_threshold(
            $profit_net_total,
            Options::get_free_shipping_max_profit_spend_percent()
        );
        if ($free_threshold > 0.0 && $shipping_cost_total <= ($free_threshold + 0.0001)) {
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

    private function free_shipping_cost_threshold(float $profit_net_total, float $max_profit_spend_percent): float
    {
        if ($profit_net_total <= self::MIN_PROFIT_AFTER_FREE_SHIPPING) {
            return 0.0;
        }

        $max_profit_spend_percent = max(0.0, min(100.0, $max_profit_spend_percent));
        $percent_threshold = $profit_net_total * ($max_profit_spend_percent / 100.0);
        $penny_profit_threshold = $profit_net_total - self::MIN_PROFIT_AFTER_FREE_SHIPPING;

        return max(0.0, min($percent_threshold, $penny_profit_threshold));
    }

    /**
     * @param array<string,mixed> $state_row
     */
    private function map_real_price_free_shipping_override_enabled(array $state_row): bool
    {
        $policy = strtolower(trim((string) ($state_row['map_visibility_policy'] ?? '')));
        if ($policy !== Options::MAP_POLICY_EMAIL_FOR_QUOTE) {
            return false;
        }

        return $this->to_boolish($state_row['quote_free_shipping_override'] ?? null, false);
    }

    /**
     * @param array<string,mixed> $state_row
     */
    private function estimate_shipping_cost_total_for_quote_product(array $state_row): float
    {
        $shipping_settings = $this->shipping_method_settings_snapshot();
        $fallback_ship = (float) ($shipping_settings['fallback_shipping'] ?? 15.0);

        $dist_id = strtolower(trim((string) ($state_row['distributor_id'] ?? '')));
        if ($dist_id === '') {
            return max(0.0, $fallback_ship);
        }

        $ffl_required = $this->to_boolish($state_row['ffl_required'] ?? null, false);
        $dropship_enabled = $this->to_boolish($state_row['dropship_enabled'] ?? null, true);

        $ship_raw = $state_row['shipping_cost'] ?? null;
        $dist_lane_fee = $this->to_non_negative_float($ship_raw, $fallback_ship);
        if ($dist_lane_fee <= 0.0) {
            $dist_lane_fee = max(0.0, $fallback_ship);
        }
        $weight_oz = $this->to_non_negative_float($state_row['shipping_weight_oz'] ?? null, 0.0);

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

    private static function normalize_upc(string $upc): string
    {
        $upc = preg_replace('/\D+/', '', trim($upc));

        return is_string($upc) ? $upc : '';
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
            'force_plain_text' => $context->force_plain_text ? 1 : 0,
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

    /**
     * @param array<string,mixed> $job_row
     */
    private function send_quote_processing_block_warning_email(array $job_row, string $blocked_recipient, string $reason_code): bool
    {
        $warning_recipient = sanitize_email((string) apply_filters(
            'fflhub_quote_email_block_warning_recipient',
            (string) get_option('admin_email'),
            $job_row,
            $blocked_recipient,
            $reason_code
        ));

        if ($warning_recipient === '' || !is_email($warning_recipient)) {
            self::debug_ctx('blocked warning skipped: invalid warning recipient', [
                'job_id' => isset($job_row['id']) ? (int) $job_row['id'] : 0,
                'blocked_recipient' => $blocked_recipient,
                'warning_recipient' => $warning_recipient,
            ]);
            return false;
        }

        $job_id = isset($job_row['id']) ? (int) $job_row['id'] : 0;
        $quote_upc = trim((string) ($job_row['quote_upc'] ?? ''));
        $quote_product_name = trim((string) ($job_row['quote_product_name'] ?? ''));
        $submitted_at = trim((string) ($job_row['submitted_at'] ?? ''));
        $request_first_name = trim((string) ($job_row['request_first_name'] ?? ''));
        $request_last_name = trim((string) ($job_row['request_last_name'] ?? ''));
        $request_email = trim((string) ($job_row['request_email'] ?? ''));

        $subject = (string) apply_filters(
            'fflhub_quote_email_block_warning_subject',
            sprintf('[FFLHub] Quote email blocked (%s) for %s', $reason_code, $blocked_recipient),
            $job_row,
            $blocked_recipient,
            $reason_code
        );

        $message = (string) apply_filters(
            'fflhub_quote_email_block_warning_body',
            implode("\n", [
                'A quote email job was blocked.',
                '',
                'Reason: ' . $reason_code,
                'Blocked Recipient: ' . $blocked_recipient,
                'Job ID: ' . (string) $job_id,
                'Request Name: ' . trim($request_first_name . ' ' . $request_last_name),
                'Request Email: ' . $request_email,
                'Quote Product: ' . $quote_product_name,
                'Quote UPC: ' . $quote_upc,
                'Submitted (UTC): ' . $submitted_at,
            ]),
            $job_row,
            $blocked_recipient,
            $reason_code
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($warning_recipient, $subject, $message, $headers);

        self::debug_ctx('blocked warning email attempted', [
            'job_id' => $job_id,
            'blocked_recipient' => $blocked_recipient,
            'warning_recipient' => $warning_recipient,
            'sent' => $sent ? 1 : 0,
        ]);

        return (bool) $sent;
    }

    private function is_blocked_request_name(string $first_name, string $last_name): bool
    {
        $first = strtolower(trim($first_name));
        $last = strtolower(trim($last_name));
        return ($first === 'dennis' && $last === 'joe');
    }

    private function is_blocked_request_email(string $email): bool
    {
        $email = strtolower(trim(sanitize_email($email)));
        if ($email === '') {
            return false;
        }

        $blocked_emails = (array) apply_filters('fflhub_blocked_quote_emails', self::BLOCKED_QUOTE_EMAILS);
        foreach ($blocked_emails as $blocked_email) {
            if ($email === strtolower(trim(sanitize_email((string) $blocked_email)))) {
                return true;
            }
        }

        return false;
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
