<?php

declare(strict_types=1);

namespace FFLHub\Content;

use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Homepage-ready carousel for blog posts.
 *
 * This intentionally shares the same frontend classes/assets as the collection
 * carousel so homepage blog links feel like part of the same merchandising
 * system: image card, title, SEO meta copy, and auto-advance behavior.
 */
final class BlogPostCarouselBlock
{
    private const BLOCK_NAME = 'fflhub/blog-post-carousel';
    private const EDITOR_SCRIPT_HANDLE = 'fflhub-blog-post-carousel-editor';
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
            'title'           => __('FFLHub Blog Post Carousel', 'ffl-hub'),
            'category'        => 'widgets',
            'description'     => __('Displays blog post links in an auto-advancing carousel.', 'ffl-hub'),
            'editor_script'   => self::EDITOR_SCRIPT_HANDLE,
            'render_callback' => [self::class, 'render'],
            'attributes'      => [
                'title' => [
                    'type'    => 'string',
                    'default' => 'Latest From the Blog',
                ],
                'include' => [
                    'type'    => 'string',
                    'default' => '',
                ],
                'categorySlugs' => [
                    'type'    => 'string',
                    'default' => '',
                ],
                'perPage' => [
                    'type'    => 'number',
                    'default' => 8,
                ],
                'orderBy' => [
                    'type'    => 'string',
                    'default' => 'date',
                ],
                'order' => [
                    'type'    => 'string',
                    'default' => 'desc',
                ],
                'showDescription' => [
                    'type'    => 'boolean',
                    'default' => true,
                ],
                'showMeta' => [
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
        $editor_path = FFLHUB_PLUGIN_PATH . 'assets/js/fflhub-blog-post-carousel-block.js';

        wp_register_script(
            self::EDITOR_SCRIPT_HANDLE,
            FFLHUB_PLUGIN_URL . 'assets/js/fflhub-blog-post-carousel-block.js',
            ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-server-side-render'],
            file_exists($editor_path) ? (string) filemtime($editor_path) : FFLHUB_PLUGIN_VERSION,
            true
        );

        self::register_shared_frontend_assets();
    }

    private static function register_shared_frontend_assets(): void
    {
        $script_path = FFLHUB_PLUGIN_PATH . 'assets/js/fflhub-collection-carousel.js';
        $style_path = FFLHUB_PLUGIN_PATH . 'assets/css/fflhub-collection-carousel.css';

        if (!wp_script_is(self::FRONTEND_SCRIPT_HANDLE, 'registered')) {
            wp_register_script(
                self::FRONTEND_SCRIPT_HANDLE,
                FFLHUB_PLUGIN_URL . 'assets/js/fflhub-collection-carousel.js',
                [],
                file_exists($script_path) ? (string) filemtime($script_path) : FFLHUB_PLUGIN_VERSION,
                true
            );
        }

        if (!wp_style_is(self::FRONTEND_STYLE_HANDLE, 'registered')) {
            wp_register_style(
                self::FRONTEND_STYLE_HANDLE,
                FFLHUB_PLUGIN_URL . 'assets/css/fflhub-collection-carousel.css',
                [],
                file_exists($style_path) ? (string) filemtime($style_path) : FFLHUB_PLUGIN_VERSION
            );
        }
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        $attributes = self::normalize_attributes($attributes);
        $posts = self::posts($attributes);
        if ($posts === []) {
            return '';
        }

        wp_enqueue_style(self::FRONTEND_STYLE_HANDLE);
        wp_enqueue_script(self::FRONTEND_SCRIPT_HANDLE);

        $title = trim((string) $attributes['title']);
        $show_description = (bool) $attributes['showDescription'];
        $show_meta = (bool) $attributes['showMeta'];
        $autoplay = (bool) $attributes['autoplay'];
        $interval = max(1500, (int) $attributes['interval']);
        $section_id = wp_unique_id('fflhub-blog-post-carousel-');

        ob_start();
        ?>
        <section
            id="<?php echo esc_attr($section_id); ?>"
            class="fflhub-collection-carousel fflhub-blog-post-carousel alignwide"
            data-autoplay="<?php echo esc_attr($autoplay ? '1' : '0'); ?>"
            data-interval="<?php echo esc_attr((string) $interval); ?>"
            aria-label="<?php echo esc_attr($title !== '' ? $title : __('Blog posts', 'ffl-hub')); ?>"
        >
            <?php if ($title !== '') : ?>
                <div class="fflhub-collection-carousel__header">
                    <h2 class="fflhub-collection-carousel__title"><?php echo esc_html($title); ?></h2>
                </div>
            <?php endif; ?>

            <div class="fflhub-collection-carousel__viewport">
                <button class="fflhub-collection-carousel__button fflhub-collection-carousel__button--prev" type="button" aria-label="<?php echo esc_attr__('Previous post', 'ffl-hub'); ?>">
                    <span aria-hidden="true">&lsaquo;</span>
                </button>

                <div class="fflhub-collection-carousel__track" tabindex="0">
                    <?php foreach ($posts as $post) : ?>
                        <?php self::render_card($post, $show_description, $show_meta); ?>
                    <?php endforeach; ?>
                </div>

                <button class="fflhub-collection-carousel__button fflhub-collection-carousel__button--next" type="button" aria-label="<?php echo esc_attr__('Next post', 'ffl-hub'); ?>">
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
            'title'           => 'Latest From the Blog',
            'include'         => '',
            'categorySlugs'   => '',
            'perPage'         => 8,
            'orderBy'         => 'date',
            'order'           => 'desc',
            'showDescription' => true,
            'showMeta'        => true,
            'autoplay'        => true,
            'interval'        => 4500,
        ];

        $attributes = array_merge($defaults, $attributes);
        $attributes['perPage'] = max(1, min(100, absint($attributes['perPage'])));
        $attributes['orderBy'] = in_array((string) $attributes['orderBy'], ['date', 'title', 'modified', 'menu_order', 'rand', 'post__in'], true)
            ? (string) $attributes['orderBy']
            : 'date';
        $attributes['order'] = strtolower((string) $attributes['order']) === 'asc' ? 'asc' : 'desc';
        $attributes['interval'] = max(1500, absint($attributes['interval']));

        return $attributes;
    }

    /**
     * @param array<string,mixed> $attributes
     * @return WP_Post[]
     */
    private static function posts(array $attributes): array
    {
        $include = self::parse_include_list((string) $attributes['include']);
        $args = [
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'order'               => strtoupper((string) $attributes['order']),
            'orderby'             => (string) $attributes['orderBy'],
            'post_status'         => 'publish',
            'post_type'           => 'post',
            'posts_per_page'      => (int) $attributes['perPage'],
            'suppress_filters'    => false,
        ];

        if ($include !== []) {
            $args['post__in'] = $include;
            $args['orderby'] = 'post__in';
        } else {
            $category_slugs = self::parse_slug_list((string) $attributes['categorySlugs']);
            if ($category_slugs !== []) {
                $args['category_name'] = implode(',', $category_slugs);
            }
        }

        $posts = get_posts($args);
        if (!is_array($posts)) {
            return [];
        }

        return array_values(array_filter($posts, static fn ($post): bool => $post instanceof WP_Post));
    }

    /**
     * @return int[]
     */
    private static function parse_include_list(string $raw): array
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

            $post = get_page_by_path(sanitize_title($piece), OBJECT, 'post');
            if ($post instanceof WP_Post) {
                $out[] = (int) $post->ID;
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * @return string[]
     */
    private static function parse_slug_list(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $piece) {
            $slug = sanitize_title($piece);
            if ($slug !== '') {
                $out[] = $slug;
            }
        }

        return array_values(array_unique($out));
    }

    private static function render_card(WP_Post $post, bool $show_description, bool $show_meta): void
    {
        $image_id = absint(get_post_thumbnail_id($post));
        $description = self::seo_meta_excerpt($post);
        $badge = self::post_badge($post);
        ?>
        <a class="fflhub-collection-carousel__card" href="<?php echo esc_url(get_permalink($post)); ?>">
            <span class="fflhub-collection-carousel__media">
                <?php if ($image_id > 0) : ?>
                    <?php
                    echo wp_get_attachment_image($image_id, 'medium_large', false, [
                        'class'    => 'fflhub-collection-carousel__image',
                        'loading'  => 'lazy',
                        'decoding' => 'async',
                        'alt'      => self::image_alt($image_id, $post),
                    ]);
                    ?>
                <?php else : ?>
                    <span class="fflhub-collection-carousel__image-placeholder" aria-hidden="true"></span>
                <?php endif; ?>
            </span>
            <span class="fflhub-collection-carousel__body">
                <span class="fflhub-collection-carousel__name"><?php echo esc_html(get_the_title($post)); ?></span>
                <?php if ($show_description && $description !== '') : ?>
                    <span class="fflhub-collection-carousel__description"><?php echo esc_html($description); ?></span>
                <?php endif; ?>
                <?php if ($show_meta && $badge !== '') : ?>
                    <span class="fflhub-collection-carousel__count"><?php echo esc_html($badge); ?></span>
                <?php endif; ?>
            </span>
        </a>
        <?php
    }

    private static function image_alt(int $image_id, WP_Post $post): string
    {
        $alt = trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true));

        return $alt !== '' ? $alt : get_the_title($post);
    }

    private static function seo_meta_excerpt(WP_Post $post): string
    {
        $description = self::yoast_meta_description($post);
        if ($description === '') {
            $description = trim(wp_strip_all_tags((string) get_the_excerpt($post)));
        }

        if ($description === '') {
            return '';
        }

        return wp_html_excerpt($description, 150, '...');
    }

    private static function yoast_meta_description(WP_Post $post): string
    {
        $value = trim((string) get_post_meta((int) $post->ID, '_yoast_wpseo_metadesc', true));
        if ($value === '') {
            return '';
        }

        if (function_exists('wpseo_replace_vars')) {
            $replaced = wpseo_replace_vars($value, $post);
            if (is_string($replaced) && trim($replaced) !== '') {
                return trim($replaced);
            }
        }

        return $value;
    }

    private static function post_badge(WP_Post $post): string
    {
        $categories = get_the_category((int) $post->ID);
        if (is_array($categories) && $categories !== [] && isset($categories[0]->name)) {
            return (string) $categories[0]->name;
        }

        return get_the_date('', $post);
    }
}
