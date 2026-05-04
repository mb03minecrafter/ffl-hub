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

<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Hi %s,', 'ffl-hub'), esc_html($first_name)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Thanks for your quote request. I looked it over and got this one set up for you.', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(wp_kses_post(__('For the %1$s, your final quoted price is <strong>%2$s</strong> %3$s.', 'ffl-hub')), esc_html($context->product_name), esc_html($context->final_price_display), $shipping_phrase_markup); ?></p>
<?php if ($context->quote_cart_url !== '') : ?>
<p style="margin:0 0 14px;text-align:center;"><a href="<?php echo esc_url($context->quote_cart_url); ?>" style="display:inline-block;padding:10px 16px;background:#c9a24d;color:#000000;text-decoration:none;border:0;border-radius:4px;font-weight:700;"><?php echo esc_html__('Add to cart with quoted price', 'ffl-hub'); ?></a></p>
<?php endif; ?>
<p style="margin:0 0 14px;"><?php echo esc_html__('Product link:', 'ffl-hub'); ?> <?php echo esc_url($context->product_url); ?></p>
<p style="margin:0 0 10px;"><?php echo esc_html__('Your code:', 'ffl-hub'); ?> <strong><?php echo esc_html($context->coupon_code); ?></strong></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Expires:', 'ffl-hub'); ?> <?php echo esc_html($context->expires_display); ?> <?php echo esc_html__('(48 hours)', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('This quote code is only for you, only for this item, and it works one time.', 'ffl-hub'); ?></p>
<p style="margin:0;"><?php echo esc_html__('If anything is unclear, reply to this email and I can walk you through it.', 'ffl-hub'); ?><br><br><?php echo esc_html__('Talk soon,', 'ffl-hub'); ?><br><?php echo esc_html($context->rep_name); ?><br><?php echo esc_html__('Sales Team, Bickham Firearms', 'ffl-hub'); ?></p>

<?php
do_action('woocommerce_email_footer', $email);
