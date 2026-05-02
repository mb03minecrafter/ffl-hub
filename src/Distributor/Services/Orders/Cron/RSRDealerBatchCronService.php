<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin RSR dealer batch wrapper over the shared batch engine.
 */
final class RSRDealerBatchCronService extends AbstractOrderBatchCronService
{
    /** Action Scheduler / WP-Cron hook polled every minute for RSR dealer batch work. */
    public const CRON_HOOK = 'fflhub_rsr_dealer_batch_poll';

    private const LOG_PREFIX = '[FFLHub][RSRDealerBatchCron]';

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_log_prefix(): string
    {
        return self::LOG_PREFIX;
    }

    protected function get_distributor_id(): string
    {
        return 'rsr';
    }

    protected function get_option_prefix(): string
    {
        return 'fflhub_rsr_dealer_batch';
    }

    protected function get_po_prefix(): string
    {
        return 'RSRB';
    }

    protected function get_batch_description_prefix(): string
    {
        return 'RSR dealer batch aggregate';
    }

    protected function get_batch_mode(): string
    {
        return self::MODE_DEALER;
    }

    protected function is_dispatch_day_allowed(\DateTimeImmutable $local_time): bool
    {
        $day_of_week = (int) $local_time->format('N');
        return $day_of_week >= 1 && $day_of_week <= 5;
    }

    protected function dispatch_day_block_reason(\DateTimeImmutable $local_time): string
    {
        return 'rsr_weekend_hold';
    }
}

