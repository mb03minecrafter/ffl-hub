<?php

namespace FFLHub\Distributor\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalized shipment data returned from distributor APIs.
 *
 * Supports multiple tracking + invoice numbers (common for split/partial shipments).
 *
 * NOTE: Keep "first item" helpers for backward compatibility with older code paths.
 */
final class DistributorShipment
{
    /** @var array<int,string> */
    public array $tracking_numbers;

    /** @var array<int,string> */
    public array $invoice_numbers;

    /** Optional: service name (UPS Ground, etc.) */
    public ?string $shipping_service;

    /** Optional: weight string (as provided by distributor) */
    public ?string $shipping_weight;

    /**
     * Raw distributor response (audit/debug).
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
        $this->tracking_numbers = self::normalize_string_list($tracking_numbers);
        $this->invoice_numbers  = self::normalize_string_list($invoice_numbers);
        $this->shipping_service = ($shipping_service !== null && trim($shipping_service) !== '') ? trim($shipping_service) : null;
        $this->shipping_weight  = ($shipping_weight !== null && trim($shipping_weight) !== '') ? trim($shipping_weight) : null;
        $this->raw              = is_array($raw) ? $raw : [];
    }

    /**
     * Back-compat: first tracking number (or null).
     */
    public function tracking_number(): ?string
    {
        return $this->tracking_numbers[0] ?? null;
    }

    /**
     * Back-compat: first invoice number (or null).
     */
    public function invoice_number(): ?string
    {
        return $this->invoice_numbers[0] ?? null;
    }

    public function has_tracking(): bool
    {
        return !empty($this->tracking_numbers);
    }

    public function has_invoices(): bool
    {
        return !empty($this->invoice_numbers);
    }

    /**
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

        // de-dupe while preserving order
        $out = array_values(array_unique($out));

        return $out;
    }
}
