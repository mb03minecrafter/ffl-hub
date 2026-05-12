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
$quote_button_style = \FFLHub\Woo\Emails\QuoteOfferEmailStyles::button_style();

do_action('woocommerce_email_header', $email_heading, $email);
?>

<p style="margin:0 0 14px;"><?php echo sprintf(esc_html__('Hi %s,', 'ffl-hub'), esc_html($first_name)); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Thanks for reaching out for a quote. I reviewed your request and got pricing approved for you.', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo sprintf(wp_kses_post(__('For the %1$s, your final price is <strong>%2$s</strong> %3$s.', 'ffl-hub')), esc_html($context->product_name), esc_html($context->final_price_display), $shipping_phrase_markup); ?></p>
<?php if ($context->quote_cart_url !== '') : ?>
<p style="margin:0 0 14px;text-align:center;"><a href="<?php echo esc_url($context->quote_cart_url); ?>" style="<?php echo esc_attr($quote_button_style); ?>"><?php echo esc_html__('Add to cart with quoted price', 'ffl-hub'); ?></a></p>
<?php endif; ?>
<p style="margin:0 0 14px;"><?php echo esc_html__('Product link:', 'ffl-hub'); ?> <?php echo esc_url($context->product_url); ?></p>
<p style="margin:0 0 10px;"><?php echo esc_html__('Your code:', 'ffl-hub'); ?> <strong><?php echo esc_html($context->coupon_code); ?></strong></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('Expires:', 'ffl-hub'); ?> <?php echo esc_html($context->expires_display); ?> <?php echo esc_html__('(48 hours)', 'ffl-hub'); ?></p>
<p style="margin:0 0 14px;"><?php echo esc_html__('This code is tied to your email, only works for this product, and can only be used one time.', 'ffl-hub'); ?></p>
<p style="margin:0;"><?php echo esc_html__('If you want me to double check anything before you place the order, just reply and I can help.', 'ffl-hub'); ?><br><br><?php echo esc_html__('Thanks again,', 'ffl-hub'); ?><br><?php echo esc_html($context->rep_name); ?><br><?php echo esc_html($context->team_signature); ?></p>

<?php
do_action('woocommerce_email_footer', $email);
