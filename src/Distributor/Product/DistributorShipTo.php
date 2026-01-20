<?php

namespace FFLHub\Distributor\Product;

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
}
