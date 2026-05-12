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
echo __('Appreciate you sending in a quote request. I just finished reviewing it for you.', 'ffl-hub') . "\n\n";
echo sprintf(__('Your quoted price for the %1$s is %2$s %3$s.', 'ffl-hub'), $context->product_name, '**' . $context->final_price_display . '**', $shipping_phrase_text) . "\n\n";
if ($context->quote_cart_url !== '') {
    echo __('Add to cart with quoted price:', 'ffl-hub') . ' ' . $context->quote_cart_url . "\n\n";
}
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Your code:', 'ffl-hub') . ' ' . '**' . $context->coupon_code . '**' . "\n";
echo __('Expires:', 'ffl-hub') . ' ' . $context->expires_display . ' ' . __('(48 hours)', 'ffl-hub') . "\n\n";
echo __('Quick heads up: this code is for your email only, this product only, and one-time use only.', 'ffl-hub') . "\n\n";
echo __('If you have any questions at all, reply here and I will take care of you.', 'ffl-hub') . "\n\n";
echo __('Appreciate you,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo $context->team_signature . "\n";
