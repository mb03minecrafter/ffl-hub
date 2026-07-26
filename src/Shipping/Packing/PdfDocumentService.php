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
    private const FOUR_BY_SIX_WIDTH_PT = 288.0;
    private const FOUR_BY_SIX_HEIGHT_PT = 432.0;

    /**
     * @param string[] $pdf_documents
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function combine(array $pdf_documents, string $filename)
    {
        return $this->combine_with_options($pdf_documents, $filename);
    }

    /**
     * @param array<int,string|array{body:string,force_4x6?:bool}> $pdf_documents
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function combine_with_options(array $pdf_documents, string $filename)
    {
        if (!class_exists(Fpdi::class)) {
            return new WP_Error(
                'fflhub_pdf_merge_missing_library',
                'FPDI is not installed. Run composer install for setasign/fpdi.'
            );
        }

        $paths = [];
        try {
            foreach ($pdf_documents as $index => $document) {
                $body = is_array($document) ? (string) ($document['body'] ?? '') : (string) $document;
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

                $paths[] = [
                    'path' => $path,
                    'force_4x6' => is_array($document) && !empty($document['force_4x6']),
                ];
            }

            $pdf = new Fpdi('P', 'pt');
            $pdf->SetAutoPageBreak(false);

            foreach ($paths as $document) {
                $path = (string) ($document['path'] ?? '');
                if ($path === '') {
                    continue;
                }

                $page_count = $pdf->setSourceFile($path);
                for ($page_number = 1; $page_number <= $page_count; $page_number++) {
                    $template_id = $pdf->importPage($page_number);
                    $size = $pdf->getTemplateSize($template_id);
                    $width = max(1.0, (float) ($size['width'] ?? 288));
                    $height = max(1.0, (float) ($size['height'] ?? 432));

                    if (!empty($document['force_4x6'])) {
                        $this->add_four_by_six_page($pdf, $template_id, $width, $height);
                        continue;
                    }

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
            foreach ($paths as $document) {
                $path = is_array($document) ? (string) ($document['path'] ?? '') : (string) $document;
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function add_four_by_six_page(Fpdi $pdf, $template_id, float $source_width, float $source_height): void
    {
        $pdf->AddPage('P', [self::FOUR_BY_SIX_WIDTH_PT, self::FOUR_BY_SIX_HEIGHT_PT]);

        if ($source_width > 360.0 || $source_height > 540.0) {
            /*
             * FedEx can return PDF labels on letter-sized pages even when the
             * shipment requested 4x6 output. The actual label is positioned in
             * the top-left region, so we crop to a 4x6 page and lightly scale
             * the source to keep the right edge and bottom barcode visible.
             */
            $scale = 0.92;
            $pdf->useTemplate(
                $template_id,
                -12.0,
                -12.0,
                $source_width * $scale,
                $source_height * $scale,
                false
            );

            return;
        }

        $scale = min(
            self::FOUR_BY_SIX_WIDTH_PT / $source_width,
            self::FOUR_BY_SIX_HEIGHT_PT / $source_height
        );
        $draw_width = $source_width * $scale;
        $draw_height = $source_height * $scale;

        $pdf->useTemplate(
            $template_id,
            (self::FOUR_BY_SIX_WIDTH_PT - $draw_width) / 2.0,
            (self::FOUR_BY_SIX_HEIGHT_PT - $draw_height) / 2.0,
            $draw_width,
            $draw_height,
            false
        );
    }
}
