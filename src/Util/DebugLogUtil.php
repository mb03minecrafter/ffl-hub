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

        error_log(self::format_line($prefix, $msg));
    }

    /** @param array<string,mixed> $ctx */
    public static function log_ctx(string $debug_constant, string $prefix, string $msg, array $ctx): void
    {
        if (!self::debug_enabled($debug_constant)) {
            return;
        }

        $ctx_json = self::encode_ctx($ctx);
        $line = self::format_line($prefix, $msg . ' ' . $ctx_json);

        error_log($line);
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

    private static function format_line(string $prefix, string $msg): string
    {
        // UTC timestamp keeps logs sortable across servers
        $ts = gmdate('Y-m-d H:i:s');

        // PID helps when multiple PHP workers are running
        $pid = function_exists('getmypid') ? (int) getmypid() : 0;

        return sprintf('[%s][pid:%d]%s %s', $ts, $pid, $prefix, $msg);
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
