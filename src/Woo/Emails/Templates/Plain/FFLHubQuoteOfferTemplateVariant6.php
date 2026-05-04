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
echo __('I got your quote request handled and wanted to send over your pricing details right away.', 'ffl-hub') . "\n\n";
echo sprintf(__('Your quote for the %1$s comes out to %2$s %3$s.', 'ffl-hub'), $context->product_name, '**' . $context->final_price_display . '**', $shipping_phrase_text) . "\n\n";
if ($context->quote_cart_url !== '') {
    echo __('Add to cart with quoted price:', 'ffl-hub') . ' ' . $context->quote_cart_url . "\n\n";
}
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Your code:', 'ffl-hub') . ' ' . '**' . $context->coupon_code . '**' . "\n";
echo __('Expires:', 'ffl-hub') . ' ' . $context->expires_display . ' ' . __('(48 hours)', 'ffl-hub') . "\n\n";
echo __('The code is personal to your email, limited to this item, and can only be used once.', 'ffl-hub') . "\n\n";
echo __('Need anything else before you order? Just reply and I can help with whatever you need.', 'ffl-hub') . "\n\n";
echo __('Thanks,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
