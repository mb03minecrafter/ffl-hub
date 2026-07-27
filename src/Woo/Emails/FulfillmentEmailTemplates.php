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
        add_filter('woocommerce_mail_callback_params', [self::class, 'filter_mail_callback_params'], 20, 2);
    }

    public static function locate_template(string $template, string $template_name, string $template_path): string
    {
        if (!isset(self::$templates[$template_name])) {
            return $template;
        }

        $override = trailingslashit(FFLHUB_PLUGIN_PATH) . self::$templates[$template_name];
        return is_readable($override) ? $override : $template;
    }

    /**
     * Removes Woo's account-order guidance from rendered fulfillment emails.
     *
     * The template override above is the preferred path, but Woo's newer email
     * renderer can also produce fulfillment emails from rendered/block content.
     * This last-mile filter only touches Woo fulfillment email IDs, after Woo
     * has built and styled the message but before wp_mail() receives it.
     *
     * @param array<int,mixed> $params wp_mail callback params: to, subject, message, headers, attachments.
     * @param mixed $email Woo email object.
     * @return array<int,mixed>
     */
    public static function filter_mail_callback_params(array $params, $email): array
    {
        if (!is_object($email) || !property_exists($email, 'id')) {
            return $params;
        }

        if (!in_array((string) $email->id, self::fulfillment_email_ids(), true)) {
            return $params;
        }

        if (isset($params[2]) && is_string($params[2])) {
            $params[2] = self::remove_account_orders_copy($params[2]);
        }

        return $params;
    }

    /**
     * @return list<string>
     */
    private static function fulfillment_email_ids(): array
    {
        return [
            'customer_fulfillment_created',
            'customer_fulfillment_updated',
            'customer_fulfillment_deleted',
        ];
    }

    private static function remove_account_orders_copy(string $content): string
    {
        $content = preg_replace(
            '~<p\b[^>]*>\s*You can access(?:\s+to)?\s+more details of your order by visiting\b.*?My Account\s*&gt;\s*Orders.*?</p>\s*~is',
            '',
            $content
        ) ?? $content;

        $content = preg_replace(
            '~\s*You can access(?:\s+to)?\s+more details of your order by visiting\s+My Account\s*>\s*Orders.*?(?:\r?\n){1,3}~i',
            "\n\n",
            $content
        ) ?? $content;

        return preg_replace(
            '~\s*You can access(?:\s+to)?\s+more details of your order by visiting\s+\[My Account\s*>\s*Orders\]\([^)]+\).*?(?:\r?\n){1,3}~i',
            "\n\n",
            $content
        ) ?? $content;
    }
}
