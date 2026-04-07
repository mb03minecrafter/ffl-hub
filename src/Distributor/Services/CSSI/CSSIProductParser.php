<?php

namespace FFLHub\Distributor\Services\CSSI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parser/mapper for CSSI product feed CSV rows and /items API rows.
 */
class CSSIProductParser
{
    /**
     * @param array<int,mixed> $header
     * @return array<string,int>
     */
    public function build_header_map(array $header): array
    {
        $headerMap = [];

        foreach ($header as $idx => $name) {
            $key = strtolower($this->normalize_scalar($name));
            if ($key === '') {
                continue;
            }
            $headerMap[$key] = (int) $idx;
        }

        return $headerMap;
    }

    /**
     * @param array<string,int> $headerMap
     */
    public function has_required_columns(array $headerMap): bool
    {
        $hasId = $this->first_index($headerMap, ['sku', 'cssi_id', 'item_id', 'item number', 'item_number']) !== null;
        $hasUpc = $this->first_index($headerMap, ['upc', 'upc_code', 'upc code']) !== null;

        return $hasId || $hasUpc;
    }

    /**
     * @param array<int,mixed> $csv
     * @param array<string,int> $headerMap
     * @return array<string,string>|null
     */
    public function parse_csv_row(array $csv, array $headerMap): ?array
    {
        if ($csv === [null] || count($csv) < 1) {
            return null;
        }

        $itemId = $this->get_csv($csv, $headerMap, [
            'sku',
            'cssi_id',
            'item_id',
            'item number',
            'item_number',
            'item_no',
        ]);
        $upc = $this->clean_upc($this->get_csv($csv, $headerMap, ['upc', 'upc_code', 'upc code']));

        if ($itemId === '' && $upc === '') {
            return null;
        }

        $inventory = $this->to_int_string($this->get_csv($csv, $headerMap, [
            'quantity in stock',
            'inventory',
            'quantity',
            'qty',
            'qty_available',
        ]));
        $inStockFlag = $this->to_flag($this->get_csv($csv, $headerMap, ['in_stock_flag', 'in stock flag']));
        $dropShipFlag = $this->to_flag($this->get_csv($csv, $headerMap, [
            'drop ship flag',
            'drop_ship_flag',
            'dropship flag',
        ]));
        $allocatedFlag = $this->to_flag($this->get_csv($csv, $headerMap, ['allocated item?', 'allocated_flag', 'allocated item']));
        $allocationStatus = $allocatedFlag === '1'
            ? 'allocated'
            : $this->allocation_status($inventory, $inStockFlag);

        return [
            'upc' => $upc,
            'cssi_item_number' => $itemId,

            'inventory_quantity' => $inventory,
            'in_stock_flag' => $inStockFlag,
            'allocation_status' => $allocationStatus,
            'distributor_price' => $this->clean_money($this->get_csv($csv, $headerMap, ['price', 'custom_price', 'dealer_price'])),
            'retail_map' => $this->clean_money($this->get_csv($csv, $headerMap, ['retail map', 'map', 'map_price', 'retail_map'])),
            'retail_msrp' => $this->clean_money($this->get_csv($csv, $headerMap, ['msrp', 'retail_price', 'retail_msrp'])),
            'drop_ship_price' => $this->clean_money($this->get_csv($csv, $headerMap, ['drop ship price', 'drop_ship_price', 'dropship_price'])),

            'product_name' => $this->get_csv($csv, $headerMap, ['web item name', 'item name', 'name', 'product_name']),
            'product_description' => $this->get_csv($csv, $headerMap, ['web item description', 'description', 'product_description']),
            'manufacturer' => $this->get_csv($csv, $headerMap, ['manufacturer', 'brand']),
            'model' => $this->get_csv($csv, $headerMap, ['model', 'model_series']),
            'mfg_model_number' => $this->get_csv($csv, $headerMap, ['manufacturer item number', 'manufacturer_model_no', 'mfg_model_number']),
            'caliber_gauge' => $this->get_csv($csv, $headerMap, ['caliber', 'caliber_gauge']),
            'item_type' => $this->get_csv($csv, $headerMap, ['category', 'item_type', 'type']),
            'serialized_flag' => $this->to_flag($this->get_csv($csv, $headerMap, ['serialized_flag', 'serialized flag'])),

            'ffl_required' => $this->to_flag($this->get_csv($csv, $headerMap, ['ffl_flag', 'ffl_required', 'ffl flag'])),
            'sot_required' => $this->to_flag($this->get_csv($csv, $headerMap, ['sot_required', 'nfa_required'])),
            'dropship_enabled' => $dropShipFlag,
            'dropship_block_reason' => ($dropShipFlag === '1') ? '' : 'drop_ship_flag=0',
            'drop_ship_delivery_options' => $this->get_csv($csv, $headerMap, ['available drop ship delivery options', 'available_drop_ship_delivery_options', 'drop_ship_delivery_options']),

            'shipping_weight' => $this->clean_decimal($this->get_csv($csv, $headerMap, ['ship weight', 'shipping_weight', 'weight'])),
            'shipping_length_in' => $this->get_csv($csv, $headerMap, ['length', 'shipping_length_in']),
            'shipping_width_in' => $this->get_csv($csv, $headerMap, ['width', 'shipping_width_in']),
            'shipping_height_in' => $this->get_csv($csv, $headerMap, ['height', 'shipping_height_in']),
            'last_seen_utc' => $this->get_csv($csv, $headerMap, ['qas_last_updated', 'qas_last_updated_after', 'last_updated_utc']),

            // Present in CSSI CSV, currently not persisted in schema:
            'image_location' => $this->get_csv($csv, $headerMap, ['image location', 'image_url']),
            'specifications' => $this->get_csv($csv, $headerMap, ['specifications']),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,string>|null
     */
    public function parse_api_item(array $item): ?array
    {
        $itemId = $this->get_array($item, ['cssi_id', 'item_id', 'item_number', 'itemNo']);
        $upc = $this->clean_upc($this->get_array($item, ['upc_codes', 'upc', 'upc_code']));

        if ($itemId === '' && $upc === '') {
            return null;
        }

        $inventory = $this->to_int_string($this->get_array($item, ['inventory', 'quantity']));
        $inStockFlag = $this->to_flag($this->get_array($item, ['in_stock_flag']));
        $dropShipFlag = $this->to_flag($this->get_array($item, ['drop_ship_flag']));

        return [
            'upc' => $upc,
            'cssi_item_number' => $itemId,

            'inventory_quantity' => $inventory,
            'in_stock_flag' => $inStockFlag,
            'allocation_status' => $this->allocation_status($inventory, $inStockFlag),
            'distributor_price' => $this->clean_money($this->get_array($item, ['custom_price', 'price'])),
            'retail_map' => $this->clean_money($this->get_array($item, ['map_price'])),
            'retail_msrp' => $this->clean_money($this->get_array($item, ['retail_price', 'msrp'])),
            'drop_ship_price' => $this->clean_money($this->get_array($item, ['drop_ship_price'])),

            'product_name' => $this->get_array($item, ['name', 'product_name']),
            'product_description' => $this->get_array($item, ['description', 'product_description']),
            'manufacturer' => $this->get_array($item, ['manufacturer', 'brand']),
            'model' => $this->get_array($item, ['model', 'model_series']),
            'mfg_model_number' => $this->get_array($item, ['manufacturer_model_no', 'mfg_model_number']),
            'caliber_gauge' => $this->get_array($item, ['caliber', 'caliber_gauge']),
            'item_type' => $this->get_array($item, ['item_type', 'type', 'category']),
            'serialized_flag' => $this->to_flag($this->get_array($item, ['serialized_flag'])),

            'ffl_required' => $this->to_flag($this->get_array($item, ['ffl_flag', 'ffl_required'])),
            'sot_required' => $this->to_flag($this->get_array($item, ['sot_required', 'nfa_required'])),
            'dropship_enabled' => $dropShipFlag,
            'dropship_block_reason' => ($dropShipFlag === '1') ? '' : 'drop_ship_flag=0',
            'drop_ship_delivery_options' => $this->get_array($item, ['available_drop_ship_delivery_options', 'drop_ship_delivery_options']),

            'shipping_weight' => $this->clean_decimal($this->get_array($item, ['shipping_weight', 'weight'])),
            'shipping_length_in' => $this->get_array($item, ['shipping_length_in', 'length']),
            'shipping_width_in' => $this->get_array($item, ['shipping_width_in', 'width']),
            'shipping_height_in' => $this->get_array($item, ['shipping_height_in', 'height']),
            'last_seen_utc' => $this->get_array($item, ['qas_last_updated_at', 'qas_last_updated_after', 'qas_last_updated', 'last_updated_utc']),
        ];
    }

    /**
     * @param array<int,mixed> $row
     * @param array<string,int> $headerMap
     * @param array<int,string> $aliases
     */
    private function get_csv(array $row, array $headerMap, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $idx = $this->first_index($headerMap, [$alias]);
            if ($idx === null) {
                continue;
            }

            return $this->normalize_scalar($row[$idx] ?? '');
        }

        return '';
    }

    /**
     * @param array<string,mixed> $item
     * @param array<int,string> $keys
     */
    private function get_array(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }
            $value = $this->normalize_scalar($item[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,int> $headerMap
     * @param array<int,string> $aliases
     */
    private function first_index(array $headerMap, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $key = strtolower(trim((string) $alias));
            if ($key === '') {
                continue;
            }
            if (isset($headerMap[$key])) {
                return (int) $headerMap[$key];
            }
        }

        return null;
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

    private function clean_upc(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = trim($value, "# \t\r\n");
        $digits = preg_replace('/\D+/', '', $value);
        $digits = is_string($digits) ? trim($digits) : '';

        return $digits;
    }

    private function clean_money(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(['$', ','], '', $value);
        $value = trim($value);

        if ($value === '' || $value === '-' || $value === '.') {
            return '';
        }

        return $value;
    }

    private function clean_decimal(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^0-9.\-]/', '', $value);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || $value === '-' || $value === '.') {
            return '';
        }

        return $value;
    }

    private function to_int_string(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '0';
        }

        $num = (int) round((float) str_replace(',', '', $value));
        if ($num < 0) {
            $num = 0;
        }

        return (string) $num;
    }

    private function to_flag(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '0';
        }

        if (in_array($value, ['1', 'Y', 'YES', 'TRUE', 'T', 'ON'], true)) {
            return '1';
        }

        if (is_numeric($value) && (float) $value > 0) {
            return '1';
        }

        return '0';
    }

    private function allocation_status(string $inventory, string $inStockFlag): string
    {
        if ((int) $inventory > 0) {
            return 'in_stock';
        }

        if ($inStockFlag === '1') {
            return 'in_stock';
        }

        return 'out_of_stock';
    }
}
