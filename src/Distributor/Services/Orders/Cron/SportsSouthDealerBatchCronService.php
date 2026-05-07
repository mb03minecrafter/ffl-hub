<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin Sports South dealer batch wrapper over the shared batch engine.
 */
final class SportsSouthDealerBatchCronService extends AbstractOrderBatchCronService
{
    /** Action Scheduler / WP-Cron hook polled every minute for Sports South dealer batch work. */
    public const CRON_HOOK = 'fflhub_sports_south_dealer_batch_poll';

    private const LOG_PREFIX = '[FFLHub][SportsSouthDealerBatchCronService]';

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
        return 'sports_south';
    }

    protected function get_option_prefix(): string
    {
        return 'fflhub_sports_south_dealer_batch';
    }

    protected function get_po_prefix(): string
    {
        return 'SSDB';
    }

    protected function get_batch_description_prefix(): string
    {
        return 'Sports South dealer batch aggregate';
    }

    protected function get_batch_mode(): string
    {
        return self::MODE_DEALER;
    }
}
