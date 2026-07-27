<?php
declare(strict_types=1);

namespace FFLHub\Shipping\PrintNode;

use FFLHub\Distributor\Services\Cron\AbstractCronService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Background worker for throttled PrintNode output.
 *
 * It submits a small timed burst per run. The service sleeps between due jobs
 * inside the background worker, so one cron wakeup can print several documents
 * without hammering the thermal printer all at once.
 */
final class PrintNodePrintQueueCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_printnode_print_queue_cron';

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 15;
    }

    public function get_action_group(): string
    {
        return 'fflhub_shipping';
    }

    public function run(): void
    {
        (new PrintNodePrintQueueService())->process_due_jobs_for_window();
    }
}
