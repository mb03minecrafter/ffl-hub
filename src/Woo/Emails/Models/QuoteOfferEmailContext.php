<?php

namespace FFLHub\Woo\Emails\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable payload used to render quote offer emails.
 */
final class QuoteOfferEmailContext
{
    public string $recipient_email;
    public string $subject;
    public int $variant_index;
    public string $first_name;
    public string $rep_name;
    public string $product_name;
    public string $product_url;
    public string $quote_cart_url;
    public string $coupon_code;
    public string $coupon_amount_display;
    public string $final_price_display;
    public string $shipping_phrase;
    public string $expires_display;
    public bool $force_plain_text;
    public string $team_signature;

    public function __construct(
        string $recipient_email,
        string $subject,
        int $variant_index,
        string $first_name,
        string $rep_name,
        string $product_name,
        string $product_url,
        string $quote_cart_url,
        string $coupon_code,
        string $coupon_amount_display,
        string $final_price_display,
        string $shipping_phrase,
        string $expires_display,
        bool $force_plain_text = false,
        string $team_signature = ''
    ) {
        $this->recipient_email = trim($recipient_email);
        $this->subject = trim($subject);
        $this->variant_index = ($variant_index >= 0 && $variant_index <= 5) ? $variant_index : 0;
        $this->first_name = self::normalize_first_name_for_display($first_name);
        $this->rep_name = trim($rep_name);
        $this->product_name = trim($product_name);
        $this->product_url = trim($product_url);
        $this->quote_cart_url = trim($quote_cart_url);
        $this->coupon_code = trim($coupon_code);
        $this->coupon_amount_display = trim($coupon_amount_display);
        $this->final_price_display = trim($final_price_display);
        $this->shipping_phrase = trim($shipping_phrase);
        $this->expires_display = trim($expires_display);
        $this->force_plain_text = $force_plain_text;
        $this->team_signature = self::normalize_team_signature_for_display($team_signature);
    }

    private static function normalize_first_name_for_display(string $first_name): string
    {
        $first_name = trim($first_name);
        if ($first_name === '') {
            return '';
        }

        $lower = strtolower($first_name);
        $normalized = preg_replace_callback(
            "/(^|[\\s\\-'])([a-z])/i",
            static function (array $matches): string {
                $prefix = isset($matches[1]) ? (string) $matches[1] : '';
                $letter = isset($matches[2]) ? (string) $matches[2] : '';
                return $prefix . strtoupper($letter);
            },
            $lower
        );

        if (!is_string($normalized) || $normalized === '') {
            return $first_name;
        }

        return $normalized;
    }

    private static function normalize_team_signature_for_display(string $team_signature): string
    {
        $team_signature = trim($team_signature);
        if ($team_signature !== '') {
            return $team_signature;
        }

        $site_name = function_exists('get_bloginfo') ? trim((string) get_bloginfo('name')) : '';
        if ($site_name !== '') {
            return 'Sales Team, ' . $site_name;
        }

        return 'Sales Team';
    }
}
