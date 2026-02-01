<?php

namespace FFLHub\Distributor\Models;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalized ship-to contact + address.
 *
 * What this DTO is for
 * --------------------
 * Distributors all want a “ship to” payload, but WooCommerce stores shipping and billing
 * fields separately, and each distributor has slightly different requirements.
 *
 * This class provides:
 * - A normalized in-memory representation of a shipping destination (name/company/address/etc.)
 * - Safe debug helpers (string + array) for logging and job details
 * - A canonical builder from a WooCommerce order using a sensible fallback policy
 *
 * Field semantics
 * ---------------
 * - name/company/address* => destination identity / label fields
 * - city/state/zip        => destination geography
 * - phone/email           => customer contact info (by Woo convention, from billing)
 *
 * Normalization policy
 * --------------------
 * - state is uppercased and trimmed in constructor (keeps downstream payload mapping simple)
 * - other string fields are currently stored as-is (trim happens in builders / debug helpers)
 *
 * Logging policy
 * --------------
 * Debug output uses safe() to avoid empty fields producing unreadable logs.
 * NOTE: This does NOT redact PII; it only formats empty values as "(empty)".
 * If you ever log these in production at high volume, consider a redacted variant.
 */
final class DistributorShipTo
{
    public string $name;
    public string $company;
    public string $address1;
    public string $address2;
    public string $city;
    public string $state;
    public string $zip;
    public string $phone;
    public string $email;

    /**
     * Constructor for normalized ship-to fields.
     *
     * Keep this “dumb”: it just stores values with minimal normalization so it remains safe
     * to construct even from partially-cleaned upstream sources.
     */
    public function __construct(
        string $name,
        string $company,
        string $address1,
        string $address2,
        string $city,
        string $state,
        string $zip,
        string $phone,
        string $email
    ) {
        $this->name = $name;
        $this->company = $company;
        $this->address1 = $address1;
        $this->address2 = $address2;
        $this->city = $city;

        // Normalize state once here so all downstream payload builders can assume uppercase.
        $this->state = strtoupper(trim($state));

        $this->zip = $zip;
        $this->phone = $phone;
        $this->email = $email;
    }

    /**
     * Human-readable debug string for logging / order notes.
     *
     * Intended usage:
     * - error_log() lines
     * - job details blobs
     * - Woo order notes
     *
     * Format notes:
     * - Multi-line, aligned, easy to scan in logs
     * - Shows address2 on its own line if present
     */
    public function to_debug_string(): string
    {
        $lines = [];

        $lines[] = 'Ship-To:';
        $lines[] = '  Name:    ' . $this->safe($this->name);
        $lines[] = '  Company: ' . $this->safe($this->company);
        $lines[] = '  Address: ' . $this->safe($this->address1);

        if ($this->address2 !== '') {
            $lines[] = '           ' . $this->safe($this->address2);
        }

        $lines[] = sprintf(
            '  City:    %s, %s %s',
            $this->safe($this->city),
            $this->safe($this->state),
            $this->safe($this->zip)
        );

        $lines[] = '  Phone:   ' . $this->safe($this->phone);
        $lines[] = '  Email:   ' . $this->safe($this->email);

        return implode("\n", $lines);
    }

    /**
     * Structured debug payload (safe for json_encode / logs).
     *
     * Intended usage:
     * - wp_json_encode($shipTo->to_debug_array())
     * - job details arrays
     *
     * NOTE: This is raw PII. Do not include it in logs unless you explicitly want that.
     */
    public function to_debug_array(): array
    {
        return [
            'name'     => $this->name,
            'company'  => $this->company,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city'     => $this->city,
            'state'    => $this->state,
            'zip'      => $this->zip,
            'phone'    => $this->phone,
            'email'    => $this->email,
        ];
    }

    /**
     * Format a field for debug output:
     * - trims whitespace
     * - replaces empty strings with "(empty)" for log readability
     *
     * This is formatting only (not security / redaction).
     */
    private function safe(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : '(empty)';
    }

    /**
     * Build a customer Ship-To from an order.
     *
     * Policy:
     * - Prefer Shipping name/address
     * - Fall back to Billing name if Shipping name is blank
     * - Fall back to Billing address fields only when Shipping address is incomplete
     * - Phone/email always from Billing (Woo convention)
     *
     * Return semantics:
     * - Returns null if required destination fields are missing after fallback.
     *
     * Why this policy exists:
     * Woo orders frequently have:
     * - shipping name blank (but shipping address present)
     * - shipping address blank (customer uses billing only)
     * - phone/email only present in billing fields
     *
     * This gives you a "best effort" ship-to that works for most distributors.
     */
    public static function from_order_shipping_fallback_billing(WC_Order $order): ?self
    {
        // -----------------------------
        // 1) Name: prefer shipping, fallback to billing
        // -----------------------------
        $first = trim((string) $order->get_shipping_first_name());
        $last  = trim((string) $order->get_shipping_last_name());
        $name  = trim($first . ' ' . $last);

        if ($name === '') {
            $bf = trim((string) $order->get_billing_first_name());
            $bl = trim((string) $order->get_billing_last_name());
            $name = trim($bf . ' ' . $bl);
        }

        // -----------------------------
        // 2) Address: prefer shipping, but fill missing required parts from billing
        // -----------------------------
        $company  = trim((string) $order->get_shipping_company());
        $address1 = trim((string) $order->get_shipping_address_1());
        $address2 = trim((string) $order->get_shipping_address_2());
        $city     = trim((string) $order->get_shipping_city());
        $state    = trim((string) $order->get_shipping_state());
        $zip      = trim((string) $order->get_shipping_postcode());

        // If any required component is missing, selectively backfill from billing.
        if ($address1 === '' || $city === '' || $state === '' || $zip === '') {
            if ($company === '')  {
                $company  = trim((string) $order->get_billing_company());
            }
            if ($address1 === '') {
                $address1 = trim((string) $order->get_billing_address_1());
            }
            if ($address2 === '') {
                $address2 = trim((string) $order->get_billing_address_2());
            }
            if ($city === '')     {
                $city     = trim((string) $order->get_billing_city());
            }
            if ($state === '')    {
                $state    = trim((string) $order->get_billing_state());
            }
            if ($zip === '')      {
                $zip      = trim((string) $order->get_billing_postcode());
            }
        }

        // -----------------------------
        // 3) Contact: always billing
        // -----------------------------
        $phone = trim((string) $order->get_billing_phone());
        $email = trim((string) $order->get_billing_email());

        // -----------------------------
        // 4) Hard requirements
        // -----------------------------
        // Note: phone/email are not required here because some distributor endpoints don't require them.
        if ($name === '' || $address1 === '' || $city === '' || $state === '' || $zip === '') {
            return null;
        }

        return new self($name, $company, $address1, $address2, $city, $state, $zip, $phone, $email);
    }
}
