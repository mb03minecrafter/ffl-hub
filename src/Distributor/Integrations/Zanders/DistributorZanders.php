<?php
// File: src/Distributor/Lipseys/DistributorLipseys.php

namespace FFLHub\Distributor\Integrations\Zanders;

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Models\DistributorProductPayload;
use FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Util\DebugLogUtil;

class DistributorZanders extends DistributorBase
{

    /**
     * Toggle Zanders debug logs.
     *
     * Enable by setting:
     *   define('FFLHUB_ZANDERS_DEBUG', true);
     * in wp-config.php, OR env var:
     *   FFLHUB_ZANDERS_DEBUG=1
     */
    private const DEBUG_FLAG = 'FFLHUB_ZANDERS_DEBUG';
    private const LOG_PREFIX = '[FFLHub][ZandersDistributor]';

    /**
     * Guardrail: prevent pathological carts from causing heavy DB lookups.
     */
    private const VALIDATE_MAX_UNIQUE_ITEMS = 75;

    public function __construct(DistributorModuleInterface $module, $services = null)
    {
        parent::__construct($module, $services);
    }


    /**
     * For Zanders, its $15 no matter what
     */
    public function get_shipping_cost_by_upc(string $upc): ?float
    {
        $normalized = $this->normalize_upc($upc);
        if ($normalized === null) {
            return null;
        }
        return 15.0;
    }




    /**
     * Build a normalized DistributorProductPayload for a UPC using the local Zanders fulfillment table.
     *
     * NOTE:
     * - No SOAP calls.
     * - Pricing: uses price_1 as the default distributor price (tier1).
     */
    public function get_product_by_upc(string $upc): ?DistributorProductPayload
    {
        if (!$this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (!$row || !is_array($row)) {
            return null;
        }

        return $this->build_payload_from_row_zanders($row, $normalized_upc, true);
    }

    //same as above, we exlcude image for our product syncing 
    public function get_pricing_payload_by_upc(string $upc): ?DistributorProductPayload
    {
        if (!$this->services) {
            return null;
        }

        $normalized_upc = $this->normalize_upc($upc);
        if ($normalized_upc === null) {
            return null;
        }

        $row = $this->services->get_fulfillment_table()->get_row_by_upc($normalized_upc);
        if (!$row || !is_array($row)) {
            return null;
        }

        return $this->build_payload_from_row_zanders($row, $normalized_upc, false); //false for the image flag
    }


    /**
     * Zanders-specific payload builder.
     *
     * Differences vs base:
     * - Cleans price strings (strip $ and other junk)
     * - Parses "available" safely (supports "10+", " 25 ", etc.)
     * - Uses map_zanders() category mapper
     * - FTP image loading 
     *
     * @param array<string,mixed> $row
     */
    protected function build_payload_from_row_zanders(
        array $row,
        string $normalized_upc,
        bool $include_images = true
    ): DistributorProductPayload {
        // Helpers: parse money + parse int-ish strings safely.
        $money = static function ($v): float {
            $s = trim((string) $v);
            if ($s === '') {
                return 0.0;
            }
            $s = preg_replace('/[^0-9\.\-]/', '', $s);
            $s = is_string($s) ? $s : '';
            return $s === '' ? 0.0 : (float) $s;
        };

        $intish = static function ($v): int {
            $s = trim((string) $v);
            if ($s === '') {
                return 0;
            }
            // supports "10+", "Qty: 5", etc.
            $digits = preg_replace('/\D+/', '', $s);
            $digits = is_string($digits) ? $digits : '';
            return $digits === '' ? 0 : (int) $digits;
        };

        $sku     = trim((string) ($this->get_string_field($row, ['zanders_item_number']) ?? ''));
        $upc_raw = $this->get_string_field($row, ['upc']) ?? $normalized_upc;
        $upc     = $this->normalize_upc((string) $upc_raw) ?? $normalized_upc;

        // Name/description: Zanders gives desc1/desc2
        $raw_name = trim((string) ($this->get_string_field($row, ['manufacturer', 'desc1', 'desc2']) ?? ''));
        $raw_desc = trim((string) ($this->get_string_field($row, ['desc1', 'desc2']) ?? ''));

        $raw_name = trim((string) preg_replace('/\s+/', ' ', $raw_name));
        $raw_desc = trim((string) preg_replace('/\s+/', ' ', $raw_desc));

        if ($raw_name !== '' && $raw_desc !== '') {
            $name = (stripos($raw_name, $raw_desc) !== false) ? $raw_name : ($raw_name . ' – ' . $raw_desc);
        } else {
            $name = $raw_name !== '' ? $raw_name : $raw_desc;
        }

        $description = $raw_desc;

        $price    = $money($this->get_string_field($row, ['price_1']));
        $mapPrice = $money($this->get_string_field($row, ['map_price']));
        $msrp     = $money($this->get_string_field($row, ['msrp']));

        $quantity = $intish($this->get_string_field($row, ['available']));

        $shipping  = (float) ($this->get_shipping_cost_by_upc($normalized_upc) ?? 0.0);
        $true_cost = $this->get_true_cost_by_distributor_cost_shipping_cost($price, $shipping);
        if ($true_cost === null) {
            $true_cost = $price + $shipping;
        }

        $category_raw = $this->get_string_field($row, ['category']);
        $recommended_category = null;
        if (is_string($category_raw) && trim($category_raw) !== '') {
            $recommended_category = \FFLHub\Distributor\Product\Category\DistributorProductCategoryMapper::map_zanders($category_raw);
            if (!is_array($recommended_category)) {
                $recommended_category = null;
            }
        }

        $image = '';
        if ($include_images) {
            // field param unused for Zanders; pass null for clarity
            $image = $this->get_image_url_from_row($row, null);
            $image = is_string($image) ? trim($image) : '';
        }

        // No explicit FFL required flag in your Zanders schema.
        $ffl_required = false;

        return new DistributorProductPayload(
            $upc,
            $sku,
            $name,
            $description,
            $price,
            $mapPrice,
            $msrp,
            $quantity,
            $shipping,
            (float) $true_cost,
            $image,
            $ffl_required,
            $recommended_category,
            $row
        );
    }



    /**
     * Zanders image resolver (FTP-cached).
     *
     * Remote:
     *   /Inventory/Images_2/{zanders_item_number}.jpg
     *
     * Local cache:
     *   wp-content/uploads/fflhub-zanders/images/{zanders_item_number}.jpg
     *
     * @param array<string,mixed> $row
     * @param mixed              $field Unused for Zanders
     */
    protected function get_image_url_from_row(array $row, $field): string
    {
        $item_no = trim((string) ($this->get_string_field($row, ['zanders_item_number']) ?? ''));
        if ($item_no === '') {
            $this->log('Image: missing zanders_item_number');
            return '';
        }

        $remote_path = $this->build_zanders_remote_image_path($item_no);
        if ($remote_path === '') {
            $this->log('Image: failed to build remote path', ['item_no' => $item_no]);
            return '';
        }

        $missing_key = 'fflhub_zanders_img_missing_' . md5($remote_path);
        if (get_transient($missing_key)) {
            $this->log('Image: skip (known missing)', ['remote' => $remote_path, 'item_no' => $item_no]);
            return '';
        }

        $uploads = wp_upload_dir();
        $subdir  = 'fflhub-zanders/images';
        $dir     = rtrim((string) ($uploads['basedir'] ?? ''), '/\\') . '/' . $subdir;

        if ($dir === '' || empty($uploads['baseurl'])) {
            $this->log('Image: uploads dir/baseurl missing', ['basedir' => $uploads['basedir'] ?? '', 'baseurl' => $uploads['baseurl'] ?? '']);
            return '';
        }

        $safe_item = preg_replace('/[^A-Za-z0-9_\-\.]+/', '', $item_no);
        $safe_item = is_string($safe_item) ? $safe_item : '';
        if ($safe_item === '') {
            $safe_item = 'zanders';
        }

        $filename   = $safe_item . '.jpg';
        $local_path = $dir . '/' . $filename;
        $public_url = rtrim((string) $uploads['baseurl'], '/\\') . '/' . $subdir . '/' . rawurlencode($filename);

        // ✅ CACHE HIT
        if (is_file($local_path) && filesize($local_path) > 1024) {
            $this->log('Image: cache hit', [
                'item_no' => $item_no,
                'local'   => $local_path,
                'bytes'   => (int) filesize($local_path),
            ]);
            return $public_url;
        }

        $lock_key = 'fflhub_zanders_img_dl_' . md5($remote_path);
        if (get_transient($lock_key)) {
            $this->log('Image: skip (download lock active)', ['remote' => $remote_path, 'item_no' => $item_no]);
            return '';
        }
        set_transient($lock_key, 1, 10 * MINUTE_IN_SECONDS);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Image: failed to create cache dir', ['dir' => $dir]);
            return '';
        }

        $creds = $this->get_ftp_credentials();
        if (!$creds) {
            $this->log('Image: missing FTP creds');
            return '';
        }

        $this->log('Image: cache miss, attempting download', [
            'remote' => $remote_path,
            'local'  => $local_path,
            'item_no' => $item_no,
        ]);

        try {
            $ftp = new FTPClientService(
                $creds['host'],
                $creds['username'],
                $creds['password'],
                (bool) $creds['use_ssl'],
                (int) $creds['port'],
                30,
                true,
                '[FFLHub][ZandersFTP]'
            );

            if (!$ftp->is_connected()) {
                $this->log('Image: FTP not connected', ['host' => $creds['host']]);
                return '';
            }

            $remote_size = $ftp->get_remote_size($remote_path);

            // ✅ REMOTE MISSING
            if ($remote_size <= 0) {
                set_transient($missing_key, 1, DAY_IN_SECONDS);
                $this->log('Image: remote missing (cached)', [
                    'remote' => $remote_path,
                    'size'   => $remote_size,
                ]);
                return '';
            }

            $this->log('Image: remote exists, downloading', ['remote' => $remote_path, 'size' => $remote_size]);

            $ok = $ftp->download_file($remote_path, $local_path);
            if (!$ok) {
                $this->log('Image: download failed', [
                    'remote' => $remote_path,
                    'local'  => $local_path,
                    'err'    => $ftp->get_last_error(),
                ]);
                return '';
            }
        } catch (\Throwable $e) {
            $this->log('Image: exception during download', ['remote' => $remote_path, 'err' => $e->getMessage()]);
            return '';
        }

        // ✅ DOWNLOAD SUCCESS
        if (is_file($local_path) && filesize($local_path) > 1024) {
            $this->log('Image: download success', [
                'local' => $local_path,
                'bytes' => (int) filesize($local_path),
            ]);
            return $public_url;
        }

        $this->log('Image: downloaded file invalid/tiny', [
            'local' => $local_path,
            'bytes' => is_file($local_path) ? (int) filesize($local_path) : 0,
        ]);

        return '';
    }




    private function build_zanders_remote_image_path(string $item_no): string
    {
        $item_no = trim($item_no);
        if ($item_no === '') {
            return '';
        }

        // Example: /Inventory/Images_2/00061.jpg
        return '/Inventory/Images_2/' . $item_no . '.jpg';
    }




    /**
     * Retrieve and validate FTP credentials from Zanders distributor settings.
     *
     * Note: Zanders is FTP (no TLS). We enforce use_ssl=false and port=21 defaults.
     *
     * @return array{host:string,username:string,password:string,use_ssl:bool,port:int}|null
     */
    private function get_ftp_credentials(): ?array
    {
        $host     = \FFLHub\Settings\Options::get_distributor_option('zanders', 'ftp_host', '');
        $username = \FFLHub\Settings\Options::get_distributor_option('zanders', 'ftp_username', '');
        $password = \FFLHub\Settings\Options::get_distributor_option('zanders', 'ftp_password', '');

        $host     = trim((string) $host);
        $username = trim((string) $username);
        $password = trim((string) $password);

        if ($host === '' || $username === '' || $password === '') {
            // If you don't have a logger on the distributor class, you can remove this.
            if (defined('FFLHUB_CRON_DEBUG') && FFLHUB_CRON_DEBUG === true) {
                error_log('[FFLHub][ZandersDistributor] Missing FTP credentials');
            }
            return null;
        }

        return [
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'use_ssl'  => false,
            'port'     => 21,
        ];
    }



    /** @param array<string,mixed> $ctx */
    private function log(string $msg, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }
}
