<?php

namespace FFLHub\Woo\Emails;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps Woo's native fulfillment emails aligned with Deerford's checkout model.
 *
 * Woo's stock fulfillment email assumes customers can review orders through an
 * account portal. Deerford does not use customer accounts, so this final email
 * payload filter removes that guidance and personalizes the opening sentence.
 */
final class FulfillmentEmailContent
{
    public static function init(): void
    {
        add_filter('woocommerce_mail_callback_params', [self::class, 'filter_mail_callback_params'], 20, 2);
    }

    /**
     * @param array<int,mixed> $params wp_mail callback params: to, subject, message, headers, attachments.
     * @param mixed $email Woo email object.
     * @return array<int,mixed>
     */
    public static function filter_mail_callback_params(array $params, $email): array
    {
        if (!self::is_fulfillment_email($email)) {
            return $params;
        }

        if (isset($params[2]) && is_string($params[2])) {
            $params[2] = self::customize_content($params[2], self::customer_greeting_name($email));
        }

        return $params;
    }

    private static function is_fulfillment_email($email): bool
    {
        return is_object($email)
            && property_exists($email, 'id')
            && in_array(
                (string) $email->id,
                [
                    'customer_fulfillment_created',
                    'customer_fulfillment_updated',
                    'customer_fulfillment_deleted',
                ],
                true
            );
    }

    private static function customer_greeting_name($email): string
    {
        $order = is_object($email) && property_exists($email, 'object') ? $email->object : null;

        foreach (['get_billing_first_name', 'get_shipping_first_name'] as $method) {
            if (is_object($order) && method_exists($order, $method)) {
                $name = trim((string) $order->{$method}());
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return 'there';
    }

    private static function customize_content(string $content, string $customer_name): string
    {
        $content = self::replace_intro($content, $customer_name);
        return self::remove_account_orders_copy($content);
    }

    private static function replace_intro(string $content, string $customer_name): string
    {
        $html_intro = sprintf(
            'Hi %s, some items you purchased are being fulfilled. You can use the below information to track your shipment:',
            htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8')
        );

        $plain_intro = sprintf(
            'Hi %s, some items you purchased are being fulfilled. You can use the below information to track your shipment:',
            $customer_name
        );

        $content = preg_replace(
            '~(<p\b[^>]*>)\s*Woo!\s+Some items you purchased are being fulfilled\.\s+You can use the below information to track your shipment:\s*(</p>)~i',
            '$1' . $html_intro . '$2',
            $content
        ) ?? $content;

        $fallback_intro = str_contains($content, '<') ? $html_intro : $plain_intro;

        return preg_replace(
            '~Woo!\s+Some items you purchased are being fulfilled\.\s+You can use the below information to track your shipment:~i',
            $fallback_intro,
            $content
        ) ?? $content;
    }

    private static function remove_account_orders_copy(string $content): string
    {
        $content = preg_replace(
            '~<p\b[^>]*>\s*You can access(?:\s+to)?\s+more details of your order by visiting\b.*?My Account\s*&gt;\s*Orders.*?</p>\s*~is',
            '',
            $content
        ) ?? $content;

        return preg_replace(
            '~\s*You can access(?:\s+to)?\s+more details of your order by visiting\s+My Account\s*>\s*Orders.*?(?:\r?\n){1,3}~i',
            "\n\n",
            $content
        ) ?? $content;
    }
}
