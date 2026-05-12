<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Email $email */
/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$email_heading = $email->get_heading();
$first_name = ($context->first_name !== '') ? $context->first_name : __('there', 'ffl-hub');
$shipping_phrase_markup = esc_html($context->shipping_phrase);
if (stripos($context->shipping_phrase, 'free shipping') !== false) {
    $shipping_phrase_markup = '<strong>' . $shipping_phrase_markup . '</strong>';
}

do_action('woocommerce_email_header', $email_heading, $email);
?>

<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Hey %s,', 'ffl-hub'), esc_html($first_name)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('I just wrapped up your quote request and wanted to send everything over.', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(wp_kses_post(__('Your quote price for the %1$s is <strong>%2$s</strong> %3$s.', 'ffl-hub')), esc_html($context->product_name), esc_html($context->final_price_display), $shipping_phrase_markup); ?></p>
<?php if ($context->quote_cart_url !== '') : ?>
<p style="margin:0 0 14px;text-align:center;"><a href="<?php echo esc_url($context->quote_cart_url); ?>" style="display:inline-block;padding:10px 16px;background:#c9a24d;color:#000000;text-decoration:none;border:0;border-radius:4px;font-weight:700;"><?php echo esc_html__('Add to cart with quoted price', 'ffl-hub'); ?></a></p>
<?php endif; ?>
<p style="margin:0 0 14px;"><?php echo esc_html__('Product link:', 'ffl-hub'); ?> <?php echo esc_url($context->product_url); ?></p>
<p style="margin:0 0 10px;"><?php echo esc_html__('Your code:', 'ffl-hub'); ?> <strong><?php echo esc_html($context->coupon_code); ?></strong></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Expires:', 'ffl-hub'); ?> <?php echo esc_html($context->expires_display); ?> <?php echo esc_html__('(48 hours)', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('The code is specific to your email, this product only, and can be redeemed once.', 'ffl-hub'); ?></p>
<p style="margin:0;"><?php echo esc_html__('If you want to confirm anything before checkout, reply back and I will help.', 'ffl-hub'); ?><br><br><?php echo esc_html__('Thank you,', 'ffl-hub'); ?><br><?php echo esc_html($context->rep_name); ?><br><?php echo esc_html($context->team_signature); ?></p>

<?php
do_action('woocommerce_email_footer', $email);
