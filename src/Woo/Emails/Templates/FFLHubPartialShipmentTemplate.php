<?php
// src/Woo/Emails/Templates/FFLHubPartialShipmentTemplate.php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Order $order */
/** @var WC_Email $email */
/** @var array<string,mixed> $context */

use FFLHub\Product\ProductMeta;

$email_heading = $email->get_heading();

// -----------------------------
// Build "items in this shipment update" based on job payload UPCs
// context['lines'] should be: [ ['upc'=>'...', 'qty'=>1], ... ]
// -----------------------------
$lines = (isset($context['lines']) && is_array($context['lines'])) ? $context['lines'] : [];

$upc_set = [];
foreach ($lines as $ln) {
    if (!is_array($ln)) continue;
    $u = trim((string) ($ln['upc'] ?? ''));
    if ($u !== '') $upc_set[$u] = true;
}

$shipment_items = [];
foreach ($order->get_items() as $item_id => $item) {
    if (!($item instanceof WC_Order_Item_Product)) continue;

    $product = $item->get_product();
    if (!($product instanceof WC_Product)) continue;

    $upc = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
    if ($upc !== '' && isset($upc_set[$upc])) {
        $shipment_items[$item_id] = $item;
    }
}

// Fallback: show all items if we can't match UPCs
if (empty($shipment_items)) {
    $shipment_items = $order->get_items();
}

$added_tracking = (isset($context['added_tracking']) && is_array($context['added_tracking'])) ? $context['added_tracking'] : [];
$added_invoices = (isset($context['added_invoices']) && is_array($context['added_invoices'])) ? $context['added_invoices'] : [];

do_action('woocommerce_email_header', $email_heading, $email);
?>

<p>
    Hi <?php echo esc_html($order->get_billing_first_name()); ?>,
</p>

<p>
    We have a shipment update for your order
    <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>.
</p>

<?php if (!empty($added_tracking)) : ?>
    <p><strong>New tracking:</strong> <?php echo esc_html(implode(', ', array_map('strval', $added_tracking))); ?></p>
<?php endif; ?>

<?php if (!empty($added_invoices)) : ?>
    <p><strong>New invoices:</strong> <?php echo esc_html(implode(', ', array_map('strval', $added_invoices))); ?></p>
<?php endif; ?>

<?php if (!empty($context['shipping_service'])) : ?>
    <p>
        <strong>Carrier/Service:</strong>
        <?php echo esc_html((string) $context['shipping_service']); ?>
    </p>
<?php endif; ?>

<?php if (!empty($context['shipping_weight'])) : ?>
    <p>
        <strong>Shipment weight:</strong>
        <?php echo esc_html((string) $context['shipping_weight']); ?>
    </p>
<?php endif; ?>

<?php if (!empty($context['po_number'])) : ?>
    <p>
        <strong>Reference:</strong>
        <?php echo esc_html((string) $context['po_number']); ?>
    </p>
<?php endif; ?>

<h2 style="margin: 24px 0 12px;">
    <?php esc_html_e('Items in this shipment update', 'fflhub'); ?>
</h2>

<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5;" border="1">
    <thead>
        <tr>
            <th scope="col" style="text-align:left; border: 1px solid #e5e5e5;"><?php esc_html_e('Product', 'fflhub'); ?></th>
            <th scope="col" style="text-align:left; border: 1px solid #e5e5e5;"><?php esc_html_e('Quantity', 'fflhub'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($shipment_items as $item) : ?>
            <?php /** @var WC_Order_Item_Product $item */ ?>
            <tr>
                <td style="text-align:left; border: 1px solid #e5e5e5;">
                    <?php echo wp_kses_post($item->get_name()); ?>
                </td>
                <td style="text-align:left; border: 1px solid #e5e5e5;">
                    <?php echo esc_html((string) $item->get_quantity()); ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<p style="margin-top: 16px;">
    <a href="<?php echo esc_url($order->get_view_order_url()); ?>"
       class="link"
       style="display:inline-block; padding:12px 18px; text-decoration:none; border-radius:3px;">
        <?php esc_html_e('View order', 'fflhub'); ?>
    </a>
</p>

<p>
    Additional items may ship separately. We’ll email you again when more tracking becomes available.
</p>

<?php
do_action('woocommerce_email_footer', $email);
