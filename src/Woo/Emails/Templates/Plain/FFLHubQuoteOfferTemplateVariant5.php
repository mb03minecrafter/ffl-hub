<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks again for reaching out. I reviewed your request and got your quote finalized.', 'ffl-hub') . "\n\n";
echo sprintf(__('For %1$s, your final checkout price is %2$s %3$s.', 'ffl-hub'), $context->product_name, $context->final_price_display, $context->shipping_phrase) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Your code:', 'ffl-hub') . ' ' . $context->coupon_code . "\n";
echo __('Expires:', 'ffl-hub') . ' ' . $context->expires_display . ' ' . __('(48 hours)', 'ffl-hub') . "\n\n";
echo __('Just note this is tied to your email, valid for this product only, and good for one use.', 'ffl-hub') . "\n\n";
echo __('If anything else would help, reply here and I will get right back to you.', 'ffl-hub') . "\n\n";
echo __('Best,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
