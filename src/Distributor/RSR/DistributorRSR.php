<?php

namespace FFLHub\Distributor\RSR;

use FFLHub\Distributor\DistributorWithFulfillmentTable;
use FFLHub\Distributor\Product\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\RSR\RSRServices;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RSR distributor implementation.
 *
 * Now uses the local RSR fulfillment table for product/price/quantity lookups,
 * instead of calling the RSR get-items API for those operations.
 */
class DistributorRSR extends DistributorWithFulfillmentTable
{
   


    private const ID          = 'rsr';
    private const LABEL       = 'RSR';
    private const NAME        = 'RSR Group';
    private const DESCRIPTION = 'RSR Group Distributor';
    private const SECTION_DESCRIPTION = 'RSR Group Distributor';
    private const ICON_URL = FFLHUB_PLUGIN_URL . 'assets/icons/logo-rsr.png';

    public static function get_id(): string { return self::ID; }
    public static function get_label(): string { return self::LABEL; }
    public static function get_name(): string { return self::NAME; }
    public static function get_description(): string { return self::DESCRIPTION; }
    public static function get_section_description(): string { return self::SECTION_DESCRIPTION; }
    public static function get_icon_url(): string { return self::ICON_URL; }

    public static function get_field_definitions(): array
    {
        return [
            'main_account_number' => [
                'label'       => 'Main Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your main RSR account number (this is your RSR username).',
                'default'     => '',
            ],
            'main_account_password' => [
                'label'       => 'Main Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR main account password.',
                'default'     => '',
            ],
            'pos_indicator' => [
                'label'       => 'POS Indicator',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR POS indicator for API requests.',
                'default'     => '',
            ],
            'dropship_account_number' => [
                'label'       => 'Drop-Ship Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR drop-ship account number (different from main).',
                'default'     => '',
            ],
            'dropship_account_password' => [
                'label'       => 'Drop-Ship Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Password for the drop-ship account.',
                'default'     => '',
            ],
            'ftp_host' => [
                'label'       => 'FTP Host',
                'type'        => 'text',
                'placeholder' => 'ftps.rsrgroup.com',
                'description' => 'Hostname for the RSR FTP server.',
                'default'     => '',
            ],
            'ftp_username' => [
                'label'       => 'FTP Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR FTP username.',
                'default'     => '',
            ],
            'ftp_password' => [
                'label'       => 'FTP Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR FTP password.',
                'default'     => '',
            ],
            'ftp_use_ssl' => [
                'label'       => 'Use FTPS (SSL)',
                'type'        => 'checkbox',
                'description' => 'Connect using FTPS/SSL (recommended).',
                'default'     => '1',
            ],
        ];
    }

    private const SERVICE_CLASS = RSRServices::class;

    public static function get_services_class(): string { return self::SERVICE_CLASS; }



    

    public function __construct()
    {
        
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
        $username = get_option( $this->get_option_name( 'dropship_account_number' ) );
        $password = get_option( $this->get_option_name( 'dropship_account_password' ) );
        $pos      = get_option( $this->get_option_name( 'pos_indicator' ) );

        $username = is_string( $username ) ? trim( $username ) : '';
        $password = is_string( $password ) ? trim( $password ) : '';
        $pos      = is_string( $pos )      ? trim( $pos )      : '';

        if ( $username === '' || $password === '' || $pos === '' ) {
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
     * Look up a single product by UPC using the local fulfillment table.
     *
     * @param string $upc
     * @return DistributorProductPayload|null
     */
    public function get_product_by_upc( string $upc ): ?DistributorProductPayload
    {
        $normalized_upc = $this->normalize_upc( $upc );
        if ( $normalized_upc === null ) {
            return null;
        }

        $row = $this->get_row_by_upc( $normalized_upc );

        if ( ! $row ) {
            error_log( 'FFLHub RSR: no fulfillment row found for UPC ' . $normalized_upc );
            return null;
        }

        // Map DB columns → payload fields.
        $sku = $this->get_string_field(
            $row,
            array( 'rsr_stock_number', 'sku' )
        );

        $item_upc = $this->get_string_field(
            $row,
            array( 'upc' )
        );

        $name = $this->get_string_field(
            $row,
            array( 'model' )
        );

        $description = $this->get_string_field(
            $row,
            array( 'product_description' )
        );

        // Just for now since RSR model names suck.
        $name = $description;

        // Distributor (dealer) price.
        $price = $this->get_float_field(
            $row,
            array( 'distributor_price' )
        );

        $quantity = $this->get_int_field(
            $row,
            array( 'inventory_quantity' )
        );

        // MAP (Minimum Advertised Price).
        $map = $this->get_float_field(
            $row,
            array( 'retail_map' )
        );

        // MSRP / retail.
        $msrp = $this->get_float_field(
            $row,
            array( 'retail_msrp' )
        );

        // Drop-ship block flag (not used yet, but kept for future).
        $blocked_flag = $this->get_string_field(
            $row,
            array( 'blocked_from_dropship', 'drop_ship_block' )
        );

        // Raw payload: keep the DB row so debug tools / UIs can inspect it.
        $raw = $row;

        // Shipping cost and "true cost".
        $shipping_cost = $this->get_shipping_cost_by_upc( $normalized_upc );
        $true_cost     = $this->get_true_cost_by_distributor_cost_shipping_cost( $price, $shipping_cost );

        // Base image name from RSR feed, e.g. "LAS981-0054_1.jpg".
        $image_name = $this->get_string_field(
            $row,
            array( 'image_name' )
        );
        $image_name = trim( (string) $image_name );

        // Build full list of *real* image URLs (no "image coming soon").
        $rsr_image_urls = array();
        if ( $image_name !== '' ) {
            $rsr_image_urls = $this->build_rsr_image_urls_from_image_name( $image_name );
        }

        // Primary image URL = first image in the list (if any).
        $primary_image_url = '';
        if ( ! empty( $rsr_image_urls ) ) {
            $primary_image_url = (string) $rsr_image_urls[0];
        }

        $deptNum              = $this->get_string_field( $row, array( 'dept_number' ) );
        $reccomended_category = DistributorProductCategoryMapper::map_rsr( $deptNum );

        // TODO: FIGURE OUT RSR FFL REQUIREMENTS CHECKING
        $ffl_required = false;

        // Build the normalized payload. This seeds image_urls with $primary_image_url (if non-empty).
        $payload = new DistributorProductPayload(
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
        if ( ! empty( $rsr_image_urls ) ) {
            // Skip index 0 because constructor already added primary.
            foreach ( array_slice( $rsr_image_urls, 1 ) as $extra_url ) {
                $payload->add_image_url( $extra_url );
            }
        }

        error_log( implode( ' ', $rsr_image_urls ) );

        return $payload;
    }

    /**
     * Lightweight pricing/stock payload for cron sync.
     *
     * Same normalized payload shape, but does NOT do any remote image probing.
     *
     * @param string $upc
     * @return DistributorProductPayload|null
     */
    public function get_pricing_payload_by_upc( string $upc ): ?DistributorProductPayload
    {
        $normalized_upc = $this->normalize_upc( $upc );
        if ( $normalized_upc === null ) {
            return null;
        }

        $row = $this->get_row_by_upc( $normalized_upc );
        if ( ! $row ) {
            return null;
        }

        // Basic fields from the fulfillment table.
        $sku = $this->get_string_field(
            $row,
            array( 'rsr_stock_number', 'sku' )
        );

        $item_upc = $this->get_string_field(
            $row,
            array( 'upc' )
        );

        $name = $this->get_string_field(
            $row,
            array( 'model' )
        );

        $description = $this->get_string_field(
            $row,
            array( 'product_description' )
        );

        // Dealer price.
        $price = $this->get_float_field(
            $row,
            array( 'distributor_price' )
        );

        // Quantity.
        $quantity = $this->get_int_field(
            $row,
            array( 'inventory_quantity' )
        );

        // MAP.
        $map = $this->get_float_field(
            $row,
            array( 'retail_map' )
        );

        // MSRP.
        $msrp = $this->get_float_field(
            $row,
            array( 'retail_msrp' )
        );

        // Shipping + true cost.
        $shipping_cost = $this->get_shipping_cost_by_upc( $normalized_upc );
        $true_cost     = $this->get_true_cost_by_distributor_cost_shipping_cost( $price, $shipping_cost );

        // Recommended category.
        $deptNum              = $this->get_string_field( $row, array( 'dept_number' ) );
        $recommended_category = DistributorProductCategoryMapper::map_rsr( $deptNum );

        // For sync we don't need images, so just leave image_urls empty.
        $image_url = '';

        // TODO: real RSR FFL requirement logic. For now, false.
        $ffl_required = false;

        $raw = $row;

        return new DistributorProductPayload(
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
            $image_url,
            (bool) $ffl_required,
            $recommended_category,
            $raw
        );
    }

    /**
     * Get stock quantity for a product by UPC using the fulfillment table.
     *
     * @param string $upc
     * @return int|null
     */
    public function get_stock_quantity_by_upc( string $upc ): ?int
    {
        $normalized_upc = $this->normalize_upc( $upc );
        if ( $normalized_upc === null ) {
            return null;
        }

        $row = $this->get_row_by_upc( $normalized_upc );
        if ( ! $row ) {
            return null;
        }

        $quantity = $this->get_int_field(
            $row,
            array( 'inventory_quantity' )
        );

        return $quantity;
    }

    /**
     * Get distributor (dealer) price for a product by UPC using the fulfillment table.
     *
     * @param string $upc
     * @return float|null
     */
    public function get_distributor_price_by_upc( string $upc ): ?float
    {
        $normalized_upc = $this->normalize_upc( $upc );
        if ( $normalized_upc === null ) {
            return null;
        }

        $row = $this->get_row_by_upc( $normalized_upc );
        if ( ! $row ) {
            return null;
        }

        $price = $this->get_float_field(
            $row,
            array( 'distributor_price' )
        );
        if ( $price <= 0 ) {
            $price = $this->get_float_field(
                $row,
                array( 'retail_price' )
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
    public function get_shipping_cost_by_upc( string $upc ): ?float
    {
        // Base assumption: FedEx 2Day to the lower 48
        $cost = 15.0;

        $normalized_upc = $this->normalize_upc( $upc );
        if ( $normalized_upc === null ) {
            return null;
        }

        // Look up the distributor/fulfillment data for this UPC.
        $product = $this->get_row_by_upc( $normalized_upc );
        if ( ! $product ) {
            error_log( 'FFLHub RSR: no fulfillment row found for UPC ' . $normalized_upc );
            return null;
        }

        // We use a few possible column names as fallbacks in case schema evolves.
        $requires_signature = $this->get_bool_field(
            $product,
            array( 'adult_sig_required' )
        );

        if ( $requires_signature == 1 ) {
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
        $host     = get_option( $this->get_option_name( 'ftp_host' ) );
        $username = get_option( $this->get_option_name( 'ftp_username' ) );
        $password = get_option( $this->get_option_name( 'ftp_password' ) );
        $use_ssl  = get_option( $this->get_option_name( 'ftp_use_ssl' ) );

        $host     = is_string( $host )     ? trim( $host )     : '';
        $username = is_string( $username ) ? trim( $username ) : '';
        $password = is_string( $password ) ? trim( $password ) : '';
        $use_ssl  = ( is_string( $use_ssl ) ? trim( $use_ssl ) : '' ) !== '';

        if ( $host === '' || $username === '' || $password === '' ) {
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
    private function build_rsr_image_urls_from_image_name( string $image_name ): array
    {
        $image_name = trim( $image_name );
        if ( $image_name === '' ) {
            return array();
        }

        $base_prefix = 'https://img.rsrgroup.com/pimages/';
        $urls        = array();

        // Expect pattern like "LAS981-0054_1.jpg"
        if ( preg_match( '/^(.*)_([0-9]+)(\.[^.]+)$/', $image_name, $matches ) ) {
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

            for ( $i = $start_index + 1; $i <= $start_index + $max_extra_attempts; $i++ ) {
                $file = $base . '_' . $i . $ext;
                $url  = $base_prefix . $file;

                if ( ! $this->rsr_is_real_image_url( $url ) ) {
                    // Hit a placeholder (110x85) or missing image → stop.
                    break;
                }

                $urls[] = $url;
            }
        } else {
            // If we don't match the numbered pattern, just use the raw name.
            $urls[] = $base_prefix . ltrim( $image_name, '/' );
        }

        // Ensure unique URLs.
        $urls = array_values( array_unique( $urls ) );

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
    private function rsr_is_real_image_url( string $url ): bool
    {
        $url = trim( $url );
        if ( $url === '' ) {
            return false;
        }

        // Use WordPress HTTP API to fetch the image.
        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 5,
                'redirection' => 3,
            )
        );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( $body === '' || $body === null ) {
            return false;
        }

        // Determine dimensions from the binary image string.
        $image_info = @getimagesizefromstring( $body );
        if ( false === $image_info ) {
            return false;
        }

        $width  = isset( $image_info[0] ) ? (int) $image_info[0] : 0;
        $height = isset( $image_info[1] ) ? (int) $image_info[1] : 0;

        // RSR "image coming soon" placeholder is exactly 110x85.
        if ( $width === 110 && $height === 85 ) {
            return false;
        }

        return true;
    }
}

