<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks for your quote request. I looked it over and got this one set up for you.', 'ffl-hub') . "\n\n";
echo sprintf(__('For %1$s, your final quoted price is %2$s plus shipping, and the discount amount is %3$s.', 'ffl-hub'), $context->product_name, $context->final_price_display, $context->coupon_amount_display) . "\n\n";
echo sprintf(__('Use coupon code %1$s at checkout. It applies %2$s and expires on %3$s (48 hours).', 'ffl-hub'), $context->coupon_code, $context->coupon_amount_display, $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('This quote code is only for you, only for this item, and it works one time.', 'ffl-hub') . "\n\n";
echo __('If anything is unclear, reply to this email and I can walk you through it.', 'ffl-hub') . "\n\n";
echo __('Talk soon,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
