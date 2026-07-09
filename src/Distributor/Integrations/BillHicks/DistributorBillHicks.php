<?php

namespace FFLHub\Distributor\Integrations\BillHicks;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Models\DistributorShipment;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\BillHicks\BillHicksEdiFtpExchange;
use FFLHub\Distributor\Services\BillHicks\BillHicksEdiStore;
use FFLHub\Distributor\Services\BillHicks\BillHicksServices;

/**
 * Bill Hicks runtime distributor.
 *
 * Product lookup is backed by the local double-buffered Bill Hicks catalog
 * table. Ordering and shipment polling stay inert until the Bill Hicks order
 * API/workflow is implemented.
 */
final class DistributorBillHicks extends DistributorBase
{
    protected function supports_remote_validation(): bool
    {
        return false;
    }

    /**
     * @param array<string,int> $required_by_upc
     * @return array<string,mixed>
     */
    protected function validation_local_options(
        DistributorOrderRequest $request,
        array $required_by_upc,
        bool $local_only
    ): array {
        $lane = $this->infer_lane_and_ffl_enforcement($request);

        return [
            'label' => 'Bill Hicks validation (local)',
            'max_unique' => 100,
            'inventory_keys' => ['inventory_quantity'],
            'unknown_qty_blocks' => true,
            'code_prefix' => 'BILL_HICKS',
            'lane' => $lane['lane'],
            'enforce_ffl_required' => $lane['enforce_ffl_required'],
            'ffl_required_row_keys' => ['ffl_required'],
        ];
    }

    protected function validation_precheck_invariants(DistributorOrderRequest $request, bool $local_only): ?DistributorOrderValidationResult
    {
        if (strtolower(trim((string) ($request->lane ?? ''))) === 'dealer_fulfilled') {
            return null;
        }

        return $this->require_ffl_shipto_if_ffl_lines($request, 'BILL_HICKS');
    }

    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, true);
    }

    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        return $this->build_payload_from_local_row($upc, false);
    }

    /**
     * @param array<int,string> $upcs
     * @return array<string,DistributorProductPayload>
     */
    public function get_pricing_payloads_by_upcs(array $upcs): array
    {
        return $this->get_local_pricing_payloads_by_upcs(
            $upcs,
            self::payload_field_map(),
            static fn($raw_category): ?array => DistributorProductCategoryMapper::map_sports_south((string) $raw_category),
            true,
            static function (DistributorProductPayload $payload, array $row, string $normalized_upc): DistributorProductPayload {
                return self::prefer_bill_hicks_catalog_name($payload, $row);
            }
        );
    }

    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $rows = $this->get_fulfillment_rows_by_upcs([$normalized], true);
        $row = $rows[$normalized] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return $this->get_shipping_cost_from_row($row, $normalized);
    }

    protected function get_shipping_cost_from_row(array $row, string $normalized_upc): ?float
    {
        $raw = trim((string) ($row['shipping_cost'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }

        return max(0.0, (float) $raw);
    }

    protected function get_true_cost_by_distributor_cost_shipping_cost(float $distributor_cost, float $shipping_cost): ?float
    {
        return max(0.0, $distributor_cost) + max(0.0, $shipping_cost);
    }

    /**
     * @param array<string,mixed> $row
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $url = trim((string) ($row['image_url'] ?? ''));
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    public function place_order(DistributorOrderRequest $request): DistributorOrderResult
    {
        if (!($this->services instanceof BillHicksServices)) {
            return DistributorOrderResult::block_fatal(
                'Bill Hicks services not available; cannot build EDI 850 order file.',
                [DistributorOrderResult::REASON_FATAL_SERVICES_MISSING]
            );
        }

        $lane = strtolower(trim((string) $request->lane));
        $lines = $request->valid_lines();
        $builder = $this->services->get_edi_order_file_builder();
        $built = $builder->build_order_file($request, $lane, $lines);

        if (empty($built['ok'])) {
            return DistributorOrderResult::block_fatal(
                'Bill Hicks EDI 850 build failed: ' . implode('; ', (array) ($built['errors'] ?? [])),
                [DistributorOrderResult::REASON_FATAL_BAD_REQUEST, DistributorOrderResult::REASON_FATAL_MAPPING],
                [
                    'po' => (string) ($built['po_number'] ?? $request->merchant_order_id),
                    'errors' => (array) ($built['errors'] ?? []),
                    'line_count' => (int) ($built['line_count'] ?? 0),
                    'lane' => $lane,
                ]
            );
        }

        $uploads = wp_upload_dir();
        $local_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . 'fflhub-bill-hicks-edi/outbound';
        $write = $builder->write_content_to_file(
            (string) ($built['content'] ?? ''),
            $local_dir,
            (string) ($built['filename'] ?? '')
        );

        if (empty($write['ok'])) {
            return DistributorOrderResult::block_retryable(
                'Bill Hicks EDI 850 local write failed: ' . (string) ($write['error'] ?? ''),
                [DistributorOrderResult::REASON_RETRY_UNKNOWN],
                [
                    'po' => (string) ($built['po_number'] ?? ''),
                    'filename' => (string) ($built['filename'] ?? ''),
                    'local_dir' => $local_dir,
                ]
            );
        }

        $upload = (new BillHicksEdiFtpExchange())->upload_order_file(
            (string) ($write['path'] ?? ''),
            (string) ($built['filename'] ?? '')
        );

        $this->append_edi_upload_log($local_dir, $built, $write, $upload, $lane, !empty($upload['ok']));

        if (empty($upload['ok'])) {
            $error = (string) ($upload['error'] ?? 'Bill Hicks EDI FTP upload failed.');
            $details = [
                'po' => (string) ($built['po_number'] ?? ''),
                'filename' => (string) ($built['filename'] ?? ''),
                'local_path' => (string) ($write['path'] ?? ''),
                'remote_path' => (string) ($upload['remote_path'] ?? ''),
                'error' => $error,
            ];

            if (stripos($error, 'credential') !== false) {
                return DistributorOrderResult::block_fatal(
                    $error,
                    [DistributorOrderResult::REASON_FATAL_MISSING_CREDS],
                    $details
                );
            }

            return DistributorOrderResult::block_retryable(
                $error,
                [DistributorOrderResult::REASON_RETRY_UPSTREAM],
                $details
            );
        }

        return DistributorOrderResult::submitted(
            'Bill Hicks 850 uploaded; awaiting 855 acknowledgement.',
            [],
            [
                'po' => (string) ($built['po_number'] ?? ''),
                'filename' => (string) ($built['filename'] ?? ''),
                'local_path' => (string) ($write['path'] ?? ''),
                'remote_path' => (string) ($upload['remote_path'] ?? ''),
                'line_count' => (int) ($built['line_count'] ?? 0),
                'destination' => (string) ($built['destination'] ?? ''),
                'ship_method' => (string) ($built['ship_method'] ?? ''),
                'lane' => $lane,
            ]
        );
    }

    public function get_shipment_by_po(string $po_number): ?DistributorShipment
    {
        return (new BillHicksEdiStore())->get_shipment_by_po($po_number);
    }

    private function build_payload_from_local_row(string $upc, bool $include_images): ?DistributorProductPayload
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }

        $rows = $this->get_fulfillment_rows_by_upcs([$normalized], true);
        $row = $rows[$normalized] ?? null;
        if (!is_array($row)) {
            return null;
        }

        $payload = $this->build_payload_from_row(
            $row,
            self::payload_field_map(),
            static fn($raw_category): ?array => DistributorProductCategoryMapper::map_sports_south((string) $raw_category),
            $normalized,
            $include_images
        );

        return self::prefer_bill_hicks_catalog_name($payload, $row);
    }

    /**
     * Bill Hicks provides a short product name and a longer product
     * description. Keep them separate so lookup/product creation does not turn
     * the long description into the storefront title.
     *
     * @param array<string,mixed> $row
     */
    private static function prefer_bill_hicks_catalog_name(DistributorProductPayload $payload, array $row): DistributorProductPayload
    {
        $name = trim((string) ($row['product_name'] ?? ''));
        if ($name !== '') {
            $payload->name = $name;
        }

        $description = trim((string) ($row['product_description'] ?? ''));
        if ($description !== '') {
            $payload->description = $description;
        }

        return $payload;
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function payload_field_map(): array
    {
        return [
            'sku' => ['bill_hicks_item_number'],
            'upc' => ['upc'],
            'name' => ['product_name'],
            'description' => ['product_description'],
            'brand' => ['manufacturer'],
            'price' => ['distributor_price'],
            'map' => ['retail_map'],
            'msrp' => ['retail_msrp'],
            'quantity' => ['inventory_quantity'],
            'category' => ['item_type'],
            'image' => ['image_url'],
            'shipping_weight' => ['shipping_weight'],
            'shipping_length_in' => ['shipping_length'],
            'shipping_width_in' => ['shipping_width'],
            'shipping_height_in' => ['shipping_height'],
            'ffl_required' => ['ffl_required'],
            'sot_required' => ['sot_required'],
            'dropship_enabled' => ['dropship_enabled'],
        ];
    }

    /**
     * Keep a simple append-only paper trail beside the local 850 copies.
     *
     * BHC removes uploaded files after ingesting them, so this log gives us a
     * local timestamped record of what file we attempted to upload and where.
     *
     * @param array<string,mixed> $built
     * @param array<string,mixed> $write
     * @param array<string,mixed> $upload
     */
    private function append_edi_upload_log(
        string $local_dir,
        array $built,
        array $write,
        array $upload,
        string $lane,
        bool $ok
    ): void {
        $local_dir = rtrim(trim($local_dir), "/\\");
        if ($local_dir === '') {
            return;
        }

        if (function_exists('wp_mkdir_p') && !wp_mkdir_p($local_dir)) {
            return;
        }

        $line = implode("\t", [
            'utc=' . gmdate('Y-m-d H:i:s'),
            'site=' . $this->site_time_for_log(),
            'status=' . ($ok ? 'uploaded' : 'failed'),
            'po=' . $this->log_field((string) ($built['po_number'] ?? '')),
            'filename=' . $this->log_field((string) ($built['filename'] ?? '')),
            'lane=' . $this->log_field($lane),
            'ship_method=' . $this->log_field((string) ($built['ship_method'] ?? '')),
            'line_count=' . (string) (int) ($built['line_count'] ?? 0),
            'local_path=' . $this->log_field((string) ($write['path'] ?? '')),
            'remote_path=' . $this->log_field((string) ($upload['remote_path'] ?? '')),
            'error=' . $this->log_field((string) ($upload['error'] ?? '')),
        ]);

        $log_path = $local_dir . DIRECTORY_SEPARATOR . 'upload-log.txt';
        if ((file_exists($log_path) && !is_writable($log_path)) || (!file_exists($log_path) && !is_writable($local_dir))) {
            return;
        }

        file_put_contents($log_path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function site_time_for_log(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return date('Y-m-d H:i:s');
    }

    private function log_field(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\t\r\n]+/', ' ', $value);
        $value = is_string($value) ? $value : '';
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? trim($value) : '';
    }
}
