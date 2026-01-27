<?php
// src/Woo/Emails/Templates/Plain/FFLHubPartialShipmentTemplate.php

if (!defined('ABSPATH')) {
    exit;
}

/** @var WC_Order $order */
/** @var array<string,mixed> $context */

use FFLHub\Product\ProductMeta;

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

echo "Hi " . $order->get_billing_first_name() . ",\n\n";
echo "We have a shipment update for your order #" . $order->get_order_number() . ".\n\n";

if (!empty($context['added_tracking'])) {
    echo "New tracking numbers:\n";
    foreach ((array) $context['added_tracking'] as $t) {
        $t = trim((string) $t);
        if ($t !== '') {
            echo " - {$t}\n";
        }
    }
    echo "\n";
}

if (!empty($context['added_invoices'])) {
    echo "New invoice numbers:\n";
    foreach ((array) $context['added_invoices'] as $inv) {
        $inv = trim((string) $inv);
        if ($inv !== '') {
            echo " - {$inv}\n";
        }
    }
    echo "\n";
}

if (!empty($context['shipping_service'])) {
    echo "Carrier/Service: " . (string) $context['shipping_service'] . "\n";
}

if (!empty($context['shipping_weight'])) {
    echo "Shipment weight: " . (string) $context['shipping_weight'] . "\n";
}

if (!empty($context['po_number'])) {
    echo "Reference: " . (string) $context['po_number'] . "\n";
}

if (!empty($context['dist_id'])) {
    echo "Distributor: " . (string) $context['dist_id'] . "\n";
}

echo "\nItems in this shipment update:\n";
foreach ($shipment_items as $item) {
    /** @var WC_Order_Item_Product $item */
    $name = (string) $item->get_name();
    $qty  = (int) $item->get_quantity();
    echo " - {$name} x{$qty}\n";
}

echo "\nView your order:\n";
echo $order->get_view_order_url() . "\n\n";
echo "Additional items may ship separately. We'll email you again when more tracking becomes available.\n";
