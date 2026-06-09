<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Cron;

if (!defined('ABSPATH')) {
    exit;
}

final class FtpFeedFetchResult
{
    public const STATUS_DOWNLOADED = 'downloaded';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public string $status = '';
    public string $reason = '';
    public string $error_message = '';
    public string $cron_status = '';

    public string $distributor_id = '';
    public string $cron_name = '';
    public string $remote_path = '';
    public string $local_path = '';

    public int $remote_mtime = 0;
    public int $remote_size = -1;
    public int $downloaded_bytes = 0;

    public bool $force = false;

    /** @var array<string,mixed> */
    public array $context = [];

    /** @param array<string,mixed> $args */
    public static function downloaded(array $args): self
    {
        return self::from_args(self::STATUS_DOWNLOADED, $args);
    }

    /** @param array<string,mixed> $args */
    public static function skipped(string $reason, array $args = []): self
    {
        $args['reason'] = $reason;
        return self::from_args(self::STATUS_SKIPPED, $args);
    }

    /** @param array<string,mixed> $args */
    public static function failed(string $reason, string $message, array $args = []): self
    {
        $args['reason'] = $reason;
        $args['error_message'] = $message;
        return self::from_args(self::STATUS_FAILED, $args);
    }

    /** @param array<string,mixed> $args */
    private static function from_args(string $status, array $args): self
    {
        $result = new self();
        $result->status = $status;
        $result->reason = (string) ($args['reason'] ?? '');
        $result->error_message = (string) ($args['error_message'] ?? '');
        $result->cron_status = (string) ($args['cron_status'] ?? '');

        $result->distributor_id = (string) ($args['distributor_id'] ?? '');
        $result->cron_name = (string) ($args['cron_name'] ?? '');
        $result->remote_path = (string) ($args['remote_path'] ?? '');
        $result->local_path = (string) ($args['local_path'] ?? '');

        $result->remote_mtime = (int) ($args['remote_mtime'] ?? 0);
        $result->remote_size = (int) ($args['remote_size'] ?? -1);
        $result->downloaded_bytes = (int) ($args['downloaded_bytes'] ?? 0);
        $result->force = (bool) ($args['force'] ?? false);
        $result->context = is_array($args['context'] ?? null) ? $args['context'] : [];

        return $result;
    }

    public function is_downloaded(): bool
    {
        return $this->status === self::STATUS_DOWNLOADED;
    }

    public function is_skipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function is_failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** @return array<string,mixed> */
    public function to_log_context(): array
    {
        return array_merge($this->context, [
            'status' => $this->status,
            'reason' => $this->reason !== '' ? $this->reason : null,
            'error_message' => $this->error_message !== '' ? $this->error_message : null,
            'distributor_id' => $this->distributor_id,
            'cron_name' => $this->cron_name,
            'remote_path' => $this->remote_path,
            'local_path' => $this->local_path,
            'remote_mtime' => $this->remote_mtime > 0 ? $this->remote_mtime : null,
            'remote_size' => $this->remote_size >= 0 ? $this->remote_size : null,
            'downloaded_bytes' => $this->downloaded_bytes > 0 ? $this->downloaded_bytes : null,
            'force' => $this->force ? 1 : 0,
        ]);
    }
}
