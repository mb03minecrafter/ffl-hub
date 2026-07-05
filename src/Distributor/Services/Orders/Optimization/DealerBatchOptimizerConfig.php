<?php

namespace FFLHub\Distributor\Services\Orders\Optimization;

use FFLHub\Distributor\Services\Orders\Cron\DealerBatchCronRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central option names/defaults for dealer-batch timing and shipping optimization.
 */
final class DealerBatchOptimizerConfig
{
    public const DEALER_BATCH_OPTION_PREFIX = 'fflhub_dealer_batch_global';
    public const OPTIMIZER_OPTION_PREFIX = 'fflhub_dealer_batch_optimizer';

    public const DEFAULT_DISPATCH_TIME = '17:00';
    public const DEFAULT_LOW_STOCK_THRESHOLD = 3;
    public const DEFAULT_RETRY_DELAY_SECONDS = 300;
    public const DEFAULT_MAX_ROWS_PER_RUN = 200;

    private const FORCE_FLUSH_TTL_SECONDS = 3600;
    private const DEFAULT_FREE_SHIPPING_THRESHOLD = 1000.0;
    private const DEFAULT_PAID_SHIPPING_COST = 1.0;
    private const DEFAULT_PAID_SHIPPING_COST_BY_DISTRIBUTOR = [
        'bill_hicks'   => 15.0,
        'davidsons'    => 13.0,
        'rsr'          => 10.0,
        'sports_south' => 8.95,
    ];
    private const MANUAL_ONLY_OPTIMIZER_DISTRIBUTORS = [];

    private function __construct()
    {
    }

    public static function dealer_batch_option_name(string $suffix): string
    {
        return self::DEALER_BATCH_OPTION_PREFIX . '_' . trim($suffix);
    }

    public static function optimizer_option_name(string $suffix): string
    {
        return self::OPTIMIZER_OPTION_PREFIX . '_' . trim($suffix);
    }

    public static function free_shipping_threshold_option_name(string $dist_id): string
    {
        return self::OPTIMIZER_OPTION_PREFIX . '_free_shipping_threshold_' . self::normalize_dist_id($dist_id);
    }

    public static function shipping_penalty_option_name(string $dist_id): string
    {
        return self::OPTIMIZER_OPTION_PREFIX . '_shipping_penalty_' . self::normalize_dist_id($dist_id);
    }

    public static function optimizer_enabled(): bool
    {
        return self::truthy(get_option(self::optimizer_option_name('enabled'), '0'), false);
    }

    public static function dealer_batch_enabled(): bool
    {
        return self::truthy(get_option(self::dealer_batch_option_name('enabled'), '1'), true);
    }

    public static function dispatch_time(): string
    {
        $raw = trim((string) get_option(self::dealer_batch_option_name('dispatch_time'), self::DEFAULT_DISPATCH_TIME));
        if (!preg_match('/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $raw)) {
            return self::DEFAULT_DISPATCH_TIME;
        }

        return $raw;
    }

    public static function low_stock_threshold(): int
    {
        return max(0, (int) get_option(
            self::dealer_batch_option_name('low_stock_threshold'),
            self::DEFAULT_LOW_STOCK_THRESHOLD
        ));
    }

    public static function retry_delay_seconds(): int
    {
        return max(30, (int) get_option(
            self::dealer_batch_option_name('retry_delay_seconds'),
            self::DEFAULT_RETRY_DELAY_SECONDS
        ));
    }

    public static function max_rows_per_run(): int
    {
        return max(1, (int) get_option(
            self::dealer_batch_option_name('max_rows_per_run'),
            self::DEFAULT_MAX_ROWS_PER_RUN
        ));
    }

    public static function force_flush_requested(): bool
    {
        return self::force_flush_token() !== '';
    }

    public static function mark_force_flush_requested(): void
    {
        update_option(self::dealer_batch_option_name('force_flush'), (string) time(), false);
    }

    public static function consume_force_flush_for_option_prefix(string $distributor_option_prefix): bool
    {
        $raw = self::force_flush_token();
        if ($raw === '') {
            return false;
        }

        $token = $raw;
        if ($token === '') {
            $token = '1';
        }

        $consumed_option = trim($distributor_option_prefix) . '_global_force_flush_consumed_token';
        if ((string) get_option($consumed_option, '') === $token) {
            return false;
        }

        update_option($consumed_option, $token, false);
        return true;
    }

    public static function free_shipping_threshold(string $dist_id): float
    {
        $dist_id = self::normalize_dist_id($dist_id);
        if ($dist_id === '') {
            return 0.0;
        }

        return self::non_negative_float(
            get_option(self::free_shipping_threshold_option_name($dist_id), (string) self::DEFAULT_FREE_SHIPPING_THRESHOLD)
        );
    }

    public static function shipping_penalty(string $dist_id): float
    {
        $dist_id = self::normalize_dist_id($dist_id);
        if ($dist_id === '') {
            return 0.0;
        }

        $configured = self::non_negative_float(get_option(self::shipping_penalty_option_name($dist_id), '0'));
        if ($configured > 0.0) {
            return $configured;
        }

        return self::default_paid_shipping_cost($dist_id);
    }

    public static function force_flush_token(): string
    {
        $token = trim((string) get_option(self::dealer_batch_option_name('force_flush'), '0'));
        if (!self::truthy($token, false)) {
            return '';
        }

        if (is_numeric($token)) {
            $requested_at = (int) $token;
            if ($requested_at > 0 && (time() - $requested_at) <= self::FORCE_FLUSH_TTL_SECONDS) {
                return $token;
            }
        }

        update_option(self::dealer_batch_option_name('force_flush'), '0', false);
        return '';
    }

    public static function claim_pre_dispatch_optimizer_token(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $option = self::optimizer_option_name('last_pre_dispatch_token');
        if (trim((string) get_option($option, '')) === $token) {
            return false;
        }

        update_option($option, $token, false);
        return true;
    }

    private static function default_paid_shipping_cost(string $dist_id): float
    {
        $dist_id = self::normalize_dist_id($dist_id);
        if ($dist_id === '') {
            return 0.0;
        }

        if (isset(self::DEFAULT_PAID_SHIPPING_COST_BY_DISTRIBUTOR[$dist_id])) {
            return (float) self::DEFAULT_PAID_SHIPPING_COST_BY_DISTRIBUTOR[$dist_id];
        }

        return self::DEFAULT_PAID_SHIPPING_COST;
    }

    /**
     * @return string[]
     */
    public static function optimizer_distributor_ids(): array
    {
        $ids = [];

        foreach (array_merge(DealerBatchCronRegistry::distributor_ids(), self::MANUAL_ONLY_OPTIMIZER_DISTRIBUTORS) as $dist_id) {
            $dist_id = self::normalize_dist_id((string) $dist_id);
            if ($dist_id === '') {
                continue;
            }

            $ids[$dist_id] = $dist_id;
        }

        return array_values($ids);
    }

    public static function is_manual_only_optimizer_target(string $dist_id): bool
    {
        $dist_id = self::normalize_dist_id($dist_id);
        if ($dist_id === '') {
            return false;
        }

        return in_array($dist_id, self::MANUAL_ONLY_OPTIMIZER_DISTRIBUTORS, true);
    }

    /**
     * @return array<string,array{threshold:float,penalty:float}>
     */
    public static function optimizer_distributor_config(): array
    {
        $out = [];
        foreach (self::optimizer_distributor_ids() as $dist_id) {
            $dist_id = self::normalize_dist_id($dist_id);
            if ($dist_id === '') {
                continue;
            }

            $out[$dist_id] = [
                'threshold' => self::free_shipping_threshold($dist_id),
                'penalty' => self::shipping_penalty($dist_id),
            ];
        }

        return $out;
    }

    public static function init_defaults(): void
    {
        self::add_default(self::dealer_batch_option_name('enabled'), '1');
        self::add_default(self::dealer_batch_option_name('dispatch_time'), self::DEFAULT_DISPATCH_TIME);
        self::add_default(self::dealer_batch_option_name('low_stock_threshold'), (string) self::DEFAULT_LOW_STOCK_THRESHOLD);
        self::add_default(self::dealer_batch_option_name('retry_delay_seconds'), (string) self::DEFAULT_RETRY_DELAY_SECONDS);
        self::add_default(self::dealer_batch_option_name('max_rows_per_run'), (string) self::DEFAULT_MAX_ROWS_PER_RUN);
        self::add_default(self::dealer_batch_option_name('force_flush'), '0');
        self::add_default(self::dealer_batch_option_name('last_scheduled_flush_at_utc'), '');
        self::add_default(self::optimizer_option_name('enabled'), '0');
        self::add_default(self::optimizer_option_name('last_pre_dispatch_token'), '');

        foreach (self::optimizer_distributor_ids() as $dist_id) {
            self::add_default(
                self::free_shipping_threshold_option_name((string) $dist_id),
                (string) self::DEFAULT_FREE_SHIPPING_THRESHOLD
            );
            self::add_default(self::shipping_penalty_option_name((string) $dist_id), '0');
        }
    }

    /**
     * @param mixed $value
     */
    public static function non_negative_float($value): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0.0;
        }

        if (!is_numeric($raw)) {
            $raw = trim((string) preg_replace('/[^0-9.\-]/', '', $raw));
        }

        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }

        $v = (float) $raw;
        if (!is_finite($v) || $v < 0.0) {
            return 0.0;
        }

        return $v;
    }

    /**
     * @param mixed $value
     */
    public static function truthy($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_numeric($value)) {
            return ((int) $value) > 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function add_default(string $name, string $value): void
    {
        if (get_option($name, null) === null) {
            add_option($name, $value, '', false);
        }
    }

    private static function normalize_dist_id(string $dist_id): string
    {
        return strtolower(trim($dist_id));
    }
}
