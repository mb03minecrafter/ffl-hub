<?php
declare(strict_types=1);

namespace FFLHub\Shipping\Packing;

use setasign\Fpdi\Fpdi;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small PDF utility for admin shipping documents.
 *
 * Carrier labels already arrive as PDF bytes when the shared label format is
 * PDF. PackingSlipService draws our own 4x6 packing slip PDF, and this class
 * stitches those documents into one print-ready PDF without changing provider
 * purchase behavior.
 */
final class PdfDocumentService
{
    /**
     * @param string[] $pdf_documents
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function combine(array $pdf_documents, string $filename)
    {
        if (!class_exists(Fpdi::class)) {
            return new WP_Error(
                'fflhub_pdf_merge_missing_library',
                'FPDI is not installed. Run composer install for setasign/fpdi.'
            );
        }

        $paths = [];
        try {
            foreach ($pdf_documents as $index => $body) {
                $body = (string) $body;
                if (trim($body) === '' || strpos(ltrim($body), '%PDF') !== 0) {
                    return new WP_Error(
                        'fflhub_pdf_merge_invalid_document',
                        'One of the documents did not look like a PDF.'
                    );
                }

                $path = wp_tempnam('fflhub-shipping-document-' . (string) $index . '.pdf');
                if (!is_string($path) || $path === '') {
                    return new WP_Error('fflhub_pdf_merge_temp_failed', 'Could not create a temporary PDF file.');
                }

                if (file_put_contents($path, $body) === false) {
                    return new WP_Error('fflhub_pdf_merge_write_failed', 'Could not write a temporary PDF file.');
                }

                $paths[] = $path;
            }

            $pdf = new Fpdi('P', 'pt');
            $pdf->SetAutoPageBreak(false);

            foreach ($paths as $path) {
                $page_count = $pdf->setSourceFile($path);
                for ($page_number = 1; $page_number <= $page_count; $page_number++) {
                    $template_id = $pdf->importPage($page_number);
                    $size = $pdf->getTemplateSize($template_id);
                    $width = max(1.0, (float) ($size['width'] ?? 288));
                    $height = max(1.0, (float) ($size['height'] ?? 432));

                    $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
                    $pdf->useTemplate($template_id, 0, 0, $width, $height, true);
                }
            }

            return [
                'body' => (string) $pdf->Output('S'),
                'content_type' => 'application/pdf',
                'filename' => sanitize_file_name($filename !== '' ? $filename : 'shipping-documents.pdf'),
            ];
        } catch (\Throwable $e) {
            return new WP_Error(
                'fflhub_pdf_merge_failed',
                'Could not combine the shipping PDFs: ' . $e->getMessage()
            );
        } finally {
            foreach ($paths as $path) {
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }
}
