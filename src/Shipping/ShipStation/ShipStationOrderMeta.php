<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical FFL Hub order metadata for ShipStation labels.
 */
final class ShipStationOrderMeta
{
    private const LARGE_LABEL_DOCUMENT_BYTES = 10000;

    public const META_LABELS = '_fflhub_ss_labels';
    public const META_PENDING_RATES = '_fflhub_ss_pending_rates';

    public const META_SHIPMENT_ID = '_fflhub_ss_shipment_id';
    public const META_RATE_ID = '_fflhub_ss_rate_id';
    public const META_LABEL_ID = '_fflhub_ss_label_id';
    public const META_CARRIER_ID = '_fflhub_ss_carrier_id';
    public const META_CARRIER_CODE = '_fflhub_ss_carrier_code';
    public const META_SERVICE_CODE = '_fflhub_ss_service_code';
    public const META_TRACKING_NUMBER = '_fflhub_ss_tracking_number';
    public const META_TRACKING_URL = '_fflhub_ss_tracking_url';
    public const META_LABEL_FORMAT = '_fflhub_ss_label_format';
    public const META_LABEL_LAYOUT = '_fflhub_ss_label_layout';
    public const META_LABEL_URL = '_fflhub_ss_label_url';
    public const META_SHIPPING_COST = '_fflhub_ss_shipping_cost';
    public const META_INSURANCE_COST = '_fflhub_ss_insurance_cost';
    public const META_TOTAL_COST = '_fflhub_ss_total_cost';
    public const META_LABEL_STATUS = '_fflhub_ss_label_status';
    public const META_PURCHASED_AT = '_fflhub_ss_purchased_at';
    public const META_VOIDED_AT = '_fflhub_ss_voided_at';
    public const META_SHIPMENT_SNAPSHOT = '_fflhub_ss_shipment_snapshot';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function labels(WC_Order $order): array
    {
        $labels = $order->get_meta(self::META_LABELS, true);
        if (is_string($labels) && $labels !== '') {
            $decoded = json_decode($labels, true);
            $labels = is_array($decoded) ? $decoded : maybe_unserialize($labels);
        }

        if (!is_array($labels)) {
            return [];
        }

        $out = [];
        foreach ($labels as $label) {
            if (is_array($label)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public static function pending_rates(WC_Order $order): array
    {
        $rates = $order->get_meta(self::META_PENDING_RATES, true);
        if (is_string($rates) && $rates !== '') {
            $decoded = json_decode($rates, true);
            $rates = is_array($decoded) ? $decoded : maybe_unserialize($rates);
        }

        return is_array($rates) ? $rates : [];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<int,array<string,mixed>> $rates
     * @param array<int,array<string,mixed>> $invalid_rates
     */
    public static function save_pending_rates(
        WC_Order $order,
        array $snapshot,
        string $shipment_hash,
        array $rates,
        array $invalid_rates,
        string $shipment_id,
        string $rate_request_id,
        array $package_items = [],
        array $duplicate_rate_groups = [],
        array $package_details = []
    ): void {
        $payload = [
            'created_at' => current_time('mysql', true),
            'shipment_hash' => $shipment_hash,
            'shipment_snapshot' => $snapshot,
            'shipment_id' => $shipment_id,
            'rate_request_id' => $rate_request_id,
            'package_items' => $package_items,
            'package_details' => $package_details,
            'duplicate_rate_groups' => $duplicate_rate_groups,
            'rates' => $rates,
            'invalid_rates' => $invalid_rates,
        ];

        $order->update_meta_data(self::META_PENDING_RATES, wp_json_encode($payload));
        $order->save();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function pending_rate(WC_Order $order, string $rate_id, string $shipment_hash): ?array
    {
        $pending = self::pending_rates($order);
        if ((string) ($pending['shipment_hash'] ?? '') !== $shipment_hash) {
            return null;
        }

        $rates = isset($pending['rates']) && is_array($pending['rates'])
            ? $pending['rates']
            : [];

        foreach ($rates as $rate) {
            if (is_array($rate) && (string) ($rate['rate_id'] ?? '') === $rate_id) {
                return $rate;
            }
        }

        return null;
    }

    public static function has_active_label(WC_Order $order): bool
    {
        foreach (self::labels($order) as $label) {
            if (self::label_is_active($label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $label
     */
    public static function label_is_active(array $label): bool
    {
        if (!empty($label['locally_deactivated'])) {
            return false;
        }

        $status = strtolower(trim((string) ($label['status'] ?? $label['label_status'] ?? '')));
        if ($status === '' || in_array($status, ['completed', 'purchased'], true)) {
            return empty($label['voided']);
        }

        return !in_array($status, ['voided', 'error', 'purchase_error', 'cancelled', 'inactive', 'local_inactive', 'locally_deactivated'], true);
    }

    /**
     * @param array<string,mixed> $api_label
     * @param array<string,mixed> $rated
     * @param array<string,mixed> $pending
     * @return array<string,mixed>
     */
    public static function normalize_purchased_label(
        array $api_label,
        array $rated,
        array $pending,
        string $rate_id,
        string $request_id
    ): array {
        $shipment_cost = self::money_amount($api_label['shipment_cost'] ?? null);
        $insurance_cost = self::money_amount($api_label['insurance_cost'] ?? null);
        $total_cost = $shipment_cost + $insurance_cost;
        if ($total_cost <= 0.0) {
            $total_cost = (float) ($rated['total_amount'] ?? 0.0);
        }

        $label_download = is_array($api_label['label_download'] ?? null) ? $api_label['label_download'] : [];
        $format = strtolower((string) ($api_label['label_format'] ?? ShipStationOptions::label_format()));
        $label_url = (string) ($label_download[$format] ?? $label_download['href'] ?? '');
        $provider_id = (string) ($api_label['provider_id'] ?? $rated['provider_id'] ?? 'shipstation');
        $provider_label = (string) ($api_label['provider_label'] ?? $rated['provider_label'] ?? 'ShipStation');
        $source = (string) ($api_label['source'] ?? $rated['source'] ?? ($provider_id === 'shipstation' ? 'fflhub_shipstation' : 'fflhub_' . $provider_id));

        return [
            'source' => $source,
            'provider_id' => $provider_id,
            'provider_label' => $provider_label,
            'label_id' => (string) ($api_label['label_id'] ?? ''),
            'shipment_id' => (string) ($api_label['shipment_id'] ?? ($pending['shipment_id'] ?? '')),
            'rate_id' => $rate_id,
            'rate_request_id' => (string) ($pending['rate_request_id'] ?? ''),
            'carrier_id' => (string) ($api_label['carrier_id'] ?? ($rated['carrier_id'] ?? '')),
            'carrier_code' => (string) ($api_label['carrier_code'] ?? ($rated['carrier_code'] ?? '')),
            'carrier_nickname' => (string) ($rated['carrier_nickname'] ?? ''),
            'carrier_friendly_name' => (string) ($rated['carrier_friendly_name'] ?? ''),
            'service_code' => (string) ($api_label['service_code'] ?? ($rated['service_code'] ?? '')),
            'service_name' => (string) ($rated['service_type'] ?? ''),
            'tracking_number' => (string) ($api_label['tracking_number'] ?? ''),
            'tracking_url' => (string) ($api_label['tracking_url'] ?? ''),
            'shipment_cost' => self::money($shipment_cost),
            'insurance_cost' => self::money($insurance_cost),
            'cost' => self::money($total_cost),
            'total_cost' => self::money($total_cost),
            'currency' => (string) (($api_label['shipment_cost']['currency'] ?? null) ?: ($rated['currency'] ?? 'usd')),
            'label_format' => $format,
            'label_layout' => (string) ($api_label['label_layout'] ?? ShipStationOptions::label_layout()),
            'label_url' => $label_url,
            'label_download' => $label_download,
            'status' => self::normalized_billing_status((string) ($api_label['status'] ?? 'completed')),
            'label_status' => self::normalized_billing_status((string) ($api_label['status'] ?? 'completed')),
            'provider_label_status' => (string) ($api_label['status'] ?? ''),
            'purchased_at' => current_time('mysql', true),
            'created' => (string) ($api_label['created_at'] ?? current_time('mysql', true)),
            'ship_date' => (string) ($api_label['ship_date'] ?? ''),
            'shipment_snapshot' => $pending['shipment_snapshot'] ?? [],
            'package_items' => is_array($pending['package_items'] ?? null) ? $pending['package_items'] : [],
            'package_details' => is_array($pending['package_details'] ?? null) ? $pending['package_details'] : [],
            'api_request_id' => $request_id,
            'voided' => !empty($api_label['voided']),
            'voided_at' => (string) ($api_label['voided_at'] ?? ''),
            'is_return' => !empty($api_label['is_return_label']),
            'tracking' => (string) ($api_label['tracking_number'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $label
     */
    public static function append_label(WC_Order $order, array $label): void
    {
        $labels = self::labels($order);
        $labels[] = $label;

        self::save_labels($order, $labels);
        self::save_latest_label_metas($order, $label);
        $order->save();
    }

    /**
     * @param array<string,mixed> $void_response
     */
    public static function mark_voided(WC_Order $order, string $label_id, array $void_response): bool
    {
        $labels = self::labels($order);
        $changed = false;

        foreach ($labels as &$label) {
            if ((string) ($label['label_id'] ?? '') !== $label_id) {
                continue;
            }

            $label['voided'] = true;
            $label['status'] = 'voided';
            $label['label_status'] = 'voided';
            $label['voided_at'] = current_time('mysql', true);
            $label['void_response'] = $void_response;
            $changed = true;
        }
        unset($label);

        if ($changed) {
            self::save_labels($order, $labels);
            $order->update_meta_data(self::META_LABEL_STATUS, 'voided');
            $order->update_meta_data(self::META_VOIDED_AT, current_time('mysql', true));
            $order->save();
        }

        return $changed;
    }

    /**
     * Mark a label inactive inside FFL Hub without claiming the carrier refunded
     * or cancelled it. This is intentionally separate from mark_voided() so test
     * labels or already-shipped labels can be cleared for retesting while the
     * order note still tells the truth about the provider result.
     *
     * @param array<string,mixed> $context
     */
    public static function mark_locally_deactivated(WC_Order $order, string $label_id, array $context = []): bool
    {
        $labels = self::labels($order);
        $changed = false;

        foreach ($labels as &$label) {
            if ((string) ($label['label_id'] ?? '') !== $label_id) {
                continue;
            }

            $label['locally_deactivated'] = true;
            $label['local_deactivated_at'] = current_time('mysql', true);
            $label['local_deactivation_context'] = $context;
            $label['provider_label_status_before_local_deactivation'] = (string) ($label['status'] ?? $label['label_status'] ?? '');
            $label['label_status'] = 'local_inactive';
            $changed = true;
        }
        unset($label);

        if ($changed) {
            self::save_labels($order, $labels);
            $order->update_meta_data(self::META_LABEL_STATUS, 'local_inactive');
            $order->save();
        }

        return $changed;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find_label(WC_Order $order, string $label_id): ?array
    {
        foreach (self::labels($order) as $label) {
            if ((string) ($label['label_id'] ?? '') === $label_id) {
                return self::hydrate_label_document_from_latest_meta($order, $label);
            }
        }

        return null;
    }

    public static function purchase_lock_key(WC_Order $order): string
    {
        return 'fflhub_ss_purchase_lock_' . (int) $order->get_id();
    }

    public static function acquire_purchase_lock(WC_Order $order): bool
    {
        $key = self::purchase_lock_key($order);
        $now = time();
        $existing = get_option($key, '');
        if ($existing !== '' && ($now - (int) $existing) > 120) {
            delete_option($key);
        }

        return add_option($key, (string) $now, '', false);
    }

    public static function release_purchase_lock(WC_Order $order): void
    {
        delete_option(self::purchase_lock_key($order));
    }

    /**
     * @param array<int,array<string,mixed>> $labels
     */
    private static function save_labels(WC_Order $order, array $labels): void
    {
        $order->update_meta_data(self::META_LABELS, self::labels_for_collection_storage($labels));
    }

    /**
     * HPOS order meta is not a good home for embedded carrier label documents.
     * ShipOutdoors returns the 4x6 label as a base64 token; storing that token
     * once in the latest-label fields is fine, but duplicating it inside the
     * canonical label collection can exceed the meta row size and drop the
     * entire collection. The collection only needs tracking, package assignment,
     * provider, and cost data for WMS/accounting, so large documents are stored
     * separately and reattached for latest-label downloads.
     *
     * @param array<int,array<string,mixed>> $labels
     * @return array<int,array<string,mixed>>
     */
    private static function labels_for_collection_storage(array $labels): array
    {
        $out = [];
        foreach ($labels as $label) {
            if (!is_array($label)) {
                continue;
            }

            $label_url = (string) ($label['label_url'] ?? '');
            $download = is_array($label['label_download'] ?? null) ? $label['label_download'] : [];
            $download_json = !empty($download) ? (wp_json_encode($download) ?: '') : '';
            if (strlen($label_url) > self::LARGE_LABEL_DOCUMENT_BYTES || strlen($download_json) > self::LARGE_LABEL_DOCUMENT_BYTES) {
                unset($label['label_url'], $label['label_download']);
                $label['label_document_stored_separately'] = true;
            }

            $out[] = $label;
        }

        return array_values($out);
    }

    /**
     * @param array<string,mixed> $label
     * @return array<string,mixed>
     */
    private static function hydrate_label_document_from_latest_meta(WC_Order $order, array $label): array
    {
        $label_id = (string) ($label['label_id'] ?? '');
        if ($label_id === '' || $label_id !== (string) $order->get_meta(self::META_LABEL_ID, true)) {
            return $label;
        }

        $label_url = (string) $order->get_meta(self::META_LABEL_URL, true);
        if ($label_url === '') {
            return $label;
        }

        $format = strtolower((string) ($label['label_format'] ?? $order->get_meta(self::META_LABEL_FORMAT, true) ?: ShipStationOptions::label_format()));
        $label['label_url'] = $label_url;
        $label['label_download'] = [
            ($format !== '' ? $format : 'pdf') => $label_url,
            'href' => $label_url,
        ];

        return $label;
    }

    /**
     * @param array<string,mixed> $label
     */
    private static function save_latest_label_metas(WC_Order $order, array $label): void
    {
        $order->update_meta_data(self::META_SHIPMENT_ID, (string) ($label['shipment_id'] ?? ''));
        $order->update_meta_data(self::META_RATE_ID, (string) ($label['rate_id'] ?? ''));
        $order->update_meta_data(self::META_LABEL_ID, (string) ($label['label_id'] ?? ''));
        $order->update_meta_data(self::META_CARRIER_ID, (string) ($label['carrier_id'] ?? ''));
        $order->update_meta_data(self::META_CARRIER_CODE, (string) ($label['carrier_code'] ?? ''));
        $order->update_meta_data(self::META_SERVICE_CODE, (string) ($label['service_code'] ?? ''));
        $order->update_meta_data(self::META_TRACKING_NUMBER, (string) ($label['tracking_number'] ?? ''));
        $order->update_meta_data(self::META_TRACKING_URL, (string) ($label['tracking_url'] ?? ''));
        $order->update_meta_data(self::META_LABEL_FORMAT, (string) ($label['label_format'] ?? ''));
        $order->update_meta_data(self::META_LABEL_LAYOUT, (string) ($label['label_layout'] ?? ''));
        $order->update_meta_data(self::META_LABEL_URL, (string) ($label['label_url'] ?? ''));
        $order->update_meta_data(self::META_SHIPPING_COST, (string) ($label['shipment_cost'] ?? '0.0000'));
        $order->update_meta_data(self::META_INSURANCE_COST, (string) ($label['insurance_cost'] ?? '0.0000'));
        $order->update_meta_data(self::META_TOTAL_COST, (string) ($label['total_cost'] ?? '0.0000'));
        $order->update_meta_data(self::META_LABEL_STATUS, (string) ($label['label_status'] ?? $label['status'] ?? ''));
        $order->update_meta_data(self::META_PURCHASED_AT, (string) ($label['purchased_at'] ?? ''));
        $order->update_meta_data(self::META_SHIPMENT_SNAPSHOT, wp_json_encode($label['shipment_snapshot'] ?? []));
    }

    /**
     * @param mixed $money
     */
    private static function money_amount($money): float
    {
        if (is_array($money)) {
            return max(0.0, (float) ($money['amount'] ?? 0));
        }

        return max(0.0, (float) $money);
    }

    private static function money(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    private static function normalized_billing_status(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '' || in_array($normalized, ['purchased', 'completed', 'label_purchased'], true)) {
            return $normalized !== '' ? $normalized : 'completed';
        }

        // EasyPost purchased Shipments usually move immediately into carrier
        // tracking states. Those are still billable labels for audit purposes.
        if (in_array($normalized, ['unknown', 'pre_transit', 'in_transit', 'out_for_delivery', 'delivered', 'available_for_pickup', 'return_to_sender'], true)) {
            return 'purchased';
        }

        return $normalized;
    }
}
