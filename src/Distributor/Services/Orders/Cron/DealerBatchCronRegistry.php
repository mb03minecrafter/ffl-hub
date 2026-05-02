<?php

namespace FFLHub\Distributor\Services\Orders\Cron;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Models\OrderPlacementJobRow;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central map of distributors that use dealer-batch order placement.
 */
final class DealerBatchCronRegistry
{
    /** @return array<string,string> dist_id => cron hook */
    public static function hooks_by_distributor(): array
    {
        return [
            'rsr'     => RSRDealerBatchCronService::CRON_HOOK,
            'lipseys' => LipseysDealerBatchCronService::CRON_HOOK,
            'orion'   => OrionDealerBatchCronService::CRON_HOOK,
            'zanders' => ZandersDealerBatchCronService::CRON_HOOK,
        ];
    }

    /** @return array<string,string> dist_id => CA relay cron hook */
    public static function ca_relay_hooks_by_distributor(): array
    {
        return [
            'lipseys' => LipseysCaRelayBatchCronService::CRON_HOOK,
            'orion'   => OrionCaRelayBatchCronService::CRON_HOOK,
            'zanders' => ZandersCaRelayBatchCronService::CRON_HOOK,
        ];
    }

    /** @return string[] */
    public static function distributor_ids(): array
    {
        return array_keys(self::hooks_by_distributor());
    }

    /** @return string[] */
    public static function hooks(): array
    {
        return array_values(array_merge(
            array_values(self::hooks_by_distributor()),
            array_values(self::ca_relay_hooks_by_distributor())
        ));
    }

    public static function supports_distributor(string $dist_id): bool
    {
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        return $dist_id !== '' && array_key_exists($dist_id, self::hooks_by_distributor());
    }

    public static function hook_for_distributor(string $dist_id): string
    {
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        $hooks = self::hooks_by_distributor();
        return (string) ($hooks[$dist_id] ?? '');
    }

    public static function ca_relay_hook_for_distributor(string $dist_id): string
    {
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        $hooks = self::ca_relay_hooks_by_distributor();
        return (string) ($hooks[$dist_id] ?? '');
    }

    /** @return string[] */
    public static function ca_relay_distributor_ids(): array
    {
        return array_keys(self::ca_relay_hooks_by_distributor());
    }

    public static function supports_ca_relay_distributor(string $dist_id): bool
    {
        $dist_id = OrderPlacementKeysUtil::normalize_dist_id($dist_id);
        return $dist_id !== '' && array_key_exists($dist_id, self::ca_relay_hooks_by_distributor());
    }

    public static function is_dealer_batch_lane(string $dist_id, string $lane): bool
    {
        return self::supports_distributor($dist_id)
            && OrderPlacementKeysUtil::is_dealer_fulfilled_lane($lane);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function is_ca_relay_batch_payload(string $dist_id, string $lane, array $payload): bool
    {
        if (!self::supports_ca_relay_distributor($dist_id)) {
            return false;
        }

        if (!OrderPlacementKeysUtil::is_direct_ship_non_ffl_lane($lane)) {
            return false;
        }

        $relay = $payload['ca_relay'] ?? null;
        if (!is_array($relay)) {
            return false;
        }

        $enabled = $relay['enabled'] ?? null;
        if (is_bool($enabled)) {
            $enabled_bool = $enabled;
        } elseif (is_numeric($enabled)) {
            $enabled_bool = ((int) $enabled) === 1;
        } else {
            $enabled_bool = in_array(strtolower(trim((string) $enabled)), ['1', 'true', 'yes', 'on'], true);
        }

        $state = strtoupper(trim((string) ($relay['restricted_state'] ?? $relay['original_customer_state'] ?? '')));

        return $enabled_bool && $state === 'CA';
    }

    public static function is_ca_relay_batch_job(OrderPlacementJobRow $job): bool
    {
        return self::is_ca_relay_batch_payload(
            (string) $job->dist_id_norm(),
            (string) $job->lane_norm(),
            $job->payload()
        );
    }
}
