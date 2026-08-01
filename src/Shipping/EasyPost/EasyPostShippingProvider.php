<?php
declare(strict_types=1);

namespace FFLHub\Shipping\EasyPost;

use FFLHub\Shipping\Providers\ShippingProviderInterface;
use FFLHub\Shipping\ShippingOptions;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EasyPost implementation of the provider contract.
 *
 * The rest of FFL Hub uses a ShipStation-shaped shipment payload today. This
 * adapter accepts that shape, converts the address/parcel/options fields to
 * EasyPost's API shape, and normalizes EasyPost responses back to the rate and
 * label fields our admin UI already understands.
 */
final class EasyPostShippingProvider implements ShippingProviderInterface
{
    private EasyPostClient $client;

    public function __construct(?EasyPostClient $client = null)
    {
        $this->client = $client ?? new EasyPostClient();
    }

    public function id(): string
    {
        return 'easypost';
    }

    public function label(): string
    {
        return 'EasyPost';
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>|WP_Error
     */
    public function validate_address(array $address)
    {
        $easypost = self::easypost_address($address);
        $mode = EasyPostOptions::address_verification_mode();
        if ($mode === 'verify') {
            $easypost['verify'] = true;
        } elseif ($mode === 'strict') {
            $easypost['verify_strict'] = true;
        }

        $result = $this->client->create_address($easypost);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'validated_addresses' => [self::fflhub_address($result)],
            'validation' => self::fflhub_address($result),
            'easypost_address_id' => (string) ($result['id'] ?? ''),
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
        $shipment = $this->shipment_from_payload($payload);
        if (is_wp_error($shipment)) {
            return $shipment;
        }

        $result = $this->client->create_shipment($shipment);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::normalized_rate_response($result);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function purchase_label_from_rate(string $rate_id, array $payload)
    {
        $shipment_id = sanitize_text_field((string) ($payload['shipment_id'] ?? ''));
        if ($shipment_id === '') {
            return new WP_Error(
                'fflhub_easypost_missing_shipment_id',
                'EasyPost label purchase needs the shipment ID returned by the rate request.'
            );
        }

        $options = self::label_options($payload);
        $insurance = trim((string) ($payload['insurance'] ?? ''));
        if ($insurance === '') {
            $insurance = '0.00';
        }
        $result = $this->client->buy_shipment($shipment_id, $rate_id, $options, $insurance);
        if (is_wp_error($result)) {
            return $result;
        }

        return self::normalized_label_response($result, $rate_id);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function void_label(string $label_id)
    {
        $parts = explode('|', $label_id);
        if (count($parts) !== 3 || $parts[0] !== 'easypost') {
            return new WP_Error(
                'fflhub_easypost_refund_label_shape',
                'EasyPost refund needs the saved EasyPost label token.'
            );
        }

        return $this->client->refund_tracking_codes($parts[1], [$parts[2]]);
    }

    /**
     * @return array{body:string,content_type:string,filename:string}|WP_Error
     */
    public function download_label(string $url)
    {
        return $this->client->download_label($url);
    }

    /**
     * Normalize a retrieved EasyPost purchased Shipment into the provider label
     * shape consumed by ShipStationOrderMeta::normalize_purchased_label().
     *
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>
     */
    public function normalize_purchased_shipment(array $shipment, string $rate_id): array
    {
        return self::normalized_label_response($shipment, $rate_id);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    private function shipment_from_payload(array $payload)
    {
        $shipment = isset($payload['shipment']) && is_array($payload['shipment'])
            ? $payload['shipment']
            : $payload;

        if (isset($shipment['to_address'], $shipment['from_address'], $shipment['parcel'])) {
            return $shipment;
        }

        $packages = isset($shipment['packages']) && is_array($shipment['packages']) ? $shipment['packages'] : [];
        if (count($packages) !== 1) {
            return new WP_Error(
                'fflhub_easypost_single_package_only',
                'EasyPost shipment adapter expects exactly one package per label request.'
            );
        }

        $package = is_array($packages[0] ?? null) ? $packages[0] : [];
        $out = [
            'to_address' => self::easypost_address(is_array($shipment['ship_to'] ?? null) ? $shipment['ship_to'] : []),
            'from_address' => self::easypost_address(is_array($shipment['ship_from'] ?? null) ? $shipment['ship_from'] : []),
            'return_address' => self::easypost_address(is_array($shipment['return_to'] ?? null) ? $shipment['return_to'] : ($shipment['ship_from'] ?? [])),
            'parcel' => self::easypost_parcel($package),
            'reference' => sanitize_text_field((string) ($shipment['external_shipment_id'] ?? $shipment['external_order_id'] ?? '')),
            'options' => self::shipment_options($shipment),
        ];

        $carrier_accounts = isset($payload['carrier_accounts']) && is_array($payload['carrier_accounts'])
            ? $payload['carrier_accounts']
            : ($shipment['carrier_accounts'] ?? []);
        $carrier_accounts = is_array($carrier_accounts) ? self::string_list($carrier_accounts) : [];
        if (!empty($carrier_accounts)) {
            $out['carrier_accounts'] = $carrier_accounts;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private static function easypost_address(array $address): array
    {
        $residential = strtolower(trim((string) ($address['address_residential_indicator'] ?? '')));

        $out = [
            'name' => sanitize_text_field((string) ($address['name'] ?? '')),
            'company' => sanitize_text_field((string) ($address['company_name'] ?? '')),
            'street1' => sanitize_text_field((string) ($address['address_line1'] ?? '')),
            'street2' => sanitize_text_field((string) ($address['address_line2'] ?? '')),
            'city' => sanitize_text_field((string) ($address['city_locality'] ?? '')),
            'state' => strtoupper(sanitize_text_field((string) ($address['state_province'] ?? ''))),
            'zip' => sanitize_text_field((string) ($address['postal_code'] ?? '')),
            'country' => strtoupper(sanitize_text_field((string) ($address['country_code'] ?? 'US'))),
            'phone' => sanitize_text_field((string) ($address['phone'] ?? '')),
            'email' => sanitize_email((string) ($address['email'] ?? '')),
        ];

        if ($residential === 'yes') {
            $out['residential'] = true;
        } elseif ($residential === 'no') {
            $out['residential'] = false;
        }

        return array_filter($out, static fn($value): bool => $value !== '' && $value !== null);
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private static function fflhub_address(array $address): array
    {
        return [
            'name' => (string) ($address['name'] ?? ''),
            'phone' => (string) ($address['phone'] ?? ''),
            'email' => (string) ($address['email'] ?? ''),
            'company_name' => (string) ($address['company'] ?? ''),
            'address_line1' => (string) ($address['street1'] ?? ''),
            'address_line2' => (string) ($address['street2'] ?? ''),
            'address_line3' => '',
            'city_locality' => (string) ($address['city'] ?? ''),
            'state_province' => (string) ($address['state'] ?? ''),
            'postal_code' => (string) ($address['zip'] ?? ''),
            'country_code' => (string) ($address['country'] ?? 'US'),
            'address_residential_indicator' => array_key_exists('residential', $address)
                ? (!empty($address['residential']) ? 'yes' : 'no')
                : 'unknown',
        ];
    }

    /**
     * @param array<string,mixed> $package
     * @return array<string,mixed>
     */
    private static function easypost_parcel(array $package): array
    {
        $weight = is_array($package['weight'] ?? null) ? $package['weight'] : [];
        $dims = is_array($package['dimensions'] ?? null) ? $package['dimensions'] : [];
        $package_code = self::package_code((string) ($package['package_code'] ?? 'package'));

        $parcel = [
            'weight' => self::decimal((float) ($weight['value'] ?? 0)),
        ];

        if ($package_code !== '') {
            $parcel['predefined_package'] = $package_code;
        } else {
            $parcel['length'] = self::decimal((float) ($dims['length'] ?? 0));
            $parcel['width'] = self::decimal((float) ($dims['width'] ?? 0));
            $parcel['height'] = self::decimal((float) ($dims['height'] ?? 0));
        }

        return array_filter($parcel, static fn($value): bool => $value !== '' && $value !== null);
    }

    private static function package_code(string $package_code): string
    {
        $map = [
            'flat_rate_envelope' => 'FlatRateEnvelope',
            'flat_rate_legal_envelope' => 'FlatRateLegalEnvelope',
            'flat_rate_padded_envelope' => 'FlatRatePaddedEnvelope',
            'small_flat_rate_box' => 'SmallFlatRateBox',
            'medium_flat_rate_box' => 'MediumFlatRateBox',
            'large_flat_rate_box' => 'LargeFlatRateBox',
            'large_envelope_or_flat' => 'Flat',
            'letter' => 'Letter',
            'card' => 'Card',
            'softpack' => 'SoftPack',
        ];

        $key = strtolower(trim(str_replace(['-', ' '], '_', $package_code)));
        return $map[$key] ?? '';
    }

    private static function fflhub_package_code_from_predefined(string $predefined): string
    {
        $map = [
            'FlatRateEnvelope' => 'flat_rate_envelope',
            'FlatRateLegalEnvelope' => 'flat_rate_legal_envelope',
            'FlatRatePaddedEnvelope' => 'flat_rate_padded_envelope',
            'SmallFlatRateBox' => 'small_flat_rate_box',
            'MediumFlatRateBox' => 'medium_flat_rate_box',
            'LargeFlatRateBox' => 'large_flat_rate_box',
            'Flat' => 'large_envelope_or_flat',
            'Letter' => 'letter',
            'Card' => 'card',
            'SoftPack' => 'softpack',
        ];

        return $map[$predefined] ?? $predefined;
    }

    /**
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>
     */
    private static function shipment_options(array $shipment): array
    {
        $options = [
            'label_format' => strtoupper(EasyPostOptions::label_format()),
            'label_size' => EasyPostOptions::label_layout() === '4x6' ? '4x6' : '8.5x11',
            'delivery_confirmation' => self::delivery_confirmation_option((string) ($shipment['confirmation'] ?? EasyPostOptions::confirmation())),
        ];

        if (trim((string) ($shipment['external_order_id'] ?? '')) !== '') {
            $options['print_custom_1'] = substr(sanitize_text_field((string) $shipment['external_order_id']), 0, 30);
        }

        return array_filter($options, static fn($value): bool => $value !== '' && $value !== null);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function label_options(array $payload): array
    {
        $format = strtoupper(sanitize_text_field((string) ($payload['label_format'] ?? EasyPostOptions::label_format())));
        $layout = sanitize_text_field((string) ($payload['label_layout'] ?? EasyPostOptions::label_layout()));

        $confirmation = (string) ($payload['confirmation'] ?? EasyPostOptions::confirmation());
        $rate = is_array($payload['rate'] ?? null) ? $payload['rate'] : [];
        if (self::rate_is_usps($rate)) {
            $confirmation = 'delivery';
        }

        return array_filter([
            'label_format' => in_array($format, ['PDF', 'PNG', 'ZPL', 'EPL2'], true) ? $format : 'PDF',
            'label_size' => $layout === '4x6' ? '4x6' : '8.5x11',
            'delivery_confirmation' => self::delivery_confirmation_option($confirmation),
        ], static fn($value): bool => $value !== '' && $value !== null);
    }

    /**
     * EasyPost USPS labels should never request a signature add-on for FFL Hub.
     * Firearm-capable UPS remains handled by ShipOutdoors; ordinary EasyPost
     * UPS/FedEx rates are hidden for FFL packages before purchase.
     *
     * @param array<string,mixed> $rate
     */
    public static function rate_is_usps(array $rate): bool
    {
        $text = strtolower(implode(' ', [
            (string) ($rate['carrier_code'] ?? ''),
            (string) ($rate['carrier'] ?? ''),
            (string) ($rate['carrier_nickname'] ?? ''),
            (string) ($rate['carrier_friendly_name'] ?? ''),
            (string) ($rate['service_code'] ?? ''),
            (string) ($rate['service_type'] ?? ''),
        ]));

        return strpos($text, 'usps') !== false || strpos($text, 'stamps') !== false;
    }

    public static function delivery_confirmation_option(string $confirmation): string
    {
        $map = [
            'none' => 'NO_SIGNATURE',
            'delivery' => 'NO_SIGNATURE',
            'signature' => 'SIGNATURE',
            'direct_signature' => 'SIGNATURE',
            'adult_signature' => 'ADULT_SIGNATURE',
        ];

        return $map[strtolower(trim($confirmation))] ?? 'NO_SIGNATURE';
    }

    /**
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>
     */
    private static function normalized_rate_response(array $shipment): array
    {
        $rates = [];
        foreach ((array) ($shipment['rates'] ?? []) as $rate) {
            if (is_array($rate)) {
                $normalized = self::normalized_rate($rate, (string) ($shipment['id'] ?? ''));
                if ((string) ($normalized['rate_id'] ?? '') !== '') {
                    $rates[] = $normalized;
                }
            }
        }

        usort($rates, static function (array $a, array $b): int {
            $by_total = ((float) ($a['total_amount'] ?? 0)) <=> ((float) ($b['total_amount'] ?? 0));
            if ($by_total !== 0) {
                return $by_total;
            }

            return strcmp((string) ($a['service_type'] ?? ''), (string) ($b['service_type'] ?? ''));
        });

        return [
            '_fflhub_status' => (int) ($shipment['_fflhub_status'] ?? 0),
            '_fflhub_request_id' => (string) ($shipment['_fflhub_request_id'] ?? ''),
            'shipment_id' => (string) ($shipment['id'] ?? ''),
            'rate_response' => [
                'shipment_id' => (string) ($shipment['id'] ?? ''),
                'rates' => $rates,
                'invalid_rates' => self::invalid_rate_messages($shipment),
            ],
            'raw' => $shipment,
        ];
    }

    /**
     * @param array<string,mixed> $rate
     * @return array<string,mixed>
     */
    private static function normalized_rate(array $rate, string $shipment_id): array
    {
        $shipping = max(0.0, (float) ($rate['rate'] ?? 0));

        return [
            'provider_id' => 'easypost',
            'provider_label' => 'EasyPost',
            'provider_rate_id' => (string) ($rate['id'] ?? ''),
            'rate_id' => (string) ($rate['id'] ?? ''),
            'shipment_id' => (string) ($rate['shipment_id'] ?? $shipment_id),
            'carrier_id' => (string) ($rate['carrier_account_id'] ?? ''),
            'carrier_code' => (string) ($rate['carrier'] ?? ''),
            'carrier_nickname' => (string) ($rate['carrier'] ?? ''),
            'carrier_friendly_name' => (string) ($rate['carrier'] ?? ''),
            'service_code' => (string) ($rate['service'] ?? ''),
            'service_type' => (string) ($rate['service'] ?? ''),
            'package_type' => self::fflhub_package_code_from_predefined((string) ($rate['predefined_package'] ?? '')),
            'shipping_amount' => self::round_decimal($shipping, 4),
            'insurance_amount' => 0.0,
            'confirmation_amount' => 0.0,
            'other_amount' => 0.0,
            'total_amount' => self::round_decimal($shipping, 4),
            'currency' => strtolower((string) ($rate['currency'] ?? 'usd')),
            'delivery_days' => isset($rate['delivery_days']) ? (int) $rate['delivery_days'] : (isset($rate['est_delivery_days']) ? (int) $rate['est_delivery_days'] : null),
            'estimated_delivery_date' => (string) ($rate['delivery_date'] ?? ''),
            'guaranteed_service' => !empty($rate['delivery_date_guaranteed']),
            'trackable' => true,
            'warning_messages' => [],
            'raw' => $rate,
        ];
    }

    /**
     * @param array<string,mixed> $shipment
     * @return array<int,array<string,mixed>>
     */
    private static function invalid_rate_messages(array $shipment): array
    {
        $out = [];
        foreach ((array) ($shipment['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $out[] = [
                'carrier_id' => '',
                'carrier_code' => (string) ($message['carrier'] ?? ''),
                'carrier_nickname' => (string) ($message['carrier'] ?? ''),
                'service_code' => '',
                'service_type' => '',
                'error_messages' => self::string_list($message['message'] ?? $message['text'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $shipment
     * @return array<string,mixed>
     */
    private static function normalized_label_response(array $shipment, string $rate_id): array
    {
        $rate = is_array($shipment['selected_rate'] ?? null) ? $shipment['selected_rate'] : [];
        $postage_label = is_array($shipment['postage_label'] ?? null) ? $shipment['postage_label'] : [];
        $label_format = strtolower((string) ($postage_label['label_file_type'] ?? EasyPostOptions::label_format()));
        if (strpos($label_format, 'pdf') !== false) {
            $label_format = 'pdf';
        } elseif (strpos($label_format, 'zpl') !== false) {
            $label_format = 'zpl';
        } else {
            $label_format = 'png';
        }

        $label_url = (string) ($postage_label['label_pdf_url'] ?? '');
        if ($label_format === 'zpl') {
            $label_url = (string) ($postage_label['label_zpl_url'] ?? $label_url);
        } elseif ($label_format === 'png') {
            $label_url = (string) ($postage_label['label_url'] ?? $label_url);
        }
        if ($label_url === '') {
            $label_url = (string) ($postage_label['label_url'] ?? $postage_label['label_pdf_url'] ?? $postage_label['label_zpl_url'] ?? '');
        }

        $carrier = (string) ($rate['carrier'] ?? '');
        $tracking = (string) ($shipment['tracking_code'] ?? '');
        $shipment_cost = max(0.0, (float) ($rate['rate'] ?? 0));
        $insurance_cost = self::fee_amount($shipment, 'InsuranceFee');
        $total_cost = $shipment_cost + $insurance_cost;

        return [
            'provider_id' => 'easypost',
            'provider_label' => 'EasyPost',
            '_fflhub_status' => (int) ($shipment['_fflhub_status'] ?? 0),
            '_fflhub_request_id' => (string) ($shipment['_fflhub_request_id'] ?? ''),
            'label_id' => self::label_token($carrier, $tracking),
            'shipment_id' => (string) ($shipment['id'] ?? ''),
            'rate_id' => $rate_id,
            'carrier_id' => (string) ($rate['carrier_account_id'] ?? ''),
            'carrier_code' => $carrier,
            'service_code' => (string) ($rate['service'] ?? ''),
            'tracking_number' => $tracking,
            'tracking_url' => is_array($shipment['tracker'] ?? null) ? (string) ($shipment['tracker']['public_url'] ?? '') : '',
            'shipment_cost' => [
                'amount' => self::round_decimal($shipment_cost, 4),
                'currency' => strtolower((string) ($rate['currency'] ?? 'usd')),
            ],
            'insurance_cost' => [
                'amount' => self::round_decimal($insurance_cost, 4),
                'currency' => strtolower((string) ($rate['currency'] ?? 'usd')),
            ],
            'label_format' => $label_format,
            'label_layout' => (string) ($postage_label['label_size'] ?? EasyPostOptions::label_layout()),
            'label_download' => [
                $label_format => $label_url,
                'href' => $label_url,
            ],
            'status' => (string) ($shipment['status'] ?? 'purchased'),
            'created_at' => (string) ($shipment['created_at'] ?? current_time('mysql', true)),
            'raw' => $shipment,
            'total_cost' => [
                'amount' => self::round_decimal($total_cost, 4),
                'currency' => strtolower((string) ($rate['currency'] ?? 'usd')),
            ],
        ];
    }

    private static function label_token(string $carrier, string $tracking): string
    {
        $carrier = str_replace('|', '', sanitize_text_field($carrier));
        $tracking = str_replace('|', '', sanitize_text_field($tracking));
        return 'easypost|' . $carrier . '|' . $tracking;
    }

    /**
     * @param array<string,mixed> $shipment
     */
    private static function fee_amount(array $shipment, string $type): float
    {
        $total = 0.0;
        foreach ((array) ($shipment['fees'] ?? []) as $fee) {
            if (is_array($fee) && (string) ($fee['type'] ?? '') === $type) {
                $total += max(0.0, (float) ($fee['amount'] ?? 0));
            }
        }

        return $total;
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
            $item = sanitize_text_field((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format(max(0.0, $value), 2, '.', ''), '0'), '.');
    }

    private static function round_decimal(float $value, int $precision): float
    {
        return round($value, $precision);
    }
}
