<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin Zanders CA relay batch wrapper over the shared batch engine.
 */
final class ZandersCaRelayBatchCronService extends AbstractOrderBatchCronService
{
    /** Action Scheduler / WP-Cron hook polled every minute for Zanders CA relay batch work. */
    public const CRON_HOOK = 'fflhub_zanders_ca_relay_batch_poll';

    private const LOG_PREFIX = '[FFLHub][ZandersCaRelayBatchCronService]';

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
        return 'zanders';
    }

    protected function get_option_prefix(): string
    {
        return 'fflhub_zanders_ca_relay_batch';
    }

    protected function get_po_prefix(): string
    {
        return 'ZANR';
    }

    protected function get_batch_description_prefix(): string
    {
        return 'Zanders CA relay batch aggregate';
    }

    protected function get_batch_mode(): string
    {
        return self::MODE_CA_RELAY;
    }
}

