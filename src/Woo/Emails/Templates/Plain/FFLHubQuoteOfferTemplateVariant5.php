<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks again for reaching out. I reviewed your request and got your quote finalized.', 'ffl-hub') . "\n\n";
echo sprintf(__('For %1$s, your final checkout price is %2$s plus shipping. Your approved discount amount is %3$s.', 'ffl-hub'), $context->product_name, $context->final_price_display, $context->coupon_amount_display) . "\n\n";
echo sprintf(__('Use code %1$s when you checkout. The code value is %2$s and it expires on %3$s (48 hours).', 'ffl-hub'), $context->coupon_code, $context->coupon_amount_display, $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('Just note this is tied to your email, valid for this product only, and good for one use.', 'ffl-hub') . "\n\n";
echo __('If anything else would help, reply here and I will get right back to you.', 'ffl-hub') . "\n\n";
echo __('Best,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
