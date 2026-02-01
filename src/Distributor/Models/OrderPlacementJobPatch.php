<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobPatch
 *
 * A small, safe “partial update” object for an OrderPlacement job row.
 *
 * Why this exists
 * ---------------
 * Job rows get updated from many places (validation, placement, shipping polling, retries).
 * If callers pass ad-hoc arrays straight into the writer, it’s easy to:
 * - accidentally overwrite unrelated columns
 * - forget to normalize values (null vs '', codes JSON, etc.)
 * - spread write-shape knowledge all over the codebase
 *
 * This patch object centralizes:
 * - an allowlisted write payload (only specific columns are writable)
 * - fluent helpers for common domain mutations (status/step/codes/scheduling touches)
 * - optional semantic results (ShippingUpdateResult) that callers can act on
 *
 * Important notes
 * ---------------
 * - This object is mutable: fluent methods mutate and return $this.
 * - Writer/store layers should apply ->to_write_array(), not ->write().
 * - ShippingUpdateResult is a semantic outcome; it may not map 1:1 to stored columns.
 */
final class OrderPlacementJobPatch
{
    /**
     * Raw write payload being accumulated.
     * This may contain non-allowlisted keys if constructed unsafely,
     * but output helpers will filter to allowlisted fields.
     *
     * @var array<string,mixed>
     */
    private array $write;

    /**
     * Semantic shipping update result computed during merge/persist.
     * Not necessarily “the same thing” as stored shipping columns.
     */
    private ?ShippingUpdateResult $shipping_result;

    /**
     * Allowlist of columns that may be written via patch.
     *
     * Keep this in sync with the underlying job writer/store allowlist.
     *
     * @var string[]
     */
    private const ALLOWED = [
        // identity / routing
        'dist_id',
        'bucket',

        // execution / status
        'status',
        'attempts',
        'action_id',
        'next_run_at',
        'last_step',

        // error / diagnostics
        'last_error',
        'last_codes_json',

        // completion
        'done_at',

        // payload snapshots
        'payload_json',
        'validate_result_json',
        'place_result_json',

        // merchant / external ids
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
        $this->write = $write;
        $this->shipping_result = $shipping_result;
    }

    /* ======================================================
     * Constructors
     * ====================================================== */

    /**
     * Create an empty patch (no writes, no semantic result).
     */
    public static function empty(): self
    {
        return new self([], null);
    }

    /**
     * Create a patch with a raw write payload.
     *
     * Prefer fluent helpers for safety/normalization when possible.
     *
     * @param array<string,mixed> $write
     */
    public static function with_write(array $write): self
    {
        return new self($write, null);
    }

    /**
     * Create a shipping patch: carries a ShippingUpdateResult plus optional write payload.
     *
     * @param array<string,mixed> $write
     */
    public static function for_shipping(ShippingUpdateResult $r, array $write = []): self
    {
        return new self($write, $r);
    }

    /* ======================================================
     * Introspection
     * ====================================================== */

    /**
     * Raw write payload (may contain non-allowlisted keys).
     *
     * Store/writer layers should prefer ->to_write_array().
     *
     * @return array<string,mixed>
     */
    public function write(): array
    {
        return $this->write;
    }

    /**
     * True if there is at least one field to write.
     */
    public function has_write(): bool
    {
        return !empty($this->write);
    }

    /**
     * Semantic shipping update result.
     *
     * Never returns null: missing result collapses to ShippingUpdateResult::empty().
     */
    public function shipping_result(): ShippingUpdateResult
    {
        return ($this->shipping_result instanceof ShippingUpdateResult)
            ? $this->shipping_result
            : ShippingUpdateResult::empty();
    }

    /**
     * Convenience: whether the shipping result indicates changes.
     */
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
     * Safety:
     * - Silently ignores unknown keys (not in ALLOWED).
     *
     * @param mixed $value
     */
    public function with_field(string $key, $value): self
    {
        $key = trim($key);
        if ($key === '') {
            return $this;
        }

        if (!self::is_allowed_field($key)) {
            return $this;
        }

        $this->write[$key] = $value;
        return $this;
    }

    /**
     * Set a field only if it is not already present in the patch (chainable).
     *
     * Safety:
     * - Silently ignores unknown keys (not in ALLOWED).
     *
     * @param mixed $value
     */
    public function with_field_if_missing(string $key, $value): self
    {
        $key = trim($key);
        if ($key === '') {
            return $this;
        }

        if (!self::is_allowed_field($key)) {
            return $this;
        }

        if (!array_key_exists($key, $this->write)) {
            $this->write[$key] = $value;
        }

        return $this;
    }

    /**
     * Merge another patch into this one (other wins on write conflicts).
     *
     * Shipping semantics:
     * - If only one patch has a ShippingUpdateResult, keep it.
     * - If both have one, prefer the one that has changes;
     *   if both have equal "change-ness", let other win.
     */
    public function merge(self $other): self
    {
        foreach ($other->write() as $k => $v) {
            $this->with_field((string) $k, $v);
        }

        if ($other->shipping_result instanceof ShippingUpdateResult) {
            if (!($this->shipping_result instanceof ShippingUpdateResult)) {
                $this->shipping_result = $other->shipping_result;
            } else {
                $mine_changes  = $this->shipping_result->has_changes();
                $other_changes = $other->shipping_result->has_changes();

                if ($other_changes && !$mine_changes) {
                    $this->shipping_result = $other->shipping_result;
                } elseif ($other_changes === $mine_changes) {
                    $this->shipping_result = $other->shipping_result;
                }
            }
        }

        return $this;
    }

    /* ======================================================
     * Domain helpers (callers should use these)
     * ====================================================== */

    /**
     * Set last_step (normalized).
     *
     * Keep the allowlist intentionally small so UIs/logs stay predictable.
     */
    public function with_last_step(string $step): self
    {
        $step = strtolower(trim($step));
        if ($step !== 'validate' && $step !== 'place' && $step !== 'shipped') {
            $step = '';
        }
        return $this->with_field('last_step', $step);
    }

    public function with_status(string $status): self
    {
        return $this->with_field('status', (string) $status);
    }

    /**
     * Clear any scheduling metadata.
     *
     * In a DB-only-dispatcher model, action_id should generally stay NULL.
     */
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

    /**
     * Set next_run_at as MySQL UTC datetime string (nullable).
     */
    public function with_next_run_at_mysql(?string $mysql): self
    {
        $s = trim((string) $mysql);
        return $this->with_field('next_run_at', ($s !== '') ? $s : null);
    }

    public function with_last_error(string $message): self
    {
        return $this->with_field('last_error', (string) $message);
    }

    /**
     * Persist last_codes_json as a JSON array of unique strings (max 25).
     *
     * @param array<int,string> $codes
     */
    public function with_last_codes(array $codes): self
    {
        $codes = self::normalize_codes($codes);

        $json = wp_json_encode($codes);
        if (!is_string($json) || $json === '') {
            $json = '[]';
        }

        return $this->with_field('last_codes_json', $json);
    }

    /**
     * Update last_shipping_poll_at (MySQL UTC datetime).
     */
    public function touch_last_shipping_poll_at(string $now_mysql_utc): self
    {
        $s = trim((string) $now_mysql_utc);
        return $this->with_field('last_shipping_poll_at', ($s !== '') ? $s : null);
    }

    /**
     * Update updated_at (MySQL UTC datetime).
     *
     * Store layer may set updated_at itself; having this enables centralization later.
     */
    public function touch_updated_at(string $now_mysql_utc): self
    {
        $s = trim((string) $now_mysql_utc);
        return $this->with_field('updated_at', ($s !== '') ? $s : null);
    }

    /* ======================================================
     * Output helpers (writer/store should use these)
     * ====================================================== */

    /**
     * Get a safe write array (allowed fields only).
     *
     * @return array<string,mixed>
     */
    public function to_write_array(): array
    {
        $out = [];
        foreach ($this->write as $k => $v) {
            $k = (string) $k;
            if (!self::is_allowed_field($k)) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private static function is_allowed_field(string $k): bool
    {
        return in_array($k, self::ALLOWED, true);
    }

    /**
     * Normalize codes as string[], unique, capped to 25.
     *
     * @param array<int,string> $codes
     * @return array<int,string>
     */
    private static function normalize_codes(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $out[] = $c;
            }
        }

        $out = array_values(array_unique($out));

        if (count($out) > 25) {
            $out = array_slice($out, 0, 25);
        }

        return $out;
    }
}
