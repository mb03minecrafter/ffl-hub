<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Email $email */
/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$email_heading = $email->get_heading();
$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

do_action('woocommerce_email_header', $email_heading, $email);
?>

<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Hi %s,', 'ffl-hub'), esc_html($first_name)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Thanks for your quote request. I looked it over and got this one set up for you.', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('For %1$s, your final quoted price is %2$s %3$s.', 'ffl-hub'), esc_html($context->product_name), esc_html($context->final_price_display), esc_html($context->shipping_phrase)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Product link:', 'ffl-hub'); ?> <?php echo esc_url($context->product_url); ?></p>
<p style="margin:0 0 10px;"><?php echo esc_html__('Your code:', 'ffl-hub'); ?> <?php echo esc_html($context->coupon_code); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Expires:', 'ffl-hub'); ?> <?php echo esc_html($context->expires_display); ?> <?php echo esc_html__('(48 hours)', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('This quote code is only for you, only for this item, and it works one time.', 'ffl-hub'); ?></p>
<p style="margin:0;"><?php echo esc_html__('If anything is unclear, reply to this email and I can walk you through it.', 'ffl-hub'); ?><br><br><?php echo esc_html__('Talk soon,', 'ffl-hub'); ?><br><?php echo esc_html($context->rep_name); ?><br><?php echo esc_html__('Sales Team, Bickham Firearms', 'ffl-hub'); ?></p>

<?php
do_action('woocommerce_email_footer', $email);
