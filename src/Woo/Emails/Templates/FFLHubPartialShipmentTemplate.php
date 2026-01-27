<?php
// src/Woo/Emails/Templates/FFLHubPartialShipmentTemplate.php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Order $order */
/** @var WC_Email $email */
/** @var \FFLHub\Distributor\Models\PartialShipmentEmailContext $context */

use FFLHub\Product\ProductMeta;

// -----------------------------
// Brand palette (tweak these to match bickhamfirearms.com)
// -----------------------------
// NOTE: I couldn't fetch the site colors (406), so these are safe defaults.
// Replace these with your exact brand hex values once you pick them.
$brand_text   = '#111111';
$brand_muted  = '#414141';
$brand_border = 'rgba(0,0,0,.20)';
$brand_accent = '#ffd33d';  // <- swap to your exact accent
$brand_accent_text = '#111111';
$brand_panel_bg = '#ffffff';

$email_heading = $email->get_heading();

// -----------------------------
// Build "items in this shipment update" based on job payload UPCs
// context->lines is DistributorOrderLine[]
// -----------------------------
$lines = (isset($context->lines) && is_array($context->lines)) ? $context->lines : [];

$upc_set = [];
foreach ($lines as $ln) {
    if (!($ln instanceof \FFLHub\Distributor\Models\DistributorOrderLine)) {
        continue;
    }
    $u = trim((string) $ln->upc);
    if ($u !== '') {
        $upc_set[$u] = true;
    }
}

$shipment_items = [];
foreach ($order->get_items() as $item_id => $item) {
    if (!($item instanceof WC_Order_Item_Product)) {
        continue;
    }

    $product = $item->get_product();
    if (!($product instanceof WC_Product)) {
        continue;
    }

    $upc = trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true));
    if ($upc !== '' && isset($upc_set[$upc])) {
        $shipment_items[$item_id] = $item;
    }
}

// Fallback: if we can't match, show all items (safer than showing none).
if (empty($shipment_items)) {
    $shipment_items = $order->get_items();
}

// NEW-only deltas (what we want to notify about)
$added_tracking = (isset($context->update) && isset($context->update->added_tracking) && is_array($context->update->added_tracking))
    ? array_values(array_filter(array_map('strval', $context->update->added_tracking)))
    : [];

$shipping_service = (isset($context->shipment) && property_exists($context->shipment, 'shipping_service'))
    ? (string) ($context->shipment->shipping_service ?? '')
    : '';

$shipping_weight = (isset($context->shipment) && property_exists($context->shipment, 'shipping_weight'))
    ? (string) ($context->shipment->shipping_weight ?? '')
    : '';

$po_number = (isset($context->job) && isset($context->job->merchant_po))
    ? (string) ($context->job->merchant_po ?? '')
    : '';

$tracking_display = '';
if (!empty($added_tracking)) {
    // Display each tracking on its own line (email-friendly)
    $tracking_display = implode('<br>', array_map('esc_html', $added_tracking));
}

do_action('woocommerce_email_header', $email_heading, $email);
?>

<div class="email-introduction" style="padding-bottom: 24px;">
    <p style="margin: 0 0 16px;">
        Hi <?php echo esc_html($order->get_billing_first_name()); ?>,
    </p>

    <p style="margin: 0 0 16px;">
        We have a shipment update for your order <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>.
    </p>

    <p style="margin: 0;">
        Here’s what’s included in this shipment update:
    </p>
</div>

<h2 class="email-order-detail-heading" style='color: <?php echo esc_attr($brand_text); ?>; display: block; font-family: "Helvetica Neue",Helvetica,Roboto,Arial,sans-serif; font-size: 20px; font-weight: bold; line-height: 160%; margin: 0 0 18px; text-align: left;'>
    Items in this shipment update
</h2>

<div style="margin-bottom: 24px;">
    <table class="td font-family email-order-details" cellspacing="0" cellpadding="6" border="0" style='color: <?php echo esc_attr($brand_muted); ?>; border: 0; vertical-align: middle; font-family: "Helvetica Neue",Helvetica,Roboto,Arial,sans-serif; width: 100%;' width="100%">
        <tbody>
            <?php
            // ✅ This outputs rows that match Woo's completed-order styling.
            // ✅ And we pass ONLY the shipped items.
            echo wc_get_email_order_items(
                $order,
                [
                    'items'              => $shipment_items,
                    'show_sku'           => false,
                    'show_image'         => true,
                    'image_size'         => [48, 48],
                    'show_purchase_note' => false,
                    'plain_text'         => false,
                    'sent_to_admin'      => false,
                ]
            );
            ?>
        </tbody>
    </table>
</div>

<h2 class="email-order-detail-heading" style='color: <?php echo esc_attr($brand_text); ?>; display: block; font-family: "Helvetica Neue",Helvetica,Roboto,Arial,sans-serif; font-size: 20px; font-weight: bold; line-height: 160%; text-align: left; margin: 0 0 18px;'>
    Shipment details
</h2>

<div style="margin-bottom: 24px;">
    <table class="td font-family email-order-details" cellspacing="0" cellpadding="6" border="0" width="100%" style='color: <?php echo esc_attr($brand_muted); ?>; font-family: "Helvetica Neue",Helvetica,Roboto,Arial,sans-serif; width: 100%; border: 0; vertical-align: middle; background-color: <?php echo esc_attr($brand_panel_bg); ?>;'>
        <tbody>
            <?php if ($tracking_display !== '') : ?>
                <tr>
                    <th class="td text-align-left" scope="row" align="left"
                        style="color: <?php echo esc_attr($brand_text); ?>; vertical-align: middle; border: 0; text-align: left; padding: 10px 12px; padding-left: 0; font-weight: normal;">
                        Tracking
                    </th>
                    <td class="td text-align-right" align="right"
                        style="color: <?php echo esc_attr($brand_text); ?>; vertical-align: middle; border: 0; text-align: right; padding: 10px 12px; padding-right: 0; font-weight: 700;">
                        <?php echo $tracking_display; // already escaped per-line ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php if ($shipping_service !== '') : ?>
                <tr>
                    <th class="td text-align-left" scope="row" align="left"
                        style="color: <?php echo esc_attr($brand_muted); ?>; vertical-align: middle; border: 0; text-align: left; padding: 10px 12px; padding-left: 0; font-weight: normal; border-top: 1px solid <?php echo esc_attr($brand_border); ?>;">
                        Carrier / Service
                    </th>
                    <td class="td text-align-right" align="right"
                        style="color: <?php echo esc_attr($brand_text); ?>; vertical-align: middle; border: 0; text-align: right; padding: 10px 12px; padding-right: 0; font-weight: normal; border-top: 1px solid <?php echo esc_attr($brand_border); ?>;">
                        <?php echo esc_html($shipping_service); ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php if ($shipping_weight !== '') : ?>
                <tr>
                    <th class="td text-align-left" scope="row" align="left"
                        style="color: <?php echo esc_attr($brand_muted); ?>; vertical-align: middle; border: 0; text-align: left; padding: 10px 12px; padding-left: 0; font-weight: normal; border-top: 1px solid <?php echo esc_attr($brand_border); ?>;">
                        Shipment weight
                    </th>
                    <td class="td text-align-right" align="right"
                        style="color: <?php echo esc_attr($brand_text); ?>; vertical-align: middle; border: 0; text-align: right; padding: 10px 12px; padding-right: 0; font-weight: normal; border-top: 1px solid <?php echo esc_attr($brand_border); ?>;">
                        <?php echo esc_html($shipping_weight); ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php if ($po_number !== '') : ?>
                <tr>
                    <th class="td text-align-left" scope="row" align="left"
                        style="color: <?php echo esc_attr($brand_muted); ?>; vertical-align: middle; border: 0; text-align: left; padding: 10px 12px; padding-left: 0; font-weight: normal; border-top: 1px solid <?php echo esc_attr($brand_border); ?>; border-bottom: 1px solid <?php echo esc_attr($brand_border); ?>; padding-bottom: 18px;">
                        PO / Reference
                    </th>
                    <td class="td text-align-right" align="right"
                        style="color: <?php echo esc_attr($brand_text); ?>; vertical-align: middle; border: 0; text-align: right; padding: 10px 12px; padding-right: 0; font-weight: normal; border-top: 1px solid <?php echo esc_attr($brand_border); ?>; border-bottom: 1px solid <?php echo esc_attr($brand_border); ?>; padding-bottom: 18px;">
                        <?php echo esc_html($po_number); ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
// Standard Woo blocks (keeps styling consistent with core)
do_action('woocommerce_email_order_meta', $order, false, false, $email);
do_action('woocommerce_email_customer_details', $order, false, false, $email);
?>

<br>

<p style="margin: 0 0 16px; margin-top: 16px;">
    <a href="<?php echo esc_url($order->get_view_order_url()); ?>"
       class="link"
       style="font-weight: normal; color: <?php echo esc_attr($brand_text); ?>; display: inline-block; padding: 12px 18px; text-decoration: none; border-radius: 3px; background: <?php echo esc_attr($brand_accent); ?>;">
        View order
    </a>
</p>

<p style="margin: 0 0 16px; margin-top: 14px;">
    Additional items may ship separately. We’ll email you again when more tracking becomes available.
</p>

<?php
do_action('woocommerce_email_footer', $email);
