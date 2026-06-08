<?php

namespace FFLHub\Distributor\Services\Zanders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser for one row of the Zanders fulfillment CSV.
 *
 * Expected source columns:
 * available, category, desc1, desc2, itemnumber, manufacturer, mfgpnumber,
 * msrp, price1, price2, price3, qty1, qty2, qty3, upc, weight (lbs), serialized, mapprice
 */
class ZandersProductParser
{
    /**
     * @param array<int, mixed> $header
     * @return array<string, int>
     */
    public function build_header_map(array $header): array
    {
        $header_map = [];

        foreach ($header as $idx => $name) {
            $k = strtolower(trim((string) $name));
            if ($k === '') {
                continue;
            }

            // Strip UTF-8 BOM if it appears on first header value.
            $k = (string) preg_replace('/^\xEF\xBB\xBF/', '', $k);
            $header_map[$k] = (int) $idx;
        }

        return $header_map;
    }

    /**
     * @param array<string, int> $header_map
     */
    public function has_required_columns(array $header_map): bool
    {
        return isset($header_map['upc']) && isset($header_map['itemnumber']);
    }

    /**
     * Map one parsed CSV row into the Zanders fulfillment schema row.
     *
     * @param array<int, mixed>  $csv
     * @param array<string, int> $header_map
     * @return array<string, string|null>|null
     */
    public function parse_csv_row(array $csv, array $header_map): ?array
    {
        // Skip completely empty lines (Zanders sometimes has them).
        if ($csv === [null] || count($csv) < 5) {
            return null;
        }

        $upc          = $this->get($csv, $header_map, 'upc');
        $item         = $this->get($csv, $header_map, 'itemnumber');
        $manufacturer = ZandersManufacturerNormalizer::canonical_display($this->get($csv, $header_map, 'manufacturer'));
        $category     = $this->get($csv, $header_map, 'category');
        $desc1        = $this->get($csv, $header_map, 'desc1');
        $desc2        = $this->get($csv, $header_map, 'desc2');
        $price1       = $this->get($csv, $header_map, 'price1');

        return [
            'upc'                 => $upc,
            'zanders_item_number' => $item,

            'inventory_quantity' => $this->blank_to_zero($this->get($csv, $header_map, 'available')),
            'allocation_status'  => '',

            'distributor_price' => $price1,
            'shipping_cost'     => $this->shipping_cost_from_distributor_price($price1),
            'retail_map'        => $this->blank_to_zero($this->get($csv, $header_map, 'mapprice')),
            'retail_msrp'       => $this->get($csv, $header_map, 'msrp'),

            'product_description' => $this->combine_desc($desc1, $desc2),
            'item_type'           => $category,
            'manufacturer'        => $manufacturer,
            'manufacturer_norm'   => ZandersManufacturerNormalizer::canonical_norm($manufacturer),
            'mfg_model_number'    => $this->get($csv, $header_map, 'mfgpnumber'),

            'shipping_weight' => $this->pounds_to_ounces_or_null($this->get($csv, $header_map, 'weight')),

            'price_2'    => $this->get($csv, $header_map, 'price2'),
            'price_3'    => $this->get($csv, $header_map, 'price3'),
            'bulk_qty_1' => $this->get($csv, $header_map, 'qty1'),
            'bulk_qty_2' => $this->get($csv, $header_map, 'qty2'),
            'bulk_qty_3' => $this->get($csv, $header_map, 'qty3'),

            'ffl_required' => $this->deduce_ffl_required($category),
            'sot_required' => $this->deduce_sot_required($category),
            'dropship_enabled' => '1',
            'dropship_block_reason' => '',

            'serialized' => $this->to_bool_flag($this->get($csv, $header_map, 'serialized')),
        ];
    }

    /**
     * @param array<int, mixed>  $row
     * @param array<string, int> $map
     */
    private function get(array $row, array $map, string $key): string
    {
        if (!isset($map[$key])) {
            return '';
        }

        $idx = (int) $map[$key];
        return $this->normalize_value($row[$idx] ?? '');
    }

    /**
     * Shared hard-normalize (match LOAD DATA trimming intent).
     * Note: fgetcsv already unquotes.
     *
     * @param mixed $val
     */
    private function normalize_value($val): string
    {
        if ($val === null) {
            return '';
        }

        $v = (string) $val;

        // Remove BOM if it sneaks into row data.
        $v = (string) preg_replace('/^\xEF\xBB\xBF/', '', $v);

        // Trim whitespace and line endings.
        $v = trim($v);
        $v = trim($v, "\r\n");

        return $v;
    }

    /**
     * Blank/"" => 0 (matches LOAD DATA CASE behavior).
     */
    private function blank_to_zero(string $v): string
    {
        $v = trim($v);
        if ($v === '' || $v === '""') {
            return '0';
        }
        return $v;
    }

    /**
     * Convert source pounds to ounces for shipping_weight (DECIMAL(10,2) or NULL).
     * Accepts blank/"" => NULL.
     */
    private function pounds_to_ounces_or_null(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || $v === '""') {
            return null;
        }

        // Keep digits, dot, minus only; if it collapses to empty, return null.
        $clean = preg_replace('/[^0-9\.\-]/', '', $v);
        $clean = trim((string) $clean);

        if ($clean === '' || $clean === '-' || $clean === '.' || $clean === '-.') {
            return null;
        }

        $pounds = (float) $clean;
        $ounces = $pounds * 16.0;

        return number_format($ounces, 2, '.', '');
    }

    private function shipping_cost_from_distributor_price(string $v): string
    {
        $v = trim($v);
        if ($v === '' || $v === '""') {
            return '15';
        }

        $clean = preg_replace('/[^0-9\.\-]/', '', $v);
        $clean = trim((string) $clean);
        if ($clean === '' || $clean === '-' || $clean === '.' || $clean === '-.') {
            return '15';
        }

        return ((float) $clean >= 500.0) ? '0' : '15';
    }

    private function to_bool_flag(string $v): string
    {
        $v = strtoupper(trim($v));
        return in_array($v, ['YES', 'Y', '1', 'TRUE', 'T'], true) ? '1' : '0';
    }

    private function deduce_ffl_required(string $category): string
    {
        $c = strtoupper(trim($category));

        return in_array(
            $c,
            [
                'PISTOL',
                'REVOLVER',
                'RIFLE',
                'SHOTGUN',
                'OTHER FIREARMS',
                'RECEIVER',
                'PISTOL FRAMES',
                'DS SUPPRESSORS',
            ],
            true
        ) ? '1' : '0';
    }

    private function deduce_sot_required(string $category): string
    {
        $c = strtoupper(trim($category));
        return in_array($c, ['DS SUPPRESSORS'], true) ? '1' : '0';
    }

    private function combine_desc(string $d1, string $d2): string
    {
        $d1 = trim($d1);
        $d2 = trim($d2);

        if ($d1 === '') {
            return $d2;
        }

        if ($d2 === '') {
            return $d1;
        }

        return $d1 . ' ' . $d2;
    }
}
