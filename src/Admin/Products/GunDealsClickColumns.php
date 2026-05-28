<?php

namespace FFLHub\Admin\Products;

use FFLHub\Feeds\GunDeals\GunDealsClickTracker;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsClickColumns
{
    private const COL_GUNDEALS_CLICKS = 'fflhub_gundeals_clicks';

    public function register(): void
    {
        add_filter('manage_edit-product_columns', [$this, 'inject_columns'], 30);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 30, 2);
        add_action('admin_head', [$this, 'render_admin_styles']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function inject_columns(array $columns): array
    {
        if (isset($columns[self::COL_GUNDEALS_CLICKS])) {
            return $columns;
        }

        $new_columns = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;

            if ($key === 'price') {
                $new_columns[self::COL_GUNDEALS_CLICKS] = __('Gun.deals Clicks', 'ffl-hub');
                $inserted = true;
            }
        }

        if (!$inserted) {
            $new_columns[self::COL_GUNDEALS_CLICKS] = __('Gun.deals Clicks', 'ffl-hub');
        }

        return $new_columns;
    }

    public function render_column(string $column_name, int $post_id): void
    {
        if ($column_name !== self::COL_GUNDEALS_CLICKS || $post_id <= 0) {
            return;
        }

        $raw = max(0, (int) get_post_meta($post_id, GunDealsClickTracker::META_RAW_CLICKS, true));
        $deduped = max(0, (int) get_post_meta($post_id, GunDealsClickTracker::META_DEDUPED_CLICKS, true));
        $last_click = trim((string) get_post_meta($post_id, GunDealsClickTracker::META_LAST_CLICK_AT, true));

        if ($raw <= 0 && $deduped <= 0) {
            echo '<span class="fflhub-product-empty">&mdash;</span>';
            return;
        }

        echo '<span class="fflhub-product-pill is-gundeals-clicks" title="'
            . esc_attr__('Raw clicks / deduped clicks in a 30 minute window.', 'ffl-hub')
            . '">'
            . esc_html((string) $raw)
            . ' / '
            . esc_html((string) $deduped)
            . '</span>';

        if ($last_click !== '') {
            echo '<br><span class="fflhub-gundeals-last-click">'
                . esc_html__('Last:', 'ffl-hub')
                . ' '
                . esc_html($last_click)
                . ' UTC</span>';
        }
    }

    public function render_admin_styles(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->id) || (string) $screen->id !== 'edit-product') {
            return;
        }

        echo '<style>
            .column-' . self::COL_GUNDEALS_CLICKS . ' { width: 145px; }
            .fflhub-product-pill.is-gundeals-clicks {
                color: #3b2f00;
                border-color: #e6ca76;
                background: #fff8db;
            }
            .fflhub-gundeals-last-click {
                display: inline-block;
                margin-top: 4px;
                color: #646970;
                font-size: 11px;
                line-height: 1.3;
            }
        </style>';
    }
}
