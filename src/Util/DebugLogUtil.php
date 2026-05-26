<?php

namespace FFLHub\Util;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight debug logger gated by constants.
 *
 * Usage:
 *   DebugLogUtil::log('FFLHUB_DEBUG_SHIPPING', '[ShippingPoller]', 'message');
 *   DebugLogUtil::log_ctx('FFLHUB_DEBUG_SHIPPING', '[ShippingPoller]', 'message', [
 *       'order_id' => 123,
 *       'job_key'  => 'rsr:0',
 *   ]);
 */
final class DebugLogUtil
{
    private function __construct() {}

    // --------------------------------------------------
    // Public API
    // --------------------------------------------------

    public static function log(string $debug_constant, string $prefix, string $msg): void
    {
        if (!self::debug_enabled($debug_constant)) {
            return;
        }

        self::emit($prefix, $msg, $debug_constant);
    }

    /** @param array<string,mixed> $ctx */
    public static function log_ctx(string $debug_constant, string $prefix, string $msg, array $ctx): void
    {
        if (!self::debug_enabled($debug_constant)) {
            return;
        }

        $ctx_json = self::encode_ctx($ctx);
        self::emit($prefix, $msg . ' ' . $ctx_json, $debug_constant);
    }

    public static function log_if(bool $enabled, string $prefix, string $msg, string $debug_constant = ''): void
    {
        if (!$enabled) {
            return;
        }

        self::emit($prefix, $msg, $debug_constant);
    }

    /** @param array<string,mixed> $ctx */
    public static function log_if_ctx(
        bool $enabled,
        string $prefix,
        string $msg,
        array $ctx,
        string $debug_constant = ''
    ): void {
        if (!$enabled) {
            return;
        }

        $ctx_json = self::encode_ctx($ctx);
        self::emit($prefix, $msg . ' ' . $ctx_json, $debug_constant);
    }

    /**
     * Summarize array shape for logs without dumping huge numeric key lists.
     *
     * @param array<mixed> $data
     * @return array<string,mixed>
     */
    public static function summarize_array_keys(array $data, string $prefix = 'data', int $sample_size = 12): array
    {
        $sample_size = max(0, $sample_size);
        $key_count = count($data);
        $is_list = self::array_is_list_compat($data);

        $summary = [
            $prefix . '_key_count' => $key_count,
            $prefix . '_is_list' => $is_list ? 1 : 0,
        ];

        if (!$is_list && $sample_size > 0) {
            $summary[$prefix . '_keys'] = array_values(array_map(
                'strval',
                array_slice(array_keys($data), 0, $sample_size)
            ));

            if ($key_count > $sample_size) {
                $summary[$prefix . '_keys_truncated'] = 1;
            }
        }

        return $summary;
    }

    // --------------------------------------------------
    // Internals
    // --------------------------------------------------

    private static function debug_enabled(string $debug_constant): bool
    {
        if (!defined($debug_constant)) {
            return false;
        }

        return (bool) constant($debug_constant);
    }

    /** @param array<mixed> $data */
    private static function array_is_list_compat(array $data): bool
    {
        $expected = 0;
        foreach (array_keys($data) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    private static function format_line(string $prefix, string $msg): string
    {
        // UTC timestamp keeps logs sortable across servers
        $ts = gmdate('Y-m-d H:i:s');

        // PID helps when multiple PHP workers are running
        $pid = function_exists('getmypid') ? (int) getmypid() : 0;

        return sprintf('[%s][pid:%d]%s %s', $ts, $pid, $prefix, $msg);
    }

    private static function emit(string $prefix, string $msg, string $debugConstant): void
    {
        $line = self::format_line($prefix, $msg);
        DebugLogFileRouter::write($line, $prefix, $debugConstant);
    }

    /** @param array<string,mixed> $ctx */
    private static function encode_ctx(array $ctx): string
    {
        if (empty($ctx)) {
            return '{}';
        }

        $json = wp_json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '{ctx_encode_failed}';
        }

        return $json;
    }
}
