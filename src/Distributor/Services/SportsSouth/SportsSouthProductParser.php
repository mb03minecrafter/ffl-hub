<?php

namespace FFLHub\Distributor\Services\SportsSouth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps Sports South DailyItemUpdate and IncrementalOnhandUpdate XML rows.
 */
final class SportsSouthProductParser
{
    private const IMAGE_BASE = 'https://media.server.theshootingwarehouse.com';

    /**
     * @param callable(array<string,mixed>):void $callback
     */
    public function each_catalog_row(string $filePath, callable $callback): int
    {
        return $this->each_xml_row(
            $filePath,
            ['Table', 'Item', 'ITEM', 'Product', 'DailyItem', 'InventoryItem'],
            ['ITEMNO', 'ITEMNUMBER'],
            function (array $raw) use ($callback): void {
                $row = $this->parse_product($raw);
                if (is_array($row)) {
                    $callback($row);
                }
            }
        );
    }

    /**
     * @param callable(array<string,mixed>):void $callback
     */
    public function each_onhand_row(string $filePath, callable $callback): int
    {
        return $this->each_xml_row(
            $filePath,
            ['Onhand', 'Table'],
            ['I', 'ITEMNO', 'ITEMNUMBER'],
            function (array $raw) use ($callback): void {
                $row = $this->parse_onhand($raw);
                if (is_array($row)) {
                    $callback($row);
                }
            }
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public function parse_product(array $raw): ?array
    {
        $upc = $this->normalize_upc($this->first($raw, ['UPC', 'ITUPC', 'U', 'BARCODE', 'GTIN']));
        if ($upc === '') {
            return null;
        }

        $item_number = $this->clean_text($this->first($raw, ['ITEMNO', 'ITEMNUMBER', 'I']));
        if ($item_number === '') {
            return null;
        }

        $name = $this->clean_text($this->first($raw, ['ITDESC', 'DESC', 'DESCRIPTION', 'IDESC', 'ITEMDESC', 'NAME']));
        $description = $this->clean_text($this->first($raw, ['LONGDESC', 'LONGDESCRIPTION', 'TEXT', 'DESCRIPTION', 'IDESC', 'ITDESC']));
        $category_id = $this->clean_text($this->first($raw, ['CATID', 'CATEGORYID', 'CAT']));
        $item_type = $this->clean_text($this->first($raw, ['CATDESC', 'CATEGORY', 'TYPE', 'ITEMTYPE', 'DEPT']));
        $manufacturer = $this->clean_text($this->first($raw, ['BRAND', 'BRDNAM', 'MFG', 'MANUFACTURER', 'ITBRD']));
        $brand_number = $this->clean_text($this->first($raw, ['ITBRDNO', 'BRDNO', 'BRANDNO']));
        $mfg_part = $this->clean_text($this->first($raw, ['MFGITEMNO', 'MFGNO', 'MFGITEM', 'ITMFGNO', 'M', 'MANUFACTURERPARTNUMBER']));
        $model = $this->clean_text($this->first($raw, ['MODEL', 'ITMODEL']));
        $image_ref = $this->clean_text($this->first($raw, ['PICREF', 'PICTURE', 'IMAGE']));
        if ($image_ref === '') {
            $image_ref = $item_number;
        }

        $image_urls = $this->image_urls($image_ref);
        $restricted_states = $this->clean_text($this->first($raw, ['RESTRICTEDSTATES', 'STATE_RESTRICTIONS', 'STATES']));
        $haystack = strtoupper(trim($name . ' ' . $description . ' ' . $item_type . ' ' . $category_id));

        $row = [
            'upc' => $upc,
            'sports_south_item_number' => $item_number,
            'remote_identifier' => $item_number,

            'inventory_quantity' => '0',
            'allocation_status' => 'out_of_stock',
            'distributor_price' => $this->money_string($this->first($raw, ['C', 'CUSTOMERPRICE', 'CUSTOMER_PRICE', 'PRICE'])),
            'catalog_price' => $this->money_string($this->first($raw, ['P', 'CATALOGPRICE', 'CATALOG_PRICE', 'LISTPRICE'])),
            'retail_map' => $this->money_string($this->first($raw, ['MAP', 'ITMAP', 'MINADVERTISEDPRICE'])),
            'retail_msrp' => $this->money_string($this->first($raw, ['MSRP', 'ITMSRP', 'RETAIL', 'MFPRC'])),

            'product_name' => $name,
            'product_description' => $description !== '' ? $description : $name,
            'manufacturer' => $manufacturer,
            'brand_number' => $brand_number,
            'model' => $model,
            'manufacturer_part_number' => $mfg_part,
            'category_id' => $category_id,
            'item_type' => $item_type,
            'caliber_gauge' => $this->clean_text($this->first($raw, ['CALIBER', 'GAUGE', 'CALGAUGE'])),
            'attributes_json' => $this->encode_json($this->extract_attributes($raw)),

            'ffl_required' => $this->looks_ffl_required($haystack) ? '1' : '0',
            'sot_required' => $this->looks_sot_required($haystack) ? '1' : '0',
            'dropship_enabled' => $this->dropship_enabled($raw) ? '1' : '0',
            'dropship_block_reason' => $this->dropship_enabled($raw) ? '' : 'feed_flag',
            'restricted_states' => $restricted_states,

            'shipping_weight' => $this->decimal_string($this->first($raw, ['WEIGHT', 'WT', 'SHPWT'])),
            'shipping_length_in' => $this->dimension_string($this->first($raw, ['LENGTH', 'LEN', 'SHPLEN'])),
            'shipping_width_in' => $this->dimension_string($this->first($raw, ['WIDTH', 'WID', 'SHPWID'])),
            'shipping_height_in' => $this->dimension_string($this->first($raw, ['HEIGHT', 'HGT', 'SHPHGT'])),
            'image_ref' => $image_ref,
            'image_url' => $image_urls[0] ?? '',
            'image_urls_json' => $this->encode_json($image_urls),
            'text_ref' => $this->clean_text($this->first($raw, ['TXTREF', 'TEXTREF'])),

            'last_seen_utc' => gmdate('Y-m-d H:i:s'),
            'last_onhand_utc' => '',
            'raw_item_json' => $this->encode_json($raw),
        ];

        if ($row['distributor_price'] === '' && $row['catalog_price'] !== '') {
            $row['distributor_price'] = $row['catalog_price'];
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public function parse_onhand(array $raw): ?array
    {
        $item_number = $this->clean_text($this->first($raw, ['I', 'ITEMNO', 'ITEMNUMBER']));
        $upc = $this->normalize_upc($this->first($raw, ['U', 'UPC', 'ITUPC']));
        if ($item_number === '' && $upc === '') {
            return null;
        }

        $quantity = $this->first($raw, ['Q', 'QUANTITY', 'QTY', 'ONHAND']);
        if ($quantity === '') {
            return null;
        }

        return [
            'item_number' => $item_number,
            'upc' => $upc,
            'quantity_delta' => (string) ((int) $quantity),
            'catalog_price' => $this->money_string($this->first($raw, ['P', 'CATALOGPRICE', 'CATALOG_PRICE'])),
            'customer_price' => $this->money_string($this->first($raw, ['C', 'CUSTOMERPRICE', 'CUSTOMER_PRICE'])),
        ];
    }

    public function extract_next_since_datetime(string $xml): string
    {
        $candidates = [];
        if (preg_match_all('/<([A-Za-z0-9_:\-]*?(?:SinceDateTime|SinceDate|TimeStamp|Timestamp|DATETIME|LASTUPDATE)[A-Za-z0-9_:\-]*)\b[^>]*>(.*?)<\/\1>/is', $xml, $m)) {
            foreach ($m[2] as $value) {
                $value = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        if (preg_match_all('/\d{4}\-\d{2}\-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?[+\-]\d{2}:?\d{2}/', $xml, $m)) {
            foreach ($m[0] as $value) {
                $candidates[] = (string) $value;
            }
        }

        foreach (array_reverse($candidates) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $this->format_since_datetime($candidate);
            }
        }

        return '';
    }

    public function format_since_datetime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return gmdate('Y-m-d\TH:i:s.00+00.00');
        }

        $value = str_replace('Z', '+00:00', $value);
        $value = preg_replace('/([+\-]\d{2})\.(\d{2})$/', '$1:$2', $value);
        $value = is_string($value) ? $value : '';

        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }

        return gmdate('Y-m-d\TH:i:s.00+00.00', $ts);
    }

    /**
     * @param string[] $candidateNodes
     * @param string[] $requiredKeys
     * @param callable(array<string,mixed>):void $callback
     */
    private function each_xml_row(string $filePath, array $candidateNodes, array $requiredKeys, callable $callback): int
    {
        if (!is_readable($filePath)) {
            return 0;
        }

        $count = 0;

        if (class_exists('\XMLReader')) {
            $reader = new \XMLReader();
            if ($reader->open($filePath, null, LIBXML_NONET | LIBXML_NOCDATA)) {
                while ($reader->read()) {
                    if ($reader->nodeType !== \XMLReader::ELEMENT) {
                        continue;
                    }

                    if (!in_array($reader->localName, $candidateNodes, true)) {
                        continue;
                    }

                    $outer = $reader->readOuterXML();
                    if (!is_string($outer) || trim($outer) === '') {
                        continue;
                    }

                    $raw = $this->raw_map_from_xml($outer);
                    if (!$this->has_any_key($raw, $requiredKeys)) {
                        continue;
                    }

                    $callback($raw);
                    $count++;
                }

                $reader->close();
            }
        }

        if ($count > 0) {
            return $count;
        }

        $xml = (string) file_get_contents($filePath);
        $xml = $this->decode_if_escaped_xml($xml);
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$doc instanceof \SimpleXMLElement) {
            return 0;
        }

        $nodes = [];
        foreach ($candidateNodes as $nodeName) {
            $matches = $doc->xpath('//*[local-name()="' . $nodeName . '"]');
            if (is_array($matches)) {
                $nodes = array_merge($nodes, $matches);
            }
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \SimpleXMLElement) {
                continue;
            }

            $raw = $this->raw_map_from_node($node);
            if (!$this->has_any_key($raw, $requiredKeys)) {
                continue;
            }

            $callback($raw);
            $count++;
        }

        return $count;
    }

    /**
     * @return array<string,string>
     */
    private function raw_map_from_xml(string $xml): array
    {
        $xml = $this->decode_if_escaped_xml($xml);
        $previous = libxml_use_internal_errors(true);
        $node = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $node instanceof \SimpleXMLElement ? $this->raw_map_from_node($node) : [];
    }

    /**
     * @return array<string,string>
     */
    private function raw_map_from_node(\SimpleXMLElement $node): array
    {
        $map = [];
        foreach ($node->children() as $key => $value) {
            $map[strtoupper((string) $key)] = trim((string) $value);
        }

        foreach ($node->attributes() as $key => $value) {
            $map[strtoupper((string) $key)] = trim((string) $value);
        }

        return $map;
    }

    private function decode_if_escaped_xml(string $xml): string
    {
        $trimmed = trim($xml);
        if (strpos($trimmed, '&lt;') !== false && strpos($trimmed, '<') === false) {
            return html_entity_decode($trimmed, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $xml;
    }

    /**
     * @param array<string,mixed> $raw
     * @param string[] $keys
     */
    private function first(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            $upper = strtoupper($key);
            if (array_key_exists($upper, $raw) && trim((string) $raw[$upper]) !== '') {
                return trim((string) $raw[$upper]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $raw
     * @param string[] $keys
     */
    private function has_any_key(array $raw, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists(strtoupper($key), $raw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,string>
     */
    private function extract_attributes(array $raw): array
    {
        $attributes = [];
        foreach ($raw as $key => $value) {
            $key = strtoupper((string) $key);
            if (preg_match('/^(IT)?ATR\d+$/', $key) || strpos($key, 'ATTR') === 0) {
                $attributes[$key] = trim((string) $value);
            }
        }

        return $attributes;
    }

    /**
     * @return string[]
     */
    private function image_urls(string $imageRef): array
    {
        $ref = trim($imageRef);
        if ($ref === '') {
            return [];
        }

        $ref = rawurlencode($ref);

        return [
            self::IMAGE_BASE . '/large/' . $ref . '.jpg',
            self::IMAGE_BASE . '/small/' . $ref . '.jpg',
            self::IMAGE_BASE . '/thumbnail/' . $ref . '.jpg',
            self::IMAGE_BASE . '/hires/' . $ref . '.png',
        ];
    }

    private function normalize_upc(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B#");
        $digits = preg_replace('/\D+/', '', $value);

        return is_string($digits) ? trim($digits) : '';
    }

    private function money_string(string $value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim($value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '';
        }

        $amount = (float) $value;
        if (!is_finite($amount) || $amount < 0.0) {
            return '';
        }

        return number_format($amount, 2, '.', '');
    }

    private function decimal_string(string $value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim($value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '';
        }

        $num = (float) $value;
        if (!is_finite($num) || $num <= 0.0) {
            return '';
        }

        return number_format($num, 2, '.', '');
    }

    private function dimension_string(string $value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim($value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '';
        }

        $num = (float) $value;
        if (!is_finite($num) || $num <= 0.0) {
            return '';
        }

        return rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.');
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

    private function looks_ffl_required(string $haystack): bool
    {
        foreach (['PISTOL', 'REVOLVER', 'RIFLE', 'SHOTGUN', 'FIREARM', 'RECEIVER', 'FRAME', 'LOWER'] as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function looks_sot_required(string $haystack): bool
    {
        foreach (['SOT', 'NFA', 'SUPPRESSOR', 'SUPPRESSORS', 'SILENCER', 'SILENCERS', 'CLASS 3', 'CLASS III'] as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function dropship_enabled(array $raw): bool
    {
        $flag = strtoupper($this->first($raw, ['DROPSHIP', 'DROPSHIPENABLED', 'FULFILLMENT', 'CANSHIPDIRECT']));
        if ($flag === '') {
            return true;
        }

        return !in_array($flag, ['0', 'N', 'NO', 'FALSE', 'F'], true);
    }

    /**
     * @param mixed $value
     */
    private function encode_json($value): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }
}
