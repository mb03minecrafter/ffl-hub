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

echo sprintf(__('Hey %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('I just wrapped up your quote request and wanted to send everything over.', 'ffl-hub') . "\n\n";
echo sprintf(__('Your quote price for the %1$s is %2$s %3$s.', 'ffl-hub'), $context->product_name, '**' . $context->final_price_display . '**', $shipping_phrase_text) . "\n\n";
if ($context->quote_cart_url !== '') {
    echo __('Add to cart with quoted price:', 'ffl-hub') . ' ' . $context->quote_cart_url . "\n\n";
}
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Your code:', 'ffl-hub') . ' ' . '**' . $context->coupon_code . '**' . "\n";
echo __('Expires:', 'ffl-hub') . ' ' . $context->expires_display . ' ' . __('(48 hours)', 'ffl-hub') . "\n\n";
echo __('The code is specific to your email, this product only, and can be redeemed once.', 'ffl-hub') . "\n\n";
echo __('If you want to confirm anything before checkout, reply back and I will help.', 'ffl-hub') . "\n\n";
echo __('Thank you,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
