<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks for sending over your quote request. I reviewed it and got your code ready.', 'ffl-hub') . "\n\n";
echo __('Drop this code in at checkout and it will apply your approved quote pricing.', 'ffl-hub') . "\n\n";
echo __('Your Quote Coupon:', 'ffl-hub') . ' ' . $context->coupon_code . "\n";
echo sprintf(__('Final product price: %s + shipping', 'ffl-hub'), $context->final_price_display) . "\n";
echo sprintf(__('Coupon value: %s', 'ffl-hub'), $context->coupon_amount_display) . "\n";
echo sprintf(__('Product: %s', 'ffl-hub'), $context->product_name) . "\n";
echo sprintf(__('Expires: %s (48 hours)', 'ffl-hub'), $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('This code is for you only, tied to your email only, valid on this product only, and can be used one time within 48 hours.', 'ffl-hub') . "\n\n";
echo __('If you have any questions at all, just hit reply and I can help.', 'ffl-hub') . "\n\n";
echo __('Talk soon,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
