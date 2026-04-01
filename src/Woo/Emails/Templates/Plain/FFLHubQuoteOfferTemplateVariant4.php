<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hey %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('I just wrapped up your quote request and wanted to send everything over.', 'ffl-hub') . "\n\n";
echo sprintf(__('Your quote price for %1$s is %2$s plus shipping. The savings amount approved for you is %3$s.', 'ffl-hub'), $context->product_name, $context->final_price_display, $context->coupon_amount_display) . "\n\n";
echo sprintf(__('At checkout, use code %1$s. It is worth %2$s and expires %3$s (48 hours).', 'ffl-hub'), $context->coupon_code, $context->coupon_amount_display, $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('The code is specific to your email, this product only, and can be redeemed once.', 'ffl-hub') . "\n\n";
echo __('If you want to confirm anything before checkout, reply back and I will help.', 'ffl-hub') . "\n\n";
echo __('Thank you,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
