<?php

declare(strict_types=1);

namespace FFLHub\Admin;

use FFLHub\Admin\Pages\AdminPage;
use FFLHub\Admin\Pages\DistributorOrderingAdminPage;
use FFLHub\Admin\Pages\ShippingAdminPage;
use FFLHub\Admin\Pages\WMSAdminPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps FFL Hub top-level admin menus grouped together.
 *
 * WooCommerce and Woo extensions inject top-level menus around the same numeric
 * position range as FFL Hub. Numeric positions alone can still allow Analytics
 * or Marketing to land between our menus, so this final menu-order pass keeps
 * the FFL Hub parent pages adjacent without changing the rest of wp-admin.
 */
final class AdminMenuOrder
{
    public static function init(): void
    {
        add_filter('custom_menu_order', [self::class, 'enable_custom_menu_order']);
        add_filter('menu_order', [self::class, 'order_menu_slugs'], 100);
    }

    public static function enable_custom_menu_order(bool $enabled): bool
    {
        return true;
    }

    /**
     * @param array<int,string> $menu_order
     * @return array<int,string>
     */
    public static function order_menu_slugs(array $menu_order): array
    {
        $fflhub_slugs = [
            AdminPage::get_page_slug(),
            DistributorOrderingAdminPage::MENU_SLUG,
            ShippingAdminPage::MENU_SLUG,
            WMSAdminPage::MENU_SLUG,
        ];

        $available_fflhub_slugs = array_values(array_filter(
            $fflhub_slugs,
            static fn(string $slug): bool => in_array($slug, $menu_order, true)
        ));

        if (empty($available_fflhub_slugs)) {
            return $menu_order;
        }

        $ordered = [];
        $inserted_fflhub_block = false;

        foreach ($menu_order as $slug) {
            if (in_array($slug, $fflhub_slugs, true)) {
                if (!$inserted_fflhub_block) {
                    array_push($ordered, ...$available_fflhub_slugs);
                    $inserted_fflhub_block = true;
                }

                continue;
            }

            $ordered[] = $slug;
        }

        return $ordered;
    }
}
