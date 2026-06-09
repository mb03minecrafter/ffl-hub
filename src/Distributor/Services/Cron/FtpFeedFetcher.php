<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Distributor\Services\FTP\FTPFreshnessGate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared FTP feed-fetching plumbing for distributor cron jobs.
 *
 * This service deliberately stops at "download the remote feed file." The
 * caller still owns parsing, ZIP extraction, LOAD DATA, table swaps, and all
 * distributor-specific import semantics.
 */
final class FtpFeedFetcher
{
    public function fetch(FtpFeedFetchRequest $request, CronRunLogger $logger): FtpFeedFetchResult
    {
        $credentials = $request->credentials;
        if (!is_array($credentials)) {
            return $this->failed($request, 'missing_credentials', 'Missing FTP credentials', [
                'cron_status' => 'ERROR (missing credentials)',
            ]);
        }

        $pre_gate = FTPFreshnessGate::evaluate_pre_connect(
            $request->last_checked_option,
            $request->last_applied_mtime_option,
            $request->min_check_gap_seconds,
            $request->cooldown_seconds,
            $request->force
        );

        if ((bool) $pre_gate['skip']) {
            $logger->log((string) $pre_gate['log_message'], (array) $pre_gate['log_context']);

            return $this->skipped($request, $this->skip_reason_from_status((string) $pre_gate['status']), [
                'cron_status' => (string) $pre_gate['status'],
                'context' => (array) $pre_gate['log_context'],
            ]);
        }

        $ftp = null;

        try {
            $t_ftp = $logger->now();
            $ftp = new FTPClientService(
                (string) $credentials['host'],
                (string) $credentials['username'],
                (string) $credentials['password'],
                (bool) $credentials['use_ssl'],
                (int) ($credentials['port'] ?? 21),
                $request->timeout_seconds,
                $request->passive,
                $request->ftp_log_prefix,
                $request->use_pasv_address
            );

            if (!$ftp->is_connected()) {
                $this->mark_download_error($request);
                $logger->log('ERROR: FTP connection not available.', [
                    'host' => (string) $credentials['host'],
                    'use_ssl' => (bool) $credentials['use_ssl'] ? 1 : 0,
                    'port' => (int) ($credentials['port'] ?? 21),
                ]);
                $logger->profile('FTP connection (failed)', $t_ftp);

                return $this->failed($request, 'ftp_connect_failed', 'FTP connection failed', [
                    'cron_status' => 'ERROR (FTP connection failed)',
                ]);
            }

            $logger->profile('FTP connection', $t_ftp, [
                'ok' => 1,
                'host' => (string) $credentials['host'],
                'use_ssl' => (bool) $credentials['use_ssl'] ? 1 : 0,
                'port' => (int) ($credentials['port'] ?? 21),
            ]);

            $t_meta = $logger->now();
            $last_applied_mtime = (int) get_option($request->last_applied_mtime_option, 0);
            $meta_gate = FTPFreshnessGate::evaluate_remote_meta(
                $ftp,
                $request->remote_path,
                $request->last_seen_mtime_option,
                $request->last_seen_size_option,
                $last_applied_mtime,
                $request->force,
                $request->size_stability_sleep_us,
                $request->no_change_log_message
            );

            $logger->profile((string) $meta_gate['profile_label'], $t_meta, (array) $meta_gate['profile_context']);

            if ((bool) $meta_gate['skip']) {
                $logger->log((string) $meta_gate['log_message'], (array) $meta_gate['log_context']);

                return $this->skipped($request, $this->skip_reason_from_status((string) $meta_gate['status']), [
                    'cron_status' => (string) $meta_gate['status'],
                    'remote_mtime' => (int) $meta_gate['remote_mtime'],
                    'remote_size' => (int) $meta_gate['remote_size'],
                    'context' => (array) $meta_gate['log_context'],
                ]);
            }

            $t_download = $logger->now();
            $bytes_before = file_exists($request->local_path) ? (int) filesize($request->local_path) : 0;
            $ok = $ftp->download_file($request->remote_path, $request->local_path);
            $bytes_after = file_exists($request->local_path) ? (int) filesize($request->local_path) : 0;

            $logger->profile('FTP download', $t_download, [
                'ok' => $ok ? 1 : 0,
                'bytes_before' => $bytes_before > 0 ? $bytes_before : null,
                'bytes_after' => $bytes_after > 0 ? $bytes_after : null,
                'kb_before' => $bytes_before > 0 ? (int) round($bytes_before / 1024) : 0,
                'kb_after' => $bytes_after > 0 ? (int) round($bytes_after / 1024) : 0,
            ]);

            if (!$ok) {
                $this->mark_download_error($request);
                $logger->log('ERROR: FTP download failed', [
                    'remote_path' => $request->remote_path,
                    'local_path' => $request->local_path,
                ]);

                return $this->failed($request, 'download_failed', 'FTP download failed', [
                    'cron_status' => 'ERROR (download failed)',
                    'remote_mtime' => (int) $meta_gate['remote_mtime'],
                    'remote_size' => (int) $meta_gate['remote_size'],
                ]);
            }

            if (!is_file($request->local_path) || !is_readable($request->local_path)) {
                $this->mark_download_error($request);

                return $this->failed($request, 'local_file_not_readable', 'Downloaded file is not readable', [
                    'cron_status' => 'ERROR (download failed)',
                    'remote_mtime' => (int) $meta_gate['remote_mtime'],
                    'remote_size' => (int) $meta_gate['remote_size'],
                    'downloaded_bytes' => $bytes_after,
                ]);
            }

            if ($request->min_bytes !== null && $bytes_after < $request->min_bytes) {
                $this->mark_download_error($request);

                return $this->failed($request, 'downloaded_file_empty', 'Downloaded file is smaller than the minimum byte threshold', [
                    'cron_status' => 'ERROR (download failed)',
                    'remote_mtime' => (int) $meta_gate['remote_mtime'],
                    'remote_size' => (int) $meta_gate['remote_size'],
                    'downloaded_bytes' => $bytes_after,
                ]);
            }

            $this->mark_download_success($request);

            return FtpFeedFetchResult::downloaded($this->base_result_args($request, [
                'remote_mtime' => (int) $meta_gate['remote_mtime'],
                'remote_size' => (int) $meta_gate['remote_size'],
                'downloaded_bytes' => $bytes_after,
            ]));
        } finally {
            if ($ftp instanceof FTPClientService) {
                $ftp->close();
            }
        }
    }

    /** @param array<string,mixed> $args */
    private function skipped(FtpFeedFetchRequest $request, string $reason, array $args = []): FtpFeedFetchResult
    {
        return FtpFeedFetchResult::skipped($reason, $this->base_result_args($request, $args));
    }

    /** @param array<string,mixed> $args */
    private function failed(FtpFeedFetchRequest $request, string $reason, string $message, array $args = []): FtpFeedFetchResult
    {
        return FtpFeedFetchResult::failed($reason, $message, $this->base_result_args($request, $args));
    }

    /** @param array<string,mixed> $args */
    private function base_result_args(FtpFeedFetchRequest $request, array $args = []): array
    {
        return array_merge([
            'distributor_id' => $request->distributor_id,
            'cron_name' => $request->cron_name,
            'remote_path' => $request->remote_path,
            'local_path' => $request->local_path,
            'force' => $request->force,
            'context' => $request->context,
        ], $args);
    }

    private function mark_download_error(FtpFeedFetchRequest $request): void
    {
        if ($request->last_download_error_option !== '') {
            update_option($request->last_download_error_option, current_time('mysql'));
        }
    }

    private function mark_download_success(FtpFeedFetchRequest $request): void
    {
        if ($request->last_download_option !== '') {
            update_option($request->last_download_option, current_time('mysql'));
        }

        if ($request->last_download_error_option !== '') {
            delete_option($request->last_download_error_option);
        }
    }

    private function skip_reason_from_status(string $status): string
    {
        $normalized = strtolower($status);

        if (strpos($normalized, 'throttle') !== false) {
            return 'cooldown_not_elapsed';
        }

        if (strpos($normalized, 'cooldown') !== false) {
            return 'cooldown_not_elapsed';
        }

        if (strpos($normalized, 'no change') !== false) {
            return 'remote_not_newer';
        }

        if (strpos($normalized, 'unstable') !== false) {
            return 'remote_unchanged';
        }

        return 'skipped';
    }
}
