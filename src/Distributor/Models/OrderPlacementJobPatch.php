<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Represents a safe, partial update to an OrderPlacementJobRow,
 * plus semantic results callers act on (e.g. ShippingUpdateResult).
 *
 * Keeps "what to write" separate from "what happened".
 */
final class OrderPlacementJobPatch
{
    /** @var array<string,mixed> */
    private array $write;

    private ?ShippingUpdateResult $shipping_result;

    /**
     * Allowlist of columns that may be written via patch.
     * (Keep this in sync with OrderPlacementJobsStore::update_job_fields allowed list.)
     *
     * @var string[]
     */
    private const ALLOWED = [
        'dist_id',
        'bucket',
        'status',
        'attempts',
        'action_id',
        'next_run_at',
        'last_step',
        'last_error',
        'last_codes_json',
        'done_at',
        'payload_json',
        'validate_result_json',
        'place_result_json',
        'merchant_po',
        'external_order_ids_json',
        'external_order_id',

        // shipping fields
        'shipped_at',
        'tracking_numbers_json',
        'invoice_numbers_json',
        'shipping_service',
        'shipping_weight',
        'shipment_raw_json',
        'last_shipping_poll_at',

        // housekeeping
        'updated_at',
    ];

    /**
     * @param array<string,mixed> $write
     */
    public function __construct(array $write = [], ?ShippingUpdateResult $shipping_result = null)
    {
        $this->write = is_array($write) ? $write : [];
        $this->shipping_result = $shipping_result;
    }

    public static function empty(): self
    {
        return new self([], null);
    }

    /**
     * @param array<string,mixed> $write
     */
    public static function with_write(array $write): self
    {
        return new self($write, null);
    }

    /**
     * @param array<string,mixed> $write
     */
    public static function for_shipping(ShippingUpdateResult $r, array $write = []): self
    {
        return new self($write, $r);
    }

    /** @return array<string,mixed> */
    public function write(): array
    {
        return $this->write;
    }

    public function has_write(): bool
    {
        return !empty($this->write);
    }

    public function shipping_result(): ShippingUpdateResult
    {
        return ($this->shipping_result instanceof ShippingUpdateResult)
            ? $this->shipping_result
            : ShippingUpdateResult::empty();
    }

    public function shipping_has_changes(): bool
    {
        return $this->shipping_result instanceof ShippingUpdateResult
            ? $this->shipping_result->has_changes()
            : false;
    }

    /* ======================================================
     * Fluent write helpers
     * ====================================================== */

    /**
     * Set/overwrite a field in the patch (chainable).
     *
     * @param mixed $value
     */
    public function with_field(string $key, $value): self
    {
        $key = trim((string) $key);
        if ($key === '') return $this;

        if (!self::is_allowed_field($key)) {
            return $this; // silently ignore non-allowed fields
        }

        $this->write[$key] = $value;
        return $this;
    }

    /**
     * Set a field only if it is not already present in the patch (chainable).
     *
     * @param mixed $value
     */
    public function with_field_if_missing(string $key, $value): self
    {
        $key = trim((string) $key);
        if ($key === '') return $this;

        if (!self::is_allowed_field($key)) {
            return $this;
        }

        if (!array_key_exists($key, $this->write)) {
            $this->write[$key] = $value;
        }
        return $this;
    }

    /**
     * Merge another patch into this one (other wins on conflicts).
     */
    public function merge(self $other): self
    {
        foreach ($other->write() as $k => $v) {
            $this->with_field((string) $k, $v);
        }

        // Prefer keeping a non-empty result if we don't already have one.
        if (!($this->shipping_result instanceof ShippingUpdateResult) && ($other->shipping_result instanceof ShippingUpdateResult)) {
            $this->shipping_result = $other->shipping_result;
        }

        return $this;
    }

    /* ======================================================
     * Domain helpers (these are what shrink your callers)
     * ====================================================== */

    public function with_last_step(string $step): self
    {
        $step = strtolower(trim((string) $step));
        if ($step !== 'validate' && $step !== 'place' && $step !== 'shipped') {
            $step = '';
        }
        return $this->with_field('last_step', $step);
    }

    public function with_status(string $status): self
    {
        return $this->with_field('status', (string) $status);
    }

    public function clear_action_and_schedule(): self
    {
        $this->with_field('action_id', null);
        $this->with_field('next_run_at', null);
        return $this;
    }

    public function with_action_id(?int $action_id): self
    {
        $aid = (int) $action_id;
        return $this->with_field('action_id', ($aid > 0) ? $aid : null);
    }

    public function with_next_run_at_mysql(?string $mysql): self
    {
        $s = trim((string) $mysql);
        return $this->with_field('next_run_at', ($s !== '') ? $s : null);
    }

    public function with_last_error(string $message): self
    {
        return $this->with_field('last_error', (string) $message);
    }

    /** @param string[] $codes */
    public function with_last_codes(array $codes): self
    {
        $codes = self::normalize_codes($codes);
        return $this->with_field('last_codes_json', wp_json_encode($codes));
    }

    public function touch_last_shipping_poll_at(string $now_mysql_utc): self
    {
        return $this->with_field('last_shipping_poll_at', $now_mysql_utc);
    }

    public function touch_updated_at(string $now_mysql_utc): self
    {
        // Store currently sets updated_at itself; but having this lets you centralize later.
        return $this->with_field('updated_at', $now_mysql_utc);
    }

    /* ======================================================
     * Output helpers
     * ====================================================== */

    /**
     * Get a safe write array (allowed fields only).
     * If you later move updated_at logic here, this is where it belongs.
     *
     * @return array<string,mixed>
     */
    public function to_write_array(): array
    {
        $out = [];
        foreach ($this->write as $k => $v) {
            $k = (string) $k;
            if (!self::is_allowed_field($k)) continue;
            $out[$k] = $v;
        }
        return $out;
    }

    private static function is_allowed_field(string $k): bool
    {
        return in_array($k, self::ALLOWED, true);
    }

    /** @param string[] $codes @return string[] */
    private static function normalize_codes(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') $out[] = $c;
        }
        $out = array_values(array_unique($out));
        if (count($out) > 25) $out = array_slice($out, 0, 25);
        return $out;
    }
}
