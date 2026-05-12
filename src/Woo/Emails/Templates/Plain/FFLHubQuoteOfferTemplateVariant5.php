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
echo __('Thanks again for reaching out. I reviewed your request and got your quote finalized.', 'ffl-hub') . "\n\n";
echo sprintf(__('For the %1$s, your final checkout price is %2$s %3$s.', 'ffl-hub'), $context->product_name, '**' . $context->final_price_display . '**', $shipping_phrase_text) . "\n\n";
if ($context->quote_cart_url !== '') {
    echo __('Add to cart with quoted price:', 'ffl-hub') . ' ' . $context->quote_cart_url . "\n\n";
}
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Your code:', 'ffl-hub') . ' ' . '**' . $context->coupon_code . '**' . "\n";
echo __('Expires:', 'ffl-hub') . ' ' . $context->expires_display . ' ' . __('(48 hours)', 'ffl-hub') . "\n\n";
echo __('Just note this is tied to your email, valid for this product only, and good for one use.', 'ffl-hub') . "\n\n";
echo __('If anything else would help, reply here and I will get right back to you.', 'ffl-hub') . "\n\n";
echo __('Best,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo $context->team_signature . "\n";
