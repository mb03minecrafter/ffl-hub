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
<p style="margin:0 0 14px;"><?php echo esc_html__('Thanks for reaching out for a quote. I reviewed your request and got pricing approved for you.', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('For %1$s, your final price is %2$s plus shipping, and your approved savings is %3$s.', 'ffl-hub'), esc_html($context->product_name), esc_html($context->final_price_display), esc_html($context->coupon_amount_display)); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Use code %1$s at checkout. The code value is %2$s and it expires on %3$s (48 hours).', 'ffl-hub'), esc_html($context->coupon_code), esc_html($context->coupon_amount_display), esc_html($context->expires_display)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Product link:', 'ffl-hub'); ?> <?php echo esc_url($context->product_url); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('This code is tied to your email, only works for this product, and can only be used one time.', 'ffl-hub'); ?></p>
<p style="margin:0;"><?php echo esc_html__('If you want me to double check anything before you place the order, just reply and I can help.', 'ffl-hub'); ?><br><br><?php echo esc_html__('Thanks again,', 'ffl-hub'); ?><br><?php echo esc_html($context->rep_name); ?><br><?php echo esc_html__('Sales Team, Bickham Firearms', 'ffl-hub'); ?></p>

<?php
do_action('woocommerce_email_footer', $email);
