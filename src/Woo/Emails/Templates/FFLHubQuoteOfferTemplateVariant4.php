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

<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Hey %s,', 'ffl-hub'), esc_html($first_name)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('I just wrapped up your quote request and wanted to send everything over.', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Your quote price for %1$s is %2$s %3$s.', 'ffl-hub'), esc_html($context->product_name), esc_html($context->final_price_display), esc_html($context->shipping_phrase)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Product link:', 'ffl-hub'); ?> <?php echo esc_url($context->product_url); ?></p>
<p style="margin:0 0 10px;"><?php echo esc_html__('Your code:', 'ffl-hub'); ?> <?php echo esc_html($context->coupon_code); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Expires:', 'ffl-hub'); ?> <?php echo esc_html($context->expires_display); ?> <?php echo esc_html__('(48 hours)', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('The code is specific to your email, this product only, and can be redeemed once.', 'ffl-hub'); ?></p>
<p style="margin:0;"><?php echo esc_html__('If you want to confirm anything before checkout, reply back and I will help.', 'ffl-hub'); ?><br><br><?php echo esc_html__('Thank you,', 'ffl-hub'); ?><br><?php echo esc_html($context->rep_name); ?><br><?php echo esc_html__('Sales Team, Bickham Firearms', 'ffl-hub'); ?></p>

<?php
do_action('woocommerce_email_footer', $email);
