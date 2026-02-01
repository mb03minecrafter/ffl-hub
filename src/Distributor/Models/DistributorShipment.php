<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalized shipment data returned from distributor APIs.
 *
 * What this represents
 * --------------------
 * A distributor may ship a single PO in multiple cartons and/or multiple invoices.
 * This DTO normalizes that into:
 *  - tracking_numbers[] (0..n)
 *  - invoice_numbers[]  (0..n)
 * plus a couple optional “nice to have” fields and a raw audit blob.
 *
 * Design goals
 * ------------
 * - Stable shape across distributors (RSR, Lipsey's, etc.)
 * - Supports partial shipments naturally (many tracking numbers per PO)
 * - Defensive normalization: trim strings, drop empties, de-dupe
 * - Keep simple "first item" helpers for older call sites
 *
 * Important behavior notes
 * ------------------------
 * - Ordering is preserved from the input list (before de-dupe), which matters if you
 *   want deterministic “first tracking number” semantics.
 * - raw is intentionally a small-ish associative array; integrations should avoid
 *   stuffing huge vendor responses here unless explicitly needed.
 */
final class DistributorShipment
{
    /**
     * Tracking numbers (may contain multiple entries for split shipments).
     *
     * @var array<int,string>
     */
    public array $tracking_numbers;

    /**
     * Invoice numbers (may contain multiple entries for split invoices).
     *
     * @var array<int,string>
     */
    public array $invoice_numbers;

    /**
     * Optional: shipping service name (e.g., "UPS Ground").
     * Some distributors don't provide this; null means "unknown/not provided".
     */
    public ?string $shipping_service;

    /**
     * Optional: shipping weight as provided by the distributor (string to avoid unit assumptions).
     * Null means "unknown/not provided".
     */
    public ?string $shipping_weight;

    /**
     * Raw distributor response (audit/debug).
     *
     * Guidance:
     * - Keep it redacted and small (avoid secrets / giant blobs).
     * - Prefer storing key fields + small slices of the response.
     *
     * @var array<string,mixed>
     */
    public array $raw;

    /**
     * @param array<int,string> $tracking_numbers
     * @param array<int,string> $invoice_numbers
     * @param array<string,mixed> $raw
     */
    public function __construct(
        array $tracking_numbers = [],
        array $invoice_numbers = [],
        ?string $shipping_service = null,
        ?string $shipping_weight = null,
        array $raw = []
    ) {
        // Normalize lists: trim, drop empties, de-dupe.
        $this->tracking_numbers = self::normalize_string_list($tracking_numbers);
        $this->invoice_numbers  = self::normalize_string_list($invoice_numbers);

        // Normalize optional strings: treat empty as null.
        $this->shipping_service = ($shipping_service !== null && trim($shipping_service) !== '')
            ? trim($shipping_service)
            : null;

        $this->shipping_weight  = ($shipping_weight !== null && trim($shipping_weight) !== '')
            ? trim($shipping_weight)
            : null;

        // Raw: ensure it's an array (defensive).
        $this->raw = is_array($raw) ? $raw : [];
    }

    /**
     * Back-compat helper: first tracking number (or null).
     *
     * Older code often assumed “one shipment => one tracking”. This keeps those
     * call sites working while newer code can consume tracking_numbers[] directly.
     */
    public function tracking_number(): ?string
    {
        return $this->tracking_numbers[0] ?? null;
    }

    /**
     * Back-compat helper: first invoice number (or null).
     */
    public function invoice_number(): ?string
    {
        return $this->invoice_numbers[0] ?? null;
    }

    /**
     * True if at least one tracking number is present.
     */
    public function has_tracking(): bool
    {
        return !empty($this->tracking_numbers);
    }

    /**
     * True if at least one invoice number is present.
     */
    public function has_invoices(): bool
    {
        return !empty($this->invoice_numbers);
    }

    /**
     * Normalize a list of mixed values into a list of unique non-empty strings.
     *
     * Rules:
     * - Cast to string
     * - Trim whitespace
     * - Drop empty strings
     * - De-dupe while preserving order
     *
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private static function normalize_string_list(array $values): array
    {
        $out = [];

        foreach ($values as $v) {
            $s = trim((string) $v);
            if ($s === '') {
                continue;
            }
            $out[] = $s;
        }

        // De-dupe while preserving order.
        $out = array_values(array_unique($out));

        return $out;
    }
}
