<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Order\OrderProfitAuditMeta;
use FFLHub\Product\State\ProductStateStore;
use FFLHub\Shipping\Providers\ShippingProviderInterface;
use FFLHub\Shipping\ShippingOptions;
use FFLHub\Util\DebugLogUtil;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds order shipments, shops ShipStation rates, and records purchased labels.
 *
 * The browser can edit package and destination fields, but this class is still
 * the authority for the global origin, order, FFL destination, carrier policy,
 * rate totals, and duplicate-label protection.
 */
final class ShipStationShipmentService
{
    private FFLTable $ffl_table;
    private ShippingProviderInterface $provider;
    private ShipStationCarrierCache $carrier_cache;

    public function __construct(
        FFLTable $ffl_table,
        ?ShipStationClient $client = null,
        ?ShipStationCarrierCache $carrier_cache = null,
        ?ShippingProviderInterface $provider = null
    ) {
        $this->ffl_table = $ffl_table;
        $client = $client ?? new ShipStationClient();
        $this->provider = $provider ?? new ShipStationShippingProvider($client);
        $this->carrier_cache = $carrier_cache ?? new ShipStationCarrierCache($client);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function build_context(WC_Order $order)
    {
        $requires_ffl = $this->order_requires_ffl($order);
        $receiving_ffl = $requires_ffl ? $this->receiving_ffl_snapshot($order) : null;

        $destination = $requires_ffl
            ? $this->destination_from_ffl($receiving_ffl)
            : $this->destination_from_order($order);

        $context = [
            'enabled' => ShipStationOptions::is_enabled(),
            'mode' => ShipStationOptions::mode(),
            'api_key_source' => ShipStationOptions::api_key_source(),
            'api_key_mask' => ShipStationOptions::api_key_mask(),
            'requires_ffl' => $requires_ffl,
            'receiving_ffl' => $receiving_ffl,
            'origin' => ShipStationOptions::origin_address(),
            'destination' => $destination,
            'order_items' => $this->order_items_for_packages($order),
            'packages' => $this->default_packages_from_order($order),
            'package_presets' => ShipStationOptions::package_presets(),
            'settings' => [
                'label_format' => ShipStationOptions::label_format(),
                'label_layout' => ShipStationOptions::label_layout(),
                'confirmation' => ShipStationOptions::confirmation(),
                'insurance_mode' => ShipStationOptions::insurance_mode(),
                'after_purchase_status' => ShipStationOptions::after_purchase_status(),
                'show_debug_fields' => ShipStationOptions::show_debug_fields(),
            ],
            'carriers' => [],
            'eligible_carriers' => [],
            'labels' => array_map([self::class, 'public_label'], ShipStationOrderMeta::labels($order)),
            'pending_rates' => ShipStationOrderMeta::pending_rates($order),
        ];

        if (ShipStationOptions::is_enabled() && ShipStationOptions::api_key_source() !== 'none') {
            $carrier_cache = $this->carrier_cache->get(false);
            if (!is_wp_error($carrier_cache)) {
                $context['carriers'] = $carrier_cache['carriers'] ?? [];
                $context['carrier_cache'] = [
                    'fetched_at' => (int) ($carrier_cache['fetched_at'] ?? 0),
                    'stale' => !empty($carrier_cache['stale']),
                    'error' => (string) ($carrier_cache['error'] ?? ''),
                ];
                $context['eligible_carriers'] = $this->carrier_cache->eligible_carriers($requires_ffl);
            } else {
                $context['carrier_cache'] = [
                    'fetched_at' => 0,
                    'stale' => false,
                    'error' => $carrier_cache->get_error_message(),
                ];
            }
        }

        /**
         * Allow future regulated-shipment policy to add context without
         * leaking those decisions into the admin controller.
         *
         * @param array<string,mixed> $context
         * @param WC_Order $order
         */
        return apply_filters('fflhub_shipstation_shipment_context', $context, $order);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    public function validate_address(array $input)
    {
        $address = self::address_from_input($input['address'] ?? []);
        $missing = $this->missing_address_fields($address, 'Address');
        if (!empty($missing)) {
            return new WP_Error(
                'fflhub_shipstation_address_incomplete',
                implode(' ', $missing),
                ['status' => 400]
            );
        }

        $result = $this->provider->validate_address($address);
        if (is_wp_error($result)) {
            return $this->address_validation_error_response($result, $address);
        }

        return $this->normalize_address_validation_result($result, $address);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $original_address
     * @return array<string,mixed>
     */
    private function normalize_address_validation_result(array $result, array $original_address): array
    {
        $entries = isset($result['validated_addresses']) && is_array($result['validated_addresses'])
            ? $result['validated_addresses']
            : [];
        $entry = is_array($entries[0] ?? null) ? $entries[0] : [];
        $status = strtolower(trim((string) ($entry['status'] ?? $entry['validation_status'] ?? 'verified')));
        $recommended = self::address_candidate_from_validation($entry, $original_address);
        $messages = self::validation_messages($entry['messages'] ?? $entry['validation_messages'] ?? []);

        $is_valid = !in_array($status, ['error', 'invalid', 'unverified', 'failed'], true);
        if (empty($messages)) {
            $messages[] = $is_valid
                ? 'ShipStation address validation completed.'
                : 'ShipStation could not fully validate this address.';
        }

        return [
            'validation_available' => true,
            'validation_status' => $is_valid ? 'validated' : 'failed',
            'valid' => $is_valid,
            'message' => $messages[0],
            'messages' => $messages,
            'original_address' => $original_address,
            'recommended_address' => $recommended,
            'shipstation_status' => $status,
            'raw' => $result,
        ];
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private function address_validation_error_response(WP_Error $error, array $address): array
    {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];
        $status = (int) ($data['status'] ?? 0);
        $messages = [$error->get_error_message()];
        foreach ((array) ($data['errors'] ?? []) as $entry) {
            if (is_array($entry) && trim((string) ($entry['message'] ?? '')) !== '') {
                $messages[] = trim((string) $entry['message']);
            }
        }
        $messages = array_values(array_unique(array_filter($messages)));

        $is_unavailable = in_array($status, [0, 401, 402, 403, 404], true) || $status >= 500;
        $message = $is_unavailable
            ? 'ShipStation address validation is unavailable for this account/API mode. You can still get rates and buy labels with the address shown.'
            : ($messages[0] ?? 'ShipStation could not validate this address.');

        return [
            'validation_available' => !$is_unavailable,
            'validation_status' => $is_unavailable ? 'unavailable' : 'failed',
            'valid' => false,
            'message' => $message,
            'messages' => $messages,
            'original_address' => $address,
            'recommended_address' => $address,
            'shipstation_error' => [
                'code' => $error->get_error_code(),
                'status' => $status,
                'request_id' => (string) ($data['request_id'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    public function rate_order(WC_Order $order, array $input)
    {
        if (!ShipStationOptions::is_enabled()) {
            return new WP_Error('fflhub_shipstation_disabled', 'ShipStation labels are disabled.', ['status' => 400]);
        }

        $context = $this->build_context($order);
        if (is_wp_error($context)) {
            return $context;
        }

        $shipment = $this->shipment_from_input($order, $context, $input);
        if (is_wp_error($shipment)) {
            return $shipment;
        }

        $carrier_ids = $this->eligible_carrier_ids($order, !empty($context['requires_ffl']));
        if (empty($carrier_ids)) {
            return new WP_Error(
                'fflhub_shipstation_no_eligible_carriers',
                'No eligible ShipStation carriers are configured for this order.',
                ['status' => 400]
            );
        }

        /**
         * Final policy hook before rates are requested.
         *
         * @param string[] $carrier_ids
         * @param WC_Order $order
         * @param array<string,mixed> $context
         */
        $carrier_ids = (array) apply_filters('fflhub_shipstation_eligible_carrier_ids', $carrier_ids, $order, $context);
        $carrier_ids = array_values(array_filter(array_map('strval', $carrier_ids)));
        if (empty($carrier_ids)) {
            return new WP_Error(
                'fflhub_shipstation_no_policy_carriers',
                'Shipment policy removed all ShipStation carriers for this order.',
                ['status' => 400]
            );
        }

        $payload = [
            'rate_options' => [
                'carrier_ids' => $carrier_ids,
            ],
            'shipment' => $shipment,
        ];

        /**
         * Let future shipping policy tune rate options without moving HTTP
         * requests into UI callbacks.
         *
         * @param array<string,mixed> $payload
         * @param WC_Order $order
         * @param array<string,mixed> $context
         */
        $payload = (array) apply_filters('fflhub_shipstation_rate_request', $payload, $order, $context);

        $response = $this->provider->get_rates($payload);
        if (is_wp_error($response)) {
            return $response;
        }

        $normalized = $this->normalize_rate_response($response);
        $shipment_hash = self::shipment_hash($shipment);
        $package_items = self::package_item_assignments_from_input(
            $input['package_items'] ?? [],
            isset($context['order_items']) && is_array($context['order_items']) ? $context['order_items'] : []
        );
        $duplicate_rate_groups = isset($normalized['duplicate_rate_groups']) && is_array($normalized['duplicate_rate_groups'])
            ? $normalized['duplicate_rate_groups']
            : [];
        $this->log_duplicate_rate_groups($order, $duplicate_rate_groups);
        ShipStationOrderMeta::save_pending_rates(
            $order,
            $shipment,
            $shipment_hash,
            $normalized['rates'],
            $normalized['invalid_rates'],
            (string) ($normalized['shipment_id'] ?? ''),
            (string) ($response['_fflhub_request_id'] ?? ''),
            $package_items,
            $duplicate_rate_groups
        );

        $result = [
            'shipment_hash' => $shipment_hash,
            'shipment_id' => (string) ($normalized['shipment_id'] ?? ''),
            'request_id' => (string) ($response['_fflhub_request_id'] ?? ''),
            'rates' => $normalized['rates'],
            'invalid_rates' => $normalized['invalid_rates'],
            'duplicate_rate_groups' => $duplicate_rate_groups,
            'shipment' => $shipment,
            'package_items' => $package_items,
        ];

        /**
         * Last chance to decorate normalized results for admin display.
         *
         * @param array<string,mixed> $result
         * @param WC_Order $order
         * @param array<string,mixed> $context
         */
        return apply_filters('fflhub_shipstation_rate_results', $result, $order, $context);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    public function purchase_label(WC_Order $order, array $input)
    {
        $rate_id = sanitize_text_field((string) ($input['rate_id'] ?? ''));
        $shipment_hash = sanitize_text_field((string) ($input['shipment_hash'] ?? ''));
        if ($rate_id === '' || $shipment_hash === '') {
            return new WP_Error('fflhub_shipstation_missing_rate', 'Select a current ShipStation rate first.', ['status' => 400]);
        }

        $context = $this->build_context($order);
        if (is_wp_error($context)) {
            return $context;
        }

        $shipment_input = isset($input['shipment']) && is_array($input['shipment']) ? $input['shipment'] : $input;
        $current_shipment = $this->shipment_from_input($order, $context, $shipment_input);
        if (is_wp_error($current_shipment)) {
            return $current_shipment;
        }

        if (!hash_equals($shipment_hash, self::shipment_hash($current_shipment))) {
            return new WP_Error(
                'fflhub_shipstation_stale_shipment',
                'The shipment changed after rates were retrieved. Refresh rates before buying a label.',
                ['status' => 409]
            );
        }

        if (!ShipStationOrderMeta::acquire_purchase_lock($order)) {
            return new WP_Error('fflhub_shipstation_purchase_locked', 'A label purchase is already in progress for this order.', ['status' => 409]);
        }

        try {
            if (ShipStationOrderMeta::has_active_label($order)) {
                return new WP_Error(
                    'fflhub_shipstation_active_label_exists',
                    'This order already has an active FFL Hub ShipStation label. Void it before buying another.',
                    ['status' => 409]
                );
            }

            $pending = ShipStationOrderMeta::pending_rates($order);
            $rated = ShipStationOrderMeta::pending_rate($order, $rate_id, $shipment_hash);
            $package_items_source = null;
            if (is_array($shipment_input) && array_key_exists('package_items', $shipment_input)) {
                $package_items_source = $shipment_input['package_items'];
            } elseif (array_key_exists('package_items', $input)) {
                $package_items_source = $input['package_items'];
            }
            if ($package_items_source !== null) {
                $pending['package_items'] = self::package_item_assignments_from_input(
                    $package_items_source,
                    isset($context['order_items']) && is_array($context['order_items']) ? $context['order_items'] : []
                );
            }
            if (!is_array($rated)) {
                $selected_rate = isset($input['selected_rate']) && is_array($input['selected_rate']) ? $input['selected_rate'] : [];
                if ((string) ($selected_rate['rate_id'] ?? '') !== $rate_id) {
                    return new WP_Error(
                        'fflhub_shipstation_stale_rate',
                        'That rate is stale. Refresh rates before buying a label.',
                        ['status' => 409]
                    );
                }

                // Woo order meta can be unavailable between the rate request and label purchase on some admin flows.
                // The current shipment hash was already rechecked above, so this fallback only preserves label metadata;
                // ShipStation still validates the selected rate_id when purchasing the label.
                $rated = $selected_rate;
                $pending = [
                    'shipment_hash' => $shipment_hash,
                    'shipment_snapshot' => $current_shipment,
                    'shipment_id' => '',
                    'rate_request_id' => '',
                    'rates' => [$selected_rate],
                    'invalid_rates' => [],
                    'package_items' => $pending['package_items'] ?? [],
                ];
            }

            $payload = [
                'test_label' => ShipStationOptions::mode() === 'sandbox',
                'label_format' => ShipStationOptions::label_format(),
                'label_layout' => ShipStationOptions::label_layout(),
                'label_download_type' => 'url',
                'validate_address' => 'no_validation',
                'display_scheme' => 'label',
            ];

            $response = $this->provider->purchase_label_from_rate($rate_id, $payload);
            if (is_wp_error($response)) {
                return $response;
            }

            $label = ShipStationOrderMeta::normalize_purchased_label(
                $response,
                $rated,
                $pending,
                $rate_id,
                (string) ($response['_fflhub_request_id'] ?? '')
            );
            ShipStationOrderMeta::append_label($order, $label);

            $this->add_purchase_note($order, $label);
            $this->maybe_update_status($order);
            OrderProfitAuditMeta::recalculate_order($order, true);

            return [
                'label' => self::public_label($label),
                'labels' => array_map([self::class, 'public_label'], ShipStationOrderMeta::labels($order)),
            ];
        } finally {
            ShipStationOrderMeta::release_purchase_lock($order);
        }
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function void_label(WC_Order $order, string $label_id)
    {
        $label_id = sanitize_text_field($label_id);
        if ($label_id === '') {
            return new WP_Error('fflhub_shipstation_missing_label', 'Missing label ID.', ['status' => 400]);
        }

        if (!ShipStationOrderMeta::acquire_purchase_lock($order)) {
            return new WP_Error('fflhub_shipstation_purchase_locked', 'Another label action is already in progress for this order.', ['status' => 409]);
        }

        try {
            $label = ShipStationOrderMeta::find_label($order, $label_id);
            if (!is_array($label)) {
                return new WP_Error('fflhub_shipstation_label_not_found', 'Could not find that label on this order.', ['status' => 404]);
            }

            if (!ShipStationOrderMeta::label_is_active($label)) {
                return new WP_Error('fflhub_shipstation_label_not_active', 'That label is already voided or inactive.', ['status' => 409]);
            }

            $response = $this->provider->void_label($label_id);
            if (is_wp_error($response)) {
                return $response;
            }

            ShipStationOrderMeta::mark_voided($order, $label_id, $response);
            $order->add_order_note(sprintf('FFL Hub ShipStation label %s voided.', $label_id));
            OrderProfitAuditMeta::recalculate_order($order, true);

            return [
                'void_response' => $response,
                'labels' => array_map([self::class, 'public_label'], ShipStationOrderMeta::labels($order)),
            ];
        } finally {
            ShipStationOrderMeta::release_purchase_lock($order);
        }
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(WC_Order $order, string $label_id)
    {
        $label = ShipStationOrderMeta::find_label($order, sanitize_text_field($label_id));
        if (!is_array($label)) {
            return new WP_Error('fflhub_shipstation_label_not_found', 'Could not find that label on this order.', ['status' => 404]);
        }

        $url = (string) ($label['label_url'] ?? '');
        if ($url === '') {
            return new WP_Error('fflhub_shipstation_label_url_missing', 'The saved label has no download URL.', ['status' => 404]);
        }

        return $this->provider->download_label($url);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|WP_Error
     */
    private function shipment_from_input(WC_Order $order, array $context, array $input)
    {
        $origin = self::address_from_input($context['origin'] ?? []);
        $destination = self::address_from_input($input['destination'] ?? $context['destination'] ?? []);
        $packages = self::packages_from_input($input['packages'] ?? $context['packages'] ?? []);
        $confirmation = self::choice((string) ($input['confirmation'] ?? ShipStationOptions::confirmation()), [
            'none',
            'delivery',
            'signature',
            'adult_signature',
            'direct_signature',
        ], ShipStationOptions::confirmation());

        $ship_date = sanitize_text_field((string) ($input['ship_date'] ?? gmdate('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ship_date)) {
            $ship_date = gmdate('Y-m-d');
        }

        $errors = array_merge(
            $this->missing_address_fields($origin, 'Ship-from'),
            $this->missing_address_fields($destination, 'Ship-to'),
            $this->package_validation_errors($packages),
            self::package_assignment_validation_errors(
                $input,
                isset($context['order_items']) && is_array($context['order_items']) ? $context['order_items'] : []
            )
        );
        if (!empty($errors)) {
            return new WP_Error('fflhub_shipstation_shipment_invalid', implode(' ', $errors), ['status' => 400]);
        }

        $shipment = [
            'validate_address' => 'no_validation',
            'external_order_id' => (string) $order->get_id(),
            'external_shipment_id' => self::external_shipment_id($order),
            'ship_date' => $ship_date . 'T00:00:00Z',
            'ship_from' => $origin,
            'ship_to' => $destination,
            'return_to' => $origin,
            'confirmation' => $confirmation,
            'insurance_provider' => ShipStationOptions::insurance_mode() === 'declared_value' ? 'carrier' : 'none',
            'packages' => $packages,
        ];

        if (!empty($context['requires_ffl']) && is_array($context['receiving_ffl'] ?? null)) {
            $shipment['advanced_options'] = [
                'custom_field1' => 'FFL shipment',
                'custom_field2' => (string) ($context['receiving_ffl']['ffl_number'] ?? ''),
            ];
        }

        return $shipment;
    }

    /**
     * @return string[]
     */
    private function eligible_carrier_ids(WC_Order $order, bool $requires_ffl): array
    {
        $ids = [];
        foreach ($this->carrier_cache->eligible_carriers($requires_ffl) as $carrier) {
            $carrier_id = (string) ($carrier['carrier_id'] ?? '');
            if ($carrier_id !== '') {
                $ids[] = $carrier_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function order_requires_ffl(WC_Order $order): bool
    {
        if (trim((string) $order->get_meta('fflhub_receiving_ffl_number', true)) !== '') {
            return true;
        }

        if (self::truthy($order->get_meta('_fflhub_requires_ffl', true))) {
            return true;
        }

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if ($product instanceof WC_Product && ProductStateStore::get_ffl_required_for_product($product)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function receiving_ffl_snapshot(WC_Order $order): ?array
    {
        $structured = $order->get_meta('fflhub_receiving_ffl', true);
        if (is_array($structured) && !empty($structured)) {
            return $structured;
        }

        $ffl_number = FFLRowMapper::normalize_ffl_number((string) $order->get_meta('fflhub_receiving_ffl_number', true));
        if ($ffl_number === '') {
            return null;
        }

        return FFLRepository::find_by_number($this->ffl_table, $ffl_number);
    }

    /**
     * @param array<string,mixed>|null $ffl
     * @return array<string,mixed>
     */
    private function destination_from_ffl(?array $ffl): array
    {
        $premise = isset($ffl['premise']) && is_array($ffl['premise']) ? $ffl['premise'] : [];

        return [
            'name' => (string) ($ffl['name'] ?? 'Receiving FFL'),
            'phone' => (string) ($ffl['phone'] ?? ''),
            'email' => '',
            'company_name' => (string) ($ffl['name'] ?? ''),
            'address_line1' => (string) ($premise['street'] ?? ''),
            'address_line2' => '',
            'address_line3' => '',
            'city_locality' => (string) ($premise['city'] ?? ''),
            'state_province' => (string) ($premise['state'] ?? ''),
            'postal_code' => (string) ($premise['zip'] ?? ''),
            'country_code' => 'US',
            'address_residential_indicator' => 'no',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function destination_from_order(WC_Order $order): array
    {
        $has_shipping = trim((string) $order->get_shipping_address_1()) !== '';

        return [
            'name' => trim(($has_shipping ? $order->get_shipping_first_name() : $order->get_billing_first_name()) . ' ' . ($has_shipping ? $order->get_shipping_last_name() : $order->get_billing_last_name())),
            'phone' => (string) ($order->get_shipping_phone() ?: $order->get_billing_phone()),
            'email' => (string) $order->get_billing_email(),
            'company_name' => (string) ($has_shipping ? $order->get_shipping_company() : $order->get_billing_company()),
            'address_line1' => (string) ($has_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1()),
            'address_line2' => (string) ($has_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2()),
            'address_line3' => '',
            'city_locality' => (string) ($has_shipping ? $order->get_shipping_city() : $order->get_billing_city()),
            'state_province' => (string) ($has_shipping ? $order->get_shipping_state() : $order->get_billing_state()),
            'postal_code' => (string) ($has_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode()),
            'country_code' => (string) (($has_shipping ? $order->get_shipping_country() : $order->get_billing_country()) ?: 'US'),
            'address_residential_indicator' => 'unknown',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function default_packages_from_order(WC_Order $order): array
    {
        $total_oz = 0.0;
        $insured_value = max(0.0, (float) $order->get_total() - (float) $order->get_total_tax());
        $package_items = [];

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!($product instanceof WC_Product)) {
                continue;
            }

            $qty = max(1, (int) $item->get_quantity());
            $measurements = self::shipping_measurements_for_product($product);
            $weight_oz = $measurements['weight_oz'];

            $package_items[] = [
                'item_id' => (int) $item->get_id(),
                'quantity' => $qty,
            ];

            if ($weight_oz !== null) {
                $total_oz += ($weight_oz * $qty);
            }
        }

        return [[
            'package_code' => 'package',
            'weight' => [
                'value' => self::round_decimal($total_oz, 2),
                'unit' => 'ounce',
            ],
            'dimensions' => [
                'unit' => 'inch',
                'length' => '',
                'width' => '',
                'height' => '',
            ],
            'insured_value' => [
                'currency' => get_woocommerce_currency() ? strtolower((string) get_woocommerce_currency()) : 'usd',
                'amount' => ShipStationOptions::insurance_mode() === 'declared_value' ? self::round_decimal($insured_value, 2) : 0,
            ],
            'items' => $package_items,
        ]];
    }

    /**
     * The package editor mirrors WooCommerce Shipping by showing order line
     * items under each package. These rows are for admin clarity and label
     * history only; ShipStation receives only the package dimensions, weight,
     * value, and service choices.
     *
     * @return array<int,array<string,mixed>>
     */
    private function order_items_for_packages(WC_Order $order): array
    {
        $rows = [];
        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            $sku = $product instanceof WC_Product ? (string) $product->get_sku() : '';
            $product_id = $product instanceof WC_Product ? (int) $product->get_id() : (int) $item->get_product_id();
            $measurements = $product instanceof WC_Product
                ? self::shipping_measurements_for_product($product)
                : [
                    'weight_oz' => null,
                    'length_in' => null,
                    'width_in' => null,
                    'height_in' => null,
                ];

            $rows[] = [
                'item_id' => (int) $item->get_id(),
                'product_id' => $product_id,
                'variation_id' => (int) $item->get_variation_id(),
                'name' => (string) $item->get_name(),
                'sku' => $sku,
                'quantity' => max(0, (int) $item->get_quantity()),
                'weight_oz' => $measurements['weight_oz'] !== null ? self::round_decimal((float) $measurements['weight_oz'], 2) : null,
                'length_in' => $measurements['length_in'] !== null ? self::round_decimal((float) $measurements['length_in'], 2) : null,
                'width_in' => $measurements['width_in'] !== null ? self::round_decimal((float) $measurements['width_in'], 2) : null,
                'height_in' => $measurements['height_in'] !== null ? self::round_decimal((float) $measurements['height_in'], 2) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{weight_oz:?float,length_in:?float,width_in:?float,height_in:?float}
     */
    private static function shipping_measurements_for_product(WC_Product $product): array
    {
        $row = ProductStateStore::get_row_for_product($product);

        return [
            'weight_oz' => self::positive_float($row['shipping_weight_oz'] ?? null)
                ?? self::woo_weight_to_ounces((string) $product->get_weight()),
            'length_in' => self::positive_float($row['shipping_length_in'] ?? null)
                ?? self::woo_dimension_to_inches((string) $product->get_length()),
            'width_in' => self::positive_float($row['shipping_width_in'] ?? null)
                ?? self::woo_dimension_to_inches((string) $product->get_width()),
            'height_in' => self::positive_float($row['shipping_height_in'] ?? null)
                ?? self::woo_dimension_to_inches((string) $product->get_height()),
        ];
    }

    /**
     * @param mixed $rates_response
     * @return array{shipment_id:string,rates:array<int,array<string,mixed>>,invalid_rates:array<int,array<string,mixed>>,duplicate_rate_groups:array<int,array<string,mixed>>}
     */
    public function normalize_rate_response(array $rates_response): array
    {
        $rate_response = isset($rates_response['rate_response']) && is_array($rates_response['rate_response'])
            ? $rates_response['rate_response']
            : $rates_response;
        $rates = isset($rate_response['rates']) && is_array($rate_response['rates']) ? $rate_response['rates'] : [];
        $invalid = isset($rate_response['invalid_rates']) && is_array($rate_response['invalid_rates']) ? $rate_response['invalid_rates'] : [];
        $shipment_id = (string) ($rates_response['shipment_id'] ?? $rate_response['shipment_id'] ?? '');

        $normalized_rates = [];
        foreach ($rates as $rate) {
            if (is_array($rate)) {
                $normalized = self::normalize_rate($rate);
                if ((string) ($normalized['rate_id'] ?? '') !== '' && !self::is_excluded_service_rate($normalized)) {
                    $normalized_rates[] = $normalized;
                }
            }
        }
        $duplicate_rate_groups = [];
        $normalized_rates = self::dedupe_equivalent_rates($normalized_rates, $duplicate_rate_groups);

        usort($normalized_rates, static function (array $a, array $b): int {
            $by_total = ((float) ($a['total_amount'] ?? 0)) <=> ((float) ($b['total_amount'] ?? 0));
            if ($by_total !== 0) {
                return $by_total;
            }

            return strcmp((string) ($a['service_type'] ?? ''), (string) ($b['service_type'] ?? ''));
        });

        $invalid_rates = [];
        foreach ($invalid as $rate) {
            if (is_array($rate)) {
                $invalid_rates[] = [
                    'carrier_id' => (string) ($rate['carrier_id'] ?? ''),
                    'carrier_code' => (string) ($rate['carrier_code'] ?? ''),
                    'carrier_nickname' => (string) ($rate['carrier_nickname'] ?? ''),
                    'service_code' => (string) ($rate['service_code'] ?? ''),
                    'service_type' => (string) ($rate['service_type'] ?? ''),
                    'error_messages' => self::string_list($rate['error_messages'] ?? $rate['errors'] ?? []),
                ];
            }
        }

        return [
            'shipment_id' => $shipment_id,
            'rates' => $normalized_rates,
            'invalid_rates' => $invalid_rates,
            'duplicate_rate_groups' => $duplicate_rate_groups,
        ];
    }

    /**
     * @param array<string,mixed> $rate
     * @return array<string,mixed>
     */
    public static function normalize_rate(array $rate): array
    {
        $shipping = self::money_amount($rate['shipping_amount'] ?? null);
        $insurance = self::money_amount($rate['insurance_amount'] ?? null);
        $confirmation = self::money_amount($rate['confirmation_amount'] ?? null);
        $other = self::money_amount($rate['other_amount'] ?? null);
        $total = $shipping + $insurance + $confirmation + $other;

        if ($total <= 0.0) {
            $total = self::money_amount($rate['rate'] ?? $rate['estimated_amount'] ?? null);
        }

        return [
            'rate_id' => (string) ($rate['rate_id'] ?? ''),
            'shipment_id' => (string) ($rate['shipment_id'] ?? ''),
            'carrier_id' => (string) ($rate['carrier_id'] ?? ''),
            'carrier_code' => (string) ($rate['carrier_code'] ?? ''),
            'carrier_nickname' => (string) ($rate['carrier_nickname'] ?? ''),
            'carrier_friendly_name' => (string) ($rate['carrier_friendly_name'] ?? ''),
            'service_code' => (string) ($rate['service_code'] ?? ''),
            'service_type' => (string) ($rate['service_type'] ?? ''),
            'package_type' => (string) ($rate['package_type'] ?? ''),
            'shipping_amount' => self::round_decimal($shipping, 4),
            'insurance_amount' => self::round_decimal($insurance, 4),
            'confirmation_amount' => self::round_decimal($confirmation, 4),
            'other_amount' => self::round_decimal($other, 4),
            'total_amount' => self::round_decimal($total, 4),
            'currency' => (string) (($rate['shipping_amount']['currency'] ?? null) ?: 'usd'),
            'delivery_days' => isset($rate['delivery_days']) ? (int) $rate['delivery_days'] : null,
            'estimated_delivery_date' => (string) ($rate['estimated_delivery_date'] ?? ''),
            'guaranteed_service' => !empty($rate['guaranteed_service']),
            'trackable' => !empty($rate['trackable']),
            'warning_messages' => self::string_list($rate['warning_messages'] ?? []),
            'raw' => $rate,
        ];
    }

    /**
     * ShipStation can return multiple rate IDs that are operationally the same
     * choice: same carrier account, service, package type, transit, and price.
     * Keep the first rate ID so label purchase still has an exact ShipStation
     * rate to buy, but do not make the admin pick between duplicate cards.
     *
     * @param array<int,array<string,mixed>> $rates
     * @param array<int,array<string,mixed>> $duplicate_groups
     * @return array<int,array<string,mixed>>
     */
    private static function dedupe_equivalent_rates(array $rates, array &$duplicate_groups = []): array
    {
        $seen = [];
        $unique = [];
        $duplicate_groups = [];

        foreach ($rates as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            $key = self::equivalent_rate_key($rate);
            if (isset($seen[$key])) {
                $group_index = $seen[$key]['group_index'];
                if ($group_index === null) {
                    $group_index = count($duplicate_groups);
                    $seen[$key]['group_index'] = $group_index;
                    $duplicate_groups[$group_index] = self::duplicate_rate_group_seed($seen[$key]['rate']);
                }
                $duplicate_groups[$group_index]['duplicate_rate_ids'][] = (string) ($rate['rate_id'] ?? '');
                $duplicate_groups[$group_index]['duplicates'][] = self::duplicate_rate_summary($rate, $seen[$key]['rate']);
                continue;
            }

            $seen[$key] = [
                'rate' => $rate,
                'group_index' => null,
            ];
            $unique[] = $rate;
        }

        return $unique;
    }

    /**
     * @param array<string,mixed> $rate
     * @return array<string,mixed>
     */
    private static function duplicate_rate_group_seed(array $rate): array
    {
        return [
            'kept_rate_id' => (string) ($rate['rate_id'] ?? ''),
            'duplicate_rate_ids' => [],
            'carrier_id' => (string) ($rate['carrier_id'] ?? ''),
            'carrier_code' => (string) ($rate['carrier_code'] ?? ''),
            'carrier_nickname' => (string) ($rate['carrier_nickname'] ?? ''),
            'carrier_friendly_name' => (string) ($rate['carrier_friendly_name'] ?? ''),
            'service_code' => (string) ($rate['service_code'] ?? ''),
            'service_type' => (string) ($rate['service_type'] ?? ''),
            'package_type' => (string) ($rate['package_type'] ?? ''),
            'total_amount' => (string) ($rate['total_amount'] ?? ''),
            'shipping_amount' => (string) ($rate['shipping_amount'] ?? ''),
            'delivery_days' => $rate['delivery_days'] ?? null,
            'estimated_delivery_date' => (string) ($rate['estimated_delivery_date'] ?? ''),
            'duplicates' => [],
        ];
    }

    /**
     * @param array<string,mixed> $duplicate
     * @param array<string,mixed> $kept
     * @return array<string,mixed>
     */
    private static function duplicate_rate_summary(array $duplicate, array $kept): array
    {
        return [
            'rate_id' => (string) ($duplicate['rate_id'] ?? ''),
            'raw_differences' => self::raw_rate_differences($kept, $duplicate),
        ];
    }

    /**
     * @param array<string,mixed> $kept
     * @param array<string,mixed> $duplicate
     * @return array<int,array<string,string>>
     */
    private static function raw_rate_differences(array $kept, array $duplicate): array
    {
        $kept_raw = is_array($kept['raw'] ?? null) ? $kept['raw'] : [];
        $duplicate_raw = is_array($duplicate['raw'] ?? null) ? $duplicate['raw'] : [];
        if (empty($kept_raw) && empty($duplicate_raw)) {
            return [];
        }

        $keys = array_values(array_unique(array_merge(array_keys($kept_raw), array_keys($duplicate_raw))));
        sort($keys);

        $diffs = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $kept_value = $kept_raw[$key] ?? null;
            $duplicate_value = $duplicate_raw[$key] ?? null;
            if (wp_json_encode($kept_value) === wp_json_encode($duplicate_value)) {
                continue;
            }

            $diffs[] = [
                'field' => $key,
                'kept' => self::debug_value($kept_value),
                'duplicate' => self::debug_value($duplicate_value),
            ];

            if (count($diffs) >= 20) {
                break;
            }
        }

        return $diffs;
    }

    /**
     * @param mixed $value
     */
    private static function debug_value($value): string
    {
        if (is_scalar($value) || $value === null) {
            return substr((string) $value, 0, 160);
        }

        if (is_array($value)) {
            return '[array:' . count($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }

    /**
     * @param array<string,mixed> $rate
     */
    private static function equivalent_rate_key(array $rate): string
    {
        $warnings = $rate['warning_messages'] ?? [];
        if (!is_array($warnings)) {
            $warnings = [$warnings];
        }
        $warnings = array_values(array_map('strval', $warnings));
        sort($warnings);

        return (string) wp_json_encode([
            'carrier_id' => (string) ($rate['carrier_id'] ?? ''),
            'carrier_code' => (string) ($rate['carrier_code'] ?? ''),
            'carrier_nickname' => (string) ($rate['carrier_nickname'] ?? ''),
            'carrier_friendly_name' => (string) ($rate['carrier_friendly_name'] ?? ''),
            'service_code' => (string) ($rate['service_code'] ?? ''),
            'service_type' => (string) ($rate['service_type'] ?? ''),
            'package_type' => (string) ($rate['package_type'] ?? ''),
            'shipping_amount' => (string) ($rate['shipping_amount'] ?? ''),
            'insurance_amount' => (string) ($rate['insurance_amount'] ?? ''),
            'confirmation_amount' => (string) ($rate['confirmation_amount'] ?? ''),
            'other_amount' => (string) ($rate['other_amount'] ?? ''),
            'total_amount' => (string) ($rate['total_amount'] ?? ''),
            'currency' => (string) ($rate['currency'] ?? ''),
            'delivery_days' => $rate['delivery_days'] ?? null,
            'estimated_delivery_date' => (string) ($rate['estimated_delivery_date'] ?? ''),
            'warning_messages' => $warnings,
        ]);
    }

    /**
     * @param array<string,mixed> $rate
     */
    private static function is_excluded_service_rate(array $rate): bool
    {
        return ShippingOptions::rate_service_is_banned($rate);
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function address_from_input($value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'name' => sanitize_text_field((string) ($value['name'] ?? '')),
            'phone' => sanitize_text_field((string) ($value['phone'] ?? '')),
            'email' => sanitize_email((string) ($value['email'] ?? '')),
            'company_name' => sanitize_text_field((string) ($value['company_name'] ?? '')),
            'address_line1' => sanitize_text_field((string) ($value['address_line1'] ?? '')),
            'address_line2' => sanitize_text_field((string) ($value['address_line2'] ?? '')),
            'address_line3' => sanitize_text_field((string) ($value['address_line3'] ?? '')),
            'city_locality' => sanitize_text_field((string) ($value['city_locality'] ?? '')),
            'state_province' => strtoupper(sanitize_text_field((string) ($value['state_province'] ?? ''))),
            'postal_code' => sanitize_text_field((string) ($value['postal_code'] ?? '')),
            'country_code' => strtoupper(sanitize_text_field((string) ($value['country_code'] ?? 'US'))),
            'address_residential_indicator' => self::choice(
                (string) ($value['address_residential_indicator'] ?? 'unknown'),
                ['unknown', 'yes', 'no'],
                'unknown'
            ),
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private static function address_candidate_from_validation(array $entry, array $fallback): array
    {
        foreach (['matched_address', 'normalized_address', 'recommended_address', 'address'] as $key) {
            if (isset($entry[$key]) && is_array($entry[$key])) {
                return self::address_from_input(array_merge($fallback, $entry[$key]));
            }
        }

        $has_address_fields = false;
        foreach (['address_line1', 'city_locality', 'state_province', 'postal_code'] as $key) {
            if (trim((string) ($entry[$key] ?? '')) !== '') {
                $has_address_fields = true;
                break;
            }
        }

        return $has_address_fields ? self::address_from_input(array_merge($fallback, $entry)) : $fallback;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function validation_messages($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $messages = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $message = trim((string) ($entry['message'] ?? $entry['detail'] ?? $entry['code'] ?? ''));
                if ($message !== '') {
                    $messages[] = $message;
                }
                continue;
            }

            $message = trim((string) $entry);
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param mixed $input
     * @return array<int,array<string,mixed>>
     */
    private static function packages_from_input($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        $packages = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                continue;
            }

            $weight = is_array($row['weight'] ?? null) ? $row['weight'] : [];
            $dims = is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [];
            $insured = is_array($row['insured_value'] ?? null) ? $row['insured_value'] : [];

            $packages[] = [
                'package_code' => sanitize_text_field((string) ($row['package_code'] ?? 'package')),
                'weight' => [
                    'value' => self::round_decimal(max(0.0, (float) ($weight['value'] ?? 0)), 2),
                    'unit' => self::choice((string) ($weight['unit'] ?? 'ounce'), ['ounce', 'pound', 'gram', 'kilogram'], 'ounce'),
                ],
                'dimensions' => [
                    'unit' => self::choice((string) ($dims['unit'] ?? 'inch'), ['inch', 'centimeter'], 'inch'),
                    'length' => self::round_decimal(max(0.0, (float) ($dims['length'] ?? 0)), 2),
                    'width' => self::round_decimal(max(0.0, (float) ($dims['width'] ?? 0)), 2),
                    'height' => self::round_decimal(max(0.0, (float) ($dims['height'] ?? 0)), 2),
                ],
                'insured_value' => [
                    'currency' => strtolower(sanitize_text_field((string) ($insured['currency'] ?? 'usd'))),
                    'amount' => self::round_decimal(max(0.0, (float) ($insured['amount'] ?? 0)), 2),
                ],
            ];
        }

        return $packages;
    }

    /**
     * @param mixed $input
     * @param array<int,array<string,mixed>> $order_items
     * @return array<int,array<int,array{item_id:int,quantity:int}>>
     */
    private static function package_item_assignments_from_input($input, array $order_items): array
    {
        if (!is_array($input)) {
            return [];
        }

        $allowed_item_ids = [];
        foreach ($order_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_id = absint($item['item_id'] ?? 0);
            if ($item_id > 0) {
                $allowed_item_ids[$item_id] = max(0, (int) ($item['quantity'] ?? 0));
            }
        }

        $packages = [];
        foreach ($input as $package_rows) {
            $package_items = [];
            if (!is_array($package_rows)) {
                $packages[] = $package_items;
                continue;
            }

            foreach ($package_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $item_id = absint($row['item_id'] ?? 0);
                $quantity = max(0, (int) ($row['quantity'] ?? 0));
                if ($item_id <= 0 || $quantity <= 0 || !isset($allowed_item_ids[$item_id])) {
                    continue;
                }
                $package_items[] = [
                    'item_id' => $item_id,
                    'quantity' => min($quantity, max(1, $allowed_item_ids[$item_id])),
                ];
            }

            $packages[] = $package_items;
        }

        return $packages;
    }

    /**
     * The ShipStation API only needs package weight and dimensions, but our
     * admin workflow should behave like WooCommerce Shipping: every package row
     * represents actual Woo order item quantities. This prevents accidental
     * "empty" package rows from being rated or purchased.
     *
     * @param array<string,mixed> $input
     * @param array<int,array<string,mixed>> $order_items
     * @return string[]
     */
    private static function package_assignment_validation_errors(array $input, array $order_items): array
    {
        if (!array_key_exists('package_items', $input) || empty($order_items)) {
            return [];
        }

        $packages_input = is_array($input['packages'] ?? null) ? $input['packages'] : [];
        $assignments = self::package_item_assignments_from_input($input['package_items'], $order_items);
        $errors = [];

        foreach (array_keys($packages_input) as $index) {
            if (empty($assignments[(int) $index])) {
                $errors[] = 'Package ' . ((int) $index + 1) . ' must have at least one assigned Woo order item.';
            }
        }

        $expected = [];
        foreach ($order_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item_id = absint($item['item_id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }
            $expected[$item_id] = max(0, (int) ($item['quantity'] ?? 0));
        }

        $actual = array_fill_keys(array_keys($expected), 0);
        foreach ($assignments as $package_items) {
            foreach ($package_items as $row) {
                $item_id = absint($row['item_id'] ?? 0);
                if ($item_id > 0 && isset($actual[$item_id])) {
                    $actual[$item_id] += max(0, (int) ($row['quantity'] ?? 0));
                }
            }
        }

        foreach ($expected as $item_id => $quantity) {
            if ((int) ($actual[$item_id] ?? 0) !== (int) $quantity) {
                $errors[] = sprintf(
                    'Order item %d must be assigned exactly %d time(s) across packages; currently assigned %d.',
                    $item_id,
                    (int) $quantity,
                    (int) ($actual[$item_id] ?? 0)
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<int,array<string,mixed>> $duplicate_rate_groups
     */
    private function log_duplicate_rate_groups(WC_Order $order, array $duplicate_rate_groups): void
    {
        if (empty($duplicate_rate_groups)) {
            return;
        }

        $duplicate_count = 0;
        foreach ($duplicate_rate_groups as $group) {
            if (is_array($group)) {
                $duplicate_count += count((array) ($group['duplicate_rate_ids'] ?? []));
            }
        }

        DebugLogUtil::log_if_ctx(
            self::shipstation_rate_debug_enabled(),
            '[ShipStationRates]',
            'duplicate equivalent rates hidden',
            [
                'order_id' => (int) $order->get_id(),
                'group_count' => count($duplicate_rate_groups),
                'duplicate_count' => $duplicate_count,
                'groups' => $duplicate_rate_groups,
            ],
            'FFLHUB_DEBUG_SHIPSTATION'
        );
    }

    private static function shipstation_rate_debug_enabled(): bool
    {
        if (defined('FFLHUB_DEBUG_SHIPSTATION') && (bool) constant('FFLHUB_DEBUG_SHIPSTATION')) {
            return true;
        }

        if (defined('WP_DEBUG') && (bool) constant('WP_DEBUG')) {
            return true;
        }

        return ShipStationOptions::show_debug_fields();
    }

    /**
     * @param array<string,mixed> $address
     * @return string[]
     */
    private function missing_address_fields(array $address, string $label): array
    {
        $errors = [];
        if (trim((string) ($address['name'] ?? '')) === '' && trim((string) ($address['company_name'] ?? '')) === '') {
            $errors[] = "{$label} name or company is required.";
        }

        foreach ([
            'phone' => 'phone',
            'address_line1' => 'address line 1',
            'city_locality' => 'city',
            'state_province' => 'state',
            'postal_code' => 'ZIP/postal code',
            'country_code' => 'country',
        ] as $key => $friendly) {
            if (trim((string) ($address[$key] ?? '')) === '') {
                $errors[] = "{$label} {$friendly} is required.";
            }
        }

        return $errors;
    }

    /**
     * @param array<int,array<string,mixed>> $packages
     * @return string[]
     */
    private function package_validation_errors(array $packages): array
    {
        if (empty($packages)) {
            return ['At least one package is required.'];
        }

        $errors = [];
        foreach ($packages as $index => $package) {
            $label = 'Package ' . ((int) $index + 1);
            $weight = isset($package['weight']) && is_array($package['weight']) ? $package['weight'] : [];
            $dims = isset($package['dimensions']) && is_array($package['dimensions']) ? $package['dimensions'] : [];

            if ((float) ($weight['value'] ?? 0) <= 0.0) {
                $errors[] = "{$label} weight is required.";
            }
            foreach (['length', 'width', 'height'] as $dim) {
                if ((float) ($dims[$dim] ?? 0) <= 0.0) {
                    $errors[] = "{$label} {$dim} is required.";
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $shipment
     */
    public static function shipment_hash(array $shipment): string
    {
        self::ksort_recursive($shipment);
        return hash('sha256', (string) wp_json_encode($shipment));
    }

    /**
     * @param array<string|int,mixed> $value
     */
    private static function ksort_recursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::ksort_recursive($child);
            }
        }
        unset($child);

        ksort($value);
    }

    private static function external_shipment_id(WC_Order $order): string
    {
        $sequence = count(ShipStationOrderMeta::labels($order)) + 1;
        return substr('wc-' . (int) $order->get_id() . '-shipment-' . $sequence, 0, 50);
    }

    /**
     * Keep label document URLs server-side; the admin UI uses a nonce-protected
     * download endpoint keyed by label ID instead.
     *
     * @param array<string,mixed> $label
     * @return array<string,mixed>
     */
    private static function public_label(array $label): array
    {
        unset($label['label_url'], $label['label_download']);
        return $label;
    }

    /**
     * @param array<string,mixed> $label
     */
    private function add_purchase_note(WC_Order $order, array $label): void
    {
        $carrier = trim((string) ($label['carrier_nickname'] ?? $label['carrier_friendly_name'] ?? $label['carrier_code'] ?? 'ShipStation'));
        $service = trim((string) ($label['service_name'] ?? $label['service_code'] ?? ''));
        $tracking = trim((string) ($label['tracking_number'] ?? ''));
        $cost = trim((string) ($label['total_cost'] ?? '0.0000'));

        $parts = ["FFL Hub ShipStation label purchased via {$carrier}"];
        if ($service !== '') {
            $parts[] = "service {$service}";
        }
        if ($tracking !== '') {
            $parts[] = "tracking {$tracking}";
        }
        if ($cost !== '') {
            $parts[] = "cost {$cost}";
        }

        $order->add_order_note(implode(', ', $parts) . '.');
    }

    private function maybe_update_status(WC_Order $order): void
    {
        $status = ShipStationOptions::after_purchase_status();
        if ($status === '') {
            return;
        }

        $order->update_status($status, 'FFL Hub ShipStation label purchased.');
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

    /**
     * @param mixed $value
     */
    private static function positive_float($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;
        return $float > 0.0 ? $float : null;
    }

    private static function woo_weight_to_ounces(string $weight): ?float
    {
        $weight = trim($weight);
        if ($weight === '') {
            return null;
        }

        $value = (float) $weight;
        if ($value <= 0.0) {
            return null;
        }

        $unit = strtolower((string) get_option('woocommerce_weight_unit', 'lbs'));
        if (in_array($unit, ['lbs', 'lb', 'pound', 'pounds'], true)) {
            return $value * 16.0;
        }
        if (in_array($unit, ['oz', 'ounce', 'ounces'], true)) {
            return $value;
        }
        if (in_array($unit, ['kg', 'kilogram', 'kilograms'], true)) {
            return $value * 35.27396195;
        }
        if (in_array($unit, ['g', 'gram', 'grams'], true)) {
            return $value * 0.03527396195;
        }

        return $value;
    }

    private static function woo_dimension_to_inches(string $dimension): ?float
    {
        $dimension = trim($dimension);
        if ($dimension === '') {
            return null;
        }

        $value = (float) $dimension;
        if ($value <= 0.0) {
            return null;
        }

        $unit = strtolower((string) get_option('woocommerce_dimension_unit', 'in'));
        if (in_array($unit, ['in', 'inch', 'inches'], true)) {
            return $value;
        }
        if (in_array($unit, ['cm', 'centimeter', 'centimeters'], true)) {
            return $value * 0.3937007874;
        }
        if (in_array($unit, ['m', 'meter', 'meters'], true)) {
            return $value * 39.37007874;
        }
        if (in_array($unit, ['mm', 'millimeter', 'millimeters'], true)) {
            return $value * 0.03937007874;
        }
        if (in_array($unit, ['yd', 'yard', 'yards'], true)) {
            return $value * 36.0;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function string_list($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $msg = (string) ($item['message'] ?? $item['error'] ?? '');
                if ($msg !== '') {
                    $out[] = $msg;
                }
                continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private static function round_decimal(float $value, int $precision): float
    {
        return (float) number_format($value, $precision, '.', '');
    }

    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * @param string[] $allowed
     */
    private static function choice(string $value, array $allowed, string $default): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
