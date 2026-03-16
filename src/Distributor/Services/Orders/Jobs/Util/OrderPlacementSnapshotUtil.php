<?php

namespace FFLHub\Distributor\Services\Orders\Jobs\Util;

use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementSnapshotUtil
 *
 * Responsibility:
 * - Build snapshot arrays safe for DB storage (JSON columns).
 * - Normalize/limit codes, messages, details, and lists.
 * - Redact obvious secret-ish fields.
 *
 * No side effects. No DB access.
 *
 * PHP 7 compatible.
 */
final class OrderPlacementSnapshotUtil
{
    private function __construct() {}

    /** @return array<string,mixed> */
    public static function invalid_validate_return_snapshot(array $ctx): array
    {
        return [
            'ok'      => false,
            'code'    => 'INVALID_RESULT',
            'message' => 'validate_order_request() did not return a DistributorOrderValidationResult',
            'codes'   => ['FFLHUB_VALIDATE_INVALID_RETURN'],
            'details' => [],
            'at'      => gmdate('c'),
            'ctx'     => self::normalize_ctx($ctx),
        ];
    }

    /** @return array<string,mixed> */
    public static function invalid_place_return_snapshot(array $ctx): array
    {
        return [
            'ok'      => false,
            'code'    => 'INVALID_RESULT',
            'message' => 'place_order() did not return a DistributorOrderResult',
            'codes'   => ['FFLHUB_PLACE_INVALID_RETURN'],
            'http'    => 0,
            'ext_ids' => [],
            'at'      => gmdate('c'),
            'ctx'     => self::normalize_ctx($ctx),
        ];
    }

    /** @return array<string,mixed> */
    public static function validation_snapshot(DistributorOrderValidationResult $vr, array $ctx): array
    {
        $details = is_array($vr->details) ? $vr->details : [];

        return [
            'ok'      => (bool) $vr->ok,
            'code'    => (string) $vr->code,
            'message' => self::truncate((string) $vr->message, 1200),
            'codes'   => self::normalize_codes(is_array($vr->codes) ? $vr->codes : []),
            'details' => self::normalize_details($details),
            'at'      => gmdate('c'),
            'ctx'     => self::normalize_ctx($ctx),
        ];
    }

    /** @return array<string,mixed> */
    public static function place_snapshot(DistributorOrderResult $or, array $ctx): array
    {
        $details = is_array($or->details) ? $or->details : [];

        return [
            'ok'      => (bool) $or->ok,
            'code'    => (string) $or->code,
            'message' => self::truncate((string) $or->message, 1200),
            'codes'   => self::normalize_codes(is_array($or->codes) ? $or->codes : []),
            'details' => self::normalize_details($details),
            'http'    => isset($or->http_status) ? (int) $or->http_status : 0,
            'ext_ids' => is_array($or->external_order_ids)
                ? OrderPlacementJobsStoreUtil::normalize_external_ids($or->external_order_ids)
                : [],
            'at'      => gmdate('c'),
            'ctx'     => self::normalize_ctx($ctx),
        ];
    }

    // -------------------------
    // Normalizers (private)
    // -------------------------

    /** @param mixed[] $codes @return string[] */
    private static function normalize_codes(array $codes): array
    {
        $out = [];

        foreach ($codes as $c) {
            $s = trim((string) $c);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        $out = array_values(array_unique($out));

        // Safety cap
        if (count($out) > 25) {
            $out = array_slice($out, 0, 25);
        }

        return $out;
    }

    /**
     * Normalize details for storage:
     * - shallow only
     * - cap key count
     * - cap string lengths
     * - redact obvious secret-ish keys
     *
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private static function normalize_details(array $details): array
    {
        $out = [];
        $i = 0;

        foreach ($details as $k => $v) {
            if ($i >= 25) {
                $out['__more__'] = true;
                break;
            }
            $i++;

            $ks = is_string($k) ? $k : (string) $k;
            $k_lc = strtolower($ks);

            if (
                strpos($k_lc, 'password') !== false ||
                strpos($k_lc, 'passwd') !== false ||
                strpos($k_lc, 'token') !== false ||
                strpos($k_lc, 'secret') !== false ||
                strpos($k_lc, 'authorization') !== false ||
                $k_lc === 'auth' ||
                $k_lc === 'creds'
            ) {
                $out[$ks] = '[REDACTED]';
                continue;
            }

            if (is_string($v)) {
                $max = 1200;
                if (
                    strpos($k_lc, 'debug_request_body') !== false ||
                    strpos($k_lc, 'debug_request_xml') !== false
                ) {
                    $max = 20000;
                }

                $out[$ks] = self::truncate($v, $max);
                continue;
            }
            if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
                $out[$ks] = $v;
                continue;
            }
            if (is_array($v)) {
                $out[$ks] = ['__omitted_array__' => true, 'count' => count($v)];
                continue;
            }
            if (is_object($v)) {
                $out[$ks] = 'object:' . get_class($v);
                continue;
            }

            $out[$ks] = self::truncate((string) $v, 300);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function normalize_ctx(array $ctx): array
    {
        // ctx should already be small (job->ctx()). This just guards against accidents.
        $out = [];
        $i = 0;

        foreach ($ctx as $k => $v) {
            if ($i >= 20) {
                $out['__more__'] = true;
                break;
            }
            $i++;

            $ks = is_string($k) ? $k : (string) $k;

            if (is_string($v)) {
                $out[$ks] = self::truncate($v, 250);
                continue;
            }
            if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
                $out[$ks] = $v;
                continue;
            }
            if (is_array($v)) {
                $out[$ks] = (count($v) <= 10) ? $v : array_slice($v, 0, 10);
                continue;
            }
            if (is_object($v)) {
                $out[$ks] = 'object:' . get_class($v);
                continue;
            }

            $out[$ks] = self::truncate((string) $v, 300);
        }

        return $out;
    }

    private static function truncate(string $s, int $max): string
    {
        if ($max <= 0) return '';
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . '…';
    }
}
