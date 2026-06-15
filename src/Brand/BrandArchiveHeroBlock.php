<?php

declare(strict_types=1);

namespace FFLHub\Brand;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Term;

/**
 * Dynamic block for WooCommerce product brand archive headers.
 *
 * Core archive blocks can render the brand title and description, but they do
 * not know how to use Woo's brand thumbnail term meta. This block keeps the
 * Site Editor template clean while letting the brand admin screen own the SEO
 * copy and optional hero image.
 */
final class BrandArchiveHeroBlock
{
    public static function init(): void
    {
        add_action('init', [self::class, 'register']);
    }

    public static function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('fflhub/brand-archive-hero', [
            'api_version'     => 2,
            'title'           => __('FFLHub Brand Archive Hero', 'ffl-hub'),
            'category'        => 'widgets',
            'description'     => __('Displays the current WooCommerce brand title, description, product count, and optional brand thumbnail.', 'ffl-hub'),
            'render_callback' => [self::class, 'render'],
            'supports'        => [
                'align' => ['wide', 'full'],
                'html'  => false,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        $term = get_queried_object();
        if (!$term instanceof WP_Term || $term->taxonomy !== 'product_brand') {
            return '';
        }

        $title = trim((string) $term->name);
        if ($title === '') {
            return '';
        }

        $description = trim((string) term_description((int) $term->term_id, 'product_brand'));
        $count = max(0, (int) $term->count);
        $thumbnail_id = self::brand_thumbnail_id($term);
        $image_url = $thumbnail_id > 0 ? (string) wp_get_attachment_image_url($thumbnail_id, 'full') : '';
        $image_alt = $thumbnail_id > 0 ? trim((string) get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true)) : '';
        if ($image_alt === '') {
            $image_alt = $title;
        }

        $has_image = $image_url !== '';
        $classes = [
            'fflhub-brand-hero',
            $has_image ? 'has-brand-image' : 'has-no-brand-image',
        ];

        $style = $has_image
            ? 'background-image:linear-gradient(90deg,rgba(0,0,0,.82),rgba(0,0,0,.56),rgba(0,0,0,.18)),url(' . esc_url($image_url) . ');'
            : '';

        ob_start();
        ?>
        <section class="<?php echo esc_attr(implode(' ', $classes)); ?>" style="<?php echo esc_attr($style); ?>">
            <?php if ($has_image) : ?>
                <img class="fflhub-brand-hero__image" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($image_alt); ?>" loading="eager" decoding="async" />
            <?php endif; ?>
            <div class="fflhub-brand-hero__inner">
                <h1 class="fflhub-brand-hero__title"><?php echo esc_html($title); ?></h1>
                <?php if ($description !== '') : ?>
                    <div class="fflhub-brand-hero__description">
                        <?php echo wp_kses_post($description); ?>
                    </div>
                <?php endif; ?>
                <div class="fflhub-brand-hero__meta">
                    <?php
                    printf(
                        esc_html(_n('%s product available', '%s products available', $count, 'ffl-hub')),
                        esc_html(number_format_i18n($count))
                    );
                    ?>
                </div>
            </div>
        </section>
        <?php
        return self::styles() . (string) ob_get_clean();
    }

    private static function brand_thumbnail_id(WP_Term $term): int
    {
        foreach (['thumbnail_id', 'brand_thumbnail_id', 'product_brand_thumbnail_id'] as $key) {
            $value = get_term_meta((int) $term->term_id, $key, true);
            $id = absint($value);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private static function styles(): string
    {
        return '<style>
.fflhub-brand-hero{position:relative;overflow:hidden;margin:0 0 var(--wp--preset--spacing--40,2rem);border-bottom:1px solid rgba(0,0,0,.08);background:#f3f5f7;color:#101214}
.fflhub-brand-hero.has-brand-image{min-height:320px;background-position:center;background-size:cover;color:#fff;border-bottom:0}
.fflhub-brand-hero__image{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}
.fflhub-brand-hero__inner{box-sizing:border-box;width:min(1400px,calc(100% - 40px));margin:0 auto;padding:clamp(38px,6vw,82px) 0}
.fflhub-brand-hero.has-brand-image .fflhub-brand-hero__inner{display:flex;min-height:320px;flex-direction:column;justify-content:center}
.fflhub-brand-hero__title{max-width:900px;margin:0;font-size:clamp(38px,5vw,72px);font-weight:800;line-height:1.02;letter-spacing:0;color:inherit}
.fflhub-brand-hero__description{max-width:860px;margin-top:18px;font-size:clamp(16px,1.4vw,20px);line-height:1.65;color:inherit}
.fflhub-brand-hero.has-no-brand-image .fflhub-brand-hero__description{color:#34383d}
.fflhub-brand-hero__description p{margin:0 0 1em}
.fflhub-brand-hero__description p:last-child{margin-bottom:0}
.fflhub-brand-hero__meta{display:inline-flex;width:max-content;margin-top:22px;padding:7px 11px;border:1px solid currentColor;border-radius:4px;font-size:13px;font-weight:700;line-height:1;color:inherit;opacity:.86}
@media (max-width: 720px){.fflhub-brand-hero__inner{width:min(100% - 28px,1400px);padding:34px 0}.fflhub-brand-hero.has-brand-image{min-height:280px}.fflhub-brand-hero.has-brand-image .fflhub-brand-hero__inner{min-height:280px}}
</style>';
    }
}
