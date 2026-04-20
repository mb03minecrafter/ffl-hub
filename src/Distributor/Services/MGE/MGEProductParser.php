<?php

namespace FFLHub\Distributor\Services\MGE;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser for one row of the MGE full-catalog CSV.
 */
class MGEProductParser
{
    /**
     * @param array<int,mixed> $header
     * @return array<string,int>
     */
    public function build_header_map(array $header): array
    {
        $header_map = [];

        foreach ($header as $idx => $name) {
            $key = strtolower($this->normalize_scalar($name));
            if ($key === '') {
                continue;
            }
            $header_map[$key] = (int) $idx;
        }

        return $header_map;
    }

    /**
     * @param array<string,int> $header_map
     */
    public function has_required_columns(array $header_map): bool
    {
        return isset($header_map['id']) && isset($header_map['barcod']);
    }

    /**
     * @param array<int,mixed> $csv
     * @param array<string,int> $header_map
     * @return array<string,string>|null
     */
    public function parse_csv_row(array $csv, array $header_map): ?array
    {
        if ($csv === [null] || count($csv) < 4) {
            return null;
        }

        $item_number = $this->get($csv, $header_map, 'id');
        $upc = $this->clean_upc($this->get($csv, $header_map, 'barcod'));

        if ($item_number === '' && $upc === '') {
            return null;
        }

        $item_type = $this->get($csv, $header_map, 'category');
        $sub_category = $this->get($csv, $header_map, 'subcategory');
        $name = $this->get($csv, $header_map, 'name');
        $description = $this->get($csv, $header_map, 'description');
        $map_raw = $this->get($csv, $header_map, 'map');

        return [
            'upc' => $upc,
            'mge_item_number' => $item_number,
            'vendor_item_number' => $this->get($csv, $header_map, 'vendoritemno'),

            'inventory_quantity' => $this->clean_decimal($this->get($csv, $header_map, 'qty')),
            'allocation_status' => $this->get($csv, $header_map, 'stat'),
            'distributor_price' => $this->clean_money($this->get($csv, $header_map, 'price')),
            'retail_map' => $this->clean_map($map_raw),
            'retail_msrp' => '',

            'product_description' => $description,
            'model' => $name,
            'manufacturer' => $this->get($csv, $header_map, 'manufacturer_id'),
            'manufacturer_id' => $this->get($csv, $header_map, 'manufacturer_id'),
            'vendor_id' => $this->get($csv, $header_map, 'vendorid'),
            'item_type' => $item_type,
            'sub_category' => $sub_category,
            'image_url' => $this->get($csv, $header_map, 'image'),

            'ffl_required' => $this->deduce_ffl_required($item_type, $sub_category, $name, $description),
            'sot_required' => $this->deduce_sot_required($item_type, $sub_category, $name, $description),
            'dropship_enabled' => '0',
            'dropship_block_reason' => 'dropship_not_supported',

            'status_code' => $this->get($csv, $header_map, 'stat'),
            'drop_ship_source' => $this->get($csv, $header_map, 'dropship'),
            'row_index' => $this->get($csv, $header_map, 'rowindex'),
            'barcod_raw' => $this->get($csv, $header_map, 'barcod'),
        ];
    }

    /**
     * @param array<int,mixed> $row
     * @param array<string,int> $header_map
     */
    private function get(array $row, array $header_map, string $key): string
    {
        $idx = $header_map[strtolower($key)] ?? null;
        if (!is_int($idx)) {
            return '';
        }

        return $this->normalize_scalar($row[$idx] ?? '');
    }

    /**
     * @param mixed $value
     */
    private function normalize_scalar($value): string
    {
        if ($value === null) {
            return '';
        }

        $v = (string) $value;
        $v = (string) preg_replace('/^\xEF\xBB\xBF/', '', $v);
        $v = trim($v);
        $v = trim($v, "\r\n");

        return $v;
    }

    private function clean_money(string $value): string
    {
        $v = trim($value);
        if ($v === '' || strtoupper($v) === 'N/A') {
            return '';
        }

        $v = str_replace(['$', ','], '', $v);
        $v = trim($v);

        return $v;
    }

    private function clean_map(string $value): string
    {
        return $this->clean_money($value);
    }

    private function clean_decimal(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '0';
        }

        $v = str_replace([','], '', $v);
        $v = trim($v);

        if ($v === '') {
            return '0';
        }

        return $v;
    }

    private function clean_upc(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        $digits = is_string($digits) ? trim($digits) : '';

        return $digits;
    }

    private function deduce_ffl_required(string $item_type, string $sub_category, string $name, string $description): string
    {
        $haystack = strtoupper(trim($item_type . ' ' . $sub_category . ' ' . $name . ' ' . $description));
        if ($haystack === '') {
            return '0';
        }

        if (preg_match('/PISTOL|REVOLVER|RIFLE|SHOTGUN|FIREARM|RECEIVER|FRAME|LOWER|HANDGUN/', $haystack)) {
            return '1';
        }

        return '0';
    }

    private function deduce_sot_required(string $item_type, string $sub_category, string $name, string $description): string
    {
        $haystack = strtoupper(trim($item_type . ' ' . $sub_category . ' ' . $name . ' ' . $description));
        if ($haystack === '') {
            return '0';
        }

        if (preg_match('/SUPPRESS|SILENCER|NFA/', $haystack)) {
            return '1';
        }

        return '0';
    }
}
