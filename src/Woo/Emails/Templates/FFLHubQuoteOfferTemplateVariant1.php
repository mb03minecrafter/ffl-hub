<?php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Email $email */
/** @var \FFLHub\Woo\Emails\Models\QuoteOfferEmailContext $context */

$email_heading = $email->get_heading();
$quote_button_style = \FFLHub\Woo\Emails\QuoteOfferEmailStyles::button_style();
$product_name = $context->product_name !== '' ? $context->product_name : __('requested product', 'ffl-hub');
$quoted_price = trim($context->final_price_display);
$shipping_phrase = trim($context->shipping_phrase);

do_action('woocommerce_email_header', $email_heading, $email);
?>

<p style="margin:0 0 14px;">
    <a href="<?php echo esc_url($context->product_url); ?>" style="color:inherit;font-weight:700;text-decoration:underline;">
        <?php echo esc_html($product_name); ?>
    </a>
</p>
<?php if ($quoted_price !== '') : ?>
<p style="margin:0 0 14px;">
    <?php echo esc_html__('Quoted price:', 'ffl-hub'); ?>
    <strong><?php echo esc_html($quoted_price); ?></strong><?php echo $shipping_phrase !== '' ? ' ' . esc_html($shipping_phrase) : ''; ?>, <?php echo esc_html__('no tax', 'ffl-hub'); ?>.
</p>
<?php endif; ?>
<p style="margin:0 0 14px;"><?php echo esc_html__('Coupon code:', 'ffl-hub'); ?> <strong><?php echo esc_html($context->coupon_code); ?></strong></p>
<?php if ($context->quote_cart_url !== '') : ?>
<p style="margin:0 0 14px;text-align:center;"><a href="<?php echo esc_url($context->quote_cart_url); ?>" style="<?php echo esc_attr($quote_button_style); ?>"><?php echo esc_html__('Add to cart with quoted price', 'ffl-hub'); ?></a></p>
<?php endif; ?>

<?php
do_action('woocommerce_email_footer', $email);
