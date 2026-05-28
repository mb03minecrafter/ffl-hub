<?php

namespace FFLHub\Feeds\GunDeals;

use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class GunDealsFeedCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_gundeals_feed_generate';

    private const DEBUG_CONST = 'FFLHUB_GUNDEALS_FEED_DEBUG';
    private const LOG_PREFIX = '[FFLHub][GunDealsFeedCron]';
    private const LOCK_OPTION = 'fflhub_gundeals_feed_generation_lock';
    private const LOCK_TTL_SECONDS = 20 * 60;

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_feeds';
    }

    protected function get_interval_seconds(): int
    {
        return 30 * 60;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 5 * 60;
    }

    public function run(): void
    {
        if (!$this->acquire_lock()) {
            self::debug('feed generation skipped: existing generation lock is active');
            return;
        }

        try {
            (new GunDealsFeedGenerator())->generate();
        } finally {
            $this->release_lock();
        }
    }

    private function acquire_lock(): bool
    {
        $now = time();
        $existing = (int) get_option(self::LOCK_OPTION, 0);

        if ($existing > 0 && ($now - $existing) < self::LOCK_TTL_SECONDS) {
            return false;
        }

        if ($existing > 0) {
            delete_option(self::LOCK_OPTION);
        }

        return (bool) add_option(self::LOCK_OPTION, (string) $now, '', 'no');
    }

    private function release_lock(): void
    {
        delete_option(self::LOCK_OPTION);
    }

    private static function debug(string $message): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $message);
    }
}
