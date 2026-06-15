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
    private const HERO_IMAGE_META_KEY = 'fflhub_archive_hero_image_id';

    public static function init(): void
    {
        add_action('init', [self::class, 'register']);
        add_action('product_tag_add_form_fields', [self::class, 'render_product_tag_add_field']);
        add_action('product_tag_edit_form_fields', [self::class, 'render_product_tag_edit_field']);
        add_action('created_product_tag', [self::class, 'save_product_tag_hero_image']);
        add_action('edited_product_tag', [self::class, 'save_product_tag_hero_image']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
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
            'description'     => __('Displays the current WooCommerce product brand or tag title, description, product count, and optional archive hero image.', 'ffl-hub'),
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
        if (!$term instanceof WP_Term || !in_array($term->taxonomy, ['product_brand', 'product_tag'], true)) {
            return '';
        }

        $title = trim((string) $term->name);
        if ($title === '') {
            return '';
        }

        $description = trim((string) term_description((int) $term->term_id, $term->taxonomy));
        $count = max(0, (int) $term->count);
        $thumbnail_id = self::archive_thumbnail_id($term);
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

    private static function archive_thumbnail_id(WP_Term $term): int
    {
        foreach ([self::HERO_IMAGE_META_KEY, 'thumbnail_id', 'brand_thumbnail_id', 'product_brand_thumbnail_id'] as $key) {
            $value = get_term_meta((int) $term->term_id, $key, true);
            $id = absint($value);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    public static function render_product_tag_add_field(string $taxonomy): void
    {
        if ($taxonomy !== 'product_tag' || !self::can_manage_product_tags()) {
            return;
        }

        wp_nonce_field('fflhub_product_tag_hero_image', 'fflhub_product_tag_hero_image_nonce');
        ?>
        <div class="form-field term-fflhub-archive-hero-image-wrap">
            <label for="fflhub_archive_hero_image_id"><?php echo esc_html__('Archive hero image', 'ffl-hub'); ?></label>
            <input type="hidden" id="fflhub_archive_hero_image_id" name="fflhub_archive_hero_image_id" value="" />
            <div class="fflhub-tax-hero-image-preview"></div>
            <button type="button" class="button fflhub-tax-hero-image-upload"><?php echo esc_html__('Select image', 'ffl-hub'); ?></button>
            <button type="button" class="button fflhub-tax-hero-image-remove"><?php echo esc_html__('Remove image', 'ffl-hub'); ?></button>
            <p><?php echo esc_html__('Optional image used by the product tag archive hero template.', 'ffl-hub'); ?></p>
        </div>
        <?php
    }

    public static function render_product_tag_edit_field(WP_Term $term): void
    {
        if ($term->taxonomy !== 'product_tag' || !self::can_manage_product_tags()) {
            return;
        }

        $image_id = absint(get_term_meta((int) $term->term_id, self::HERO_IMAGE_META_KEY, true));
        $image_url = $image_id > 0 ? (string) wp_get_attachment_image_url($image_id, 'medium') : '';

        wp_nonce_field('fflhub_product_tag_hero_image', 'fflhub_product_tag_hero_image_nonce');
        ?>
        <tr class="form-field term-fflhub-archive-hero-image-wrap">
            <th scope="row">
                <label for="fflhub_archive_hero_image_id"><?php echo esc_html__('Archive hero image', 'ffl-hub'); ?></label>
            </th>
            <td>
                <input type="hidden" id="fflhub_archive_hero_image_id" name="fflhub_archive_hero_image_id" value="<?php echo esc_attr((string) $image_id); ?>" />
                <div class="fflhub-tax-hero-image-preview">
                    <?php if ($image_url !== '') : ?>
                        <img src="<?php echo esc_url($image_url); ?>" alt="" />
                    <?php endif; ?>
                </div>
                <button type="button" class="button fflhub-tax-hero-image-upload"><?php echo esc_html__('Select image', 'ffl-hub'); ?></button>
                <button type="button" class="button fflhub-tax-hero-image-remove"><?php echo esc_html__('Remove image', 'ffl-hub'); ?></button>
                <p class="description"><?php echo esc_html__('Optional image used by the product tag archive hero template.', 'ffl-hub'); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function save_product_tag_hero_image(int $term_id): void
    {
        if (!self::can_manage_product_tags()) {
            return;
        }

        $nonce = isset($_POST['fflhub_product_tag_hero_image_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_product_tag_hero_image_nonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'fflhub_product_tag_hero_image')) {
            return;
        }

        $image_id = isset($_POST['fflhub_archive_hero_image_id'])
            ? absint(wp_unslash((string) $_POST['fflhub_archive_hero_image_id']))
            : 0;

        if ($image_id > 0) {
            update_term_meta($term_id, self::HERO_IMAGE_META_KEY, $image_id);
        } else {
            delete_term_meta($term_id, self::HERO_IMAGE_META_KEY);
        }
    }

    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        if (!in_array($hook_suffix, ['edit-tags.php', 'term.php'], true)) {
            return;
        }

        $taxonomy = isset($_GET['taxonomy']) ? sanitize_key((string) wp_unslash($_GET['taxonomy'])) : '';
        if ($taxonomy !== 'product_tag' || !self::can_manage_product_tags()) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', self::admin_script());
        wp_register_style('fflhub-product-tag-hero-admin', false, [], FFLHUB_PLUGIN_VERSION);
        wp_enqueue_style('fflhub-product-tag-hero-admin');
        wp_add_inline_style('fflhub-product-tag-hero-admin', self::admin_styles());
    }

    private static function can_manage_product_tags(): bool
    {
        $taxonomy = get_taxonomy('product_tag');
        $cap = $taxonomy && isset($taxonomy->cap->edit_terms)
            ? (string) $taxonomy->cap->edit_terms
            : 'manage_product_terms';

        return current_user_can($cap);
    }

    private static function admin_script(): string
    {
        return <<<'JS'
jQuery(function($) {
    var frame;
    $('.fflhub-tax-hero-image-upload').on('click', function(e) {
        e.preventDefault();
        var $wrap = $(this).closest('.term-fflhub-archive-hero-image-wrap');
        if (frame) {
            frame.open();
            frame.off('select');
        } else {
            frame = wp.media({
                title: 'Select archive hero image',
                button: { text: 'Use this image' },
                multiple: false
            });
        }
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $wrap.find('#fflhub_archive_hero_image_id').val(attachment.id || '');
            $wrap.find('.fflhub-tax-hero-image-preview').html('<img src="' + (attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url) + '" alt="" />');
        });
        frame.open();
    });
    $('.fflhub-tax-hero-image-remove').on('click', function(e) {
        e.preventDefault();
        var $wrap = $(this).closest('.term-fflhub-archive-hero-image-wrap');
        $wrap.find('#fflhub_archive_hero_image_id').val('');
        $wrap.find('.fflhub-tax-hero-image-preview').empty();
    });
});
JS;
    }

    private static function admin_styles(): string
    {
        return '.fflhub-tax-hero-image-preview{margin:8px 0 10px}.fflhub-tax-hero-image-preview img{display:block;max-width:220px;height:auto;border:1px solid #ccd0d4;background:#fff}.fflhub-tax-hero-image-remove{margin-left:6px}';
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
