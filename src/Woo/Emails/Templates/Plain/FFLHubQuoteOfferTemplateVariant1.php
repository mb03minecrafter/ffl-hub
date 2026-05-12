<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$product_name = $context->product_name !== '' ? $context->product_name : __('requested product', 'ffl-hub');

echo __('Product:', 'ffl-hub') . ' ' . $product_name . "\n";
if ($context->product_url !== '') {
    echo $context->product_url . "\n";
}

echo "\n" . __('Coupon code:', 'ffl-hub') . ' ' . $context->coupon_code . "\n\n";

if ($context->quote_cart_url !== '') {
    echo __('Add to cart with quoted price:', 'ffl-hub') . ' ' . $context->quote_cart_url . "\n";
}
