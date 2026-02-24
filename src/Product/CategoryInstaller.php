<?php
namespace FFLHub\Product;

use FFLHub\Util\DebugLogUtil;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Creates and manages the unified FFLHub category tree as WooCommerce product categories.
 *
 * - Uses FFLHub_Category_Schema::tree() as the single source of truth for structure/names.
 * - On install, creates any missing terms and builds a "path → term_id" map stored in an option.
 * - Provides a fast lookup helper: get_term_id_from_path( [ 'Firearms', 'Handguns', 'Pistols' ] ).
 */
class CategoryInstaller
{
    private const LOG_PREFIX = '[FFLHub][CategoryInstaller]';

    /**
     * Option key used to store the category path → term_id map.
     *
     * Example entries:
     *   'Firearms'                          => 12
     *   'Firearms > Handguns'               => 34
     *   'Firearms > Handguns > Pistols'     => 56
     *   'Magazines'                         => 78
     *   'Magazines > Rifle'                 => 90
     */
    private const CATEGORY_MAP_OPTION = 'fflhub_category_path_map';

    /**
     * Public entry point – call this on plugin activation or via an admin tool.
     *
     * - Ensures WooCommerce and product_cat exist.
     * - Creates any missing terms according to the schema.
     * - Builds and stores a path → term_id map for fast lookups.
     */
    public static function install_default_categories(): void
    {
        if (! class_exists('WooCommerce')) {
            self::log('Category install aborted: WooCommerce not loaded.');
            return;
        }

        if (! taxonomy_exists('product_cat')) {
            self::log('Category install aborted: taxonomy "product_cat" does not exist.');
            return;
        }

        $tree = self::get_category_tree();

        foreach ($tree as $top_name => $sublevels) {
            // Create top-level term.
            $top_id = self::ensure_term($top_name);
            

            // If there are no sublevels (e.g. 'Lights and Lasers'), this just skips.
            foreach ($sublevels as $mid_name => $leaf_names) {
                // In your current schema, $mid_name is always a string.
                if (is_int($mid_name)) {
                    $mid_name   = $leaf_names;
                    $leaf_names = [];
                }

                // Create mid-level term (child of top).
                $mid_id = self::ensure_term($mid_name, $top_id);
                

                // Create leaf-level terms (children of mid).
                foreach ($leaf_names as $leaf_name) {
                    $leaf_id = self::ensure_term($leaf_name, $mid_id);
                    
                }
            }
        }

        
    }

    /**
     * Returns the canonical category tree from the schema class.
     *
     * This is the single source of truth for the structure and names.
     */
    private static function get_category_tree(): array
    {
        return CategorySchema::tree();
    }

    /**
     * Ensure a product_cat term exists and return its term_id.
     *
     * @param string   $name       Category name.
     * @param int|null $parent_id  Parent term_id (or 0 / null for top-level).
     * @return int                 The term_id, or 0 on failure.
     */
    private static function ensure_term(string $name, ?int $parent_id = null): int
    {
        $taxonomy = 'product_cat';
        $parent   = $parent_id ?: 0;

        if (! taxonomy_exists($taxonomy)) {
            self::log("ensure_term called but taxonomy '{$taxonomy}' does not exist.");
            return 0;
        }

        $existing = term_exists($name, $taxonomy, $parent);

        if (is_array($existing) && isset($existing['term_id'])) {
            // Term already exists under this parent.
            return (int) $existing['term_id'];
        }

        $args   = [
            'slug'   => sanitize_title($name),
            'parent' => $parent,
        ];
        $result = wp_insert_term($name, $taxonomy, $args);

        if (is_wp_error($result)) {
            self::log("Failed to insert term '{$name}': " . $result->get_error_message());
            return 0;
        }

        return (int) $result['term_id'];
    }

    /**
     * Create a stable string key from a path like ['Firearms','Handguns','Pistols'].
     *
     * Result:
     *   'Firearms > Handguns > Pistols'
     *
     * This key format is used both when building the map and when looking up IDs.
     */
    private static function path_key(array $segments): string
    {
        $segments = array_map(
            static fn($s) => trim((string) $s),
            $segments
        );
        $segments = array_filter(
            $segments,
            static fn($s) => $s !== ''
        );

        return implode(' > ', $segments);
    }

    


    /**
     * Given a category path like ['Firearms', 'Handguns', 'Pistols'],
     * return the corresponding term IDs in order.
     *
     * This resolves terms on-demand using term_exists() instead of relying
     * on any pre-built in-memory map, so it works across requests.
     */
    public static function get_term_ids_for_path(array $path): array
    {
        $taxonomy = 'product_cat';

        if (! taxonomy_exists($taxonomy)) {
            self::log('get_term_ids_for_path: taxonomy "product_cat" does not exist.');
            return array();
        }

        $parent   = 0;
        $term_ids = array();

        foreach ($path as $segment) {
            $segment = trim((string) $segment);

            if ('' === $segment) {
                continue;
            }

            // Find term with this name under the current parent.
            $term = term_exists($segment, $taxonomy, $parent);

            if (! $term) {
                self::log(sprintf('get_term_ids_for_path: segment "%s" not found under parent %d.', $segment, $parent));
                return array();
            }

            // term_exists() can return int or array.
            $term_id = is_array($term) && isset($term['term_id'])
                ? (int) $term['term_id']
                : (int) $term;

            $term_ids[] = $term_id;
            $parent     = $term_id;
        }

        if (empty($term_ids)) {
            self::log('get_term_ids_for_path: no term IDs resolved for path.');
        }

        return $term_ids;
    }

    private static function log(string $msg): void
    {
        DebugLogUtil::log('FFLHUB_ADMIN_DEBUG', self::LOG_PREFIX, $msg);
    }
}
