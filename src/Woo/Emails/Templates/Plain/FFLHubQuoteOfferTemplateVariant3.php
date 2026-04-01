<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('We appreciate you reaching out for a custom quote. Your request has been reviewed by our sales team.', 'ffl-hub') . "\n\n";
echo __('Your customer-specific coupon code is below. Enter it at checkout for your approved quote discount.', 'ffl-hub') . "\n\n";
echo __('Your Quote Coupon:', 'ffl-hub') . ' ' . $context->coupon_code . "\n";
echo sprintf(__('Coupon value: %s', 'ffl-hub'), $context->coupon_amount_display) . "\n";
echo sprintf(__('Product: %s', 'ffl-hub'), $context->product_name) . "\n";
echo sprintf(__('Expiration: %s (48 hours)', 'ffl-hub'), $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('This code is valid for one use only and is restricted to your email address.', 'ffl-hub') . "\n\n";
echo __('Thank you for contacting us.', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";

