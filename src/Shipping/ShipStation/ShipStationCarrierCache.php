<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caches connected ShipStation carrier accounts by API environment.
 */
final class ShipStationCarrierCache
{
    private const TTL_SECONDS = 28800; // 8 hours.

    private ShipStationClient $client;

    public function __construct(?ShipStationClient $client = null)
    {
        $this->client = $client ?? new ShipStationClient();
    }

    /**
     * @return array{carriers:array<int,array<string,mixed>>,fetched_at:int,stale:bool,error?:string}|WP_Error
     */
    public function get(bool $force_refresh = false)
    {
        $cached = ShipStationOptions::get_carrier_cache();
        $fetched_at = (int) ($cached['fetched_at'] ?? 0);
        $is_fresh = $fetched_at > 0 && (time() - $fetched_at) < self::TTL_SECONDS;

        if (!$force_refresh && $is_fresh) {
            return [
                'carriers' => is_array($cached['carriers'] ?? null) ? $cached['carriers'] : [],
                'fetched_at' => $fetched_at,
                'stale' => false,
            ];
        }

        $fresh = $this->refresh();
        if (!is_wp_error($fresh)) {
            return $fresh;
        }

        if (!empty($cached['carriers']) && is_array($cached['carriers'])) {
            return [
                'carriers' => $cached['carriers'],
                'fetched_at' => $fetched_at,
                'stale' => true,
                'error' => $fresh->get_error_message(),
            ];
        }

        return $fresh;
    }

    /**
     * @return array{carriers:array<int,array<string,mixed>>,fetched_at:int,stale:bool}|WP_Error
     */
    public function refresh()
    {
        $all = [];
        $page = 1;
        $pages = 1;

        do {
            $response = $this->client->list_carriers($page, 100, true);
            if (is_wp_error($response)) {
                return $response;
            }

            $rows = isset($response['carriers']) && is_array($response['carriers'])
                ? $response['carriers']
                : [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $all[] = self::normalize_carrier($row);
                }
            }

            $pages = max(1, (int) ($response['pages'] ?? 1));
            $page++;
        } while ($page <= $pages && $page <= 20);

        $cache = [
            'fetched_at' => time(),
            'mode' => ShipStationOptions::mode(),
            'carriers' => $all,
        ];
        ShipStationOptions::set_carrier_cache($cache);

        return [
            'carriers' => $all,
            'fetched_at' => (int) $cache['fetched_at'],
            'stale' => false,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function eligible_carriers(bool $requires_ffl): array
    {
        $cache = $this->get(false);
        if (is_wp_error($cache)) {
            return [];
        }

        $available = [];

        foreach (($cache['carriers'] ?? []) as $carrier) {
            if (!is_array($carrier)) {
                continue;
            }

            $carrier_id = (string) ($carrier['carrier_id'] ?? '');
            if ($carrier_id === '') {
                continue;
            }

            if (!empty($carrier['disabled_by_billing_plan']) || empty($carrier['send_rates'])) {
                continue;
            }

            $status = strtolower((string) ($carrier['connection_status'] ?? ''));
            if ($status !== '' && $status !== 'approved') {
                continue;
            }

            $available[] = $carrier;
        }

        $known_ids = [];
        foreach ($available as $carrier) {
            $known_ids[] = (string) ($carrier['carrier_id'] ?? '');
        }
        $known_ids = array_values(array_filter(array_unique($known_ids)));

        $enabled_ids = self::selection_for_current_cache(ShipStationOptions::enabled_carrier_ids(), $known_ids);
        $firearm_ids = self::selection_for_current_cache(ShipStationOptions::firearm_carrier_ids(), $known_ids);
        $out = [];

        foreach ($available as $carrier) {
            $carrier_id = (string) ($carrier['carrier_id'] ?? '');
            if (!empty($enabled_ids) && !in_array($carrier_id, $enabled_ids, true)) {
                continue;
            }

            if ($requires_ffl) {
                if (!empty($firearm_ids)) {
                    if (!in_array($carrier_id, $firearm_ids, true)) {
                        continue;
                    }
                } else {
                    $code = strtolower((string) ($carrier['carrier_code'] ?? ''));
                    if ($code !== 'usps' && $code !== 'stamps_com') {
                        continue;
                    }
                }
            }

            $out[] = $carrier;
        }

        return $out;
    }

    /**
     * ShipStation carrier IDs are account/environment specific. If every saved
     * selection is stale for the active cache, treat it like no explicit
     * selection instead of filtering all carriers out.
     *
     * @param string[] $selected_ids
     * @param string[] $known_ids
     * @return string[]
     */
    private static function selection_for_current_cache(array $selected_ids, array $known_ids): array
    {
        if (empty($selected_ids) || empty($known_ids)) {
            return $selected_ids;
        }

        $current = array_values(array_intersect($selected_ids, $known_ids));
        return empty($current) ? [] : $current;
    }

    /**
     * @param array<string,mixed> $carrier
     * @return array<string,mixed>
     */
    private static function normalize_carrier(array $carrier): array
    {
        return [
            'carrier_id' => (string) ($carrier['carrier_id'] ?? ''),
            'carrier_code' => (string) ($carrier['carrier_code'] ?? ''),
            'nickname' => (string) ($carrier['nickname'] ?? ''),
            'friendly_name' => (string) ($carrier['friendly_name'] ?? ''),
            'connection_status' => (string) ($carrier['connection_status'] ?? ''),
            'disabled_by_billing_plan' => !empty($carrier['disabled_by_billing_plan']),
            'send_rates' => !array_key_exists('send_rates', $carrier) || !empty($carrier['send_rates']),
            'primary' => !empty($carrier['primary']),
            'has_multi_package_supporting_services' => !empty($carrier['has_multi_package_supporting_services']),
            'supports_label_messages' => !empty($carrier['supports_label_messages']),
            'services' => self::normalize_services($carrier['services'] ?? []),
            'packages' => self::normalize_packages($carrier['packages'] ?? []),
            'options' => is_array($carrier['options'] ?? null) ? $carrier['options'] : [],
        ];
    }

    /**
     * @param mixed $services
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_services($services): array
    {
        if (!is_array($services)) {
            return [];
        }

        $out = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }
            $out[] = [
                'service_code' => (string) ($service['service_code'] ?? ''),
                'name' => (string) ($service['name'] ?? ''),
                'domestic' => !empty($service['domestic']),
                'international' => !empty($service['international']),
                'send_rates' => !array_key_exists('send_rates', $service) || !empty($service['send_rates']),
                'is_multi_package_supported' => !empty($service['is_multi_package_supported']),
            ];
        }

        return $out;
    }

    /**
     * @param mixed $packages
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_packages($packages): array
    {
        if (!is_array($packages)) {
            return [];
        }

        $out = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $out[] = [
                'package_id' => (string) ($package['package_id'] ?? ''),
                'package_code' => (string) ($package['package_code'] ?? ''),
                'name' => (string) ($package['name'] ?? ''),
            ];
        }

        return $out;
    }
}
