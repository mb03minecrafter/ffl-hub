<?php

namespace FFLHub\Distributor\Services\Orion;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps Orion catalog + inventory records into the local fulfillment schema.
 */
final class OrionProductParser
{
    private const FLAT_SHIPPING_COST = '13.00';

    /**
     * @param array<string,mixed> $product
     * @param array<string,mixed> $inventoryLookup
     * @return array<string,mixed>|null
     */
    public function parse_product(array $product, array $inventoryLookup = []): ?array
    {
        $upc = $this->normalize_upc($this->string_value($product, 'upc_code'));
        if ($upc === '') {
            return null;
        }

        $product_id = $this->string_value($product, 'product_id');
        $product_code = $this->string_value($product, 'product_code');
        $inventory = $this->find_inventory_row($product_id, $product_code, $inventoryLookup);

        $quantity = $this->string_value($inventory, 'quantity');
        if ($quantity === '') {
            $quantity = '0';
        }

        $sale_price = $this->money_string($this->string_value($inventory, 'sale_price'));
        $base_cost = $this->money_string($this->string_value($product, 'base_cost'));
        $list_price = $this->money_string($this->string_value($product, 'list_price'));
        $map_price = $this->money_string($this->string_value($product, 'manufacturer_advertised_price'));

        $distributor_price = $sale_price !== '' ? $sale_price : ($base_cost !== '' ? $base_cost : $list_price);
        $product_tags = $this->string_value($product, 'product_tags');
        $product_categories = $this->string_value($product, 'product_categories');
        $facets = isset($product['facets']) && is_array($product['facets']) ? $product['facets'] : [];
        $image_urls = $this->image_urls($product);

        $dropship_info = $this->dropship_info($product, $product_tags);

        $row = [
            'upc' => $upc,
            'orion_product_id' => $product_id,
            'orion_product_code' => $product_code,
            'remote_identifier' => $this->string_value($product, 'remote_identifier'),

            'inventory_quantity' => (string) max(0, (int) $quantity),
            'allocation_status' => ((int) $quantity > 0) ? 'in_stock' : 'out_of_stock',
            'distributor_price' => $distributor_price,
            'shipping_cost' => self::FLAT_SHIPPING_COST,
            'retail_map' => $map_price,
            'retail_msrp' => $list_price,
            'base_cost' => $base_cost,
            'sale_price' => $sale_price,

            'product_name' => $this->clean_text($this->string_value($product, 'description')),
            'product_description' => $this->clean_text($this->string_value($product, 'detailed_description')),
            'manufacturer' => $this->clean_text($this->string_value($product, 'product_manufacturer')),
            'model' => $this->clean_text($this->string_value($product, 'model')),
            'mfg_model_number' => $this->clean_text($this->string_value($product, 'manufacturer_sku')),
            'item_type' => $this->clean_text($this->string_value($product, 'product_type')),
            'product_format' => $this->clean_text($this->string_value($product, 'product_format')),
            'unit' => $this->clean_text($this->string_value($product, 'unit')),
            'product_categories' => $this->clean_text($product_categories),
            'product_tags' => $this->clean_text($product_tags),
            'restricted_states' => $this->clean_text($this->string_value($product, 'restricted_states')),
            'facets_json' => $this->encode_json($facets),

            'ffl_required' => $this->is_ffl_required($product_tags) ? '1' : '0',
            'sot_required' => $this->is_sot_required($product_tags) ? '1' : '0',
            'dropship_enabled' => $dropship_info['enabled'] ? '1' : '0',
            'dropship_block_reason' => $dropship_info['reason'],
            'serializable' => $this->boolish($product['serializable'] ?? null) ? '1' : '0',
            'cannot_dropship' => $this->boolish($product['cannot_dropship'] ?? null) ? '1' : '0',

            'shipping_weight' => $this->weight_ounces($this->string_value($product, 'weight')),
            'shipping_length_in' => $this->dimension_string($this->string_value($product, 'length')),
            'shipping_width_in' => $this->dimension_string($this->string_value($product, 'width')),
            'shipping_height_in' => $this->dimension_string($this->string_value($product, 'height')),
            'image_url' => $image_urls[0] ?? '',
            'image_urls_json' => $this->encode_json($image_urls),
            'last_seen_utc' => gmdate('Y-m-d H:i:s'),
        ];

        return $row;
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
            $product_id = $this->string_value($row, 'product_id');
            $product_code = $this->string_value($row, 'product_code');

            if ($product_id !== '') {
                $lookup['id:' . $product_id] = $row;
            }
            if ($product_code !== '') {
                $lookup['code:' . strtoupper($product_code)] = $row;
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
        if (isset($inventoryResponseOrRows['product_inventory']) && is_array($inventoryResponseOrRows['product_inventory'])) {
            $inventoryResponseOrRows = $inventoryResponseOrRows['product_inventory'];
        }

        $rows = [];
        foreach ($inventoryResponseOrRows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!isset($row['product_id']) && is_scalar($key) && trim((string) $key) !== '') {
                $row['product_id'] = (string) $key;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $product
     * @return string[]
     */
    private function image_urls(array $product): array
    {
        $urls = [];
        $primary = $this->string_value($product, 'image_url');
        if ($primary !== '') {
            $urls[$primary] = $primary;
        }

        if (isset($product['image_urls']) && is_array($product['image_urls'])) {
            foreach ($product['image_urls'] as $entry) {
                if (is_array($entry)) {
                    $url = $this->string_value($entry, 'url');
                } else {
                    $url = trim((string) $entry);
                }
                if ($url !== '') {
                    $urls[$url] = $url;
                }
            }
        }

        return array_values($urls);
    }

    /**
     * @param array<string,mixed> $product
     * @return array{enabled:bool,reason:string}
     */
    private function dropship_info(array $product, string $tags): array
    {
        if ($this->boolish($product['cannot_dropship'] ?? null)) {
            return ['enabled' => false, 'reason' => 'cannot_dropship'];
        }

        $tag_list = $this->tokenize($tags);
        foreach (['CANNOT_DROPSHIP_API', 'CANNOT_DROPSHIP'] as $blocked_tag) {
            if (in_array($blocked_tag, $tag_list, true)) {
                return ['enabled' => false, 'reason' => strtolower($blocked_tag)];
            }
        }

        if (!in_array('CAN_DROPSHIP', $tag_list, true)) {
            return ['enabled' => false, 'reason' => 'missing_can_dropship_tag'];
        }

        return ['enabled' => true, 'reason' => ''];
    }

    private function is_ffl_required(string $tags): bool
    {
        return in_array('FFL_REQUIRED', $this->tokenize($tags), true);
    }

    private function is_sot_required(string $tags): bool
    {
        // Orion's explicit CLASS_3 tag is the reliable SOT/NFA signal.
        // Facets can contain phrases like "Suppressor Height Sights", which
        // describe ordinary firearm features and must not trigger SOT status.
        return in_array('CLASS_3', $this->tokenize($tags), true);
    }

    /**
     * @param array<string,mixed> $inventoryLookup
     * @return array<string,mixed>
     */
    private function find_inventory_row(string $productId, string $productCode, array $inventoryLookup): array
    {
        if ($productId !== '' && isset($inventoryLookup['id:' . $productId]) && is_array($inventoryLookup['id:' . $productId])) {
            return $inventoryLookup['id:' . $productId];
        }

        $code_key = 'code:' . strtoupper($productCode);
        if ($productCode !== '' && isset($inventoryLookup[$code_key]) && is_array($inventoryLookup[$code_key])) {
            return $inventoryLookup[$code_key];
        }

        return [];
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
     * @return string[]
     */
    private function tokenize(string $value): array
    {
        $parts = preg_split('/[,|;\s]+/', strtoupper($value));
        $tokens = [];
        foreach ((array) $parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $tokens[$part] = $part;
            }
        }

        return array_values($tokens);
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
