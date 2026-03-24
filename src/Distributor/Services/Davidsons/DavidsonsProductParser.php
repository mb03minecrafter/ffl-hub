<?php

namespace FFLHub\Distributor\Services\Davidsons;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser for one row of Davidson's full catalog CSV.
 *
 * Expected headers:
 * - Item #, Item Description, MSP, Retail Price, Dealer Price, Sale Price, Sale Ends
 * - Quantity, UPC Code, Manufacturer, Gun Type, Model Series, Caliber, Action
 * - Capacity, Finish, Stock, Sights, Barrel Length, Overall Length, Features
 */
class DavidsonsProductParser
{
    /**
     * @param array<int, mixed> $header
     * @return array<string, int>
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
     * @param array<string, int> $header_map
     */
    public function has_required_columns(array $header_map): bool
    {
        return isset($header_map['item #']) && isset($header_map['upc code']);
    }

    /**
     * @param array<int, mixed> $csv
     * @param array<string, int> $header_map
     * @return array<string, string>|null
     */
    public function parse_csv_row(array $csv, array $header_map): ?array
    {
        if ($csv === [null] || count($csv) < 2) {
            return null;
        }

        $item_number = $this->get($csv, $header_map, 'item #');
        $upc         = $this->clean_upc($this->get($csv, $header_map, 'upc code'));

        if ($item_number === '' && $upc === '') {
            return null;
        }

        $item_type   = $this->get($csv, $header_map, 'gun type');
        $qty         = $this->to_int_string($this->get($csv, $header_map, 'quantity'));
        $stock_state = ((int) $qty > 0) ? 'in_stock' : 'out_of_stock';

        return [
            'upc'                  => $upc,
            'davidsons_item_number' => $item_number,

            'inventory_quantity' => $qty,
            'allocation_status'  => $stock_state,
            'distributor_price'  => $this->clean_money($this->get($csv, $header_map, 'dealer price')),
            'retail_map'         => $this->clean_money($this->get($csv, $header_map, 'msp')),
            'retail_msrp'        => $this->clean_money($this->get($csv, $header_map, 'retail price')),
            'sale_price'         => $this->clean_money($this->get($csv, $header_map, 'sale price')),
            'sale_ends'          => $this->get($csv, $header_map, 'sale ends'),

            'product_description' => $this->get($csv, $header_map, 'item description'),
            'manufacturer'        => $this->get($csv, $header_map, 'manufacturer'),
            'model'               => $this->get($csv, $header_map, 'model series'),
            'caliber_gauge'       => $this->get($csv, $header_map, 'caliber'),
            'item_type'           => $item_type,
            'action'              => $this->get($csv, $header_map, 'action'),
            'capacity'            => $this->get($csv, $header_map, 'capacity'),
            'finish'              => $this->get($csv, $header_map, 'finish'),
            'stock_frame_grips'   => $this->get($csv, $header_map, 'stock'),
            'sights'              => $this->get($csv, $header_map, 'sights'),
            'barrel_length'       => $this->get($csv, $header_map, 'barrel length'),
            'overall_length'      => $this->get($csv, $header_map, 'overall length'),
            'features'            => $this->get($csv, $header_map, 'features'),

            'ffl_required'         => $this->deduce_ffl_required($item_type),
            'sot_required'         => $this->deduce_sot_required($item_type),
            'dropship_enabled'     => '1',
            'dropship_block_reason' => '',
        ];
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, int> $header_map
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
        if ($v === '') {
            return '';
        }

        $v = str_replace(['$', ','], '', $v);
        $v = trim($v);

        if ($v === '' || $v === '-' || $v === '.') {
            return '';
        }

        return $v;
    }

    private function to_int_string(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '0';
        }

        $num = (int) round((float) str_replace(',', '', $v));
        if ($num < 0) {
            $num = 0;
        }

        return (string) $num;
    }

    private function clean_upc(string $value): string
    {
        $v = trim($value);
        $v = trim($v, "# \t\r\n");
        return $v;
    }

    private function deduce_ffl_required(string $item_type): string
    {
        $t = strtoupper(trim($item_type));
        if ($t === '') {
            return '0';
        }

        return preg_match('/PISTOL|REVOLVER|RIFLE|SHOTGUN|HANDGUN|FIREARM|RECEIVER/', $t) ? '1' : '0';
    }

    private function deduce_sot_required(string $item_type): string
    {
        $t = strtoupper(trim($item_type));
        if ($t === '') {
            return '0';
        }

        return preg_match('/SUPPRESSOR|SILENCER|NFA/', $t) ? '1' : '0';
    }
}

