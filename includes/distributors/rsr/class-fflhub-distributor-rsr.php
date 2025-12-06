<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * RSR distributor implementation.
 *
 * Now uses the local RSR fulfillment table for product/price/quantity lookups,
 * instead of calling the RSR get-items API for those operations.
 */
class FFLHub_Distributor_RSR extends FFLHub_Distributor_Base
{

    /**
     * Base URL for RSR DirectConnect API.
     *
     * Still kept for future use (e.g. placing orders), but no longer used
     * for catalog/price/quantity lookups.
     */
    private const API_BASE = 'https://www.rsrgroup.com';

    /**
     * Path for the check-catalog API.
     *
     * Full URL: https://www.rsrgroup.com/api/rsrbridge/1.0/pos/check-catalog
     * (currently unused, but kept for completeness).
     */
    private const API_CHECK_CATALOG = '/api/rsrbridge/1.0/pos/check-catalog';

    public function __construct()
    {
        // Identity / display
        $this->id          = 'rsr';
        $this->label       = 'RSR';
        $this->name        = 'RSR Group';
        $this->description = 'RSR Group Distributor';

        // Icon (already in your assets).
        $this->icon_url = FFLHUB_PLUGIN_URL . 'assets/icons/logo-rsr.png';

        // Section intro text shown at top of the modal.
        $this->section_description = 'Configure your RSR API and FTP credentials. These will be used for inventory, pricing, and order integrations.';

        // Field schema for RSR (options will be fflhub_rsr_<field_key> via get_option_name()).
        $this->fields = array(
            'main_account_number' => array(
                'label'       => 'Main Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your main RSR account number (this is your RSR username).',
                'default'     => '',
            ),
            'main_account_password' => array(
                'label'       => 'Main Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR main account password.',
                'default'     => '',
            ),
            'pos_indicator' => array(
                'label'       => 'POS Indicator',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR POS indicator for API requests (contact RSR DirectConnect if unsure).',
                'default'     => '',
            ),
            'dropship_account_number' => array(
                'label'       => 'Drop-Ship Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR drop-ship account number (different from main).',
                'default'     => '',
            ),
            'dropship_account_password' => array(
                'label'       => 'Drop-Ship Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Password for the drop-ship account.',
                'default'     => '',
            ),

            // FTP credentials for catalog/quantity file downloads.
            'ftp_host' => array(
                'label'       => 'FTP Host',
                'type'        => 'text',
                'placeholder' => 'ftps.rsrgroup.com',
                'description' => 'Hostname for the RSR FTP server used for fulfillment/catalog files.',
                'default'     => '',
            ),
            'ftp_username' => array(
                'label'       => 'FTP Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR FTP username (often the same as your main RSR account).',
                'default'     => '',
            ),
            'ftp_password' => array(
                'label'       => 'FTP Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR FTP password.',
                'default'     => '',
            ),
            'ftp_use_ssl' => array(
                'label'       => 'Use FTPS (SSL)',
                'type'        => 'checkbox',
                'description' => 'Connect using FTPS/SSL (recommended if your hosting supports it).',
                'default'     => '1', // we’ll treat non-empty as “true”
            ),
        );
    }

    /**
     * Get main-account credentials + POS indicator for API calls.
     *
     * Currently not used for catalog lookups, but kept for future needs
     * (like placing orders over RSR’s API).
     *
     * @return array|null
     */
    protected function get_main_credentials(): ?array
    {
        $username = get_option($this->get_option_name('dropship_account_number'));
        $password = get_option($this->get_option_name('dropship_account_password'));
        $pos      = get_option($this->get_option_name('pos_indicator'));

        $username = is_string($username) ? trim($username) : '';
        $password = is_string($password) ? trim($password) : '';
        $pos      = is_string($pos)      ? trim($pos)      : '';

        if ($username === '' || $password === '' || $pos === '') {
            error_log(
                sprintf(
                    'FFLHub RSR: missing credentials or POS (username: %s, pos: %s).',
                    $username !== '' ? 'set' : 'empty',
                    $pos      !== '' ? 'set' : 'empty'
                )
            );
            return null;
        }

        return array(
            'Username' => $username,
            'Password' => $password,
            'POS'      => $pos,
        );
    }

    /**
     * Helper: get the live fulfillment table name.
     *
     * @return string|null
     */
    protected function get_live_table_name(): ?string
    {
        if (! class_exists('FFLHub_RSR_Fulfillment_Table')) {
            error_log('FFLHub RSR: FFLHub_RSR_Fulfillment_Table class not found.');
            return null;
        }

        return FFLHub_RSR_Fulfillment_Table::get_live_table_name();
    }

    /**
     * Helper: load a single fulfillment row by normalized UPC.
     *
     * @param string $normalized_upc
     * @return array<string,mixed>|null
     */
    protected function get_row_by_upc(string $normalized_upc): ?array
    {
        $table = $this->get_live_table_name();
        if (! $table) {
            return null;
        }

        global $wpdb;

        $sql = "SELECT * FROM {$table} WHERE upc = %s LIMIT 1";
        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $normalized_upc),
            ARRAY_A
        );

        if (! is_array($row) || empty($row)) {
            return null;
        }

        return $row;
    }

    /**
     * Look up a single product by UPC using the local fulfillment table.
     *
     * @param string $upc
     * @return FFLHub_Distributor_Product_Payload|null
     */
    public function get_product_by_upc(string $upc): ?FFLHub_Distributor_Product_Payload
    {
        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->get_row_by_upc($normalized_upc);
        if (! $row) {
            error_log('FFLHub RSR: no fulfillment row found for UPC ' . $normalized_upc);
            return null;
        }

        // Map DB columns → payload fields.
        $sku = $this->get_string_field(
            $row,
            array('rsr_stock_number', 'sku')
        );

        $item_upc = $this->get_string_field(
            $row,
            array('upc')
        );

        $name = $this->get_string_field(
            $row,
            array('model')
        );

        

        $description = $this->get_string_field(
            $row,
            array('product_description')
        );

        $name = $description; //just for now since rsr names suck balls

        // Distributor (dealer) price.
        $price = $this->get_float_field(
            $row,
            array('distributor_price')
        );

        $quantity = $this->get_int_field(
            $row,
            array('inventory_quantity')
        );

        // MAP (Minimum Advertised Price).
        $map = $this->get_float_field(
            $row,
            array('retail_map')
        );

        // MSRP / retail.
        $msrp = $this->get_float_field(
            $row,
            array('retail_msrp')
        );

        // Drop-ship block flag (not used yet, but kept for future).
        $blocked_flag = $this->get_string_field(
            $row,
            array('blocked_from_dropship', 'drop_ship_block')
        );

        // Raw payload: keep the DB row so debug tools / UIs can inspect it.
        $raw = $row;

        // Shipping cost and "true cost".
        $shipping_cost = $this->get_shipping_cost_by_upc($normalized_upc);
        $true_cost     = $this->get_true_cost_by_distributor_cost_shipping_cost($price, $shipping_cost);

        // Base image name from RSR feed, e.g. "LAS981-0054_1.jpg".
        $image_name = $this->get_string_field(
            $row,
            array('image_name')
        );
        $image_name = trim((string) $image_name);

        // Build full list of *real* image URLs (no "image coming soon").
        $rsr_image_urls = array();
        if ($image_name !== '') {
            $rsr_image_urls = $this->build_rsr_image_urls_from_image_name($image_name);
        }

        // Primary image URL = first image in the list (if any).
        $primary_image_url = '';
        if (! empty($rsr_image_urls)) {
            $primary_image_url = (string) $rsr_image_urls[0];
        }

        $deptNum              = $this->get_string_field($row, array('dept_number'));
        $reccomended_category = FFLHub_Category_Mapper::map_rsr($deptNum);

        // TODO: FIGURE OUT RSR FFL REQUIREMENTS CHECKING
        $ffl_required = false;

        // Build the normalized payload. This seeds image_urls with $primary_image_url (if non-empty).
        $payload = new FFLHub_Distributor_Product_Payload(
            (string) $item_upc,
            (string) $sku,
            (string) $name,
            (string) $description,
            (float) $price,
            (float) $map,
            (float) $msrp,
            (int) $quantity,
            (float) $shipping_cost,
            (float) $true_cost,
            (string) $primary_image_url,
            (bool) $ffl_required,
            $reccomended_category,
            $raw
        );

        // Add any additional discovered RSR image URLs to the payload.
        if (! empty($rsr_image_urls)) {
            // Skip index 0 because constructor already added primary.
            foreach (array_slice($rsr_image_urls, 1) as $extra_url) {
                $payload->add_image_url($extra_url);
            }
        }

        error_log(implode(" ", $rsr_image_urls));

        return $payload;
    }


    /**
     * Get stock quantity for a product by UPC using the fulfillment table.
     *
     * @param string $upc
     * @return int|null
     */
    public function get_stock_quantity_by_upc(string $upc): ?int
    {
        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->get_row_by_upc($normalized_upc);
        if (! $row) {
            return null;
        }

        $quantity = $this->get_int_field(
            $row,
            array('inventory_quantity')
        );

        return $quantity;
    }

    /**
     * Get distributor (dealer) price for a product by UPC using the fulfillment table.
     *
     * @param string $upc
     * @return float|null
     */
    public function get_distributor_price_by_upc(string $upc): ?float
    {
        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->get_row_by_upc($normalized_upc);
        if (! $row) {
            return null;
        }

        $price = $this->get_float_field(
            $row,
            array('distributor_price')
        );
        if ($price <= 0) {
            $price = $this->get_float_field(
                $row,
                array('retail_price')
            );
        }

        return $price;
    }

    /**
     * Shipping cost estimate by UPC.
     *
     * We assume FedEx 2 day ($15), then add another $5 for signature-required items.
     *
     * This is an internal estimate used for free-shipping pricing. It does NOT
     * calculate the real live rate; it just gives us a per-item budget for shipping.
     *
     * @param string $upc
     * @return float|null
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        // Base assumption: FedEx 2Day to the lower 48
        $cost = 15.0;

        $normalized_upc = $this->normalize_upc($upc);


        if ($normalized_upc === null) {
            return null;
        }

        // Look up the distributor/fulfillment data for this UPC.
        // Replace this with whatever helper you already use to hit your RSR/Lipsey's table.


        $product = $this->get_row_by_upc($normalized_upc);
        if (! $product) {
            error_log('FFLHub RSR: no fulfillment row found for UPC ' . $normalized_upc);
            return null;
        }

        // Map DB columns → payload fields.
        // We use a few possible column names as fallbacks in case schema evolves.
        $requires_signature = $this->get_bool_field(
            $product,
            array('adult_sig_required')
        );


        if ($requires_signature == 1) {
            $cost += 5.0;
        }


        return $cost;
    }

    /**
     * Get FTP credentials for downloading RSR catalog/quantity files.
     *
     * @return array|null {
     *   @type string $host
     *   @type string $username
     *   @type string $password
     *   @type bool   $use_ssl
     * }
     */
    public function get_ftp_credentials(): ?array
    {
        $host     = get_option($this->get_option_name('ftp_host'));
        $username = get_option($this->get_option_name('ftp_username'));
        $password = get_option($this->get_option_name('ftp_password'));
        $use_ssl  = get_option($this->get_option_name('ftp_use_ssl'));

        $host     = is_string($host)     ? trim($host)     : '';
        $username = is_string($username) ? trim($username) : '';
        $password = is_string($password) ? trim($password) : '';
        $use_ssl  = (is_string($use_ssl) ? trim($use_ssl) : '') !== '';

        if ($host === '' || $username === '' || $password === '') {
            error_log(
                sprintf(
                    'FFLHub RSR: missing FTP credentials (host: %s, user: %s).',
                    $host     !== '' ? 'set' : 'empty',
                    $username !== '' ? 'set' : 'empty'
                )
            );
            return null;
        }

        return array(
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'use_ssl'  => $use_ssl,
        );
    }


    /**
     * Given an RSR image_name like "LAS981-0054_1.jpg", generate all real
     * product image URLs for that item, stopping when we hit the generic
     * "image coming soon" placeholder (110x85).
     *
     * @param string $image_name
     * @return string[]
     */
    private function build_rsr_image_urls_from_image_name(string $image_name): array
    {
        $image_name = trim($image_name);
        if ($image_name === '') {
            return array();
        }

        $base_prefix = 'https://img.rsrgroup.com/pimages/';
        $urls        = array();

        // Expect pattern like "LAS981-0054_1.jpg"
        if (preg_match('/^(.*)_([0-9]+)(\.[^.]+)$/', $image_name, $matches)) {
            $base        = $matches[1]; // "LAS981-0054"
            $start_index = (int) $matches[2]; // usually 1
            $ext         = $matches[3]; // ".jpg"

            // Always include the base image from the feed first, without checking.
            $first_file = $base . '_' . $start_index . $ext;
            $first_url  = $base_prefix . $first_file;
            $urls[]     = $first_url;

            // Now probe for additional images by incrementing the suffix.
            // We stop at the first non-real/placeholder image.
            $max_extra_attempts = 15; // safety cap so we don't loop forever.

            for ($i = $start_index + 1; $i <= $start_index + $max_extra_attempts; $i++) {
                $file = $base . '_' . $i . $ext;
                $url  = $base_prefix . $file;

                if (! $this->rsr_is_real_image_url($url)) {
                    // Hit a placeholder (110x85) or missing image → stop.
                    break;
                }

                $urls[] = $url;
            }
        } else {
            // If we don't match the numbered pattern, just use the raw name.
            $urls[] = $base_prefix . ltrim($image_name, '/');
        }

        // Ensure unique URLs.
        $urls = array_values(array_unique($urls));

        return $urls;
    }


    /**
     * Check whether the given RSR image URL is a real product image
     * and NOT the generic "image coming soon" placeholder.
     *
     * RSR's placeholder image is 110x85; no real product images use that size.
     *
     * @param string $url
     * @return bool True if this looks like a real product image.
     */
    private function rsr_is_real_image_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // Use WordPress HTTP API to fetch the image.
        $response = wp_remote_get($url, array(
            'timeout'     => 5,
            'redirection' => 3,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) {
            return false;
        }

        // Determine dimensions from the binary image string.
        $image_info = @getimagesizefromstring($body);
        if (false === $image_info) {
            return false;
        }

        $width  = isset($image_info[0]) ? (int) $image_info[0] : 0;
        $height = isset($image_info[1]) ? (int) $image_info[1] : 0;

        // RSR "image coming soon" placeholder is exactly 110x85.
        if ($width === 110 && $height === 85) {
            return false;
        }

        return true;
    }
}
