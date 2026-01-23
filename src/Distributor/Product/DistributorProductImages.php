<?php

namespace FFLHub\Distributor\Product;

use FFLHub\Distributor\Models\DistributorProductPayload;
use WC_Product;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central handler for importing and managing distributor product images.
 *
 * Behavior:
 * - Uses ALL image URLs in DistributorProductPayload::$image_urls.
 * - For the "primary" distributor:
 *     - Optional check to only import if no featured image yet.
 *     - First attachment becomes the featured image.
 * - For non-primary distributors:
 *     - Images are imported as gallery-only.
 * - Attachments are tagged with FFLHub-specific meta for management / cleanup.
 */
class DistributorProductImages
{
    /**
     * Attachment meta keys for FFLHub-managed images.
     */
    public const META_MANAGED     = '_fflhub_image_managed';     // 1 = imported/managed by FFLHub
    public const META_UPC         = '_fflhub_image_upc';         // UPC the image belongs to
    public const META_DISTRIBUTOR = '_fflhub_image_distributor'; // e.g. 'rsr', 'lipseys'
    public const META_SOURCE_URL  = '_fflhub_image_source_url';  // remote image URL
    public const META_KEEP        = '_fflhub_image_keep';        // 0 = temp/candidate, 1 = user-approved/keep

    /**
     * Backwards-compatible wrapper for older code that only imported from
     * a single "primary" distributor.
     *
     * Now calls import_images_for_distributor() with $is_primary = true.
     *
     * @param int           $product_id
     * @param string        $upc
     * @param DistributorProductPayload $payload
     * @param string        $distributor_id
     *
     * @return int|WP_Error|null
     */
    public static function import_primary_image_for_product(
        int $product_id,
        string $upc,
        DistributorProductPayload $payload,
        string $distributor_id
    ) {
        return self::import_images_for_distributor(
            $product_id,
            $upc,
            $payload,
            $distributor_id,
            true
        );
    }

    /**
     * Import images for a product from a single distributor.
     *
     * Behavior:
     * - Uses ALL URLs in $payload->image_urls.
     * - If $is_primary:
     *     - Respects should_import_images() (i.e., only if product has no image by default).
     *     - Sets the first attachment as the featured image if none exists.
     * - If NOT $is_primary:
     *     - Always import images (as long as there are URLs).
     *     - Never change the featured image; only add gallery images.
     *
     * @param int            $product_id
     * @param string         $upc
     * @param DistributorProductPayload $payload
     * @param string         $distributor_id e.g. 'rsr', 'lipseys'
     * @param bool           $is_primary     True if this is the main distributor.
     *
     * @return int|WP_Error|null First attachment ID for this distributor,
     *                           null if skipped, or WP_Error on failure.
     */
    public static function import_images_for_distributor(
        int $product_id,
        string $upc,
        DistributorProductPayload $payload,
        string $distributor_id,
        bool $is_primary
    ) {
        if ($product_id <= 0 || '' === trim($upc)) {
            self::log('import_images_for_distributor: invalid product_id or UPC.');
            return null;
        }

        if (! function_exists('wc_get_product')) {
            self::log('import_images_for_distributor: WooCommerce not available.');
            return null;
        }

        $product = wc_get_product($product_id);
        if (! $product instanceof WC_Product) {
            self::log(sprintf('import_images_for_distributor: product %d not found.', $product_id));
            return null;
        }

        // For the primary distributor, honor the global "should we import?" rule.
        if ($is_primary && ! self::should_import_images($product)) {
            self::log(sprintf(
                'import_images_for_distributor: skipping import for primary distributor %s on product %d (rule says no).',
                $distributor_id,
                $product_id
            ));
            return null;
        }

        $urls = self::get_all_image_urls_from_payload($payload);
        if (empty($urls)) {
            self::log(sprintf(
                'import_images_for_distributor: no image URLs for UPC %s / product %d / distributor %s.',
                $upc,
                $product_id,
                $distributor_id
            ));
            return null;
        }

        $attachment_ids = array();

        foreach ($urls as $url) {
            $existing_id = self::find_existing_attachment_for_image(
                $url,
                $upc,
                $distributor_id
            );

            if ($existing_id) {
                $attachment_ids[] = (int) $existing_id;
                continue;
            }

            $sideloaded_id = self::sideload_image_for_product($url, $product_id);
            if (is_wp_error($sideloaded_id)) {
                self::log(sprintf(
                    'import_images_for_distributor: sideload failed for product %d (URL: %s): %s',
                    $product_id,
                    $url,
                    $sideloaded_id->get_error_message()
                ));
                continue;
            }

            $sideloaded_id = (int) $sideloaded_id;

            self::tag_attachment_as_fflhub(
                $sideloaded_id,
                $upc,
                $distributor_id,
                $url,
                0 // keep = 0 initially
            );

            $attachment_ids[] = $sideloaded_id;
        }

        if (empty($attachment_ids)) {
            self::log(sprintf(
                'import_images_for_distributor: no valid attachments imported for product %d / distributor %s.',
                $product_id,
                $distributor_id
            ));
            return null;
        }

        // Refresh product to ensure we have latest featured/gallery state.
        $product = wc_get_product($product_id);
        if (! $product instanceof WC_Product) {
            return null;
        }

        $first_attachment_id = (int) $attachment_ids[0];

        // Only the primary distributor is allowed to set the featured image,
        // and only if the product doesn't already have one.
        $current_featured_id = (int) $product->get_image_id();
        if ($is_primary && $current_featured_id <= 0 && $first_attachment_id > 0) {
            self::set_product_featured_image($product_id, $first_attachment_id);
            $current_featured_id = $first_attachment_id;
        }

        // Add all attachments (except the featured one) to the gallery.
        $existing_gallery = $product->get_gallery_image_ids();
        if (! is_array($existing_gallery)) {
            $existing_gallery = array();
        }

        $gallery_candidates = array();
        foreach ($attachment_ids as $aid) {
            $aid = (int) $aid;
            if ($aid <= 0) {
                continue;
            }
            if ($aid === $current_featured_id) {
                // Don't duplicate the featured image in the gallery.
                continue;
            }
            $gallery_candidates[] = $aid;
        }

        if (! empty($gallery_candidates)) {
            $merged = array_values(array_unique(array_merge(
                array_map('intval', $existing_gallery),
                $gallery_candidates
            )));

            $product->set_gallery_image_ids($merged);
            $product->save();
        }

        self::log(sprintf(
            'import_images_for_distributor: imported %d attachment(s) for product %d via distributor %s (first ID %d, primary=%s).',
            count($attachment_ids),
            $product_id,
            $distributor_id,
            $first_attachment_id,
            $is_primary ? 'yes' : 'no'
        ));

        return $first_attachment_id;
    }

    /**
     * Decide whether we should attempt to import images for this product.
     *
     * Default rule:
     * - Import only if the product does NOT already have a featured image.
     * - Can be overridden via the `fflhub_images_should_import_images` filter.
     *
     * @param WC_Product $product
     *
     * @return bool
     */
    private static function should_import_images(WC_Product $product): bool
    {
        $has_image = (int) $product->get_image_id() > 0;
        $should    = ! $has_image;

        /**
         * Filter whether FFLHub should import images for a product.
         *
         * @param bool       $should  Default decision (true/false).
         * @param WC_Product $product WooCommerce product object.
         */
        return (bool) apply_filters(
            'fflhub_images_should_import_images',
            $should,
            $product
        );
    }

    /**
     * Get all image URLs from the distributor payload, de-duplicated and cleaned.
     *
     * @param DistributorProductPayload $payload
     *
     * @return string[]
     */
    private static function get_all_image_urls_from_payload(
        DistributorProductPayload $payload
    ): array {
        $urls = array();

        if (! empty($payload->image_urls) && is_array($payload->image_urls)) {
            foreach ($payload->image_urls as $url) {
                $url = trim((string) $url);
                if ($url === '') {
                    continue;
                }

                if (! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Try to find an existing attachment that corresponds to the given image,
     * to avoid downloading the same file again.
     *
     * Matching strategy:
     * - Match by source URL + distributor + managed flag.
     *
     * @param string $url
     * @param string $upc              (currently unused, but kept for future expansion)
     * @param string $distributor_id
     *
     * @return int|null Attachment ID if found, otherwise null.
     */
    private static function find_existing_attachment_for_image(
        string $url,
        string $upc,
        string $distributor_id
    ): ?int {
        $attachments = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => self::META_SOURCE_URL,
                        'value' => $url,
                    ),
                    array(
                        'key'   => self::META_DISTRIBUTOR,
                        'value' => $distributor_id,
                    ),
                    array(
                        'key'   => self::META_MANAGED,
                        'value' => 1,
                    ),
                ),
            )
        );

        if (! empty($attachments) && ! is_wp_error($attachments)) {
            return (int) $attachments[0];
        }

        return null;
    }

    /**
     * Download the remote image and create a WP attachment associated to the product.
     *
     * Uses WordPress media APIs to fetch the image.
     *
     * @param string $url
     * @param int    $product_id
     *
     * @return int|WP_Error Attachment ID on success, WP_Error on failure.
     */
    private static function sideload_image_for_product(
        string $url,
        int $product_id
    ) {
        if (empty($url)) {
            return new WP_Error('fflhub_images_empty_url', 'Empty image URL.');
        }

        // Ensure media functions are available.
        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        // media_sideload_image can return HTML, ID, or WP_Error depending on the 4th parameter.
        $attachment_id = media_sideload_image($url, $product_id, null, 'id');

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        return (int) $attachment_id;
    }

    /**
     * Add FFLHub-specific meta to an imported attachment so we can
     * recognize, group, and clean it up later.
     *
     * @param int    $attachment_id
     * @param string $upc
     * @param string $distributor_id
     * @param string $url
     * @param int    $keep          0 or 1, default 0 (not user-approved yet).
     */
    private static function tag_attachment_as_fflhub(
        int $attachment_id,
        string $upc,
        string $distributor_id,
        string $url,
        int $keep = 0
    ): void {
        update_post_meta($attachment_id, self::META_MANAGED, 1);
        update_post_meta($attachment_id, self::META_UPC, $upc);
        update_post_meta($attachment_id, self::META_DISTRIBUTOR, $distributor_id);
        update_post_meta($attachment_id, self::META_SOURCE_URL, $url);
        update_post_meta($attachment_id, self::META_KEEP, (int) $keep);
    }

    /**
     * Set the given attachment as the product's featured image using WooCommerce CRUD.
     *
     * Falls back to set_post_thumbnail() if wc_get_product() is unavailable or fails.
     *
     * @param int $product_id
     * @param int $attachment_id
     */
    private static function set_product_featured_image(
        int $product_id,
        int $attachment_id
    ): void {
        if ($product_id <= 0 || $attachment_id <= 0) {
            return;
        }

        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product instanceof WC_Product) {
                $product->set_image_id($attachment_id);
                $product->save();

                // Ensure attachment parent is the product, if not already set.
                $attachment_post = get_post($attachment_id);
                if ($attachment_post && (int) $attachment_post->post_parent !== $product_id) {
                    wp_update_post(
                        array(
                            'ID'          => $attachment_id,
                            'post_parent' => $product_id,
                        )
                    );
                }

                return;
            }
        }

        // Fallback: core WordPress function.
        set_post_thumbnail($product_id, $attachment_id);
    }

    /**
     * Simple internal logger helper for FFLHub image operations.
     *
     * @param string $message
     */
    private static function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[FFLHub][Images] ' . $message);
        }
    }
}

