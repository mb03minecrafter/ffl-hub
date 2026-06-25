<?php

namespace FFLHub\Distributor\Services\BillHicks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps Bill Hicks catalog CSV rows into our product table schema.
 *
 * The sample feed header contains a malformed manufacturer label
 * (`MFG_product"`), so this parser relies on the documented fixed column order
 * instead of trusting header names.
 */
final class BillHicksProductParser
{
    private const FFL_CATEGORY_CODES = ['H600', 'H601', 'H602', 'H603', 'H605', 'H606', 'H607', 'H608'];
    private const SOT_CATEGORY_CODES = ['H606', 'H607', 'H608'];

    /**
     * @param array<int,mixed> $csv
     * @return array<string,mixed>|null
     */
    public function parse_row(array $csv): ?array
    {
        if (count($csv) < 11) {
            return null;
        }

        $item_number = $this->clean_text($csv[0] ?? '');
        $upc = $this->clean_upc($csv[1] ?? '');
        if ($upc === '') {
            return null;
        }

        $short_description = $this->clean_text($csv[2] ?? '');
        $long_description = $this->clean_text($csv[3] ?? '');
        $category_code = strtoupper($this->clean_text($csv[4] ?? ''));
        $category_description = $this->clean_text($csv[5] ?? '');
        $price = $this->clean_money($csv[6] ?? '');
        $manufacturer = $this->clean_text($csv[7] ?? '');
        $manufacturer_norm = BillHicksFulfillmentPolicy::normalize_manufacturer($manufacturer);
        $weight_oz = $this->weight_lbs_to_oz($csv[8] ?? '');
        $map = $this->clean_money($csv[9] ?? '');
        $msrp = $this->clean_money($csv[10] ?? '');

        $ffl_required = in_array($category_code, self::FFL_CATEGORY_CODES, true) ? '1' : '0';
        $sot_required = in_array($category_code, self::SOT_CATEGORY_CODES, true) ? '1' : '0';

        $row = [
            'upc' => $upc,
            'bill_hicks_item_number' => $item_number,
            'manufacturer_number' => '',
            'inventory_quantity' => '0',
            'allocation_status' => 'out_of_stock',
            'distributor_price' => $price,
            'shipping_cost' => $this->shipping_cost($price, $category_code),
            'retail_map' => $map !== '' ? $map : '0',
            'retail_msrp' => $msrp !== '' ? $msrp : '0',
            'product_name' => $short_description,
            'product_description' => $long_description !== '' ? $long_description : $short_description,
            'manufacturer' => $manufacturer,
            'manufacturer_norm' => $manufacturer_norm,
            'model' => '',
            'caliber_gauge' => '',
            'item_type' => $category_description,
            'category' => $category_code,
            'image_url' => '',
            'shipping_weight' => $weight_oz,
            'shipping_length' => null,
            'shipping_width' => null,
            'shipping_height' => null,
            'ffl_required' => $ffl_required,
            'sot_required' => $sot_required,
            'dropship_enabled' => '1',
            'dropship_block_reason' => '',
            'status_code' => '',
            'last_change_date' => '',
            'last_change_time' => '',
        ];

        return BillHicksFulfillmentPolicy::apply_to_row($row);
    }

    /**
     * Bill Hicks shipping estimate:
     * - dealer price >= 500: free
     * - dealer price < 500 and pistol category: 20
     * - dealer price < 500 and anything else: 15
     */
    private function shipping_cost(string $price, string $category_code): string
    {
        $amount = $price !== '' ? (float) $price : 0.0;
        if ($amount >= 500.0) {
            return '0';
        }

        return $category_code === 'H602' ? '20' : '15';
    }

    /**
     * @param mixed $value
     */
    private function clean_text($value): string
    {
        return trim((string) $value);
    }

    /**
     * @param mixed $value
     */
    private function clean_upc($value): string
    {
        $clean = preg_replace('/[^0-9]/', '', trim((string) $value));
        return is_string($clean) ? $clean : '';
    }

    /**
     * @param mixed $value
     */
    private function clean_money($value): string
    {
        $clean = preg_replace('/[^0-9.\-]/', '', trim((string) $value));
        return is_string($clean) ? $clean : '';
    }

    /**
     * @param mixed $value
     */
    private function weight_lbs_to_oz($value): ?string
    {
        $clean = $this->clean_money($value);
        if ($clean === '') {
            return null;
        }

        return number_format(((float) $clean) * 16.0, 2, '.', '');
    }
}
