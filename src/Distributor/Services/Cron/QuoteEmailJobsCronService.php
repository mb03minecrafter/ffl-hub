<?php

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Product\ProductMeta;
use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;
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

        $table_name = $this->jobs_table->get_table_name();
        $now_utc = (string) current_time('mysql', true);
        $limit = max(1, (int) self::BATCH_LIMIT);

        if (self::force_no_delay_mode()) {
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
        if (!is_array($due_jobs) || empty($due_jobs)) {
            return;
        }

        foreach ($due_jobs as $job_row) {
            if (!is_array($job_row)) {
                continue;
            }

            $this->handle_due_job($job_row);
        }
    }

    private function handle_due_job(array $job_row): void
    {
        /**
         * Keep this action for extension points and custom instrumentation.
         */
        do_action('fflhub_quote_email_job_due', $job_row);

        $job_id = isset($job_row['id']) ? (int) $job_row['id'] : 0;
        $recipient = sanitize_email((string) ($job_row['request_email'] ?? ''));
        if ($job_id <= 0 || $recipient === '' || !is_email($recipient)) {
            return;
        }

        $product = $this->resolve_product_from_job_row($job_row);
        if (!($product instanceof WC_Product)) {
            return;
        }

        $coupon_amount = $this->compute_coupon_amount_for_product($product);
        if ($coupon_amount <= 0.0) {
            return;
        }

        $coupon_payload = $this->create_or_refresh_quote_coupon($job_row, $product, $coupon_amount);
        if (!is_array($coupon_payload) || empty($coupon_payload['code'])) {
            return;
        }

        $context = $this->build_quote_email_context($job_row, $product, $coupon_payload);
        if (!($context instanceof QuoteOfferEmailContext)) {
            return;
        }

        $sent = $this->dispatch_quote_offer_email($context);
        if ($sent) {
            $this->mark_job_email_sent($job_id);
        }
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
        $recommended = (float) $product->get_meta(ProductMeta::FFLHUB_LAST_COMPUTED_PRICE_META, true);
        if ($recommended <= 0.0) {
            $recommended = (float) $product->get_regular_price();
        }
        if ($recommended <= 0.0) {
            $recommended = (float) $product->get_price();
        }

        $map = (float) $product->get_meta(ProductMeta::FFLHUB_LAST_MAP_META, true);
        if ($recommended <= 0.0 || $map <= 0.0) {
            return 0.0;
        }

        $difference = round($map - $recommended, 2);
        return ($difference > 0.0) ? $difference : 0.0;
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
            return null;
        }

        if ($coupon_id <= 0) {
            return null;
        }

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
        $variant_index = ($job_id > 0) ? ($job_id % 3) : 0;
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

        $subjects = [
            sprintf(__('Your custom quote is ready for %s', 'ffl-hub'), $product_name),
            sprintf(__('Custom price quote ready: %s', 'ffl-hub'), $product_name),
            sprintf(__('Private promo code for your %s quote request', 'ffl-hub'), $product_name),
        ];

        $subject = $subjects[$variant_index] ?? $subjects[0];
        $rep_name = self::REP_NAMES[$rep_index] ?? self::REP_NAMES[0];
        $coupon_amount_display = wp_strip_all_tags(wc_price($coupon_amount));

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
            $expires_display
        );
    }

    private function dispatch_quote_offer_email(QuoteOfferEmailContext $context): bool
    {
        if (!function_exists('WC')) {
            return false;
        }

        $woo = WC();
        if (!$woo || !method_exists($woo, 'mailer')) {
            return false;
        }

        $mailer = $woo->mailer();
        if (!$mailer || !method_exists($mailer, 'get_emails')) {
            return false;
        }

        $emails = $mailer->get_emails();
        $email = $emails[FFLHubQuoteOffer::class] ?? null;
        if (!($email instanceof FFLHubQuoteOffer)) {
            $email = new FFLHubQuoteOffer();
        }

        return $email->trigger($context);
    }

    private function mark_job_email_sent(int $job_id): void
    {
        global $wpdb;

        if ($job_id <= 0) {
            return;
        }

        $wpdb->update(
            $this->jobs_table->get_table_name(),
            ['email_sent' => 1],
            ['id' => $job_id],
            ['%d'],
            ['%d']
        );
    }

    private static function force_no_delay_mode(): bool
    {
        return defined('FFLHUB_QUOTE_EMAIL_FORCE_NO_DELAY') && (bool) constant('FFLHUB_QUOTE_EMAIL_FORCE_NO_DELAY');
    }
}
