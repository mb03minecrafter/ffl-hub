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

    /** @var array<string,int> */
    private array $last_catalog_stats = [];

    /**
     * @param callable(array<string,mixed>):void $callback
     */
    public function each_catalog_row(string $filePath, callable $callback): int
    {
        $stats = [
            'xml_rows_seen' => 0,
            'catalog_rows_parsed' => 0,
            'rows_with_blank_upc' => 0,
            'parse_product_null' => 0,
        ];

        $count = $this->each_xml_row(
            $filePath,
            ['Table', 'Item', 'ITEM', 'Product', 'DailyItem', 'InventoryItem'],
            ['ITEMNO', 'ITEMNUMBER'],
            function (array $raw) use ($callback, &$stats): void {
                $stats['xml_rows_seen']++;

                $raw_upc = $this->normalize_upc($this->first($raw, ['UPC', 'ITUPC', 'U', 'BARCODE', 'GTIN']));
                if ($raw_upc === '') {
                    $stats['rows_with_blank_upc']++;
                }

                $row = $this->parse_product($raw);
                if (is_array($row)) {
                    $stats['catalog_rows_parsed']++;
                    $callback($row);
                    return;
                }

                $stats['parse_product_null']++;
            }
        );

        if ($stats['xml_rows_seen'] === 0 && $count > 0) {
            $stats['xml_rows_seen'] = $count;
        }

        $this->last_catalog_stats = $stats;

        return $count;
    }

    /**
     * @return array<string,int>
     */
    public function get_last_catalog_stats(): array
    {
        return $this->last_catalog_stats;
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
     * @param callable(array<string,mixed>):void $callback
     */
    public function each_brand_row(string $filePath, callable $callback): int
    {
        return $this->each_xml_row(
            $filePath,
            ['Table', 'Brand'],
            ['BRDNO'],
            function (array $raw) use ($callback): void {
                $row = $this->parse_brand($raw);
                if (is_array($row)) {
                    $callback($row);
                }
            }
        );
    }

    /**
     * @param callable(array<string,mixed>):void $callback
     */
    public function each_category_row(string $filePath, callable $callback): int
    {
        return $this->each_xml_row(
            $filePath,
            ['Table', 'Category'],
            ['CATID'],
            function (array $raw) use ($callback): void {
                $row = $this->parse_category($raw);
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
        $description = $this->clean_text($this->first($raw, ['SHDESC', 'LONGDESC', 'LONGDESCRIPTION', 'TEXT', 'DESCRIPTION', 'IDESC', 'ITDESC']));
        $category_id = $this->clean_text($this->first($raw, ['CATID', 'CATEGORYID', 'CAT']));
        $item_type = $this->clean_text($this->first($raw, ['ITYPE', 'CATDESC', 'CATEGORY', 'TYPE', 'ITEMTYPE', 'DEPT']));
        $manufacturer = $this->clean_text($this->first($raw, ['BRAND', 'BRDNAM', 'MFG', 'MANUFACTURER', 'ITBRD']));
        $brand_number = $this->clean_text($this->first($raw, ['ITBRDNO', 'BRDNO', 'BRANDNO']));
        $mfg_part = $this->clean_text($this->first($raw, ['MFGINO', 'MFGITEMNO', 'MFGNO', 'MFGITEM', 'ITMFGNO', 'M', 'MANUFACTURERPARTNUMBER']));
        $model = $this->clean_text($this->first($raw, ['IMODEL', 'MODEL', 'ITMODEL']));
        $image_ref = $this->clean_text($this->first($raw, ['PICREF', 'PICTURE', 'IMAGE']));
        if ($image_ref === '') {
            $image_ref = $item_number;
        }

        $image_urls = $this->image_urls($image_ref);
        $restricted_states = $this->clean_text($this->first($raw, ['RESTRICTEDSTATES', 'STATE_RESTRICTIONS', 'STATES']));
        $quantity = $this->quantity_string($this->first($raw, ['QTYOH', 'ONHAND', 'QTY', 'QUANTITY']));

        $row = [
            'upc' => $upc,
            'sports_south_item_number' => $item_number,
            'remote_identifier' => $item_number,

            'inventory_quantity' => $quantity,
            'allocation_status' => ((int) $quantity) > 0 ? 'in_stock' : 'out_of_stock',
            'distributor_price' => $this->money_string($this->first($raw, ['CPRC', 'C', 'CUSTOMERPRICE', 'CUSTOMER_PRICE', 'PRICE'])),
            'catalog_price' => $this->money_string($this->first($raw, ['PRC1', 'P', 'CATALOGPRICE', 'CATALOG_PRICE', 'LISTPRICE'])),
            'retail_map' => $this->map_price($raw),
            'retail_msrp' => $this->msrp_price($raw),

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

            'ffl_required' => '0',
            'sot_required' => '0',
            'dropship_enabled' => '1',
            'dropship_block_reason' => '',
            'restricted_states' => $restricted_states,

            'shipping_weight' => $this->weight_ounces($this->first($raw, ['WTPBX', 'WEIGHT', 'WT', 'SHPWT'])),
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

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public function parse_brand(array $raw): ?array
    {
        $brand_number = $this->clean_text($this->first($raw, ['BRDNO', 'BRANDNO', 'ITBRDNO']));
        if ($brand_number === '') {
            return null;
        }

        return [
            'brand_number' => $brand_number,
            'brand_name' => $this->clean_text($this->first($raw, ['BRDNM', 'BRAND', 'BRANDNAME'])),
            'brand_url' => $this->clean_text($this->first($raw, ['BRDURL', 'BRANDURL', 'URL'])),
            'item_count' => (int) $this->first($raw, ['ITCOUNT', 'ITEMCOUNT', 'COUNT']),
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    public function parse_category(array $raw): ?array
    {
        $category_id = $this->clean_text($this->first($raw, ['CATID', 'CATEGORYID', 'CAT']));
        if ($category_id === '') {
            return null;
        }

        return [
            'category_id' => $category_id,
            'category_description' => $this->clean_text($this->first($raw, ['CATDES', 'CATEGORY', 'CATEGORYDESCRIPTION', 'CATDESC'])),
            'department_id' => $this->clean_text($this->first($raw, ['DEPID', 'DEPARTMENTID', 'DEPTID'])),
            'department_name' => $this->clean_text($this->first($raw, ['DEP', 'DEPARTMENT', 'DEPARTMENTNAME'])),
            'attributes' => $this->extract_attributes($raw),
        ];
    }

    public function extract_next_since_datetime(string $xml): string
    {
        if (preg_match('/<SERVERTIME\b[^>]*>(.*?)<\/SERVERTIME>/is', $xml, $server_time_match)) {
            $server_time = trim(html_entity_decode((string) ($server_time_match[1] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($server_time !== '') {
                return $this->format_since_datetime($server_time);
            }
        }

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
        $candidate_lookup = array_fill_keys($candidateNodes, true);

        if (class_exists('\XMLReader')) {
            $reader = new \XMLReader();
            if ($reader->open($filePath, null, LIBXML_NONET | LIBXML_NOCDATA)) {
                while ($reader->read()) {
                    if ($reader->nodeType !== \XMLReader::ELEMENT) {
                        continue;
                    }

                    if (!isset($candidate_lookup[$reader->localName])) {
                        continue;
                    }

                    $outer = $reader->readOuterXML();
                    if (!is_string($outer) || $outer === '') {
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
        if (preg_match('/<string\b[^>]*>(.*?)<\/string>/is', $trimmed, $m)) {
            return $this->decode_xml_markup_entities(trim((string) $m[1]));
        }

        if (strpos($trimmed, '&lt;') !== false) {
            return $this->decode_xml_markup_entities($trimmed);
        }

        return $xml;
    }

    private function decode_xml_markup_entities(string $xml): string
    {
        $xml = strtr($xml, [
            '&lt;' => '<',
            '&LT;' => '<',
            '&#60;' => '<',
            '&#x3c;' => '<',
            '&#X3C;' => '<',
            '&gt;' => '>',
            '&GT;' => '>',
            '&#62;' => '>',
            '&#x3e;' => '>',
            '&#X3E;' => '>',
        ]);

        return $this->escape_bare_text_less_than($xml);
    }

    private function escape_bare_text_less_than(string $xml): string
    {
        $fixed = preg_replace('/<(?!(?:\/?(?:NewDataSet|Table|Onhand|ServerDateTime|[A-Z][A-Z0-9_]*)(?:\s[^<>]*)?\/?>|[?!]))/', '&lt;', $xml);

        return is_string($fixed) ? $fixed : $xml;
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

    /**
     * Sports South does not expose a plain MAP column in DailyItemUpdate.
     * Verified rows use MFPRTYP=M with MFPRC as the official MAP value.
     *
     * @param array<string,mixed> $raw
     */
    private function map_price(array $raw): string
    {
        $explicit = $this->money_string($this->first($raw, ['MAP', 'ITMAP', 'MINADVERTISEDPRICE']));
        if ($explicit !== '') {
            return $explicit;
        }

        $price_type = strtoupper($this->clean_text($this->first($raw, ['MFPRTYP'])));
        if ($price_type !== 'M') {
            return '';
        }

        $manufacturer_price = $this->money_string($this->first($raw, ['MFPRC']));
        if ($manufacturer_price === '' || (float) $manufacturer_price <= 0.0) {
            return '';
        }

        return $manufacturer_price;
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function msrp_price(array $raw): string
    {
        $explicit = $this->money_string($this->first($raw, ['MSRP', 'ITMSRP', 'RETAIL']));
        if ($explicit !== '') {
            return $explicit;
        }

        $price_type = strtoupper($this->clean_text($this->first($raw, ['MFPRTYP'])));
        if ($price_type === 'M') {
            return '';
        }

        return $this->money_string($this->first($raw, ['MFPRC']));
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

    private function weight_ounces(string $value): string
    {
        $value = preg_replace('/[^0-9.\-]/', '', trim($value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '';
        }

        $pounds = (float) $value;
        if (!is_finite($pounds) || $pounds <= 0.0) {
            return '';
        }

        return number_format($pounds * 16.0, 2, '.', '');
    }

    private function quantity_string(string $value): string
    {
        $value = preg_replace('/[^0-9\-]/', '', trim($value));
        if (!is_string($value) || $value === '' || !is_numeric($value)) {
            return '0';
        }

        return (string) max(0, (int) $value);
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
