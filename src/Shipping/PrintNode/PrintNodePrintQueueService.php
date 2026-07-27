<?php
declare(strict_types=1);

namespace FFLHub\Shipping\PrintNode;

use FFLHub\Shipping\EasyPost\EasyPostBatchLabelService;
use FFLHub\Shipping\EasyPost\EasyPostBatchLabelStore;
use FFLHub\Shipping\Packing\PdfDocumentService;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds and drains the PrintNode queue.
 *
 * The Sending page queues every label/slip document immediately. A WP-CLI
 * worker should drain this queue continuously so the delay between jobs is
 * real wall-clock time instead of "whatever cron happened to do today."
 */
final class PrintNodePrintQueueService
{
    private PrintNodeClient $client;
    private EasyPostBatchLabelService $batch_service;
    private PdfDocumentService $pdf_service;

    public function __construct(
        ?PrintNodeClient $client = null,
        ?EasyPostBatchLabelService $batch_service = null,
        ?PdfDocumentService $pdf_service = null
    ) {
        $this->client = $client ?? new PrintNodeClient();
        $this->batch_service = $batch_service ?? new EasyPostBatchLabelService();
        $this->pdf_service = $pdf_service ?? new PdfDocumentService();
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function queue_easypost_batch(int $batch_id)
    {
        $configured = $this->configured_error();
        if (is_wp_error($configured)) {
            return $configured;
        }

        $documents = $this->batch_service->print_documents($batch_id);
        if (is_wp_error($documents)) {
            return $documents;
        }

        $printable = [];
        $errors = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $body = $this->document_body_for_printnode($document);
            if (is_wp_error($body)) {
                $errors[] = (string) ($document['title'] ?? 'Document') . ': ' . $body->get_error_message();
                continue;
            }

            $document['body'] = $body;
            $printable[] = $document;
        }

        if (empty($printable)) {
            return new WP_Error(
                'fflhub_printnode_no_queueable_documents',
                'No PrintNode documents could be queued.',
                ['errors' => $errors]
            );
        }

        $delay = PrintNodeOptions::job_delay_seconds();
        $queued = PrintNodePrintQueueStore::enqueue_documents(
            $batch_id,
            PrintNodeOptions::default_printer_id(),
            $printable,
            $delay
        );

        $this->update_batch_print_status($batch_id, (string) $queued['run_key'], [
            'queued_at' => current_time('mysql', true),
            'delay_seconds' => $delay,
            'printer_id' => PrintNodeOptions::default_printer_id(),
            'queued_job_ids' => $queued['job_ids'],
            'queue_errors' => $errors,
        ]);

        return [
            'batch' => EasyPostBatchLabelStore::get($batch_id),
            'run_key' => (string) $queued['run_key'],
            'printer_id' => PrintNodeOptions::default_printer_id(),
            'delay_seconds' => $delay,
            'queued_count' => (int) $queued['queued_count'],
            'queue_job_ids' => $queued['job_ids'],
            'errors' => $errors,
        ];
    }

    /**
     * Queue one package's carrier label followed by its packing slip. The WMS
     * packing station calls this while the operator is working package by
     * package, so the printer only receives the documents for the active box or
     * envelope instead of the entire wave.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function queue_easypost_package(int $batch_id, int $order_id, int $package_index)
    {
        $configured = $this->configured_error();
        if (is_wp_error($configured)) {
            return $configured;
        }

        $documents = $this->batch_service->print_documents_for_package($batch_id, $order_id, $package_index);
        if (is_wp_error($documents)) {
            return $documents;
        }

        $printable = [];
        $errors = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $body = $this->document_body_for_printnode($document);
            if (is_wp_error($body)) {
                $errors[] = (string) ($document['title'] ?? 'Document') . ': ' . $body->get_error_message();
                continue;
            }

            $document['body'] = $body;
            $printable[] = $document;
        }

        if (empty($printable)) {
            return new WP_Error(
                'fflhub_printnode_no_queueable_documents',
                'No PrintNode documents could be queued for that package.',
                ['errors' => $errors]
            );
        }

        $delay = PrintNodeOptions::job_delay_seconds();
        $queued = PrintNodePrintQueueStore::enqueue_documents(
            $batch_id,
            PrintNodeOptions::default_printer_id(),
            $printable,
            $delay
        );

        $this->update_batch_print_status($batch_id, (string) $queued['run_key'], [
            'queued_at' => current_time('mysql', true),
            'delay_seconds' => $delay,
            'printer_id' => PrintNodeOptions::default_printer_id(),
            'queued_job_ids' => $queued['job_ids'],
            'queue_errors' => $errors,
            'single_package_print' => [
                'order_id' => $order_id,
                'package_index' => max(0, $package_index),
            ],
        ]);

        return [
            'batch' => EasyPostBatchLabelStore::get($batch_id),
            'run_key' => (string) $queued['run_key'],
            'printer_id' => PrintNodeOptions::default_printer_id(),
            'delay_seconds' => $delay,
            'queued_count' => (int) $queued['queued_count'],
            'queue_job_ids' => $queued['job_ids'],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function process_one_due_job(): ?array
    {
        $job = PrintNodePrintQueueStore::claim_next_due_job();
        if (!is_array($job)) {
            return null;
        }

        $job_id = (int) ($job['id'] ?? 0);
        $batch_id = (int) ($job['easypost_batch_id'] ?? 0);
        $run_key = (string) ($job['run_key'] ?? '');
        $configured = $this->configured_error();
        if (is_wp_error($configured)) {
            PrintNodePrintQueueStore::mark_failed($job_id, $configured->get_error_message());
            $this->update_batch_print_status($batch_id, $run_key);
            return [
                'job_id' => $job_id,
                'status' => PrintNodePrintQueueStore::STATUS_FAILED,
                'error' => $configured->get_error_message(),
            ];
        }

        $body = base64_decode((string) ($job['body_base64'] ?? ''), true);
        if (!is_string($body) || trim($body) === '') {
            PrintNodePrintQueueStore::mark_failed($job_id, 'Queued PrintNode job had no printable body.');
            $this->update_batch_print_status($batch_id, $run_key);
            return [
                'job_id' => $job_id,
                'status' => PrintNodePrintQueueStore::STATUS_FAILED,
                'error' => 'Queued PrintNode job had no printable body.',
            ];
        }

        $result = $this->client->print_pdf([
            'printer_id' => (int) ($job['printer_id'] ?? PrintNodeOptions::default_printer_id()),
            'title' => (string) ($job['title'] ?? 'FFL Hub Print Job'),
            'body' => $body,
        ]);
        if (is_wp_error($result)) {
            PrintNodePrintQueueStore::mark_failed($job_id, $result->get_error_message());
            $this->update_batch_print_status($batch_id, $run_key);
            return [
                'job_id' => $job_id,
                'status' => PrintNodePrintQueueStore::STATUS_FAILED,
                'error' => $result->get_error_message(),
            ];
        }

        PrintNodePrintQueueStore::mark_printed($job_id, (string) ($result['id'] ?? ''));
        $this->update_batch_print_status($batch_id, $run_key);

        return [
            'job_id' => $job_id,
            'status' => PrintNodePrintQueueStore::STATUS_PRINTED,
            'print_job_id' => (string) ($result['id'] ?? ''),
            'title' => (string) ($result['title'] ?? ($job['title'] ?? '')),
        ];
    }

    /**
     * @return array{processed:int,printed:int,failed:int,idle_loops:int,waited_seconds:int,started_at:int,stopped_at:int}
     */
    public function run_worker_loop(int $idle_sleep_seconds = 1, int $max_runtime_seconds = 0, bool $verbose = false): array
    {
        $idle_sleep_seconds = max(1, min(60, $idle_sleep_seconds));
        $max_runtime_seconds = max(0, $max_runtime_seconds);
        $started = time();
        $deadline = $max_runtime_seconds > 0 ? $started + $max_runtime_seconds : 0;
        $processed = 0;
        $printed = 0;
        $failed = 0;
        $idle_loops = 0;
        $waited = 0;

        while (true) {
            if ($deadline > 0 && time() >= $deadline) {
                break;
            }

            $result = $this->process_one_due_job();
            if (is_array($result)) {
                $processed++;
                if ((string) ($result['status'] ?? '') === PrintNodePrintQueueStore::STATUS_PRINTED) {
                    $printed++;
                } else {
                    $failed++;
                }

                if ($verbose && class_exists('\WP_CLI')) {
                    \WP_CLI::log(sprintf(
                        '[%s] %s job_id=%d title=%s',
                        gmdate('Y-m-d H:i:s'),
                        (string) ($result['status'] ?? 'processed'),
                        (int) ($result['job_id'] ?? 0),
                        (string) ($result['title'] ?? ($result['error'] ?? ''))
                    ));
                }

                $delay_seconds = PrintNodeOptions::job_delay_seconds();
                if ($delay_seconds > 0) {
                    $sleep_seconds = $this->bounded_sleep_seconds($delay_seconds, $deadline);
                    if ($sleep_seconds <= 0) {
                        break;
                    }

                    sleep($sleep_seconds);
                    $waited += $sleep_seconds;
                }
                continue;
            }

            $next_available = PrintNodePrintQueueStore::next_queued_available_timestamp();
            $sleep_seconds = $idle_sleep_seconds;
            if ($next_available !== null) {
                $sleep_seconds = max(1, min($idle_sleep_seconds, $next_available - time()));
            }

            $sleep_seconds = $this->bounded_sleep_seconds($sleep_seconds, $deadline);
            if ($sleep_seconds <= 0) {
                break;
            }

            $idle_loops++;
            sleep($sleep_seconds);
            $waited += $sleep_seconds;
        }

        return [
            'processed' => $processed,
            'printed' => $printed,
            'failed' => $failed,
            'idle_loops' => $idle_loops,
            'waited_seconds' => $waited,
            'started_at' => $started,
            'stopped_at' => time(),
        ];
    }

    public function update_batch_print_status(int $batch_id, string $run_key, array $extra = []): void
    {
        if ($batch_id <= 0 || $run_key === '') {
            return;
        }

        $batch = EasyPostBatchLabelStore::get($batch_id);
        if (!is_array($batch)) {
            return;
        }

        $response = is_array($batch['response'] ?? null) ? $batch['response'] : [];
        $last = is_array($response['printnode_last_print'] ?? null) ? $response['printnode_last_print'] : [];
        if (!empty($last['run_key']) && (string) $last['run_key'] !== $run_key && empty($extra)) {
            return;
        }

        $stats = PrintNodePrintQueueStore::stats_for_run($run_key);
        $errors = [];
        foreach (PrintNodePrintQueueStore::errors_for_run($run_key, 10) as $row) {
            $message = trim((string) ($row['error_message'] ?? ''));
            if ($message !== '') {
                $errors[] = sprintf('%s: %s', (string) ($row['title'] ?? 'Print job'), $message);
            }
        }

        $printed_jobs = [];
        foreach (PrintNodePrintQueueStore::printed_jobs_for_run($run_key, 25) as $row) {
            $printed_jobs[] = [
                'print_job_id' => (string) ($row['print_job_id'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'kind' => (string) ($row['kind'] ?? ''),
                'order_id' => (int) ($row['order_id'] ?? 0),
                'order_number' => (string) ($row['order_number'] ?? ''),
                'package_index' => (int) ($row['package_index'] ?? 0),
            ];
        }

        $status = 'queued';
        if ((int) $stats['printed'] > 0 || (int) $stats['failed'] > 0) {
            $status = ((int) $stats['pending'] > 0) ? 'printing' : 'printed';
        }
        if ((int) $stats['failed'] > 0) {
            $status = ((int) $stats['pending'] > 0) ? 'printing_with_errors' : 'printed_with_errors';
        }

        $response['printnode_last_print'] = array_merge($last, $extra, [
            'run_key' => $run_key,
            'status' => $status,
            'updated_at' => current_time('mysql', true),
            'submitted_count' => (int) $stats['printed'],
            'queued_count' => (int) $stats['total'],
            'pending_count' => (int) $stats['pending'],
            'error_count' => (int) $stats['failed'],
            'jobs' => $printed_jobs,
            'errors' => array_merge((array) ($extra['queue_errors'] ?? []), $errors),
        ]);

        EasyPostBatchLabelStore::update($batch_id, [
            'response_json' => $response,
        ]);
    }

    /**
     * @return true|WP_Error
     */
    private function configured_error()
    {
        if (!PrintNodeOptions::is_enabled()) {
            return new WP_Error('fflhub_printnode_disabled', 'PrintNode printing is disabled.');
        }
        if (PrintNodeOptions::api_key() === '') {
            return new WP_Error('fflhub_printnode_missing_api_key', 'PrintNode API key is not configured.');
        }
        if (PrintNodeOptions::default_printer_id() <= 0) {
            return new WP_Error('fflhub_printnode_missing_printer', 'Choose a PrintNode printer first.');
        }

        return true;
    }

    /**
     * @param array<string,mixed> $document
     * @return string|WP_Error
     */
    private function document_body_for_printnode(array $document)
    {
        $body = (string) ($document['body'] ?? '');
        if (empty($document['force_4x6'])) {
            return $body;
        }

        $normalized = $this->pdf_service->combine_with_options([[
            'body' => $body,
            'force_4x6' => true,
        ]], sanitize_file_name((string) ($document['title'] ?? 'label') . '.pdf'));

        if (is_wp_error($normalized)) {
            return $normalized;
        }

        return (string) ($normalized['body'] ?? '');
    }

    private function bounded_sleep_seconds(int $sleep_seconds, int $deadline): int
    {
        $sleep_seconds = max(0, $sleep_seconds);
        if ($deadline <= 0) {
            return $sleep_seconds;
        }

        return max(0, min($sleep_seconds, $deadline - time()));
    }
}
