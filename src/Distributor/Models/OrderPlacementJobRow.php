<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Represents a row from the Order Placement Jobs table (fflhub_place_jobs).
 *
 * PHP 7 compatible: no enums, no typed properties beyond scalar/property types.
 */
final class OrderPlacementJobRow
{
    private const ZERO_DATE = '0000-00-00 00:00:00';

    public int $id;
    public int $order_id;

    public string $job_key;
    public string $dist_id;
    public string $bucket;
    public string $status;

    public int $attempts;

    public ?string $created_at;
    public ?string $updated_at;

    public ?int $action_id;
    public ?string $next_run_at;

    public string $last_step;
    public ?string $last_error;
    public ?string $last_codes_json;

    public ?string $done_at;

    public string $payload_json;
    public ?string $validate_result_json;
    public ?string $place_result_json;

    public ?string $merchant_po;
    public ?string $external_order_ids_json;
    public ?string $external_order_id;

    public ?string $shipped_at;
    public ?string $tracking_numbers_json;
    public ?string $invoice_numbers_json;

    public ?string $last_shipping_poll_at;
    public ?string $shipping_service;
    public ?string $shipping_weight;
    public ?string $shipment_raw_json;

    /**
     * Construct from a DB row (ARRAY_A).
     */
    public function __construct(array $row)
    {
        $this->id       = (int) ($row['id'] ?? 0);
        $this->order_id = (int) ($row['order_id'] ?? 0);

        $this->job_key = (string) ($row['job_key'] ?? '');
        $this->dist_id = (string) ($row['dist_id'] ?? '');
        $this->bucket  = (string) ($row['bucket'] ?? '');
        $this->status  = (string) ($row['status'] ?? '');

        $this->attempts = (int) ($row['attempts'] ?? 0);

        $this->created_at = self::norm_mysql_datetime($row['created_at'] ?? null);
        $this->updated_at = self::norm_mysql_datetime($row['updated_at'] ?? null);

        $this->action_id   = self::norm_nullable_int($row['action_id'] ?? null);
        $this->next_run_at = self::norm_mysql_datetime($row['next_run_at'] ?? null);

        $this->last_step = (string) ($row['last_step'] ?? '');
        $this->last_error = self::norm_nullable_string($row['last_error'] ?? null);
        $this->last_codes_json = self::norm_nullable_string($row['last_codes_json'] ?? null);

        $this->done_at = self::norm_mysql_datetime($row['done_at'] ?? null);

        $this->payload_json = (string) ($row['payload_json'] ?? '');
        $this->validate_result_json = self::norm_nullable_string($row['validate_result_json'] ?? null);
        $this->place_result_json = self::norm_nullable_string($row['place_result_json'] ?? null);

        $this->merchant_po = self::norm_nullable_string($row['merchant_po'] ?? null);
        $this->external_order_ids_json = self::norm_nullable_string($row['external_order_ids_json'] ?? null);
        $this->external_order_id = self::norm_nullable_string($row['external_order_id'] ?? null);

        $this->shipped_at = self::norm_mysql_datetime($row['shipped_at'] ?? null);
        $this->tracking_numbers_json = self::norm_nullable_string($row['tracking_numbers_json'] ?? null);
        $this->invoice_numbers_json = self::norm_nullable_string($row['invoice_numbers_json'] ?? null);

        $this->last_shipping_poll_at = self::norm_mysql_datetime($row['last_shipping_poll_at'] ?? null);
        $this->shipping_service = self::norm_nullable_string($row['shipping_service'] ?? null);
        $this->shipping_weight  = self::norm_nullable_string($row['shipping_weight'] ?? null);
        $this->shipment_raw_json = self::norm_nullable_string($row['shipment_raw_json'] ?? null);
    }

    public function is_ffl_bucket(): bool
    {
        return strtolower(trim($this->bucket)) === 'ffl';
    }

    public function has_merchant_po(): bool
    {
        return $this->merchant_po !== null && $this->merchant_po !== '';
    }

    public function payload(): array
    {
        $a = json_decode($this->payload_json, true);
        return is_array($a) ? $a : [];
    }

    public function tracking_numbers(): array
    {
        if (!$this->tracking_numbers_json) return [];
        $a = json_decode($this->tracking_numbers_json, true);
        return is_array($a) ? $a : [];
    }

    public function invoice_numbers(): array
    {
        if (!$this->invoice_numbers_json) return [];
        $a = json_decode($this->invoice_numbers_json, true);
        return is_array($a) ? $a : [];
    }

    public function external_order_ids(): array
    {
        if (!$this->external_order_ids_json) return [];
        $a = json_decode($this->external_order_ids_json, true);
        return is_array($a) ? $a : [];
    }

    private static function norm_nullable_string($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function norm_nullable_int($v): ?int
    {
        if ($v === null) return null;
        if ($v === '') return null;
        return (int) $v;
    }

    private static function norm_mysql_datetime($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        if ($s === '' || $s === self::ZERO_DATE) return null;
        return $s;
    }
}
