<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipOutdoors;

use FFLHub\Shipping\Providers\ShippingProviderInterface;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ShipOutdoors provider adapter for firearm-capable UPS labels.
 *
 * FFL Hub builds one provider-neutral shipment payload for every label flow.
 * This adapter converts that payload into ShipOutdoors' Gun Shipper schema and
 * normalizes rates/labels back into the same fields used by ShipStation and
 * EasyPost.
 */
final class ShipOutdoorsShippingProvider implements ShippingProviderInterface
{
    private const SERVICE_CODES = [
        1 => 'UPS Next Day Air',
        2 => 'UPS Second Day Air',
        3 => 'UPS Ground',
        9 => 'UPS Next Day Air AM',
        11 => 'UPS Second Day Air AM',
    ];

    private ShipOutdoorsClient $client;

    public function __construct(?ShipOutdoorsClient $client = null)
    {
        $this->client = $client ?? new ShipOutdoorsClient();
    }

    public function id(): string
    {
        return 'shipoutdoors';
    }

    public function label(): string
    {
        return 'ShipOutdoors';
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>|WP_Error
     */
    public function validate_address(array $address)
    {
        $result = $this->client->address_validate(self::shipoutdoors_address($address));
        if (is_wp_error($result)) {
            return $result;
        }

        $validated = [];
        foreach ((array) ($result['addressValidationSuggestions'] ?? $result['address_validation_suggestions'] ?? []) as $row) {
            if (is_array($row)) {
                $validated[] = self::fflhub_address($row);
            }
        }

        return [
            'validated_addresses' => $validated,
            'validation' => $validated[0] ?? self::fflhub_address($result),
            '_fflhub_status' => (int) ($result['_fflhub_status'] ?? 0),
            '_fflhub_request_id' => (string) ($result['_fflhub_request_id'] ?? ''),
            'raw' => $result,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function get_rates(array $payload)
    {
        $request = $this->request_from_payload($payload, null);
        if (is_wp_error($request)) {
            return $request;
        }

        $result = $this->client->get_all_rates($request);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::normalized_rate_response($result, $request);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function purchase_label_from_rate(string $rate_id, array $payload)
    {
        $service_code = self::service_code_from_rate($rate_id, is_array($payload['rated'] ?? null) ? $payload['rated'] : []);
        if ($service_code <= 0) {
            return new WP_Error('fflhub_shipoutdoors_missing_service_code', 'ShipOutdoors label purchase needs a service code from the selected rate.');
        }

        $request = $this->request_from_payload($payload, $service_code);
        if (is_wp_error($request)) {
            return $request;
        }

        $email = ShipOutdoorsOptions::notification_email();
        if ($email !== '') {
            $request['notificationEmail'] = $email;
        }

        $request['returnLabelOriginalSize'] = ShipOutdoorsOptions::return_label_original_size();

        $result = $this->client->submit_shipment($request);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::normalized_label_response($result, $rate_id, is_array($payload['rated'] ?? null) ? $payload['rated'] : []);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function void_label(string $label_id)
    {
        $shipment_id = self::shipment_id_from_label_token($label_id);
        if ($shipment_id === '') {
            return new WP_Error('fflhub_shipoutdoors_void_label_shape', 'ShipOutdoors void needs the saved ShipOutdoors shipment token.');
        }

        return $this->client->void_shipment($shipment_id);
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url)
    {
        return $this->client->download_label($url);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    private function request_from_payload(array $payload, ?int $service_code)
    {
        $shipment = isset($payload['shipment']) && is_array($payload['shipment'])
            ? $payload['shipment']
            : $payload;
        $packages = isset($shipment['packages']) && is_array($shipment['packages']) ? array_values($shipment['packages']) : [];
        if (empty($packages)) {
            return new WP_Error('fflhub_shipoutdoors_missing_packages', 'ShipOutdoors needs at least one package.');
        }

        $package_items = isset($payload['package_items']) && is_array($payload['package_items'])
            ? array_values($payload['package_items'])
            : (isset($shipment['package_items']) && is_array($shipment['package_items']) ? array_values($shipment['package_items']) : []);

        $out = [
            'packages' => [],
            'toAddress' => self::shipoutdoors_address(is_array($shipment['ship_to'] ?? null) ? $shipment['ship_to'] : []),
            'fromAddress' => self::shipoutdoors_address(is_array($shipment['ship_from'] ?? null) ? $shipment['ship_from'] : ShipOutdoorsOptions::origin_address()),
            'residential' => self::is_residential(is_array($shipment['ship_to'] ?? null) ? $shipment['ship_to'] : []),
        ];

        foreach ($packages as $index => $package) {
            if (!is_array($package)) {
                continue;
            }

            $items_for_package = is_array($package_items[$index] ?? null)
                ? $package_items[$index]
                : (is_array($package['items'] ?? null) ? $package['items'] : []);
            $out['packages'][] = self::shipoutdoors_package($package, $items_for_package, $shipment, $index);
        }

        if (empty($out['packages'])) {
            return new WP_Error('fflhub_shipoutdoors_no_valid_packages', 'ShipOutdoors could not build any valid package rows.');
        }

        if ($service_code !== null) {
            $out['serviceCode'] = $service_code;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $package
     * @param array<int,array<string,mixed>> $package_items
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>
     */
    private static function shipoutdoors_package(array $package, array $package_items, array $shipment, int $index): array
    {
        $weight = is_array($package['weight'] ?? null) ? $package['weight'] : [];
        $dims = is_array($package['dimensions'] ?? null) ? $package['dimensions'] : [];
        $insured = is_array($package['insured_value'] ?? null) ? $package['insured_value'] : [];

        $external_order_id = trim((string) ($shipment['external_order_id'] ?? ''));
        $invoice_number = $external_order_id !== '' ? self::invoice_number($external_order_id, $index) : '';

        return array_filter([
            'weight' => self::package_weight_pounds($weight),
            'length' => self::positive_ceiled_dimension($dims['length'] ?? 0),
            'width' => self::positive_ceiled_dimension($dims['width'] ?? 0),
            'height' => self::positive_ceiled_dimension($dims['height'] ?? 0),
            'insuredValue' => max(0, (int) round((float) ($insured['amount'] ?? 0))),
            'signatureType' => self::signature_type((string) ($shipment['confirmation'] ?? ShipOutdoorsOptions::confirmation()), self::package_requires_ffl($package_items)),
            'packageContents' => self::package_contents($package_items),
            'packageType' => 2,
            'additionalInfo' => $external_order_id !== '' ? substr('Order ' . $external_order_id, 0, 50) : '',
            'invoiceNumber' => $invoice_number,
        ], static fn($value): bool => $value !== '' && $value !== null);
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private static function shipoutdoors_address(array $address): array
    {
        $name = trim((string) ($address['name'] ?? ''));
        $company = trim((string) ($address['company_name'] ?? $address['company'] ?? ''));
        if ($name === '') {
            $name = $company;
        }

        return [
            'name' => sanitize_text_field($name),
            'address1' => sanitize_text_field((string) ($address['address_line1'] ?? $address['street1'] ?? '')),
            'address2' => sanitize_text_field((string) ($address['address_line2'] ?? $address['street2'] ?? '')),
            'city' => sanitize_text_field((string) ($address['city_locality'] ?? $address['city'] ?? '')),
            'state' => strtoupper(sanitize_text_field((string) ($address['state_province'] ?? $address['state'] ?? ''))),
            'zipCode' => sanitize_text_field((string) ($address['postal_code'] ?? $address['zip'] ?? $address['zipcode'] ?? '')),
            'phoneNumber' => sanitize_text_field((string) ($address['phone'] ?? $address['phoneNumber'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private static function fflhub_address(array $address): array
    {
        return [
            'name' => (string) ($address['name'] ?? ''),
            'phone' => (string) ($address['phoneNumber'] ?? $address['phone'] ?? ''),
            'company_name' => '',
            'address_line1' => (string) ($address['address1'] ?? ''),
            'address_line2' => (string) ($address['address2'] ?? ''),
            'address_line3' => (string) ($address['address3'] ?? ''),
            'city_locality' => (string) ($address['city'] ?? ''),
            'state_province' => (string) ($address['state'] ?? ''),
            'postal_code' => (string) ($address['zipCode'] ?? $address['zipcode'] ?? ''),
            'country_code' => 'US',
            'address_residential_indicator' => array_key_exists('residential', $address)
                ? (!empty($address['residential']) ? 'yes' : 'no')
                : 'unknown',
        ];
    }

    /**
     * @param array<string,mixed> $weight
     */
    private static function package_weight_pounds(array $weight): float
    {
        $value = max(0.0, (float) ($weight['value'] ?? 0));
        $unit = strtolower(trim((string) ($weight['unit'] ?? 'ounce')));

        if ($unit === 'ounce') {
            $value /= 16.0;
        } elseif ($unit === 'gram') {
            $value /= 453.59237;
        } elseif ($unit === 'kilogram') {
            $value *= 2.2046226218;
        }

        return round(max(0.01, $value), 2);
    }

    /**
     * @param mixed $value
     */
    private static function positive_ceiled_dimension($value): int
    {
        return max(1, (int) ceil(max(0.0, (float) $value)));
    }

    /**
     * @param array<string,mixed> $address
     */
    private static function is_residential(array $address): bool
    {
        return strtolower(trim((string) ($address['address_residential_indicator'] ?? 'unknown'))) === 'yes';
    }

    private static function invoice_number(string $external_order_id, int $index): string
    {
        $invoice_number = preg_replace('/[^A-Za-z0-9]/', '', $external_order_id . 'P' . ($index + 1));
        $invoice_number = is_string($invoice_number) ? $invoice_number : '';

        return substr($invoice_number, 0, 30);
    }

    private static function signature_type(string $confirmation, bool $package_requires_ffl): int
    {
        $confirmation = strtolower(trim($confirmation));

        // ShipOutdoors rejects firearm shipments unless the package requests
        // Adult Signature, so firearm/FFL packages override the shared label
        // confirmation preference.
        if ($package_requires_ffl || $confirmation === 'adult_signature') {
            return 1;
        }
        if ($confirmation === 'signature' || $confirmation === 'direct_signature') {
            return 2;
        }
        if ($confirmation === 'none' || $confirmation === 'delivery') {
            return 4;
        }

        return 5;
    }

    /**
     * @param array<int,array<string,mixed>> $package_items
     */
    private static function package_contents(array $package_items): int
    {
        $package_requires_ffl = self::package_requires_ffl($package_items);
        $text = strtolower(implode(' ', array_map(static function ($row): string {
            return is_array($row)
                ? implode(' ', [
                    (string) ($row['name'] ?? ''),
                    (string) ($row['sku'] ?? ''),
                    (string) ($row['upc'] ?? ''),
                    (string) ($row['category'] ?? ''),
                ])
                : '';
        }, $package_items)));

        if (strpos($text, 'ammo') !== false || strpos($text, 'ammunition') !== false) {
            return 3;
        }

        // Accessory names often include firearm words, e.g. "pistol light".
        // Only send ShipOutdoors firearm package contents when product state
        // says the package actually contains an FFL-required item.
        if (!$package_requires_ffl) {
            return 4;
        }

        if (
            strpos($text, 'pistol') !== false
            || strpos($text, 'handgun') !== false
            || strpos($text, 'revolver') !== false
            || strpos($text, 'derringer') !== false
        ) {
            return 1;
        }

        return 2;
    }

    /**
     * @param array<int,array<string,mixed>> $package_items
     */
    private static function package_requires_ffl(array $package_items): bool
    {
        foreach ($package_items as $row) {
            if (is_array($row) && self::truthy($row['ffl_required'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private static function normalized_rate_response(array $result, array $request): array
    {
        $rates = [];
        $invalid = [];
        foreach ((array) ($result['rates'] ?? []) as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            $service_code = (int) ($rate['serviceCode'] ?? $rate['service_code'] ?? 0);
            if (!isset(self::SERVICE_CODES[$service_code])) {
                $invalid[] = [
                    'carrier_code' => 'UPS',
                    'carrier_nickname' => 'ShipOutdoors UPS',
                    'service_code' => (string) $service_code,
                    'service_type' => (string) ($rate['description'] ?? ''),
                    'error_messages' => ['ShipOutdoors returned a service code FFL Hub does not buy yet.'],
                ];
                continue;
            }

            $rates[] = self::normalized_rate($rate, $request);
        }

        usort($rates, static function (array $a, array $b): int {
            $by_total = ((float) ($a['total_amount'] ?? 0)) <=> ((float) ($b['total_amount'] ?? 0));
            if ($by_total !== 0) {
                return $by_total;
            }

            return strcmp((string) ($a['service_type'] ?? ''), (string) ($b['service_type'] ?? ''));
        });

        return [
            '_fflhub_status' => (int) ($result['_fflhub_status'] ?? 0),
            '_fflhub_request_id' => (string) ($result['_fflhub_request_id'] ?? ''),
            'shipment_id' => 'shipoutdoors-rates-' . md5(wp_json_encode($request) ?: ''),
            'rate_response' => [
                'shipment_id' => 'shipoutdoors-rates-' . md5(wp_json_encode($request) ?: ''),
                'rates' => $rates,
                'invalid_rates' => array_merge($invalid, self::invalid_rate_messages($result)),
            ],
            'raw' => $result,
        ];
    }

    /**
     * @param array<string,mixed> $rate
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private static function normalized_rate(array $rate, array $request): array
    {
        $service_code = (int) ($rate['serviceCode'] ?? $rate['service_code'] ?? 0);
        $price = max(0.0, (float) ($rate['price'] ?? 0));
        $rate_id = 'shipoutdoors|' . $service_code . '|' . md5(wp_json_encode($request) ?: '');

        return [
            'provider_id' => 'shipoutdoors',
            'provider_label' => 'ShipOutdoors',
            'provider_rate_id' => $rate_id,
            'rate_id' => $rate_id,
            'shipment_id' => 'shipoutdoors-rates-' . md5(wp_json_encode($request) ?: ''),
            'carrier_id' => 'shipoutdoors-ups',
            'carrier_code' => 'UPS',
            'carrier_nickname' => 'ShipOutdoors UPS',
            'carrier_friendly_name' => 'UPS',
            'service_code' => (string) $service_code,
            'service_type' => (string) ($rate['description'] ?? self::SERVICE_CODES[$service_code] ?? 'UPS'),
            'package_type' => 'package',
            'shipping_amount' => self::round_decimal($price, 4),
            'insurance_amount' => 0.0,
            'confirmation_amount' => 0.0,
            'other_amount' => 0.0,
            'total_amount' => self::round_decimal($price, 4),
            'currency' => 'usd',
            'delivery_days' => null,
            'estimated_delivery_date' => (string) ($rate['estimatedDeliveryTime'] ?? $rate['estimated_delivery_time'] ?? ''),
            'guaranteed_service' => in_array($service_code, [1, 2, 9, 11], true),
            'trackable' => true,
            'warning_messages' => [],
            'raw' => $rate,
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $rated
     * @return array<string,mixed>
     */
    private static function normalized_label_response(array $result, string $rate_id, array $rated): array
    {
        $packages = [];
        foreach ((array) ($result['packages'] ?? []) as $package) {
            if (is_array($package)) {
                $packages[] = $package;
            }
        }
        $first = $packages[0] ?? [];
        $tracking = (string) ($first['trackingNumber'] ?? $first['tracking_number'] ?? $result['shipmentId'] ?? $result['shipment_id'] ?? '');
        $shipment_id = (string) ($result['shipmentId'] ?? $result['shipment_id'] ?? $tracking);
        $charges = max(0.0, (float) ($result['charges'] ?? $rated['total_amount'] ?? 0));
        $label_format = (string) ($first['labelFormat'] ?? $first['label_format'] ?? 'image/gif');
        $label_body = (string) ($first['label'] ?? '');
        $label_url = self::embedded_label_url($label_body, $label_format);
        $service_code = (string) ($rated['service_code'] ?? self::service_code_from_rate($rate_id, $rated));

        return [
            'provider_id' => 'shipoutdoors',
            'provider_label' => 'ShipOutdoors',
            '_fflhub_status' => (int) ($result['_fflhub_status'] ?? 0),
            '_fflhub_request_id' => (string) ($result['_fflhub_request_id'] ?? ''),
            'label_id' => self::label_token($shipment_id, $tracking),
            'shipment_id' => $shipment_id,
            'rate_id' => $rate_id,
            'carrier_id' => 'shipoutdoors-ups',
            'carrier_code' => 'UPS',
            'service_code' => $service_code,
            'tracking_number' => $tracking,
            'tracking_url' => $tracking !== '' ? 'https://www.ups.com/track?tracknum=' . rawurlencode($tracking) : '',
            'shipment_cost' => [
                'amount' => self::round_decimal($charges, 4),
                'currency' => 'usd',
            ],
            'insurance_cost' => [
                'amount' => 0.0,
                'currency' => 'usd',
            ],
            'label_format' => 'pdf',
            'label_layout' => ShipOutdoorsOptions::label_layout(),
            'label_download' => [
                'pdf' => $label_url,
                'href' => $label_url,
            ],
            'status' => 'purchased',
            'created_at' => current_time('mysql', true),
            'raw' => [
                'shipmentId' => $shipment_id,
                'charges' => $charges,
                'billingWeight' => $result['billingWeight'] ?? null,
                'packages' => array_map(static function (array $package): array {
                    unset($package['label']);
                    return $package;
                }, $packages),
            ],
            'total_cost' => [
                'amount' => self::round_decimal($charges, 4),
                'currency' => 'usd',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private static function invalid_rate_messages(array $result): array
    {
        $out = [];
        foreach ((array) ($result['errors'] ?? []) as $message) {
            $message = trim(is_array($message) ? (string) ($message['message'] ?? $message['detail'] ?? '') : (string) $message);
            if ($message === '') {
                continue;
            }

            $out[] = [
                'carrier_id' => 'shipoutdoors-ups',
                'carrier_code' => 'UPS',
                'carrier_nickname' => 'ShipOutdoors UPS',
                'service_code' => '',
                'service_type' => '',
                'error_messages' => [$message],
            ];
        }

        return $out;
    }

    /**
     * ShipOutdoors does not return a hosted label URL. Store a compact token
     * that the ShipOutdoors client can decode into a 4x6 PDF on demand.
     */
    private static function embedded_label_url(string $label_body, string $content_type): string
    {
        if ($label_body === '') {
            return '';
        }

        $token = wp_json_encode([
            'content_type' => $content_type !== '' ? $content_type : 'image/gif',
            'body_base64' => $label_body,
        ]);

        return 'shipoutdoors-label:' . base64_encode((string) $token);
    }

    /**
     * @param array<string,mixed> $rated
     */
    private static function service_code_from_rate(string $rate_id, array $rated): int
    {
        $service_code = (int) ($rated['service_code'] ?? 0);
        if ($service_code > 0) {
            return $service_code;
        }

        $parts = explode('|', $rate_id);
        return (count($parts) >= 2 && $parts[0] === 'shipoutdoors') ? (int) $parts[1] : 0;
    }

    private static function label_token(string $shipment_id, string $tracking): string
    {
        $shipment_id = str_replace('|', '', sanitize_text_field($shipment_id));
        $tracking = str_replace('|', '', sanitize_text_field($tracking));
        return 'shipoutdoors|' . $shipment_id . '|' . $tracking;
    }

    private static function shipment_id_from_label_token(string $label_id): string
    {
        $parts = explode('|', sanitize_text_field($label_id));
        if (count($parts) < 2 || $parts[0] !== 'shipoutdoors') {
            return '';
        }

        return trim((string) $parts[1]);
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    private static function round_decimal(float $value, int $precision): float
    {
        return round($value, $precision);
    }
}
