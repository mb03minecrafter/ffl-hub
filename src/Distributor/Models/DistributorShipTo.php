<?php

namespace FFLHub\Distributor\Models;

use WC_Order;

if (! defined('ABSPATH')) {
    exit;
}

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
        $this->state = strtoupper(trim($state));
        $this->zip = $zip;
        $this->phone = $phone;
        $this->email = $email;
    }




    /**
     * Human-readable debug string for logging / order notes.
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
     */
    public static function from_order_shipping_fallback_billing(WC_Order $order): ?self
    {
        // Name: prefer shipping, fallback to billing.
        $first = trim((string) $order->get_shipping_first_name());
        $last  = trim((string) $order->get_shipping_last_name());
        $name  = trim($first . ' ' . $last);

        if ($name === '') {
            $bf = trim((string) $order->get_billing_first_name());
            $bl = trim((string) $order->get_billing_last_name());
            $name = trim($bf . ' ' . $bl);
        }

        // Address: prefer shipping; if required parts missing, fill from billing.
        $company  = trim((string) $order->get_shipping_company());
        $address1 = trim((string) $order->get_shipping_address_1());
        $address2 = trim((string) $order->get_shipping_address_2());
        $city     = trim((string) $order->get_shipping_city());
        $state    = trim((string) $order->get_shipping_state());
        $zip      = trim((string) $order->get_shipping_postcode());

        if ($address1 === '' || $city === '' || $state === '' || $zip === '') {
            if ($company === '')  $company  = trim((string) $order->get_billing_company());
            if ($address1 === '') $address1 = trim((string) $order->get_billing_address_1());
            if ($address2 === '') $address2 = trim((string) $order->get_billing_address_2());
            if ($city === '')     $city     = trim((string) $order->get_billing_city());
            if ($state === '')    $state    = trim((string) $order->get_billing_state());
            if ($zip === '')      $zip      = trim((string) $order->get_billing_postcode());
        }

        $phone = trim((string) $order->get_billing_phone());
        $email = trim((string) $order->get_billing_email());

        // Hard requirements
        if ($name === '' || $address1 === '' || $city === '' || $state === '' || $zip === '') {
            return null;
        }

        return new self($name, $company, $address1, $address2, $city, $state, $zip, $phone, $email);
    }
}
