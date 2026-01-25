<?php

namespace FFLHub\Distributor\Orders;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderPlacementStore
{
    private function __construct() {}

    /* ===================== Pipeline meta ===================== */

    public static function get_pipeline_started(WC_Order $order): bool
    {
        return ((string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED, true) === '1');
    }

    public static function set_pipeline_started(WC_Order $order, bool $started, string $started_at = '', string $started_by = ''): void
    {
        $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED, $started ? '1' : '0');

        if ($started) {
            if ($started_at !== '') $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED_AT, $started_at);
            if ($started_by !== '') $order->update_meta_data(OrderPlacementKeys::META_PIPELINE_STARTED_BY, $started_by);
        }
    }

    public static function get_pipeline_started_at(WC_Order $order): string
    {
        return (string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED_AT, true);
    }

    public static function get_pipeline_started_by(WC_Order $order): string
    {
        return (string) $order->get_meta(OrderPlacementKeys::META_PIPELINE_STARTED_BY, true);
    }

    /* ===================== Jobs index ===================== */

    /** @return string[] */
    public static function get_jobs_index(WC_Order $order): array
    {
        $index_json = (string) $order->get_meta(OrderPlacementKeys::META_JOBS_INDEX, true);
        return self::decode_job_index($index_json);
    }

    /** @param string[] $job_keys */
    public static function set_jobs_index(WC_Order $order, array $job_keys): void
    {
        $job_keys = self::normalize_job_keys($job_keys);
        $order->update_meta_data(OrderPlacementKeys::META_JOBS_INDEX, wp_json_encode($job_keys));
    }

    /**
     * @return array{
     *   safe:string,
     *   status:string,
     *   attempts:string,
     *   created:string,
     *   payload:string,
     *   action_id:string,
     *   last_error:string,
     *   last_codes:string,
     *   next_run_at:string,
     *   last_step:string,
     *   done_at:string,
     *   validate_result:string,
     *   place_result:string
     * }
     */
    public static function job_meta_keys(string $job_key): array
    {
        $safe = OrderPlacementKeys::job_key_for_meta($job_key);

        return [
            'safe'            => $safe,
            'status'          => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_STATUS,
            'attempts'        => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_ATTEMPTS,
            'created'         => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_CREATED,
            'payload'         => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_PAYLOAD,
            'action_id'       => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_ACTION_ID,
            'last_error'      => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_LAST_ERR,
            'last_codes'      => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_LAST_CODES,
            'next_run_at'     => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_NEXT_RUN_AT,
            'last_step'       => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_LAST_STEP,
            'done_at'         => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_DONE_AT,
            'validate_result' => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_VALIDATE_RESULT,
            'place_result'    => OrderPlacementKeys::META_JOB_PREFIX . $safe . OrderPlacementKeys::META_JOB_PLACE_RESULT,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function init_job_meta(WC_Order $order, string $job_key, array $payload): void
    {
        $keys = self::job_meta_keys($job_key);

        if ((string) $order->get_meta($keys['status'], true) === '')   $order->update_meta_data($keys['status'], OrderPlacementKeys::JOB_STATUS_QUEUED);
        if ((string) $order->get_meta($keys['attempts'], true) === '') $order->update_meta_data($keys['attempts'], '0');
        if ((string) $order->get_meta($keys['created'], true) === '')  $order->update_meta_data($keys['created'], gmdate('c'));

        // Optional fields: set if missing
        if ((string) $order->get_meta($keys['last_error'], true) === '')  $order->update_meta_data($keys['last_error'], '');
        if ((string) $order->get_meta($keys['last_step'], true) === '')   $order->update_meta_data($keys['last_step'], '');
        if ((string) $order->get_meta($keys['next_run_at'], true) === '') $order->update_meta_data($keys['next_run_at'], '');
        if ((string) $order->get_meta($keys['last_codes'], true) === '')  $order->update_meta_data($keys['last_codes'], wp_json_encode([]));
        if ((string) $order->get_meta($keys['done_at'], true) === '')     $order->update_meta_data($keys['done_at'], '');

        // Always overwrite payload (source of truth)
        $order->update_meta_data($keys['payload'], wp_json_encode($payload));
    }

    /* ===================== Status / action id / attempts ===================== */

    public static function set_job_status(WC_Order $order, string $job_key, string $status): void
    {
        $keys = self::job_meta_keys($job_key);
        $order->update_meta_data($keys['status'], (string) $status);
    }

    public static function get_job_status(WC_Order $order, string $job_key): string
    {
        $keys = self::job_meta_keys($job_key);
        return (string) $order->get_meta($keys['status'], true);
    }

    public static function set_job_action_id(WC_Order $order, string $job_key, string $action_id): void
    {
        $keys = self::job_meta_keys($job_key);
        $order->update_meta_data($keys['action_id'], (string) $action_id);
    }

    public static function get_job_action_id(WC_Order $order, string $job_key): string
    {
        $keys = self::job_meta_keys($job_key);
        return (string) $order->get_meta($keys['action_id'], true);
    }

    public static function clear_job_action_id(WC_Order $order, string $job_key): void
    {
        self::set_job_action_id($order, $job_key, '');
    }

    public static function increment_job_attempts_and_mark_running(WC_Order $order, string $job_key): int
    {
        $keys = self::job_meta_keys($job_key);
        $attempts = max(0, (int) $order->get_meta($keys['attempts'], true));
        $attempts_next = $attempts + 1;

        $order->update_meta_data($keys['status'], OrderPlacementKeys::JOB_STATUS_RUNNING);
        $order->update_meta_data($keys['attempts'], (string) $attempts_next);

        // We are running now; clear scheduling fields
        $order->update_meta_data($keys['next_run_at'], '');
        $order->update_meta_data($keys['action_id'], '');

        return $attempts_next;
    }

    public static function mark_job_success(WC_Order $order, string $job_key, string $done_at = ''): void
    {
        $keys = self::job_meta_keys($job_key);

        $order->update_meta_data($keys['status'], OrderPlacementKeys::JOB_STATUS_SUCCESS);
        $order->update_meta_data($keys['done_at'], $done_at !== '' ? $done_at : gmdate('c'));

        $order->update_meta_data($keys['last_error'], '');
        $order->update_meta_data($keys['last_codes'], wp_json_encode([]));
        $order->update_meta_data($keys['next_run_at'], '');
        $order->update_meta_data($keys['action_id'], '');
    }

    public static function mark_job_failed(WC_Order $order, string $job_key, string $error_message): void
    {
        $keys = self::job_meta_keys($job_key);

        $order->update_meta_data($keys['status'], OrderPlacementKeys::JOB_STATUS_FAILED);
        $order->update_meta_data($keys['last_error'], (string) $error_message);

        $order->update_meta_data($keys['next_run_at'], '');
    }

    /* ===================== Payload ===================== */

    /** @return array<string,mixed>|null */
    public static function get_job_payload(WC_Order $order, string $job_key): ?array
    {
        $keys = self::job_meta_keys($job_key);
        $payload_json = (string) $order->get_meta($keys['payload'], true);
        if ($payload_json === '') return null;

        $decoded = json_decode($payload_json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ===================== Last error / codes / next run / step ===================== */

    public static function set_job_last_error(WC_Order $order, string $job_key, string $message): void
    {
        $keys = self::job_meta_keys($job_key);
        $order->update_meta_data($keys['last_error'], (string) $message);
    }

    public static function get_job_last_error(WC_Order $order, string $job_key): string
    {
        $keys = self::job_meta_keys($job_key);
        return (string) $order->get_meta($keys['last_error'], true);
    }

    public static function set_job_next_run_at(WC_Order $order, string $job_key, string $next_run_at_iso): void
    {
        $keys = self::job_meta_keys($job_key);
        $order->update_meta_data($keys['next_run_at'], (string) $next_run_at_iso);
    }

    public static function get_job_next_run_at(WC_Order $order, string $job_key): string
    {
        $keys = self::job_meta_keys($job_key);
        return (string) $order->get_meta($keys['next_run_at'], true);
    }

    /** @param string[] $codes */
    public static function set_job_last_error_codes(WC_Order $order, string $job_key, array $codes): void
    {
        $keys = self::job_meta_keys($job_key);
        $codes = self::normalize_codes($codes);
        $order->update_meta_data($keys['last_codes'], wp_json_encode($codes));
    }

    /** @return string[] */
    public static function get_job_last_error_codes(WC_Order $order, string $job_key): array
    {
        $keys = self::job_meta_keys($job_key);
        $json = (string) $order->get_meta($keys['last_codes'], true);
        if ($json === '') return [];

        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];

        return self::normalize_codes($arr);
    }

    public static function set_job_last_step(WC_Order $order, string $job_key, string $step): void
    {
        $keys = self::job_meta_keys($job_key);

        $step = strtolower(trim($step));
        if ($step !== 'validate' && $step !== 'place') $step = '';

        $order->update_meta_data($keys['last_step'], $step);
    }

    public static function get_job_last_step(WC_Order $order, string $job_key): string
    {
        $keys = self::job_meta_keys($job_key);
        return (string) $order->get_meta($keys['last_step'], true);
    }

    /**
     * Mark retry scheduled (Option C state machine).
     *
     * @param string[] $codes
     */
    public static function mark_job_retry_scheduled(
        WC_Order $order,
        string $job_key,
        string $next_run_at_iso,
        string $reason,
        array $codes = [],
        string $step = ''
    ): void {
        self::set_job_status($order, $job_key, OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED);
        self::set_job_last_error($order, $job_key, $reason);
        self::set_job_last_error_codes($order, $job_key, $codes);
        self::set_job_next_run_at($order, $job_key, $next_run_at_iso);
        if ($step !== '') self::set_job_last_step($order, $job_key, $step);

        // We're scheduling a new AS action; clear stale action_id (will be set to the new one)
        self::clear_job_action_id($order, $job_key);
    }

    /* ===================== Snapshots ===================== */

    /** @param array<string,mixed> $snapshot */
    public static function set_job_validation_result(WC_Order $order, string $job_key, array $snapshot): void
    {
        $keys = self::job_meta_keys($job_key);
        $order->update_meta_data($keys['validate_result'], wp_json_encode($snapshot));
    }

    /** @return array<string,mixed>|null */
    public static function get_job_validation_result(WC_Order $order, string $job_key): ?array
    {
        $keys = self::job_meta_keys($job_key);
        $json = (string) $order->get_meta($keys['validate_result'], true);
        if ($json === '') return null;

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $snapshot */
    public static function set_job_place_result(WC_Order $order, string $job_key, array $snapshot): void
    {
        $keys = self::job_meta_keys($job_key);
        $order->update_meta_data($keys['place_result'], wp_json_encode($snapshot));
    }

    /** @return array<string,mixed>|null */
    public static function get_job_place_result(WC_Order $order, string $job_key): ?array
    {
        $keys = self::job_meta_keys($job_key);
        $json = (string) $order->get_meta($keys['place_result'], true);
        if ($json === '') return null;

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ===================== Small helpers ===================== */

    /** @return string[] */
    private static function decode_job_index(string $index_json): array
    {
        $index_json = trim($index_json);
        if ($index_json === '') return [];

        $arr = json_decode($index_json, true);
        if (!is_array($arr)) return [];

        $out = [];
        foreach ($arr as $v) {
            $k = is_string($v) ? trim($v) : '';
            if ($k !== '') $out[] = $k;
        }

        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** @param string[] $job_keys @return string[] */
    private static function normalize_job_keys(array $job_keys): array
    {
        $out = [];
        foreach ($job_keys as $k) {
            $k = is_string($k) ? trim($k) : '';
            if ($k !== '') $out[] = $k;
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** @param string[] $codes @return string[] */
    private static function normalize_codes(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) {
            $c = trim((string) $c);
            if ($c !== '') $out[] = $c;
        }
        $out = array_values(array_unique($out));
        if (count($out) > 25) $out = array_slice($out, 0, 25);
        return $out;
    }
}
