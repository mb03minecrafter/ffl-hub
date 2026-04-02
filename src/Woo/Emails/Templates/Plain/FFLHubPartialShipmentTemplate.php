<?php
// src/Woo/Emails/Templates/Plain/FFLHubPartialShipmentTemplate.php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Order $order */
/** @var \FFLHub\Distributor\Models\PartialShipmentEmailContext $context */

use FFLHub\Product\ProductMeta;

// -----------------------------
// Build "items in this shipment update" based on job payload UPCs
// context->lines is DistributorOrderLine[]
// -----------------------------
$lines = (isset($context->lines) && is_array($context->lines)) ? $context->lines : [];

$upc_set = [];
foreach ($lines as $ln) {
    if (!($ln instanceof \FFLHub\Distributor\Models\DistributorOrderLine)) continue;
    $u = trim((string) $ln->upc);
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

// Fallback lines (job-row scope only): render UPC + qty when order-item matching fails.
$fallback_lines = [];
foreach ($lines as $ln) {
    if (!($ln instanceof \FFLHub\Distributor\Models\DistributorOrderLine)) {
        continue;
    }
    $u = trim((string) $ln->upc);
    $q = (int) $ln->quantity;
    if ($u === '') {
        continue;
    }
    if ($q < 1) {
        $q = 1;
    }
    $fallback_lines[] = [
        'upc' => $u,
        'qty' => $q,
    ];
}

$added_tracking = (isset($context->update) && isset($context->update->added_tracking) && is_array($context->update->added_tracking))
    ? $context->update->added_tracking
    : [];

$added_invoices = (isset($context->update) && isset($context->update->added_invoices) && is_array($context->update->added_invoices))
    ? $context->update->added_invoices
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

$dist_id = (isset($context->job) && isset($context->job->dist_id))
    ? (string) ($context->job->dist_id ?? '')
    : '';

echo "Hi " . $order->get_billing_first_name() . ",\n\n";
echo "We have a shipment update for your order #" . $order->get_order_number() . ".\n\n";

if (!empty($added_tracking)) {
    echo "New tracking numbers:\n";
    foreach ($added_tracking as $t) {
        $t = trim((string) $t);
        if ($t !== '') {
            echo " - {$t}\n";
        }
    }
    echo "\n";
}

if (!empty($added_invoices)) {
    echo "New invoice numbers:\n";
    foreach ($added_invoices as $inv) {
        $inv = trim((string) $inv);
        if ($inv !== '') {
            echo " - {$inv}\n";
        }
    }
    echo "\n";
}

if ($shipping_service !== '') {
    echo "Carrier/Service: " . $shipping_service . "\n";
}

if ($shipping_weight !== '') {
    echo "Shipment weight: " . $shipping_weight . "\n";
}

if ($po_number !== '') {
    echo "Reference: " . $po_number . "\n";
}

if ($dist_id !== '') {
    echo "Distributor: " . $dist_id . "\n";
}

echo "\nItems in this shipment update:\n";
if (!empty($shipment_items)) {
    foreach ($shipment_items as $item) {
        /** @var WC_Order_Item_Product $item */
        $name = (string) $item->get_name();
        $qty  = (int) $item->get_quantity();
        echo " - {$name} x{$qty}\n";
    }
} elseif (!empty($fallback_lines)) {
    foreach ($fallback_lines as $row) {
        echo ' - UPC ' . $row['upc'] . ' x' . (int) $row['qty'] . "\n";
    }
} else {
    echo " - No line items found for this shipment job.\n";
}

echo "\nView your order:\n";
echo $order->get_view_order_url() . "\n\n";
echo "Additional items may ship separately. We'll email you again when more tracking becomes available.\n";
