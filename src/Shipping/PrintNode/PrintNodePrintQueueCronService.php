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
 * It intentionally submits at most one print job per run. The per-document
 * available_at timestamp, plus the recurring Action Scheduler cadence, prevents
 * a batch from hammering the thermal printer all at once.
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
        (new PrintNodePrintQueueService())->process_one_due_job();
    }
}
