<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipOutdoors;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin WordPress HTTP wrapper for ShipOutdoors Gun Shipper endpoints.
 *
 * ShipOutdoors uses a production API key in the Authorization header. This
 * class owns auth, JSON decoding, API error shaping, and stored label downloads;
 * business rules stay in the provider and WMS services.
 */
final class ShipOutdoorsClient
{
    private const BASE_URL = 'https://api.shipoutdoors.com/gun-shipper';
    private const TIMEOUT_SEC = 45;

    private string $api_key;

    public function __construct(?string $api_key = null)
    {
        $this->api_key = trim((string) ($api_key ?? ShipOutdoorsOptions::api_key()));
    }

    public function has_api_key(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function get_all_rates(array $payload)
    {
        return $this->request('POST', '/get-all-rates', $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function submit_shipment(array $payload)
    {
        return $this->request('POST', '/submit-shipment', $payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function address_validate(array $payload)
    {
        return $this->request('POST', '/address-validate', $payload);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function void_shipment(string $shipment_id)
    {
        $shipment_id = trim(sanitize_text_field($shipment_id));
        if ($shipment_id === '') {
            return new WP_Error('fflhub_shipoutdoors_missing_void_id', 'Missing ShipOutdoors shipment ID.');
        }

        return $this->request('POST', '/void-shipment', ['shipmentId' => $shipment_id]);
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url)
    {
        $url = trim($url);
        if ($url === '') {
            return new WP_Error('fflhub_shipoutdoors_missing_label_url', 'Missing ShipOutdoors label document.');
        }

        if (strpos($url, 'shipoutdoors-label:') === 0 || strpos($url, 'data:') === 0) {
            return $this->download_embedded_label($url);
        }

        $response = wp_remote_get(esc_url_raw($url), [
            'timeout' => self::TIMEOUT_SEC,
            'redirection' => 3,
        ]);
        if (is_wp_error($response)) {
            return $this->http_error($response);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            return new WP_Error('fflhub_shipoutdoors_download_failed', 'ShipOutdoors label download failed.', ['status' => $code]);
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        return [
            'body' => $body,
            'content_type' => $content_type !== '' ? $content_type : 'application/octet-stream',
            'filename' => sanitize_file_name(basename((string) wp_parse_url($url, PHP_URL_PATH)) ?: 'shipoutdoors-label'),
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>|WP_Error
     */
    private function request(string $method, string $path, array $body)
    {
        if ($this->api_key === '') {
            return new WP_Error('fflhub_shipoutdoors_missing_api_key', 'ShipOutdoors API key is not configured.');
        }

        $response = wp_remote_request(rtrim(self::BASE_URL, '/') . '/' . ltrim($path, '/'), [
            'method' => strtoupper($method),
            'headers' => [
                'Authorization' => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => self::TIMEOUT_SEC,
            'redirection' => 3,
        ]);
        if (is_wp_error($response)) {
            return $this->http_error($response);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $request_id = (string) wp_remote_retrieve_header($response, 'x-request-id');
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = $raw_body !== '' ? json_decode($raw_body, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status < 200 || $status >= 300) {
            return $this->api_error($decoded, $status, $request_id, $raw_body);
        }

        $decoded['_fflhub_status'] = $status;
        $decoded['_fflhub_request_id'] = $request_id;

        $errors = $this->error_messages($decoded);
        if (!empty($errors)) {
            return new WP_Error(
                'fflhub_shipoutdoors_api_error',
                'ShipOutdoors API returned errors: ' . implode(' ', $errors),
                [
                    'status' => $status,
                    'request_id' => $request_id,
                    'errors' => $errors,
                    'raw_response' => substr(trim($raw_body), 0, 1000),
                ]
            );
        }

        return $decoded;
    }

    /**
     * ShipOutdoors returns label bytes as base64. Keep that opaque token inside
     * order meta, then decode it only when the admin downloads or prints.
     *
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    private function download_embedded_label(string $url)
    {
        $content_type = 'application/octet-stream';
        $encoded = '';
        if (strpos($url, 'shipoutdoors-label:') === 0) {
            $encoded = substr($url, strlen('shipoutdoors-label:'));
            $decoded_token = base64_decode($encoded, true);
            $token = $decoded_token !== false ? json_decode($decoded_token, true) : null;
            if (is_array($token)) {
                $content_type = (string) ($token['content_type'] ?? $content_type);
                $encoded = (string) ($token['body_base64'] ?? '');
            }
        } elseif (preg_match('#^data:([^;,]+);base64,(.+)$#', $url, $matches)) {
            $content_type = (string) $matches[1];
            $encoded = (string) $matches[2];
        }

        $body = base64_decode($encoded, true);
        if ($body === false || $body === '') {
            return new WP_Error('fflhub_shipoutdoors_bad_label_data', 'ShipOutdoors label data could not be decoded.');
        }

        $pdf = $this->image_label_to_pdf($body, $content_type);
        if (!is_wp_error($pdf)) {
            return $pdf;
        }

        $extension = $this->extension_from_content_type($content_type);
        return [
            'body' => $body,
            'content_type' => $content_type,
            'filename' => 'shipoutdoors-label.' . $extension,
        ];
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    private function image_label_to_pdf(string $image_body, string $content_type)
    {
        if (stripos($content_type, 'pdf') !== false || strpos(ltrim($image_body), '%PDF') === 0) {
            return [
                'body' => $image_body,
                'content_type' => 'application/pdf',
                'filename' => 'shipoutdoors-label.pdf',
            ];
        }

        if (!class_exists('\FPDF')) {
            return new WP_Error(
                'fflhub_shipoutdoors_image_pdf_unavailable',
                'ShipOutdoors label image could not be converted to PDF on this server.'
            );
        }

        $image_type = $this->fpdf_image_type($content_type, $image_body);
        if ($image_type === '') {
            return new WP_Error('fflhub_shipoutdoors_image_decode_failed', 'ShipOutdoors label image could not be decoded.');
        }

        $extension = strtolower($image_type === 'JPEG' ? 'jpg' : $image_type);
        $path = wp_tempnam('fflhub-shipoutdoors-label.' . $extension);
        if (!is_string($path) || $path === '') {
            return new WP_Error('fflhub_shipoutdoors_label_temp_failed', 'Could not create a temporary ShipOutdoors label file.');
        }

        try {
            if (file_put_contents($path, $image_body) === false) {
                return new WP_Error('fflhub_shipoutdoors_label_temp_failed', 'Could not write a temporary ShipOutdoors label file.');
            }

            $size = @getimagesize($path);
            $width_px = max(1.0, (float) ($size[0] ?? 288));
            $height_px = max(1.0, (float) ($size[1] ?? 432));

            /*
             * ShipOutdoors currently returns UPS labels as landscape GIF
             * images. A thermal 4x6 printer expects the label body to be
             * portrait on a 4x6 page, so rotate the image before wrapping it
             * as a PDF. Without this, PrintNode receives a horizontal label
             * that gets scaled and clipped by the printer driver.
             */
            if ($width_px > $height_px) {
                $rotated = $this->rotated_label_image_path($path, $image_type);
                if (!is_wp_error($rotated)) {
                    if (is_string($path) && $path !== '' && file_exists($path)) {
                        @unlink($path);
                    }

                    $path = (string) ($rotated['path'] ?? $path);
                    $image_type = (string) ($rotated['image_type'] ?? $image_type);
                    $width_px = max(1.0, (float) ($rotated['width'] ?? $width_px));
                    $height_px = max(1.0, (float) ($rotated['height'] ?? $height_px));
                }
            }

            $pdf = new \FPDF('P', 'pt', [288, 432]);
            $pdf->SetAutoPageBreak(false);
            $pdf->AddPage();

            $scale = min(288.0 / $width_px, 432.0 / $height_px);
            $draw_width = $width_px * $scale;
            $draw_height = $height_px * $scale;

            $pdf->Image($path, (288.0 - $draw_width) / 2.0, (432.0 - $draw_height) / 2.0, $draw_width, $draw_height, $image_type);

            return [
                'body' => (string) $pdf->Output('S'),
                'content_type' => 'application/pdf',
                'filename' => 'shipoutdoors-label.pdf',
            ];
        } catch (\Throwable $e) {
            return new WP_Error(
                'fflhub_shipoutdoors_image_pdf_failed',
                'ShipOutdoors label image could not be converted to PDF: ' . $e->getMessage()
            );
        } finally {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Rotate image labels into portrait and save them as PNG for reliable FPDF
     * embedding. ShipOutdoors label images are simple black-on-white carrier
     * labels, so PNG is a safe intermediate format and avoids GIF rotation
     * edge cases in FPDF.
     *
     * @return array{path:string,image_type:string,width:float,height:float}|WP_Error
     */
    private function rotated_label_image_path(string $path, string $image_type)
    {
        if (!function_exists('imagerotate') || !function_exists('imagepng')) {
            return new WP_Error(
                'fflhub_shipoutdoors_image_rotate_unavailable',
                'ShipOutdoors label image could not be rotated on this server.'
            );
        }

        $source = null;
        if ($image_type === 'GIF' && function_exists('imagecreatefromgif')) {
            $source = @imagecreatefromgif($path);
        } elseif ($image_type === 'PNG' && function_exists('imagecreatefrompng')) {
            $source = @imagecreatefrompng($path);
        } elseif ($image_type === 'JPEG' && function_exists('imagecreatefromjpeg')) {
            $source = @imagecreatefromjpeg($path);
        }

        if (!$source) {
            return new WP_Error('fflhub_shipoutdoors_image_rotate_failed', 'ShipOutdoors label image could not be opened for rotation.');
        }

        try {
            $white = imagecolorallocate($source, 255, 255, 255);
            $rotated = imagerotate($source, 90, $white);
            if (!$rotated) {
                return new WP_Error('fflhub_shipoutdoors_image_rotate_failed', 'ShipOutdoors label image could not be rotated.');
            }

            $rotated_path = wp_tempnam('fflhub-shipoutdoors-label-portrait.png');
            if (!is_string($rotated_path) || $rotated_path === '') {
                return new WP_Error('fflhub_shipoutdoors_label_temp_failed', 'Could not create a temporary rotated ShipOutdoors label file.');
            }

            if (!imagepng($rotated, $rotated_path)) {
                return new WP_Error('fflhub_shipoutdoors_label_temp_failed', 'Could not write a temporary rotated ShipOutdoors label file.');
            }

            return [
                'path' => $rotated_path,
                'image_type' => 'PNG',
                'width' => (float) imagesx($rotated),
                'height' => (float) imagesy($rotated),
            ];
        } finally {
            if (is_resource($source) || $source instanceof \GdImage) {
                imagedestroy($source);
            }
            if (isset($rotated) && (is_resource($rotated) || $rotated instanceof \GdImage)) {
                imagedestroy($rotated);
            }
        }
    }

    private function fpdf_image_type(string $content_type, string $body): string
    {
        $content_type = strtolower($content_type);
        if (strpos($content_type, 'png') !== false || strncmp($body, "\x89PNG", 4) === 0) {
            return 'PNG';
        }
        if (
            strpos($content_type, 'jpeg') !== false
            || strpos($content_type, 'jpg') !== false
            || strncmp($body, "\xFF\xD8\xFF", 3) === 0
        ) {
            return 'JPEG';
        }

        // FPDF's GIF support requires GD, so avoid claiming support when the
        // runtime cannot actually parse ShipOutdoors' image label.
        if ((strpos($content_type, 'gif') !== false || strncmp($body, 'GIF', 3) === 0) && function_exists('imagecreatefromgif')) {
            return 'GIF';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $decoded
     * @return string[]
     */
    private function error_messages(array $decoded): array
    {
        $errors = $decoded['errors'] ?? [];
        if (!is_array($errors)) {
            $errors = [$errors];
        }

        $out = [];
        foreach ($errors as $error) {
            if (is_array($error)) {
                $message = trim((string) ($error['message'] ?? $error['detail'] ?? $error['code'] ?? ''));
            } else {
                $message = trim((string) $error);
            }
            if ($message !== '') {
                $out[] = $message;
            }
        }

        return array_values(array_unique($out));
    }

    private function extension_from_content_type(string $content_type): string
    {
        $content_type = strtolower($content_type);
        if (strpos($content_type, 'gif') !== false) {
            return 'gif';
        }
        if (strpos($content_type, 'jpeg') !== false || strpos($content_type, 'jpg') !== false) {
            return 'jpg';
        }
        if (strpos($content_type, 'png') !== false) {
            return 'png';
        }
        if (strpos($content_type, 'pdf') !== false) {
            return 'pdf';
        }

        return 'bin';
    }

    private function http_error(WP_Error $error): WP_Error
    {
        return new WP_Error('fflhub_shipoutdoors_http_error', $error->get_error_message(), ['status' => 0]);
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function api_error(array $decoded, int $status, string $request_id, string $raw_body): WP_Error
    {
        $messages = $this->error_messages($decoded);
        if (trim((string) ($decoded['message'] ?? '')) !== '') {
            $messages[] = trim((string) $decoded['message']);
        }

        $message = $messages[0] ?? ('ShipOutdoors API request failed with HTTP ' . $status . '.');
        if ($request_id !== '') {
            $message .= ' Request ID: ' . $request_id . '.';
        }

        $raw_body = trim($raw_body);
        if ($raw_body !== '') {
            $message .= ' Response: ' . substr($raw_body, 0, 500);
        }

        return new WP_Error(
            'fflhub_shipoutdoors_api_error',
            $message,
            [
                'status' => $status,
                'request_id' => $request_id,
                'errors' => $messages,
                'raw_response' => substr($raw_body, 0, 1000),
            ]
        );
    }
}
