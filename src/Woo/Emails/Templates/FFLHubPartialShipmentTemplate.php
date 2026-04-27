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
// Brand palette (matches your Frost override CSS)
// -----------------------------
// Button default: #C9A24D (bronze), text: #000000
// Hover/focus don't reliably apply in email clients, so we keep a single static button color.
$brand_text   = '#000000';
$brand_muted  = '#000000';
$brand_border = 'rgba(0,0,0,.20)';
$brand_accent = '#C9A24D';       // bronze
$brand_accent_text = '#000000';  // black text on bronze
$brand_panel_bg = '#ffffff';

$email_heading = $email->get_heading();

// -----------------------------
// Build "items in this shipment update" based on job payload UPCs.
// Do not use wc_get_email_order_items() here; Woo renders the whole order.
// -----------------------------
$lines = (isset($context->lines) && is_array($context->lines)) ? $context->lines : [];

$upc_qty_map = [];
$upc_label_map = [];
$normalize_upc = static function (string $upc): string {
    $digits = (string) preg_replace('/\D+/', '', $upc);
    return $digits !== '' ? $digits : strtolower(trim($upc));
};

foreach ($lines as $ln) {
    if (!($ln instanceof \FFLHub\Distributor\Models\DistributorOrderLine)) {
        continue;
    }
    $u = trim((string) $ln->upc);
    if ($u !== '') {
        $key = $normalize_upc($u);
        $upc_qty_map[$key] = ($upc_qty_map[$key] ?? 0) + max(1, (int) $ln->quantity);
        $upc_label_map[$key] = $u;
    }
}

$shipment_rows = [];
$matched_upc_keys = [];
foreach ($order->get_items() as $item_id => $item) {
    if (!($item instanceof WC_Order_Item_Product)) {
        continue;
    }

    $product = $item->get_product();
    if (!($product instanceof WC_Product)) {
        continue;
    }

    $candidate_upcs = [
        trim((string) $product->get_meta(ProductMeta::FFLHUB_UPC_META, true)),
        trim((string) $product->get_meta('_upc', true)),
    ];
    if (method_exists($product, 'get_global_unique_id')) {
        $candidate_upcs[] = trim((string) $product->get_global_unique_id('edit'));
    }

    $matched_key = '';
    foreach ($candidate_upcs as $candidate_upc) {
        if ($candidate_upc === '') {
            continue;
        }

        $candidate_key = $normalize_upc($candidate_upc);
        if (isset($upc_qty_map[$candidate_key])) {
            $matched_key = $candidate_key;
            break;
        }
    }

    if ($matched_key !== '') {
        $matched_upc_keys[$matched_key] = true;
        $shipment_rows[] = [
            'name'  => (string) $item->get_name(),
            'qty'   => max(1, (int) $upc_qty_map[$matched_key]),
            'sku'   => (string) $product->get_sku(),
            'image' => $product->get_image([48, 48], ['style' => 'display:block;border:0;outline:none;text-decoration:none;']),
        ];
    }
}

// Fallback lines (job-row scope only): render UPC + qty when order-item matching fails.
$fallback_lines = [];
foreach ($upc_qty_map as $key => $qty) {
    if (isset($matched_upc_keys[$key])) {
        continue;
    }

    $fallback_lines[] = [
        'upc' => $upc_label_map[$key] ?? $key,
        'qty' => max(1, (int) $qty),
    ];
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
            if (!empty($shipment_rows)) {
                foreach ($shipment_rows as $row) {
                    echo '<tr>';
                    echo '<td style="padding:10px 12px 10px 0;width:54px;vertical-align:top;">' . wp_kses_post((string) $row['image']) . '</td>';
                    echo '<td style="padding:10px 0;color:' . esc_attr($brand_text) . ';vertical-align:top;">';
                    echo '<strong>' . esc_html((string) $row['name']) . '</strong>';
                    if ((string) $row['sku'] !== '') {
                        echo '<br><span style="color:' . esc_attr($brand_muted) . ';font-size:12px;">SKU: ' . esc_html((string) $row['sku']) . '</span>';
                    }
                    echo '</td>';
                    echo '<td style="padding:10px 0 10px 12px;text-align:right;color:' . esc_attr($brand_text) . ';vertical-align:top;white-space:nowrap;">' .
                        esc_html('x' . (string) $row['qty']) .
                        '</td>';
                    echo '</tr>';
                }
            } elseif (!empty($fallback_lines)) {
                foreach ($fallback_lines as $row) {
                    echo '<tr>';
                    echo '<td style="padding:10px 0;color:' . esc_attr($brand_text) . ';">' .
                        esc_html('UPC ' . $row['upc']) .
                        '</td>';
                    echo '<td style="padding:10px 0;text-align:right;color:' . esc_attr($brand_text) . ';">' .
                        esc_html('x' . (string) $row['qty']) .
                        '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td style="padding:10px 0;color:' . esc_attr($brand_muted) . ';">' .
                    esc_html__('No line items found for this shipment job.', 'ffl-hub') .
                    '</td></tr>';
            }
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
       style="font-weight: normal; color: <?php echo esc_attr($brand_accent_text); ?>; display: inline-block; padding: 12px 18px; text-decoration: none; border-radius: 3px; background: <?php echo esc_attr($brand_accent); ?>;">
        View order
    </a>
</p>

<p style="margin: 0 0 16px; margin-top: 14px;">
    Additional items may ship separately. We’ll email you again when more tracking becomes available.
</p>

<?php
do_action('woocommerce_email_footer', $email);
