<?php
declare(strict_types=1);

namespace FFLHub\Shipping\EasyPost;

use EasyPost\EasyPostClient as OfficialEasyPostClient;
use EasyPost\EasyPostObject;
use EasyPost\Exception\Api\ApiException;
use EasyPost\Exception\General\EasyPostException;
use Throwable;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adapter around the official EasyPost PHP client.
 *
 * FFL Hub's shipping services already consume plain arrays and WP_Error
 * instances, so this class keeps that local contract while delegating API calls
 * to easypost/easypost-php.
 */
final class EasyPostClient
{
    private const TIMEOUT_SEC = 30;

    private string $api_key;
    private ?OfficialEasyPostClient $client = null;
    private string $last_request_id = '';
    private int $last_status = 0;

    public function __construct(?string $api_key = null)
    {
        $this->api_key = trim((string) ($api_key ?? EasyPostOptions::api_key()));
    }

    public function has_api_key(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * @param string[] $carriers
     * @param string[] $types
     * @return array<string,mixed>|WP_Error
     */
    public function carrier_metadata(array $carriers = [], array $types = [])
    {
        $carriers = array_values(array_filter(array_map('sanitize_key', $carriers)));
        $types = array_values(array_filter(array_map('sanitize_key', $types)));

        return $this->sdk_call('carrier_metadata', function (OfficialEasyPostClient $client) use ($carriers, $types): array {
            return [
                'carriers' => $client->carrierMetadata->retrieve(
                    !empty($carriers) ? $carriers : null,
                    !empty($types) ? $types : null
                ),
            ];
        });
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>|WP_Error
     */
    public function create_address(array $address)
    {
        return $this->sdk_call('create_address', fn (OfficialEasyPostClient $client) => $client->address->create($address));
    }

    /**
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>|WP_Error
     */
    public function create_shipment(array $shipment)
    {
        return $this->sdk_call('create_shipment', fn (OfficialEasyPostClient $client) => $client->shipment->create($shipment));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function retrieve_shipment(string $shipment_id)
    {
        $shipment_id = sanitize_text_field($shipment_id);
        if ($shipment_id === '') {
            return new WP_Error('fflhub_easypost_missing_shipment_id', 'Missing EasyPost shipment ID.');
        }

        return $this->sdk_call('retrieve_shipment', fn (OfficialEasyPostClient $client) => $client->shipment->retrieve($shipment_id));
    }

    /**
     * @param array<int,array<string,mixed>> $shipments
     * @return array<string,mixed>|WP_Error
     */
    public function create_batch(array $shipments, string $reference = '')
    {
        if (empty($shipments)) {
            return new WP_Error('fflhub_easypost_empty_batch', 'EasyPost batch needs at least one shipment.');
        }

        $batch = [
            'shipments' => array_values($shipments),
        ];

        $reference = sanitize_text_field($reference);
        if ($reference !== '') {
            $batch['reference'] = $reference;
        }

        return $this->sdk_call('create_batch', fn (OfficialEasyPostClient $client) => $client->batch->create($batch));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function retrieve_batch(string $batch_id)
    {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') {
            return new WP_Error('fflhub_easypost_missing_batch_id', 'Missing EasyPost batch ID.');
        }

        return $this->sdk_call('retrieve_batch', fn (OfficialEasyPostClient $client) => $client->batch->retrieve($batch_id));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function buy_batch(string $batch_id)
    {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') {
            return new WP_Error('fflhub_easypost_missing_batch_id', 'Missing EasyPost batch ID.');
        }

        return $this->sdk_call('buy_batch', fn (OfficialEasyPostClient $client) => $client->batch->buy($batch_id));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function create_batch_label(string $batch_id, string $file_format = 'PDF')
    {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') {
            return new WP_Error('fflhub_easypost_missing_batch_id', 'Missing EasyPost batch ID.');
        }

        $format = strtoupper(sanitize_text_field($file_format));
        if (!in_array($format, ['PDF', 'ZPL', 'EPL2'], true)) {
            $format = 'PDF';
        }

        return $this->sdk_call('create_batch_label', fn (OfficialEasyPostClient $client) => $client->batch->label($batch_id, [
            'file_format' => $format,
        ]));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|WP_Error
     */
    public function buy_shipment(string $shipment_id, string $rate_id, array $options = [], string $insurance = '')
    {
        $shipment_id = sanitize_text_field($shipment_id);
        $rate_id = sanitize_text_field($rate_id);
        if ($shipment_id === '' || $rate_id === '') {
            return new WP_Error('fflhub_easypost_missing_buy_fields', 'Missing EasyPost shipment or rate ID.');
        }

        $payload = [
            'rate' => [
                'id' => $rate_id,
            ],
        ];

        if (!empty($options)) {
            $payload['options'] = $options;
        }

        $insurance = trim($insurance);
        if ($insurance !== '') {
            $payload['insurance'] = $insurance;
        }

        return $this->sdk_call('buy_shipment', fn (OfficialEasyPostClient $client) => $client->shipment->buy($shipment_id, $payload));
    }

    /**
     * @param string[] $tracking_codes
     * @return array<string,mixed>|WP_Error
     */
    public function refund_tracking_codes(string $carrier, array $tracking_codes)
    {
        $carrier = sanitize_text_field($carrier);
        $codes = [];
        foreach ($tracking_codes as $tracking_code) {
            $tracking_code = sanitize_text_field((string) $tracking_code);
            if ($tracking_code !== '') {
                $codes[] = $tracking_code;
            }
        }

        if ($carrier === '' || empty($codes)) {
            return new WP_Error('fflhub_easypost_missing_refund_fields', 'Missing EasyPost carrier or tracking code.');
        }

        return $this->sdk_call('refund_tracking_codes', fn (OfficialEasyPostClient $client) => $client->refund->create([
            'carrier' => $carrier,
            'tracking_codes' => $codes,
        ]));
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url)
    {
        $url = esc_url_raw($url);
        if ($url === '') {
            return new WP_Error('fflhub_easypost_missing_label_url', 'Missing EasyPost label URL.');
        }

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT_SEC,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return $this->http_error($response);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            return new WP_Error(
                'fflhub_easypost_download_failed',
                'EasyPost label download failed.',
                ['status' => $code]
            );
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if ($content_type === '') {
            $content_type = 'application/octet-stream';
        }

        $filename = basename((string) wp_parse_url($url, PHP_URL_PATH));
        if ($filename === '' || $filename === '/') {
            $filename = 'easypost-label';
        }

        return [
            'body' => $body,
            'content_type' => $content_type,
            'filename' => sanitize_file_name($filename),
        ];
    }

    /**
     * @return OfficialEasyPostClient|WP_Error
     */
    private function sdk()
    {
        if ($this->api_key === '') {
            return new WP_Error('fflhub_easypost_missing_api_key', 'EasyPost API key is not configured.');
        }

        if ($this->client instanceof OfficialEasyPostClient) {
            return $this->client;
        }

        $this->client = new OfficialEasyPostClient($this->api_key, self::TIMEOUT_SEC);
        $this->client->subscribeToResponseHook(function (array $args): void {
            $this->capture_response_context($args);
        });

        return $this->client;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function sdk_call(string $operation, callable $callback)
    {
        $client = $this->sdk();
        if (is_wp_error($client)) {
            return $client;
        }

        $this->last_request_id = '';
        $this->last_status = 0;

        try {
            $result = $callback($client);
            $normalized = $this->normalize_sdk_value($result);
            if (!is_array($normalized)) {
                $normalized = ['result' => $normalized];
            }

            $this->attach_response_context($normalized);
            return $normalized;
        } catch (ApiException $exception) {
            return $this->api_exception($exception);
        } catch (EasyPostException $exception) {
            return new WP_Error(
                'fflhub_easypost_sdk_error',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'EasyPost SDK request failed.',
                [
                    'operation' => $operation,
                    'status' => $this->last_status,
                    'request_id' => $this->last_request_id,
                ]
            );
        } catch (Throwable $exception) {
            return new WP_Error(
                'fflhub_easypost_sdk_error',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'EasyPost SDK request failed.',
                [
                    'operation' => $operation,
                    'status' => $this->last_status,
                    'request_id' => $this->last_request_id,
                ]
            );
        }
    }

    /**
     * @param array<string,mixed> $args
     */
    private function capture_response_context(array $args): void
    {
        $this->last_status = max(0, (int) ($args['http_status'] ?? 0));
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : [];

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'x-request-id') {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            $this->last_request_id = trim((string) $value);
            break;
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    private function attach_response_context(array &$value): void
    {
        if ($this->last_status > 0) {
            $value['_fflhub_status'] = $this->last_status;
        }

        if ($this->last_request_id !== '') {
            $value['_fflhub_request_id'] = $this->last_request_id;
        }
    }

    private function normalize_sdk_value(mixed $value): mixed
    {
        if ($value instanceof EasyPostObject) {
            return $value->__toArray(true);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $entry) {
                $normalized[$key] = $this->normalize_sdk_value($entry);
            }

            return $normalized;
        }

        return $value;
    }

    private function http_error(WP_Error $error): WP_Error
    {
        return new WP_Error(
            'fflhub_easypost_http_error',
            $error->get_error_message(),
            ['status' => 0]
        );
    }

    private function api_exception(ApiException $exception): WP_Error
    {
        $status = (int) ($exception->getHttpStatus() ?? $this->last_status);
        $request_id = $this->last_request_id;
        $raw_body = trim((string) ($exception->getHttpBody() ?? ''));
        $errors = is_array($exception->errors ?? null) ? $exception->errors : [];

        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = $status > 0
                ? 'EasyPost API request failed with HTTP ' . $status . '.'
                : 'EasyPost API request failed.';
        }
        if ($request_id !== '') {
            $message .= ' Request ID: ' . $request_id . '.';
        }
        if ($raw_body !== '' && empty($errors)) {
            $message .= ' Response: ' . substr($raw_body, 0, 500);
        }

        return new WP_Error(
            'fflhub_easypost_api_error',
            $message,
            [
                'status' => $status,
                'request_id' => $request_id,
                'errors' => $errors,
                'raw_response' => substr($raw_body, 0, 1000),
                'code' => (string) ($exception->code ?? ''),
            ]
        );
    }
}
