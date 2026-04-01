<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks for checking in with us on pricing. I took a look at your quote request and got this ready for you.', 'ffl-hub') . "\n\n";
echo __('Your personalized promo code is active now and ready to use at checkout.', 'ffl-hub') . "\n\n";
echo __('Private Promo Code:', 'ffl-hub') . ' ' . $context->coupon_code . "\n";
echo sprintf(__('Final product price: %s + shipping', 'ffl-hub'), $context->final_price_display) . "\n";
echo sprintf(__('Savings from code: %s', 'ffl-hub'), $context->coupon_amount_display) . "\n";
echo sprintf(__('Quoted product: %s', 'ffl-hub'), $context->product_name) . "\n";
echo sprintf(__('Valid until: %s (48 hours)', 'ffl-hub'), $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Quick heads up: this code is only for your email, only for this product, and one-time use only. It expires in 48 hours.', 'ffl-hub') . "\n\n";
echo __('Reply here if you want help with checkout or shipping options.', 'ffl-hub') . "\n\n";
echo __('Appreciate you,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
