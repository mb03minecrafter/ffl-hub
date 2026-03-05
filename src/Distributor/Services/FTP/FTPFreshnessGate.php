<?php

namespace FFLHub\Distributor\Services\FTP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared freshness gate for FTP-backed cron jobs.
 *
 * Responsibilities:
 * - Skip repeated checks within a hard minimum gap.
 * - Skip FTP connect during a post-apply cooldown window.
 * - Skip download/apply when remote mtime is unchanged.
 * - Defer when remote file size appears unstable (mid-upload).
 */
final class FTPFreshnessGate
{
    /**
     * Evaluate throttle/cooldown gates before opening an FTP connection.
     *
     * @return array{
     *   skip:bool,
     *   status:?string,
     *   log_message:?string,
     *   log_context:array<string,mixed>,
     *   now:int,
     *   last_applied_mtime:int
     * }
     */
    public static function evaluate_pre_connect(
        string $opt_last_checked_at,
        string $opt_last_applied_mtime,
        int $min_check_gap_seconds,
        int $cooldown_seconds,
        bool $force_update = false,
        ?int $now = null
    ): array {
        $now = $now ?? time();

        $last_checked_at = (int) get_option($opt_last_checked_at, 0);
        if (
            !$force_update
            && $last_checked_at > 0
            && ($now - $last_checked_at) < $min_check_gap_seconds
        ) {
            return [
                'skip' => true,
                'status' => 'SUCCESS (throttle; recent check)',
                'log_message' => 'FTP check throttled (recently checked) - skipping connect',
                'log_context' => [
                    'last_checked_at' => $last_checked_at,
                    'age_sec' => (int) ($now - $last_checked_at),
                    'min_gap_sec' => (int) $min_check_gap_seconds,
                ],
                'now' => (int) $now,
                'last_applied_mtime' => 0,
            ];
        }

        $last_applied_mtime = (int) get_option($opt_last_applied_mtime, 0);
        if (
            !$force_update
            && $last_applied_mtime > 0
            && $now < ($last_applied_mtime + $cooldown_seconds)
        ) {
            return [
                'skip' => true,
                'status' => 'SUCCESS (cooldown)',
                'log_message' => 'Cooldown after last applied change - skipping FTP connect',
                'log_context' => [
                    'last_applied_mtime' => $last_applied_mtime,
                    'cooldown_sec' => (int) $cooldown_seconds,
                    'skip_for_sec' => (int) (($last_applied_mtime + $cooldown_seconds) - $now),
                ],
                'now' => (int) $now,
                'last_applied_mtime' => $last_applied_mtime,
            ];
        }

        // Mark that this run is performing an FTP check.
        update_option($opt_last_checked_at, $now, false);

        return [
            'skip' => false,
            'status' => null,
            'log_message' => null,
            'log_context' => [],
            'now' => (int) $now,
            'last_applied_mtime' => (int) $last_applied_mtime,
        ];
    }

    /**
     * Evaluate remote mtime/size freshness after FTP connection.
     *
     * @return array{
     *   skip:bool,
     *   status:?string,
     *   log_message:?string,
     *   log_context:array<string,mixed>,
     *   profile_label:string,
     *   profile_context:array<string,mixed>,
     *   remote_mtime:int,
     *   remote_size:int
     * }
     */
    public static function evaluate_remote_meta(
        FTPClientService $ftp,
        string $remote_path,
        string $opt_last_seen_mtime,
        string $opt_last_seen_size,
        int $last_applied_mtime,
        bool $force_update = false,
        int $size_stability_sleep_us = 250000,
        string $no_change_log_message = 'No update available (remote mtime unchanged) - skipping download/import/swap'
    ): array {
        $remote_mtime = (int) ($ftp->get_remote_mtime($remote_path) ?? 0);
        update_option($opt_last_seen_mtime, $remote_mtime);

        if (
            !$force_update
            && $remote_mtime > 0
            && $remote_mtime <= $last_applied_mtime
        ) {
            return [
                'skip' => true,
                'status' => 'SUCCESS (no change)',
                'log_message' => $no_change_log_message,
                'log_context' => [
                    'remote_mtime' => $remote_mtime,
                    'last_applied_mtime' => $last_applied_mtime,
                ],
                'profile_label' => 'FTP meta check (mtime only)',
                'profile_context' => [
                    'remote_mtime' => $remote_mtime,
                    'last_applied_mtime' => $last_applied_mtime > 0 ? $last_applied_mtime : null,
                    'changed' => 0,
                    'size_checked' => 0,
                ],
                'remote_mtime' => $remote_mtime,
                'remote_size' => -1,
            ];
        }

        $remote_size = (int) ($ftp->get_remote_size($remote_path) ?? -1);
        if ($remote_size >= 0) {
            update_option($opt_last_seen_size, $remote_size);
        }

        $profile_context = [
            'remote_mtime' => $remote_mtime > 0 ? $remote_mtime : null,
            'remote_size_bytes' => $remote_size >= 0 ? $remote_size : null,
            'last_applied_mtime' => $last_applied_mtime > 0 ? $last_applied_mtime : null,
            'changed' => ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime) ? 1 : 0,
            'size_checked' => 1,
        ];

        if ($remote_mtime > 0 && $remote_mtime > $last_applied_mtime && $remote_size >= 0) {
            usleep($size_stability_sleep_us);
            $remote_size2 = (int) ($ftp->get_remote_size($remote_path) ?? -1);
            if ($remote_size2 >= 0 && $remote_size2 !== $remote_size) {
                return [
                    'skip' => true,
                    'status' => 'SUCCESS (defer; unstable remote file)',
                    'log_message' => 'Remote file still changing (size unstable) - deferring',
                    'log_context' => [
                        'size1' => $remote_size,
                        'size2' => $remote_size2,
                        'remote_mtime' => $remote_mtime,
                    ],
                    'profile_label' => 'FTP meta check (mtime/size)',
                    'profile_context' => $profile_context,
                    'remote_mtime' => $remote_mtime,
                    'remote_size' => $remote_size,
                ];
            }
        }

        return [
            'skip' => false,
            'status' => null,
            'log_message' => null,
            'log_context' => [],
            'profile_label' => 'FTP meta check (mtime/size)',
            'profile_context' => $profile_context,
            'remote_mtime' => $remote_mtime,
            'remote_size' => $remote_size,
        ];
    }
}
