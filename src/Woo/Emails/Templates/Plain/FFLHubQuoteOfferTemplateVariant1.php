<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks for reaching out for a quote. I reviewed your request and got pricing approved for you.', 'ffl-hub') . "\n\n";
echo sprintf(__('For %1$s, your final price is %2$s plus shipping, and your approved savings is %3$s.', 'ffl-hub'), $context->product_name, $context->final_price_display, $context->coupon_amount_display) . "\n\n";
echo sprintf(__('Use code %1$s at checkout. The code value is %2$s and it expires on %3$s (48 hours).', 'ffl-hub'), $context->coupon_code, $context->coupon_amount_display, $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('This code is tied to your email, only works for this product, and can only be used one time.', 'ffl-hub') . "\n\n";
echo __('If you want me to double check anything before you place the order, just reply and I can help.', 'ffl-hub') . "\n\n";
echo __('Thanks again,', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";
