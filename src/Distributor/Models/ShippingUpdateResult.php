<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ShippingUpdateResult
 *
 * Immutable-ish value object representing the *delta* and *current state*
 * of shipment identifiers after a shipping poll/update.
 *
 * This object is intentionally small and deterministic so it can be:
 * - safely embedded in job patches
 * - used as a semantic signal for downstream actions (emails, notes, hooks)
 * - serialized or logged without leaking sensitive data
 *
 * Terminology:
 * - "added_*"  => identifiers newly discovered in the latest poll
 * - "all_*"    => full merged set after applying the update
 *
 * IMPORTANT:
 * - Ordering is preserved (first-seen wins).
 * - Lists are normalized (trimmed, non-empty, unique).
 * - Absence of changes is explicitly representable (empty()).
 */
final class ShippingUpdateResult
{
    /**
     * Newly added tracking numbers from the latest poll.
     *
     * @var string[]
     */
    public array $added_tracking;

    /**
     * Newly added invoice numbers from the latest poll.
     *
     * @var string[]
     */
    public array $added_invoices;

    /**
     * Full merged list of all known tracking numbers (existing + new).
     *
     * @var string[]
     */
    public array $all_tracking;

    /**
     * Full merged list of all known invoice numbers (existing + new).
     *
     * @var string[]
     */
    public array $all_invoices;

    /**
     * @param string[] $added_tracking  Newly discovered tracking numbers
     * @param string[] $added_invoices  Newly discovered invoice numbers
     * @param string[] $all_tracking    Full merged tracking list
     * @param string[] $all_invoices    Full merged invoice list
     */
    public function __construct(
        array $added_tracking,
        array $added_invoices,
        array $all_tracking = [],
        array $all_invoices = []
    ) {
        $this->added_tracking = self::normalize_list($added_tracking);
        $this->added_invoices = self::normalize_list($added_invoices);
        $this->all_tracking   = self::normalize_list($all_tracking);
        $this->all_invoices   = self::normalize_list($all_invoices);
    }

    /**
     * Empty / no-op result.
     *
     * Used when:
     * - no shipment data exists yet
     * - a poll produced no new information
     * - a safe default is required
     */
    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    /**
     * Construct a ShippingUpdateResult by computing deltas between
     * existing and newly fetched identifiers.
     *
     * This is the preferred constructor when the caller already has:
     * - persisted values from the job row
     * - newly fetched values from the distributor API
     *
     * @param string[] $existing_tracking
     * @param string[] $new_tracking
     * @param string[] $existing_invoices
     * @param string[] $new_invoices
     */
    public static function from_lists(
        array $existing_tracking,
        array $new_tracking,
        array $existing_invoices,
        array $new_invoices
    ): self {
        $existing_tracking = self::normalize_list($existing_tracking);
        $new_tracking      = self::normalize_list($new_tracking);
        $existing_invoices = self::normalize_list($existing_invoices);
        $new_invoices      = self::normalize_list($new_invoices);

        $merged_tracking = self::merge_preserve_order($existing_tracking, $new_tracking);
        $merged_invoices = self::merge_preserve_order($existing_invoices, $new_invoices);

        $added_tracking = array_values(array_diff($new_tracking, $existing_tracking));
        $added_invoices = array_values(array_diff($new_invoices, $existing_invoices));

        return new self(
            $added_tracking,
            $added_invoices,
            $merged_tracking,
            $merged_invoices
        );
    }

    /**
     * True if at least one new tracking number was discovered.
     */
    public function has_new_tracking(): bool
    {
        return !empty($this->added_tracking);
    }

    /**
     * True if at least one new invoice number was discovered.
     */
    public function has_new_invoices(): bool
    {
        return !empty($this->added_invoices);
    }

    /**
     * True if *any* shipment-related identifiers changed.
     *
     * This is the primary semantic signal used by:
     * - partial shipment emails
     * - job patch merge logic
     * - idempotency guards
     */
    public function has_changes(): bool
    {
        return $this->has_new_tracking() || $this->has_new_invoices();
    }

    /**
     * Convenience helper for legacy paths that expect a single tracking number.
     *
     * Returns the first known tracking number (deterministic ordering),
     * or an empty string if none exist.
     */
    public function primary_tracking(): string
    {
        return !empty($this->all_tracking)
            ? (string) $this->all_tracking[0]
            : '';
    }

    /**
     * Normalize a list of arbitrary values into a clean string list.
     *
     * Rules:
     * - cast to string
     * - trim
     * - drop empty values
     * - de-duplicate while preserving order
     *
     * @param mixed[] $vals
     * @return string[]
     */
    private static function normalize_list(array $vals): array
    {
        $out = [];
        foreach ($vals as $v) {
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

    /**
     * Merge two ordered string lists without reordering existing elements.
     *
     * Elements from $a always appear first, followed by new elements from $b.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    private static function merge_preserve_order(array $a, array $b): array
    {
        $set = [];
        $out = [];

        foreach ($a as $v) {
            if ($v === '' || isset($set[$v])) {
                continue;
            }
            $set[$v] = true;
            $out[] = $v;
        }

        foreach ($b as $v) {
            if ($v === '' || isset($set[$v])) {
                continue;
            }
            $set[$v] = true;
            $out[] = $v;
        }

        return $out;
    }
}
