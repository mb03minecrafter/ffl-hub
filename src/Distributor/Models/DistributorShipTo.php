<?php

namespace FFLHub\Distributor\Models;

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
}
