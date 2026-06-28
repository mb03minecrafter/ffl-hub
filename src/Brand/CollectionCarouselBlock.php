<?php

declare(strict_types=1);

namespace FFLHub\Brand;

use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Homepage-ready carousel for customer-facing product collections.
 *
 * Product collections are still WooCommerce product tags under the hood, but
 * ProductCollectionRewrite gives them /collections/{slug}/ URLs. This block
 * renders those terms as a merchandising carousel that can live alongside the
 * existing Woo product collection blocks on the homepage.
 */
final class CollectionCarouselBlock
{
    private const BLOCK_NAME = 'fflhub/collection-carousel';
    private const HERO_IMAGE_META_KEY = 'fflhub_archive_hero_image_id';
    private const EDITOR_SCRIPT_HANDLE = 'fflhub-collection-carousel-editor';
    private const FRONTEND_SCRIPT_HANDLE = 'fflhub-collection-carousel';
    private const FRONTEND_STYLE_HANDLE = 'fflhub-collection-carousel';

    public static function init(): void
    {
        add_action('init', [self::class, 'register']);
    }

    public static function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        self::register_assets();

        register_block_type(self::BLOCK_NAME, [
            'api_version'     => 2,
            'title'           => __('FFLHub Collection Carousel', 'ffl-hub'),
            'category'        => 'widgets',
            'description'     => __('Displays product collection archive links in an auto-advancing carousel.', 'ffl-hub'),
            'editor_script'   => self::EDITOR_SCRIPT_HANDLE,
            'render_callback' => [self::class, 'render'],
            'attributes'      => [
                'title' => [
                    'type'    => 'string',
                    'default' => 'Shop Popular Collections',
                ],
                'taxonomy' => [
                    'type'    => 'string',
                    'default' => 'product_tag',
                ],
                'include' => [
                    'type'    => 'string',
                    'default' => '',
                ],
                'perPage' => [
                    'type'    => 'number',
                    'default' => 12,
                ],
                'orderBy' => [
                    'type'    => 'string',
                    'default' => 'count',
                ],
                'order' => [
                    'type'    => 'string',
                    'default' => 'desc',
                ],
                'hideEmpty' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
                'showDescription' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
                'showCount' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
                'autoplay' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
                'interval' => [
                    'type'    => 'number',
                    'default' => 4500,
                ],
            ],
            'supports' => [
                'align' => ['wide', 'full'],
                'html'  => false,
            ],
        ]);
    }

    private static function register_assets(): void
    {
        $editor_path = FFLHUB_PLUGIN_PATH . 'assets/js/fflhub-collection-carousel-block.js';
        $script_path = FFLHUB_PLUGIN_PATH . 'assets/js/fflhub-collection-carousel.js';
        $style_path = FFLHUB_PLUGIN_PATH . 'assets/css/fflhub-collection-carousel.css';

        wp_register_script(
            self::EDITOR_SCRIPT_HANDLE,
            FFLHUB_PLUGIN_URL . 'assets/js/fflhub-collection-carousel-block.js',
            ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-server-side-render'],
            file_exists($editor_path) ? (string) filemtime($editor_path) : FFLHUB_PLUGIN_VERSION,
            true
        );

        wp_register_script(
            self::FRONTEND_SCRIPT_HANDLE,
            FFLHUB_PLUGIN_URL . 'assets/js/fflhub-collection-carousel.js',
            [],
            file_exists($script_path) ? (string) filemtime($script_path) : FFLHUB_PLUGIN_VERSION,
            true
        );

        wp_register_style(
            self::FRONTEND_STYLE_HANDLE,
            FFLHUB_PLUGIN_URL . 'assets/css/fflhub-collection-carousel.css',
            [],
            file_exists($style_path) ? (string) filemtime($style_path) : FFLHUB_PLUGIN_VERSION
        );
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        $attributes = self::normalize_attributes($attributes);
        $terms = self::collection_terms($attributes);
        if ($terms === []) {
            return '';
        }

        self::enqueue_frontend_assets();

        $title = trim((string) $attributes['title']);
        $show_description = (bool) $attributes['showDescription'];
        $show_count = (bool) $attributes['showCount'];
        $autoplay = (bool) $attributes['autoplay'];
        $interval = max(1500, (int) $attributes['interval']);
        $section_id = wp_unique_id('fflhub-collection-carousel-');

        ob_start();
        ?>
        <section
            id="<?php echo esc_attr($section_id); ?>"
            class="fflhub-collection-carousel alignwide"
            data-autoplay="<?php echo esc_attr($autoplay ? '1' : '0'); ?>"
            data-interval="<?php echo esc_attr((string) $interval); ?>"
            aria-label="<?php echo esc_attr($title !== '' ? $title : __('Product collections', 'ffl-hub')); ?>"
        >
            <?php if ($title !== '') : ?>
                <div class="fflhub-collection-carousel__header">
                    <h2 class="fflhub-collection-carousel__title"><?php echo esc_html($title); ?></h2>
                </div>
            <?php endif; ?>

            <div class="fflhub-collection-carousel__viewport">
                <button class="fflhub-collection-carousel__button fflhub-collection-carousel__button--prev" type="button" aria-label="<?php echo esc_attr__('Previous collection', 'ffl-hub'); ?>">
                    <span aria-hidden="true">&lsaquo;</span>
                </button>

                <div class="fflhub-collection-carousel__track" tabindex="0">
                    <?php foreach ($terms as $term) : ?>
                        <?php self::render_card($term, $show_description, $show_count); ?>
                    <?php endforeach; ?>
                </div>

                <button class="fflhub-collection-carousel__button fflhub-collection-carousel__button--next" type="button" aria-label="<?php echo esc_attr__('Next collection', 'ffl-hub'); ?>">
                    <span aria-hidden="true">&rsaquo;</span>
                </button>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private static function normalize_attributes(array $attributes): array
    {
        $defaults = [
            'title'           => 'Shop Popular Collections',
            'taxonomy'        => 'product_tag',
            'include'         => '',
            'perPage'         => 12,
            'orderBy'         => 'count',
            'order'           => 'desc',
            'hideEmpty'       => true,
            'showDescription' => true,
            'showCount'       => true,
            'autoplay'        => true,
            'interval'        => 4500,
        ];

        $attributes = array_merge($defaults, $attributes);
        $attributes['taxonomy'] = taxonomy_exists((string) $attributes['taxonomy'])
            ? (string) $attributes['taxonomy']
            : 'product_tag';
        $attributes['perPage'] = max(1, min(30, absint($attributes['perPage'])));
        $attributes['orderBy'] = in_array((string) $attributes['orderBy'], ['count', 'name', 'slug', 'term_id', 'include'], true)
            ? (string) $attributes['orderBy']
            : 'count';
        $attributes['order'] = strtolower((string) $attributes['order']) === 'asc' ? 'asc' : 'desc';
        $attributes['interval'] = max(1500, absint($attributes['interval']));

        return $attributes;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return WP_Term[]
     */
    private static function collection_terms(array $attributes): array
    {
        $include = self::parse_include_list((string) $attributes['include'], (string) $attributes['taxonomy']);

        $query_number = $include === []
            ? min(100, max((int) $attributes['perPage'], (int) $attributes['perPage'] * 4))
            : 0;

        $args = [
            'hide_empty' => (bool) $attributes['hideEmpty'],
            'number'     => $query_number,
            'order'      => strtoupper((string) $attributes['order']),
            'orderby'    => (string) $attributes['orderBy'],
            'taxonomy'   => (string) $attributes['taxonomy'],
        ];

        if ($include !== []) {
            $args['include'] = $include;
            $args['orderby'] = 'include';
            $args['number'] = 0;
        }

        $terms = get_terms($args);
        if (is_wp_error($terms) || !is_array($terms)) {
            return [];
        }

        $out = [];
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            if ($include === [] && !self::looks_like_collection($term)) {
                continue;
            }

            $out[] = $term;
            if (count($out) >= (int) $attributes['perPage']) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return int[]
     */
    private static function parse_include_list(string $raw, string $taxonomy): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }

            if (ctype_digit($piece)) {
                $out[] = absint($piece);
                continue;
            }

            $term = get_term_by('slug', sanitize_title($piece), $taxonomy);
            if ($term instanceof WP_Term) {
                $out[] = (int) $term->term_id;
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    private static function looks_like_collection(WP_Term $term): bool
    {
        if (trim((string) $term->description) !== '') {
            return true;
        }

        if (absint(get_term_meta((int) $term->term_id, self::HERO_IMAGE_META_KEY, true)) > 0) {
            return true;
        }

        foreach (['_yoast_wpseo_focuskw', 'wpseo_focuskw'] as $key) {
            if (trim((string) get_term_meta((int) $term->term_id, $key, true)) !== '') {
                return true;
            }
        }

        if (self::has_yoast_taxonomy_meta($term)) {
            return true;
        }

        return false;
    }

    private static function has_yoast_taxonomy_meta(WP_Term $term): bool
    {
        $meta = get_option('wpseo_taxonomy_meta');
        if (!is_array($meta) || empty($meta[$term->taxonomy]) || !is_array($meta[$term->taxonomy])) {
            return false;
        }

        $term_meta = $meta[$term->taxonomy][(int) $term->term_id] ?? null;
        if (!is_array($term_meta)) {
            return false;
        }

        foreach (['wpseo_focuskw', 'wpseo_title', 'wpseo_desc', 'wpseo_metadesc'] as $key) {
            if (trim((string) ($term_meta[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function render_card(WP_Term $term, bool $show_description, bool $show_count): void
    {
        $link = get_term_link($term);
        if (is_wp_error($link)) {
            return;
        }

        $image_id = self::archive_thumbnail_id($term);
        if ($image_id <= 0) {
            $image_id = self::first_product_thumbnail_id($term);
        }

        $description = self::description_excerpt($term);
        ?>
        <a class="fflhub-collection-carousel__card" href="<?php echo esc_url((string) $link); ?>">
            <span class="fflhub-collection-carousel__media">
                <?php if ($image_id > 0) : ?>
                    <?php
                    echo wp_get_attachment_image($image_id, 'woocommerce_thumbnail', false, [
                        'class'    => 'fflhub-collection-carousel__image',
                        'loading'  => 'lazy',
                        'decoding' => 'async',
                        'alt'      => self::image_alt($image_id, $term),
                    ]);
                    ?>
                <?php else : ?>
                    <span class="fflhub-collection-carousel__image-placeholder" aria-hidden="true"></span>
                <?php endif; ?>
            </span>
            <span class="fflhub-collection-carousel__body">
                <span class="fflhub-collection-carousel__name"><?php echo esc_html($term->name); ?></span>
                <?php if ($show_description && $description !== '') : ?>
                    <span class="fflhub-collection-carousel__description"><?php echo esc_html($description); ?></span>
                <?php endif; ?>
                <?php if ($show_count) : ?>
                    <span class="fflhub-collection-carousel__count">
                        <?php
                        printf(
                            esc_html(_n('%s product', '%s products', (int) $term->count, 'ffl-hub')),
                            esc_html(number_format_i18n((int) $term->count))
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </span>
        </a>
        <?php
    }

    private static function archive_thumbnail_id(WP_Term $term): int
    {
        foreach ([self::HERO_IMAGE_META_KEY, 'thumbnail_id', 'brand_thumbnail_id', 'product_brand_thumbnail_id'] as $key) {
            $id = absint(get_term_meta((int) $term->term_id, $key, true));
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private static function first_product_thumbnail_id(WP_Term $term): int
    {
        $product_ids = get_posts([
            'fields'         => 'ids',
            'meta_key'       => '_thumbnail_id',
            'no_found_rows'  => true,
            'order'          => 'ASC',
            'orderby'        => 'menu_order title',
            'post_status'    => 'publish',
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'tax_query'      => [
                [
                    'field'            => 'term_id',
                    'include_children' => $term->taxonomy === 'product_cat',
                    'taxonomy'         => $term->taxonomy,
                    'terms'            => [(int) $term->term_id],
                ],
            ],
        ]);

        if (!is_array($product_ids) || $product_ids === []) {
            return 0;
        }

        return absint(get_post_thumbnail_id((int) $product_ids[0]));
    }

    private static function image_alt(int $image_id, WP_Term $term): string
    {
        $alt = trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true));

        return $alt !== '' ? $alt : (string) $term->name;
    }

    private static function description_excerpt(WP_Term $term): string
    {
        $description = trim(wp_strip_all_tags((string) term_description((int) $term->term_id, $term->taxonomy)));
        if ($description === '') {
            return '';
        }

        return wp_html_excerpt($description, 130, '...');
    }

    private static function enqueue_frontend_assets(): void
    {
        wp_enqueue_style(self::FRONTEND_STYLE_HANDLE);
        wp_enqueue_script(self::FRONTEND_SCRIPT_HANDLE);
    }
}
