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
<p style="margin:0 0 14px;"><?php echo esc_html__('I got your quote request handled and wanted to send over your pricing details right away.', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Your quote for %1$s comes out to %2$s plus shipping, with %3$s in approved savings.', 'ffl-hub'), esc_html($context->product_name), esc_html($context->final_price_display), esc_html($context->coupon_amount_display)); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Use %1$s at checkout. It applies %2$s and is valid until %3$s (48 hours).', 'ffl-hub'), esc_html($context->coupon_code), esc_html($context->coupon_amount_display), esc_html($context->expires_display)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Product link:', 'ffl-hub'); ?> <?php echo esc_url($context->product_url); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('The code is personal to your email, limited to this item, and can only be used once.', 'ffl-hub'); ?></p>
<p style="margin:0;"><?php echo esc_html__('Need anything else before you order? Just reply and I can help with whatever you need.', 'ffl-hub'); ?><br><br><?php echo esc_html__('Thanks,', 'ffl-hub'); ?><br><?php echo esc_html($context->rep_name); ?><br><?php echo esc_html__('Sales Team, Bickham Firearms', 'ffl-hub'); ?></p>

<?php
do_action('woocommerce_email_footer', $email);
