<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thank you for reaching out for a custom price quote. We reviewed your request and prepared a private promo code for you.', 'ffl-hub') . "\n\n";
echo __('Use the one-time code below at checkout to apply your quote savings.', 'ffl-hub') . "\n\n";
echo __('One-Time Coupon Code:', 'ffl-hub') . ' ' . $context->coupon_code . "\n";
echo sprintf(__('Discount amount: %s', 'ffl-hub'), $context->coupon_amount_display) . "\n";
echo sprintf(__('Product: %s', 'ffl-hub'), $context->product_name) . "\n";
echo sprintf(__('Expires: %s (48 hours)', 'ffl-hub'), $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('This code is specific to your email address and can only be used one time.', 'ffl-hub') . "\n\n";
echo __('Thank you again for reaching out.', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";

