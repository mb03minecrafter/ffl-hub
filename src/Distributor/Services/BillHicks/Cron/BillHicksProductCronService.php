<?php

namespace FFLHub\Distributor\Services\BillHicks\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Cron\CronRunLogger;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Bill Hicks full catalog/product cron scaffold.
 *
 * This is intentionally a no-op until the Bill Hicks catalog feed parser and
 * importer are implemented. Keeping the hook/service in place lets settings,
 * activation, and scheduling be wired safely first.
 */
final class BillHicksProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_bill_hicks_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][BillHicksProductCron]';

    public function __construct(DoubleBufferedProductTable $table)
    {
        parent::__construct($table);
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 15 * MINUTE_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        update_option('fflhub_bill_hicks_product_last_run', current_time('mysql'));

        $this->logger()->log('Bill Hicks product cron scaffold ran; importer not implemented yet.', [
            'hook' => self::CRON_HOOK,
            'live_table' => (string) $this->table->get_live_table_name(),
        ]);
    }

    private function logger(): CronRunLogger
    {
        return CronRunLogger::create(self::DEBUG_FLAG, self::LOG_PREFIX);
    }
}
