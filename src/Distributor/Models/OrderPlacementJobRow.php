<?php

namespace FFLHub\Distributor\Models;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Represents a row from the Order Placement Jobs table (fflhub_place_jobs).
 */
final class OrderPlacementJobRow
{
    private const ZERO_DATE = '0000-00-00 00:00:00';

    public int $id;
    public int $order_id;

    public string $job_key;
    public string $dist_id;
    public string $lane;
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

    /** @var array<string,mixed>|null */
    private ?array $payload_cache = null;

    /**
     * Construct from a DB row (ARRAY_A).
     *
     * @param array<string,mixed> $row
     */
    public function __construct(array $row)
    {
        $this->id       = (int) ($row['id'] ?? 0);
        $this->order_id = (int) ($row['order_id'] ?? 0);

        $this->job_key = (string) ($row['job_key'] ?? '');
        $this->dist_id = (string) ($row['dist_id'] ?? '');
        $this->lane    = (string) ($row['lane'] ?? '');
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

    /* ===================== Convenience booleans ===================== */

    public function is_direct_ship_ffl_lane(): bool
    {
        return OrderPlacementKeysUtil::is_direct_ship_ffl_lane($this->lane_norm());
    }

    public function is_direct_ship_non_ffl_lane(): bool
    {
        return OrderPlacementKeysUtil::is_direct_ship_non_ffl_lane($this->lane_norm());
    }

    public function has_merchant_po(): bool
    {
        return $this->merchant_po !== null && $this->merchant_po !== '';
    }

    public function has_external_order_id(): bool
    {
        return $this->external_order_id !== null && $this->external_order_id !== '';
    }

    public function has_tracking(): bool
    {
        return !empty($this->tracking_numbers());
    }

    public function is_shipped(): bool
    {
        return $this->has_tracking() || $this->has_shipped_at();
    }

    public function primary_tracking(): string
    {
        $t = $this->tracking_numbers();
        return !empty($t) ? (string) $t[0] : '';
    }

    /* ===================== Payload helpers ===================== */

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        if ($this->payload_cache !== null) {
            return $this->payload_cache;
        }

        $a = json_decode($this->payload_json, true);
        $this->payload_cache = is_array($a) ? $a : [];

        return $this->payload_cache;
    }

    public function payload_dist_id(): string
    {
        $p = $this->payload();
        $v = isset($p['dist_id']) ? strtolower(trim((string) $p['dist_id'])) : '';
        return $v !== '' ? $v : strtolower(trim((string) $this->dist_id));
    }

    public function payload_lane(): string
    {
        $p = $this->payload();
        $v = isset($p['lane']) ? strtolower(trim((string) $p['lane'])) : '';
        return $v !== '' ? $v : strtolower(trim((string) $this->lane));
    }

    /**
     * Convert payload['lines'] into DistributorOrderLine[].
     *
     * @return DistributorOrderLine[]
     */
    public function payload_lines(): array
    {
        $p = $this->payload();
        $lane = $this->payload_lane();
        $default_ffl_required = OrderPlacementKeysUtil::is_direct_ship_ffl_lane($lane);

        $lines = $p['lines'] ?? [];
        if (!is_array($lines) || empty($lines)) {
            return [];
        }

        $out = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $upc_raw = trim((string) ($line['upc'] ?? ''));
            $upc = self::digits_only($upc_raw);
            if ($upc === '') {
                continue;
            }

            // Be resilient to different quantity keys
            $qty =
                isset($line['qty'])      ? (int) $line['qty'] :
                (isset($line['quantity']) ? (int) $line['quantity'] : 0);

            $qty = max(1, $qty);

            $ffl_required = $default_ffl_required;
            if (array_key_exists('ffl_required', $line)) {
                $flag = $line['ffl_required'];
                if (is_bool($flag)) {
                    $ffl_required = $flag;
                } elseif (is_string($flag)) {
                    $raw = strtolower(trim($flag));
                    if (in_array($raw, ['1', 'true', 'yes', 'y', 'on'], true)) {
                        $ffl_required = true;
                    } elseif (in_array($raw, ['0', 'false', 'no', 'n', 'off', ''], true)) {
                        $ffl_required = false;
                    } else {
                        $ffl_required = ((int) $raw) === 1;
                    }
                } else {
                    $ffl_required = ((int) $flag) === 1;
                }
            }

            $out[] = new DistributorOrderLine($upc, $qty, (bool) $ffl_required);
        }

        return $out;
    }

    private static function digits_only(string $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && ctype_digit($value)) {
            return $value;
        }
        $v = preg_replace('/\D+/', '', $value);
        return is_string($v) ? $v : '';
    }

    /* ===================== JSON list fields ===================== */

    /** @return string[] */
    public function tracking_numbers(): array
    {
        return self::decode_string_list_json($this->tracking_numbers_json);
    }

    /** @return string[] */
    public function invoice_numbers(): array
    {
        return self::decode_string_list_json($this->invoice_numbers_json);
    }

    /** @return string[] */
    public function external_order_ids(): array
    {
        return self::decode_string_list_json($this->external_order_ids_json);
    }

    /** @return string[] */
    public function last_codes(): array
    {
        return self::decode_string_list_json($this->last_codes_json);
    }

    /* ===================== JSON blobs ===================== */

    public function shipment_raw(): ?array
    {
        if (!$this->shipment_raw_json) {
            return null;
        }
        $a = json_decode($this->shipment_raw_json, true);
        return is_array($a) ? $a : null;
    }

    public function validate_snapshot(): ?array
    {
        if (!$this->validate_result_json) {
            return null;
        }
        $a = json_decode($this->validate_result_json, true);
        return is_array($a) ? $a : null;
    }

    public function place_snapshot(): ?array
    {
        if (!$this->place_result_json) {
            return null;
        }
        $a = json_decode($this->place_result_json, true);
        return is_array($a) ? $a : null;
    }

    /* ===================== Internal helpers ===================== */

    /** @return string[] */
    private static function decode_string_list_json(?string $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $a = json_decode($json, true);
        if (!is_array($a)) {
            return [];
        }

        $out = [];
        foreach ($a as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        // unique + preserve order
        $set = [];
        $uniq = [];
        foreach ($out as $s) {
            if (isset($set[$s])) {
                continue;
            }
            $set[$s] = true;
            $uniq[] = $s;
        }

        return $uniq;
    }

    private static function norm_nullable_string($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function norm_nullable_int($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $i = (int) $v;
        return $i > 0 ? $i : null;
    }

    private static function norm_mysql_datetime($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '' || $s === self::ZERO_DATE) {
            return null;
        }
        return $s;
    }

    /* ===================== Normalized accessors ===================== */

    public function job_key_norm(): string
    {
        return strtolower(trim((string) $this->job_key));
    }

    public function dist_id_norm(): string
    {
        $v = strtolower(trim((string) $this->dist_id));
        return $v !== '' ? $v : $this->payload_dist_id();
    }

    public function lane_norm(): string
    {
        $v = strtolower(trim((string) $this->lane));
        return $v !== '' ? $v : $this->payload_lane();
    }

    public function merchant_po_or_empty(): string
    {
        return trim((string) ($this->merchant_po ?? ''));
    }

    public function external_order_id_or_empty(): string
    {
        return trim((string) ($this->external_order_id ?? ''));
    }

    public function shipping_service_or_empty(): string
    {
        return trim((string) ($this->shipping_service ?? ''));
    }

    public function shipping_weight_or_empty(): string
    {
        return trim((string) ($this->shipping_weight ?? ''));
    }

    public function has_shipped_at(): bool
    {
        return $this->shipped_at !== null && $this->shipped_at !== '';
    }

    public function shipped_at_or_empty(): string
    {
        return (string) ($this->shipped_at ?? '');
    }

    public function ffl_required(): bool
    {
        foreach ($this->payload_lines() as $line) {
            if ($line instanceof DistributorOrderLine && $line->ffl_required) {
                return true;
            }
        }

        return OrderPlacementKeysUtil::is_direct_ship_ffl_lane($this->payload_lane());
    }

    public function payload_lines_count(): int
    {
        return count($this->payload_lines());
    }

    /**
     * Build a small, stable context payload for snapshots/logging.
     *
     * @param int|null $attempt_n
     * @return array<string,mixed>
     */
    public function ctx(?int $attempt_n = null): array
    {
        $attempt = ($attempt_n === null) ? (int) $this->attempts : (int) $attempt_n;

        return [
            'order_id' => (int) $this->order_id,
            'job_id'   => (int) $this->id,
            'job_key'  => (string) $this->job_key_norm(),
            'dist_id'  => (string) $this->dist_id_norm(),
            'lane'     => (string) $this->lane_norm(),
            'lines'    => (int) $this->payload_lines_count(),
            'attempt'  => $attempt,
        ];
    }
}
