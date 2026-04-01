<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks for reaching out about pricing on this item. I reviewed your request and set up a private quote code for you.', 'ffl-hub') . "\n\n";
echo __('Use the code below at checkout and it will apply your approved quote savings.', 'ffl-hub') . "\n\n";
echo __('One-Time Coupon Code:', 'ffl-hub') . ' ' . $context->coupon_code . "\n";
echo sprintf(__('Your final product price: %s + shipping', 'ffl-hub'), $context->final_price_display) . "\n";
echo sprintf(__('Code value: %s', 'ffl-hub'), $context->coupon_amount_display) . "\n";
echo sprintf(__('Product: %s', 'ffl-hub'), $context->product_name) . "\n";
echo sprintf(__('Code expires: %s (48 hours)', 'ffl-hub'), $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Important: this code is only for you, only for this product, only for your email address, and it can be used one time.', 'ffl-hub') . "\n\n";
echo __('If you want me to double check anything before you place the order, just reply to this email.', 'ffl-hub') . "\n\n";
echo __('Thanks again,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
