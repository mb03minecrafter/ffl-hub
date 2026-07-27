<?php

namespace FFLHub\Woo\Emails;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Routes selected WooCommerce fulfillment email templates through FFL Hub.
 *
 * Woo's stock fulfillment details template sends customers to an account
 * orders page. Deerford intentionally avoids customer accounts, so we override
 * only the fulfillment details templates and leave the surrounding Woo email
 * shell, tracking fields, item table, and product images untouched.
 */
final class FulfillmentEmailTemplates
{
    /**
     * @var array<string,string>
     */
    private static array $templates = [
        'emails/email-fulfillment-details.php' => 'src/Woo/Emails/Templates/WooCommerce/emails/email-fulfillment-details.php',
        'emails/plain/email-fulfillment-details.php' => 'src/Woo/Emails/Templates/WooCommerce/emails/plain/email-fulfillment-details.php',
    ];

    public static function init(): void
    {
        add_filter('woocommerce_locate_template', [self::class, 'locate_template'], 20, 3);
    }

    public static function locate_template(string $template, string $template_name, string $template_path): string
    {
        if (!isset(self::$templates[$template_name])) {
            return $template;
        }

        $override = trailingslashit(FFLHUB_PLUGIN_PATH) . self::$templates[$template_name];
        return is_readable($override) ? $override : $template;
    }
}
