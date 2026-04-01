<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

echo sprintf(__('Hi %s,', 'ffl-hub'), $first_name) . "\n\n";
echo __('Thanks for contacting us about pricing. We appreciate the chance to earn your business.', 'ffl-hub') . "\n\n";
echo __('Your quote request was reviewed, and your personalized one-time promo code is active now.', 'ffl-hub') . "\n\n";
echo __('Private Promo Code:', 'ffl-hub') . ' ' . $context->coupon_code . "\n";
echo sprintf(__('Approved savings: %s', 'ffl-hub'), $context->coupon_amount_display) . "\n";
echo sprintf(__('Quoted product: %s', 'ffl-hub'), $context->product_name) . "\n";
echo sprintf(__('Valid until: %s (48 hours)', 'ffl-hub'), $context->expires_display) . "\n\n";
echo __('Product link:', 'ffl-hub') . ' ' . $context->product_url . "\n\n";
echo __('This offer is tied to your email and may only be redeemed once.', 'ffl-hub') . "\n\n";
echo __('Thank you for reaching out to our team.', 'ffl-hub') . "\n";
echo $context->rep_name . "\n";
echo __('Sales Team, Bickham Firearms', 'ffl-hub') . "\n";

