<?php


namespace FFLHub\Distributor\Lipseys;



if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\DistributorBase;
use FFLHub\Distributor\DistributorWithFulfillmentTable;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;


use FFLHub\Distributor\Services\Lipseys\LipseysServices;

/**
 * Lipsey's distributor implementation.
 *
 * Uses the official Lipsey's PHP client (lipseys/apiintegration)
 * if it is available. See: https://github.com/Lipseys/LipseysApiIntegrationPhp
 */
class DistributorLipseys extends DistributorBase
{

    /**
     * Cached Lipsey's client for this request.
     *
     * @var \lipseys\ApiIntegration\LipseysClient|null
     */
    private $client = null;

    /**
     * Whether we already attempted to initialize the client this request.
     *
     * @var bool
     */
    private $client_initialized = false;





    private const ID          = 'lipseys';
    private const LABEL       = "Lipsey's";
    private const NAME        = "Lipsey's";
    private const DESCRIPTION = "Lipsey's Distributor";
    private const SECTION_DESCRIPTION = "Lipsey's Distributor";
    private const ICON_URL = FFLHUB_PLUGIN_URL . 'assets/icons/logo-lipseys.png';



    public static function get_id(): string { return self::ID; }
    public static function get_label(): string { return self::LABEL; }
    public static function get_name(): string { return self::NAME; }
    public static function get_description(): string { return self::DESCRIPTION; }
    public static function get_section_description(): string { return self::SECTION_DESCRIPTION; }
    public static function get_icon_url(): string { return self::ICON_URL; }

    public static function get_field_definitions(): array
    {
        return [
            'dealer_email' => array(
                'label'       => 'Dealer Email',
                'type'        => 'text',
                'placeholder' => '',
                'description' => "Your Lipsey's dealer login email.",
                'default'     => '',
            ),
            'dealer_password' => array(
                'label'       => 'Dealer Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => "Password for your Lipsey's dealer account.",
                'default'     => '',
            ),
        ];
    }

    private const SERVICE_CLASS = LipseysServices::class;

    public static function get_services_class(): string { return self::SERVICE_CLASS; }


    
    


    public function __construct()
    {
        
    }





    /**
     * Get (or lazily create) the Lipsey's API client.
     *
     * @return \lipseys\ApiIntegration\LipseysClient|null
     */
    public function get_client()
    {
        if ($this->client_initialized) {
            return $this->client;
        }

        $this->client_initialized = true;
        $this->client             = null;

        if (! class_exists('\lipseys\ApiIntegration\LipseysClient')) {
            error_log('FFLHub Lipseys: LipseysClient class not found (autoload issue) in get_client().');
            return null;
        }

        $dealer_email    = get_option($this->get_option_name('dealer_email'));
        $dealer_password = get_option($this->get_option_name('dealer_password'));

        if (empty($dealer_email) || empty($dealer_password)) {
            error_log('FFLHub Lipseys: dealer_email or dealer_password not set in get_client().');
            return null;
        }

        try {
            $this->client = new \lipseys\ApiIntegration\LipseysClient(
                (string) $dealer_email,
                (string) $dealer_password
            );
        } catch (\Throwable $e) {
            error_log('FFLHub Lipseys: exception creating client in get_client(): ' . $e->getMessage());
            $this->client = null;
        }

        return $this->client;
    }

    /**
     * Debug helper: test Lipsey's CatalogFeed via the official client
     * and log a small summary to the PHP error log.
     *
     * This is NOT used in production code; it's only for verifying
     * credentials and seeing the structure of the feed.
     */
    public function debug_catalog_feed(): void
    {
        ini_set('memory_limit', '1024M');

        $client = $this->get_client();
        if (! $client) {
            error_log('FFLHub Lipseys DEBUG: get_client() returned null in debug_catalog_feed().');
            return;
        }

        error_log('FFLHub Lipseys DEBUG: Calling LipseysClient->Catalog()...');

        try {
            $result = $client->Catalog();
        } catch (\Throwable $e) {
            error_log(
                'FFLHub Lipseys DEBUG: exception calling Catalog(): ' . $e->getMessage()
            );
            return;
        }

        if (! is_array($result)) {
            error_log('FFLHub Lipseys DEBUG: Catalog() result is not an array: ' . print_r($result, true));
            return;
        }

        // Log top-level keys for quick inspection.
        $keys = implode(', ', array_keys($result));
        error_log('FFLHub Lipseys DEBUG: Catalog() top-level keys: ' . $keys);

        // Authorized flag, if present.
        if (array_key_exists('authorized', $result)) {
            error_log(
                'FFLHub Lipseys DEBUG: Catalog() authorized = ' . var_export($result['authorized'], true)
            );
        }

        // Try to inspect the "data" portion if it exists and is big.
        if (isset($result['data']) && is_array($result['data'])) {
            $count  = count($result['data']);
            $sample = array_slice($result['data'], 0, 3);

            error_log('FFLHub Lipseys DEBUG: Catalog() data count = ' . $count);

            // Log just a small sample to avoid blowing up the error log.
            $sample_str = print_r($sample, true);
            $sample_str = substr($sample_str, 0, 4000); // truncate for safety

            error_log('FFLHub Lipseys DEBUG: Catalog() data sample (first 3 items, truncated): ' . $sample_str);
        } else {
            // If we don't have a "data" key, just log a truncated view of the response.
            $result_str = print_r($result, true);
            $result_str = substr($result_str, 0, 4000);

            error_log('FFLHub Lipseys DEBUG: Catalog() full result (truncated): ' . $result_str);
        }

        error_log('FFLHub Lipseys DEBUG: CatalogFeed test complete.');
    }

    /**
     * Look up a single product by UPC using the Lipsey's fulfillment LIVE table.
     *
     * @param string $upc
     * @return DistributorProductPayload|null
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        global $wpdb;

        // Normalize UPC to digits only.
        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }


        


        // Fetch the row by UPC from the live fulfillment table.
        $row = $this->get_row_by_upc($normalized_upc);

        if (! $row) {

            return null;
        }

        // SKU: use Lipsey's item number.
        $sku = isset($row['lipseys_item_number']) ? (string) $row['lipseys_item_number'] : '';

        // Name: manufacturer + model + caliber, or fall back to product_description.
        $manufacturer = isset($row['manufacturer']) ? trim((string) $row['manufacturer']) : '';
        $model        = isset($row['model']) ? trim((string) $row['model']) : '';
        $caliber      = isset($row['caliber_gauge']) ? trim((string) $row['caliber_gauge']) : '';

        $name_parts = array();
        if ($manufacturer !== '') {
            $name_parts[] = $manufacturer;
        }
        if ($model !== '') {
            $name_parts[] = $model;
        }
        if ($caliber !== '') {
            $name_parts[] = $caliber;
        }



        $fallback_desc = isset($row['product_description']) ? (string) $row['product_description'] : '';

        $name        = ! empty($name_parts) ? implode(' ', $name_parts) : $fallback_desc;
        $description = $fallback_desc;

        // Pricing: distributor price, MAP, MSRP.
        $distributor_price = isset($row['distributor_price']) && $row['distributor_price'] !== ''
            ? (float) $row['distributor_price']
            : 0.0;

        $map = isset($row['retail_map']) && $row['retail_map'] !== ''
            ? (float) $row['retail_map']
            : 0.0;

        $msrp = isset($row['retail_msrp']) && $row['retail_msrp'] !== ''
            ? (float) $row['retail_msrp']
            : 0.0;

    

        // Inventory.
        $quantity_raw = isset($row['inventory_quantity']) ? trim((string) $row['inventory_quantity']) : '';
        $quantity     = ($quantity_raw !== '' && is_numeric($quantity_raw))
            ? (int) $quantity_raw
            : 0;

        // We only import drop-ship enabled items into this table, so we treat all as drop-shippable.
        $shipping_cost = $this->get_shipping_cost_by_upc($normalized_upc);


        $true_cost =  $this->get_true_cost_by_distributor_cost_shipping_cost($distributor_price, $shipping_cost);


        $image_name = isset($row['image_name']) ? (string) $row['image_name'] : '';

        $image_url = 'https://www.lipseyscloud.com/images/' . $image_name;



        $ffl_required = isset($row['ffl_required']) ? (bool) $row['ffl_required'] : false;




        $item_group = isset($row['item_group']) ? (string) $row['item_group'] : '';
        $reccomended_category = DistributorProductCategoryMapper::map_lipseys($item_group);



        return new DistributorProductPayload(
            (string) $normalized_upc,
            (string) $sku,
            (string) $name,
            (string) $description,
            (float) $distributor_price,
            (float) $map,
            (float) $msrp,
            (int) $quantity,
            (float) $shipping_cost,
            (float) $true_cost,
            (string) $image_url,
            (bool)$ffl_required,
            $reccomended_category,
            $row // raw source data
        );
    }

    /**
     * Get stock quantity for a product by UPC using the Lipsey's LIVE fulfillment table.
     *
     * @param string $upc
     * @return int|null
     */
    public function get_stock_quantity_by_upc(string $upc): ?int
    {
        global $wpdb;

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        

        $table_name = $this->get_live_table_name();

        $qty_raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT inventory_quantity FROM {$table_name} WHERE upc = %s LIMIT 1",
                $normalized_upc
            )
        );

        if ($qty_raw === null) {
            return null; // no matching row
        }

        $qty_raw = trim((string) $qty_raw);

        return ($qty_raw !== '' && is_numeric($qty_raw))
            ? (int) $qty_raw
            : 0;
    }

    /**
     * Get distributor price for a product by UPC from the LIVE fulfillment table.
     *
     * @param string $upc
     * @return float|null
     */
    public function get_distributor_price_by_upc(string $upc): ?float
    {
        global $wpdb;

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        

        $table_name = $this->get_live_table_name();

        $price_raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT distributor_price FROM {$table_name} WHERE upc = %s LIMIT 1",
                $normalized_upc
            )
        );

        if ($price_raw === null || $price_raw === '') {
            return null;
        }
        return is_numeric($price_raw) ? (float) $price_raw : null;
    }

    /**
     * We assume $10 for lipseys shipping, it needs clarification but atm it seems
     * its either $8 or $9, so I round it to $10
     * @param string $upc
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        return 10.0;
    }
}
