<?php

namespace FFLHub\Distributor\Services\Kinseys;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps Kinsey's catalog and inventory records into the local fulfillment schema.
 */
final class KinseysProductParser
{
    /**
     * @param array<string,mixed> $product
     * @param array<string,array<string,mixed>> $inventoryLookup
     * @return array<string,mixed>|null
     */
    public function parse_product(array $product, array $inventoryLookup = []): ?array
    {
        if (!$this->can_drop_ship($product)) {
            return null;
        }

        $upc = $this->normalize_upc($this->string_value($product, 'BarCode'));
        if ($upc === '') {
            return null;
        }

        $north_item_number = $this->string_value($product, 'NorthItemNumber');
        $south_item_number = $this->string_value($product, 'SouthItemNumber');
        $vendor_item_number = $this->string_value($product, 'VendorItemNumber');
        $product_id = $north_item_number !== '' ? $north_item_number : ($south_item_number !== '' ? $south_item_number : $vendor_item_number);

        $inventory = $this->find_inventory_row($product_id, $north_item_number, $south_item_number, $vendor_item_number, $upc, $inventoryLookup);
        $quantity = $this->inventory_quantity($inventory);
        $distributor_price = $this->money_string($this->string_value($inventory, 'price'));
        if ($distributor_price === '') {
            $distributor_price = $this->money_string($this->string_value($product, 'UnitPrice'));
        }

        $map_price = $this->money_string($this->string_value($inventory, 'map'));
        if ($map_price === '') {
            $map_price = $this->money_string($this->string_value($product, 'MAPPrice'));
        }

        $blocked = $this->boolish($product['Blocked'] ?? null);
        $inactive = $this->boolish($product['Inactive'] ?? null);
        $dropship = $this->boolish($product['CanBeDropShipped'] ?? null);
        $dropship_block_reason = '';
        if (!$dropship) {
            $dropship_block_reason = 'not_dropship_enabled';
        } elseif ($blocked) {
            $dropship_block_reason = 'blocked';
            $dropship = false;
        } elseif ($inactive) {
            $dropship_block_reason = 'inactive';
            $dropship = false;
        }

        $name = $this->clean_text($this->string_value($product, 'Name'));
        if ($name === '') {
            $name = trim($this->clean_text($this->string_value($product, 'Description1') . ' ' . $this->string_value($product, 'Description2')));
        }

        $description = $this->clean_text($this->string_value($product, 'ExtendedText'));
        if ($description === '') {
            $description = $this->clean_text(str_replace(';', '. ', $this->string_value($product, 'BulletFeatures')));
        }

        $item_category_code = $this->clean_text($this->string_value($product, 'ItemCategoryCode'));
        $product_group_code = $this->clean_text($this->string_value($product, 'ProductGroupCode'));
        $product_sub_group_1 = $this->clean_text($this->string_value($product, 'ProductSubGroup1'));
        $product_sub_group_2 = $this->clean_text($this->string_value($product, 'ProductSubGroup2'));
        $include_exclude_group = $this->clean_text($this->string_value($product, 'IncludeExcludeGroup'));
        $nav_inventory_posting_group = $this->clean_text($this->string_value($product, 'NAVInventoryPostingGroup'));
        $category_codes = [
            $item_category_code,
            $product_group_code,
            $product_sub_group_1,
            $product_sub_group_2,
        ];
        $sot_required = $this->is_sot_required_by_category($category_codes, $include_exclude_group);
        $ffl_required = $sot_required || $this->is_ffl_required_by_category($category_codes);
        $product_categories = $this->product_categories([
            $item_category_code,
            $product_group_code,
            $product_sub_group_1,
            $product_sub_group_2,
        ]);
        $restricted_states = $this->clean_text($this->string_value($product, 'ProhibitedStates'));

        return [
            'upc' => $upc,
            'kinseys_product_id' => $product_id,
            'remote_identifier' => $product_id,
            'north_item_number' => $north_item_number,
            'south_item_number' => $south_item_number,
            'vendor_item_number' => $vendor_item_number,

            'inventory_quantity' => (string) max(0, $quantity),
            'allocation_status' => $quantity > 0 ? 'in_stock' : 'out_of_stock',
            'distributor_price' => $distributor_price,
            'retail_map' => $map_price,
            'retail_msrp' => $this->money_string($this->string_value($product, 'MSRP')),
            'unit_price' => $this->money_string($this->string_value($product, 'UnitPrice')),
            'restock_eta' => $this->clean_text($this->string_value($inventory, 'RestockETA')),
            'warehouses_json' => $this->encode_json($inventory['Warehouses'] ?? []),

            'product_name' => $name,
            'product_description' => $description,
            'manufacturer' => $this->clean_text($this->string_value($product, 'Brand')),
            'model' => $this->clean_text($vendor_item_number),
            'mfg_model_number' => $this->clean_text($vendor_item_number),
            'item_type' => $nav_inventory_posting_group !== '' ? $nav_inventory_posting_group : ($product_group_code !== '' ? $product_group_code : $item_category_code),
            'caliber_gauge' => '',
            'description_1' => $this->clean_text($this->string_value($product, 'Description1')),
            'description_2' => $this->clean_text($this->string_value($product, 'Description2')),
            'bullet_features' => $this->clean_text($this->string_value($product, 'BulletFeatures')),
            'product_categories' => $product_categories,
            'country_of_origin' => $this->clean_text($this->string_value($product, 'CountryOfOrigin')),
            'item_category_code' => $item_category_code,
            'product_group_code' => $product_group_code,
            'product_sub_group_1' => $product_sub_group_1,
            'product_sub_group_2' => $product_sub_group_2,
            'pack_size' => $this->clean_text($this->string_value($product, 'PackSize')),
            'include_exclude_group' => $include_exclude_group,
            'prohibited_states' => $restricted_states,
            'restricted_states' => $restricted_states,
            'nav_inventory_posting_group' => $nav_inventory_posting_group,

            'ffl_required' => $ffl_required ? '1' : '0',
            'sot_required' => $sot_required ? '1' : '0',
            'dropship_enabled' => $dropship ? '1' : '0',
            'dropship_block_reason' => $dropship_block_reason,
            'serializable' => $ffl_required ? '1' : '0',
            'cannot_dropship' => $dropship ? '0' : '1',
            'can_be_dropshipped' => $this->boolish($product['CanBeDropShipped'] ?? null) ? '1' : '0',
            'blocked_flag' => $blocked ? '1' : '0',
            'inactive_flag' => $inactive ? '1' : '0',
            'hazardous_flag' => $this->boolish($product['Hazardous'] ?? null) ? '1' : '0',
            'flammable_flag' => $this->boolish($product['Flammable'] ?? null) ? '1' : '0',
            'prop65_applies' => $this->boolish($product['Prop65Applies'] ?? null) ? '1' : '0',
            'prop65_cancer_harm' => $this->boolish($product['Prop65CancerHarm'] ?? null) ? '1' : '0',
            'prop65_reproductive_harm' => $this->boolish($product['Prop65ReproductiveHarm'] ?? null) ? '1' : '0',
            'prop65_chemical' => $this->clean_text($this->string_value($product, 'Prop65Chemical')),

            'shipping_weight' => $this->weight_ounces($this->string_value($product, 'Weight')),
            'shipping_length_in' => $this->dimension_string($this->string_value($product, 'ProductLength')),
            'shipping_width_in' => $this->dimension_string($this->string_value($product, 'ProductWidth')),
            'shipping_height_in' => $this->dimension_string($this->string_value($product, 'ProductHeight')),
            'image_url' => '',
            'image_urls_json' => '[]',
            'color_1' => $this->clean_text($this->string_value($product, 'Color1')),
            'color_2' => $this->clean_text($this->string_value($product, 'Color2')),
            'size' => $this->clean_text($this->string_value($product, 'Size')),
            'rh_lh' => $this->clean_text($this->string_value($product, 'RHLH')),
            'parent_child_sku' => $this->clean_text($this->string_value($product, 'ParentChildSKU')),
            'parent_child_option_1' => $this->clean_text($this->string_value($product, 'ParentChildOption1')),
            'parent_child_option_2' => $this->clean_text($this->string_value($product, 'ParentChildOption2')),
            'parent_child_option_3' => $this->clean_text($this->string_value($product, 'ParentChildOption3')),
            'parent_child_option_4' => $this->clean_text($this->string_value($product, 'ParentChildOption4')),
            'date_created' => $this->clean_text($this->string_value($product, 'DateCreated')),
            'last_seen_utc' => gmdate('Y-m-d H:i:s'),
            'raw_item_json' => $this->encode_json($product),
        ];
    }

    /**
     * @param array<string,mixed> $product
     */
    public function can_drop_ship(array $product): bool
    {
        return $this->boolish($product['CanBeDropShipped'] ?? null);
    }

    /**
     * @param array<string,mixed> $inventoryResponseOrRows
     * @return array<string,array<string,mixed>>
     */
    public function build_inventory_lookup(array $inventoryResponseOrRows): array
    {
        $rows = $this->normalize_inventory_rows($inventoryResponseOrRows);
        $lookup = [];

        foreach ($rows as $row) {
            foreach ($this->inventory_keys($row) as $key) {
                $lookup[$key] = $row;
            }
        }

        return $lookup;
    }

    /**
     * @param array<string,mixed> $inventoryResponseOrRows
     * @return array<int,array<string,mixed>>
     */
    public function normalize_inventory_rows(array $inventoryResponseOrRows): array
    {
        if (isset($inventoryResponseOrRows['Products']) && is_array($inventoryResponseOrRows['Products'])) {
            $inventoryResponseOrRows = $inventoryResponseOrRows['Products'];
        } elseif (isset($inventoryResponseOrRows['products']) && is_array($inventoryResponseOrRows['products'])) {
            $inventoryResponseOrRows = $inventoryResponseOrRows['products'];
        }

        $rows = [];
        foreach ($inventoryResponseOrRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $productResponseOrRows
     * @return array<int,array<string,mixed>>
     */
    public function normalize_product_rows(array $productResponseOrRows): array
    {
        if (isset($productResponseOrRows['Products']) && is_array($productResponseOrRows['Products'])) {
            $productResponseOrRows = $productResponseOrRows['Products'];
        } elseif (isset($productResponseOrRows['products']) && is_array($productResponseOrRows['products'])) {
            $productResponseOrRows = $productResponseOrRows['products'];
        }

        $rows = [];
        foreach ($productResponseOrRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function inventory_keys(array $row): array
    {
        $keys = [];
        foreach (['productId' => 'id:', 'manufacturerId' => 'manufacturer:', 'upc' => 'upc:'] as $field => $prefix) {
            $value = $field === 'upc' ? $this->normalize_upc($this->string_value($row, $field)) : $this->string_value($row, $field);
            if ($value !== '') {
                $keys[$prefix . strtoupper($value)] = $prefix . strtoupper($value);
            }
        }

        return array_values($keys);
    }

    /**
     * @param array<string,mixed> $inventoryLookup
     * @return array<string,mixed>
     */
    private function find_inventory_row(
        string $productId,
        string $northItemNumber,
        string $southItemNumber,
        string $vendorItemNumber,
        string $upc,
        array $inventoryLookup
    ): array {
        $candidates = [];
        foreach ([$productId, $northItemNumber, $southItemNumber] as $id) {
            $id = trim($id);
            if ($id !== '') {
                $candidates[] = 'id:' . strtoupper($id);
            }
        }
        if ($vendorItemNumber !== '') {
            $candidates[] = 'manufacturer:' . strtoupper($vendorItemNumber);
        }
        if ($upc !== '') {
            $candidates[] = 'upc:' . strtoupper($upc);
        }

        foreach ($candidates as $candidate) {
            if (isset($inventoryLookup[$candidate]) && is_array($inventoryLookup[$candidate])) {
                return $inventoryLookup[$candidate];
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function inventory_quantity(array $row): int
    {
        if (!isset($row['quantityOnHand']) || !is_numeric($row['quantityOnHand'])) {
            return 0;
        }

        return max(0, (int) floor((float) $row['quantityOnHand']));
    }

    /**
     * @param string[] $parts
     */
    private function product_categories(array $parts): string
    {
        $categories = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $categories[$part] = $part;
            }
        }

        return implode(' > ', array_values($categories));
    }

    /**
     * @param string[] $categoryCodes
     */
    private function is_ffl_required_by_category(array $categoryCodes): bool
    {
        foreach ($categoryCodes as $code) {
            if ($this->category_code_starts_with($code, '7400')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $categoryCodes
     */
    private function is_sot_required_by_category(array $categoryCodes, string $includeExcludeGroup): bool
    {
        if (preg_match('/\bSOT\b/i', $includeExcludeGroup) === 1) {
            return true;
        }

        foreach ($categoryCodes as $code) {
            if ($this->category_code_starts_with($code, '7400H')) {
                return true;
            }
        }

        return false;
    }

    private function category_code_starts_with(string $code, string $prefix): bool
    {
        $code = strtoupper(trim($code));
        $prefix = strtoupper(trim($prefix));
        return $code !== '' && $prefix !== '' && strpos($code, $prefix) === 0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function string_value(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return '';
        }

        return trim((string) $row[$key]);
    }

    private function normalize_upc(string $upc): string
    {
        $normalized = preg_replace('/\D+/', '', $upc);
        return is_string($normalized) ? trim($normalized) : '';
    }

    private function money_string(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^0-9.\-]/', '', $value);
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '';
        }

        $amount = (float) $value;
        if (!is_finite($amount) || $amount < 0.0) {
            return '';
        }

        return number_format($amount, 2, '.', '');
    }

    private function dimension_string(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        $num = (float) $value;
        if (!is_finite($num) || $num <= 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.');
    }

    private function weight_ounces(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        $pounds = (float) $value;
        if (!is_finite($pounds) || $pounds <= 0.0) {
            return '';
        }

        return number_format($pounds * 16.0, 2, '.', '');
    }

    private function clean_text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('wp_strip_all_tags')) {
            $value = wp_strip_all_tags($value);
        } else {
            $value = strip_tags($value);
        }
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * @param mixed $value
     */
    private function boolish($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * @param mixed $value
     */
    private function encode_json($value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }
}
