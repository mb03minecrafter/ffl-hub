<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');
$shipping_phrase_text = $context->shipping_phrase;
if (stripos($shipping_phrase_text, 'free shipping') !== false) {
    $shipping_phrase_text = '**' . $shipping_phrase_text . '**';
}

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks for your quote request. I looked it over and got this one set up for you.', 'ffl-hub') . "\n\n";
echo sprintf(__('For the %1$s, your final quoted price is %2$s %3$s.', 'ffl-hub'), $context->product_name, '**' . $context->final_price_display . '**', $shipping_phrase_text) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Your code:', 'ffl-hub') . ' ' . '**' . $context->coupon_code . '**' . "\n";
echo __('Expires:', 'ffl-hub') . ' ' . $context->expires_display . ' ' . __('(48 hours)', 'ffl-hub') . "\n\n";
echo __('This quote code is only for you, only for this item, and it works one time.', 'ffl-hub') . "\n\n";
echo __('If anything is unclear, reply to this email and I can walk you through it.', 'ffl-hub') . "\n\n";
echo __('Talk soon,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
