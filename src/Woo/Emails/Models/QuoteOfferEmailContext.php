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
    public string $coupon_code;
    public string $coupon_amount_display;
    public string $expires_display;

    public function __construct(
        string $recipient_email,
        string $subject,
        int $variant_index,
        string $first_name,
        string $rep_name,
        string $product_name,
        string $product_url,
        string $coupon_code,
        string $coupon_amount_display,
        string $expires_display
    ) {
        $this->recipient_email = trim($recipient_email);
        $this->subject = trim($subject);
        $this->variant_index = ($variant_index >= 0 && $variant_index <= 2) ? $variant_index : 0;
        $this->first_name = trim($first_name);
        $this->rep_name = trim($rep_name);
        $this->product_name = trim($product_name);
        $this->product_url = trim($product_url);
        $this->coupon_code = trim($coupon_code);
        $this->coupon_amount_display = trim($coupon_amount_display);
        $this->expires_display = trim($expires_display);
    }
}

