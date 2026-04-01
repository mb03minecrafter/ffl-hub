<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Email $email */
/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$brand_text = '#000000';
$brand_muted = '#2B2E33';
$brand_border = 'rgba(0,0,0,.20)';
$brand_accent = '#C9A24D';
$brand_accent_text = '#000000';

$email_heading = $email->get_heading();
$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');

do_action('woocommerce_email_header', $email_heading, $email);
?>

<p style="margin:0 0 14px;">
    <?php echo sprintf(esc_html__('Hi %s,', 'ffl-hub'), esc_html($first_name)); ?>
</p>

<p style="margin:0 0 14px;">
    <?php echo esc_html__('Thanks for sending over your quote request. I reviewed it and got your code ready.', 'ffl-hub'); ?>
</p>

<p style="margin:0 0 14px;">
    <?php echo esc_html__('Drop this code in at checkout and it will apply your approved quote pricing.', 'ffl-hub'); ?>
</p>

<div style="border:1px solid <?php echo esc_attr($brand_border); ?>;border-radius:8px;padding:18px;background:#ffffff;margin:18px 0;">
    <p style="margin:0 0 8px;font-size:13px;letter-spacing:.04em;text-transform:uppercase;color:<?php echo esc_attr($brand_muted); ?>;">
        <?php echo esc_html__('Your Quote Coupon', 'ffl-hub'); ?>
    </p>
    <p style="margin:0 0 14px;font-size:30px;font-weight:700;color:<?php echo esc_attr($brand_text); ?>;letter-spacing:1px;">
        <?php echo esc_html($context->coupon_code); ?>
    </p>
    <p style="margin:0 0 8px;">
        <?php echo sprintf(esc_html__('Final product price: %s + shipping', 'ffl-hub'), esc_html($context->final_price_display)); ?>
    </p>
    <p style="margin:0 0 8px;">
        <?php echo sprintf(esc_html__('Coupon value: %s', 'ffl-hub'), esc_html($context->coupon_amount_display)); ?>
    </p>
    <p style="margin:0 0 8px;">
        <?php echo sprintf(esc_html__('Product: %s', 'ffl-hub'), esc_html($context->product_name)); ?>
    </p>
    <p style="margin:0;">
        <?php echo sprintf(esc_html__('Expires: %s (48 hours)', 'ffl-hub'), esc_html($context->expires_display)); ?>
    </p>
</div>

<p style="margin:0 0 16px;">
    <a href="<?php echo esc_url($context->product_url); ?>"
       style="display:inline-block;padding:12px 18px;border-radius:4px;background:<?php echo esc_attr($brand_accent); ?>;color:<?php echo esc_attr($brand_accent_text); ?>;text-decoration:none;font-weight:600;">
        <?php echo esc_html__('View This Product', 'ffl-hub'); ?>
    </a>
</p>

<p style="margin:0 0 10px;font-size:13px;color:<?php echo esc_attr($brand_muted); ?>;">
    <?php echo esc_html__('This code is for you only, tied to your email only, valid on this product only, and can be used one time within 48 hours.', 'ffl-hub'); ?>
</p>

<p style="margin:0;">
    <?php echo esc_html__('If you have any questions at all, just hit reply and I can help.', 'ffl-hub'); ?><br><br>
    <?php echo esc_html__('Talk soon,', 'ffl-hub'); ?><br>
    <?php echo esc_html($context->rep_name); ?><br>
    <?php echo esc_html__('Sales Team, Bickham Firearms', 'ffl-hub'); ?>
</p>

<?php
do_action('woocommerce_email_footer', $email);
