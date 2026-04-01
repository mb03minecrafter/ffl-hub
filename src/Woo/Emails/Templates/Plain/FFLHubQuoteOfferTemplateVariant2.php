<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Appreciate you sending in a quote request. I just finished reviewing it for you.', 'ffl-hub') . "\n\n";
echo sprintf(__('Your quoted price for %1$s is %2$s plus shipping, which includes %3$s in savings.', 'ffl-hub'), $context->product_name, $context->final_price_display, $context->coupon_amount_display) . "\n\n";
echo sprintf(__('When you are ready, enter code %1$s at checkout. It is worth %2$s and is valid through %3$s (48 hours).', 'ffl-hub'), $context->coupon_code, $context->coupon_amount_display, $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Quick heads up: this code is for your email only, this product only, and one-time use only.', 'ffl-hub') . "\n\n";
echo __('If you have any questions at all, reply here and I will take care of you.', 'ffl-hub') . "\n\n";
echo __('Appreciate you,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
