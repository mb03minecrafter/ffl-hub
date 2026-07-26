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
 * Sends saved EasyPost batch labels/slips to PrintNode without stitching PDFs.
 */
final class PrintNodeBatchPrintService
{
    private PrintNodeClient $client;
    private EasyPostBatchLabelService $batch_service;
    private PdfDocumentService $pdf_service;

    public function __construct(
        ?PrintNodeClient $client = null,
        ?EasyPostBatchLabelService $batch_service = null,
        ?PdfDocumentService $pdf_service = null
    )
    {
        $this->client = $client ?? new PrintNodeClient();
        $this->batch_service = $batch_service ?? new EasyPostBatchLabelService();
        $this->pdf_service = $pdf_service ?? new PdfDocumentService();
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function print_easypost_batch(int $batch_id)
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

        $documents = $this->batch_service->print_documents($batch_id);
        if (is_wp_error($documents)) {
            return $documents;
        }

        $submitted = [];
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

            $result = $this->client->print_pdf([
                'printer_id' => PrintNodeOptions::default_printer_id(),
                'title' => (string) ($document['title'] ?? 'FFL Hub Shipping Document'),
                'body' => $body,
            ]);
            if (is_wp_error($result)) {
                $errors[] = (string) ($document['title'] ?? 'Document') . ': ' . $result->get_error_message();
                continue;
            }

            $submitted[] = [
                'print_job_id' => (string) ($result['id'] ?? ''),
                'title' => (string) ($result['title'] ?? ($document['title'] ?? '')),
                'kind' => (string) ($document['kind'] ?? ''),
                'order_id' => (int) ($document['order_id'] ?? 0),
                'order_number' => (string) ($document['order_number'] ?? ''),
                'package_index' => (int) ($document['package_index'] ?? 0),
            ];
        }

        $batch = EasyPostBatchLabelStore::get($batch_id);
        if (is_array($batch)) {
            $response = is_array($batch['response'] ?? null) ? $batch['response'] : [];
            $response['printnode_last_print'] = [
                'printer_id' => PrintNodeOptions::default_printer_id(),
                'submitted_at' => current_time('mysql', true),
                'submitted_count' => count($submitted),
                'error_count' => count($errors),
                'jobs' => $submitted,
                'errors' => $errors,
            ];
            EasyPostBatchLabelStore::update($batch_id, [
                'response_json' => $response,
            ]);
            $batch = EasyPostBatchLabelStore::get($batch_id);
        }

        if (empty($submitted)) {
            return new WP_Error(
                'fflhub_printnode_no_jobs_submitted',
                'No PrintNode jobs were submitted.',
                ['errors' => $errors]
            );
        }

        return [
            'batch' => is_array($batch) ? $batch : EasyPostBatchLabelStore::get($batch_id),
            'printer_id' => PrintNodeOptions::default_printer_id(),
            'jobs_submitted' => count($submitted),
            'print_jobs' => $submitted,
            'errors' => $errors,
        ];
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
}
