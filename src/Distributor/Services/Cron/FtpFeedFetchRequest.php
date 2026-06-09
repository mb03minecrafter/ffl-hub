<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Cron;

if (!defined('ABSPATH')) {
    exit;
}

final class FtpFeedFetchRequest
{
    public string $distributor_id = '';
    public string $cron_name = '';

    /** @var array{host:string,username:string,password:string,use_ssl:bool,port:int}|null */
    public ?array $credentials = null;

    public string $remote_path = '';
    public string $local_path = '';

    public string $last_checked_option = '';
    public string $last_applied_mtime_option = '';
    public string $last_seen_mtime_option = '';
    public string $last_seen_size_option = '';
    public string $last_download_option = '';
    public string $last_download_error_option = '';

    public int $min_check_gap_seconds = 0;
    public int $cooldown_seconds = 0;
    public int $timeout_seconds = 30;
    public int $size_stability_sleep_us = 250000;

    public bool $force = false;
    public bool $passive = true;
    public bool $use_pasv_address = true;

    public ?int $min_bytes = null;

    public string $ftp_log_prefix = '[FFLHub][FTP]';
    public string $no_change_log_message = 'No update available (remote mtime unchanged) - skipping download/import/swap';

    /** @var array<string,mixed> */
    public array $context = [];

    /**
     * @param array<string,mixed> $args
     */
    public static function create(array $args): self
    {
        $request = new self();

        $request->distributor_id = trim((string) ($args['distributor_id'] ?? ''));
        $request->cron_name = trim((string) ($args['cron_name'] ?? ''));
        $request->credentials = is_array($args['credentials'] ?? null) ? $args['credentials'] : null;
        $request->remote_path = (string) ($args['remote_path'] ?? '');
        $request->local_path = (string) ($args['local_path'] ?? '');

        $request->last_checked_option = (string) ($args['last_checked_option'] ?? '');
        $request->last_applied_mtime_option = (string) ($args['last_applied_mtime_option'] ?? '');
        $request->last_seen_mtime_option = (string) ($args['last_seen_mtime_option'] ?? '');
        $request->last_seen_size_option = (string) ($args['last_seen_size_option'] ?? '');
        $request->last_download_option = (string) ($args['last_download_option'] ?? '');
        $request->last_download_error_option = (string) ($args['last_download_error_option'] ?? '');

        $request->min_check_gap_seconds = max(0, (int) ($args['min_check_gap_seconds'] ?? 0));
        $request->cooldown_seconds = max(0, (int) ($args['cooldown_seconds'] ?? 0));
        $request->timeout_seconds = max(1, (int) ($args['timeout_seconds'] ?? 30));
        $request->size_stability_sleep_us = max(0, (int) ($args['size_stability_sleep_us'] ?? 250000));

        $request->force = (bool) ($args['force'] ?? false);
        $request->passive = (bool) ($args['passive'] ?? true);
        $request->use_pasv_address = (bool) ($args['use_pasv_address'] ?? true);

        if (array_key_exists('min_bytes', $args) && $args['min_bytes'] !== null) {
            $request->min_bytes = max(0, (int) $args['min_bytes']);
        }

        $request->ftp_log_prefix = (string) ($args['ftp_log_prefix'] ?? '[FFLHub][FTP]');
        $request->no_change_log_message = (string) (
            $args['no_change_log_message']
            ?? 'No update available (remote mtime unchanged) - skipping download/import/swap'
        );
        $request->context = is_array($args['context'] ?? null) ? $args['context'] : [];

        return $request;
    }

    /** @return array<string,mixed> */
    public function credential_profile_context(): array
    {
        $credentials = $this->credentials;

        return [
            'ok' => is_array($credentials),
            'has_host' => is_array($credentials) ? (bool) ($credentials['host'] ?? '') : false,
            'has_user' => is_array($credentials) ? (bool) ($credentials['username'] ?? '') : false,
            'has_ssl' => is_array($credentials) ? (bool) ($credentials['use_ssl'] ?? false) : false,
            'port' => is_array($credentials) ? (int) ($credentials['port'] ?? 0) : 0,
        ];
    }
}
