<?php
declare(strict_types=1);

namespace FFLHub\CLI;

use FFLHub\Shipping\PrintNode\PrintNodeOptions;
use FFLHub\Shipping\PrintNode\PrintNodePrintQueueService;
use FFLHub\Shipping\PrintNode\PrintNodePrintQueueStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Continuous PrintNode queue worker for warehouse label printing.
 */
final class PrintNodeWorkerCommand
{
    /**
     * Drain queued PrintNode jobs with real wall-clock spacing.
     *
     * ## OPTIONS
     *
     * [--idle-sleep=<seconds>]
     * : Seconds to sleep while no job is due. Default: 1.
     *
     * [--max-runtime=<seconds>]
     * : Stop after N seconds. Default: 0 = run forever.
     *
     * [--once]
     * : Process a single due job and exit. Useful for smoke tests.
     *
     * [--verbose]
     * : Log every processed job.
     *
     * ## EXAMPLES
     *
     *     wp fflhub printnode-worker --idle-sleep=1
     *     wp fflhub printnode-worker --once --verbose
     *     wp fflhub printnode-worker --max-runtime=300 --verbose
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        if (!class_exists('\WP_CLI')) {
            return;
        }

        PrintNodePrintQueueStore::ensure_schema();

        $idle_sleep = max(1, min(60, (int) ($assoc_args['idle-sleep'] ?? 1)));
        $max_runtime = max(0, (int) ($assoc_args['max-runtime'] ?? 0));
        $once = isset($assoc_args['once']);
        $verbose = isset($assoc_args['verbose']);

        \WP_CLI::log(sprintf(
            'PrintNode worker starting. delay=%ds idle_sleep=%ds max_runtime=%ds once=%s',
            PrintNodeOptions::job_delay_seconds(),
            $idle_sleep,
            $max_runtime,
            $once ? 'yes' : 'no'
        ));

        $service = new PrintNodePrintQueueService();
        if ($once) {
            $result = $service->process_one_due_job();
            if (is_array($result)) {
                \WP_CLI::success(sprintf(
                    'Processed one PrintNode job. status=%s job_id=%d',
                    (string) ($result['status'] ?? ''),
                    (int) ($result['job_id'] ?? 0)
                ));
                return;
            }

            \WP_CLI::success('No PrintNode jobs were due.');
            return;
        }

        $summary = $service->run_worker_loop($idle_sleep, $max_runtime, $verbose);
        \WP_CLI::success(sprintf(
            'PrintNode worker stopped. processed=%d printed=%d failed=%d idle_loops=%d waited=%ds runtime=%ds',
            (int) ($summary['processed'] ?? 0),
            (int) ($summary['printed'] ?? 0),
            (int) ($summary['failed'] ?? 0),
            (int) ($summary['idle_loops'] ?? 0),
            (int) ($summary['waited_seconds'] ?? 0),
            max(0, (int) ($summary['stopped_at'] ?? time()) - (int) ($summary['started_at'] ?? time()))
        ));
    }
}
