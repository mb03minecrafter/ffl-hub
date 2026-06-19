<?php

namespace FFLHub\Feeds\GunMade;

use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class GunMadeFeedCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_gunmade_feed_generate';

    private const DEBUG_CONST = 'FFLHUB_GUNMADE_FEED_DEBUG';
    private const LOG_PREFIX = '[FFLHub][GunMadeFeedCron]';
    private const LOCK_OPTION = 'fflhub_gunmade_feed_generation_lock';
    private const LOCK_TTL_SECONDS = 15 * 60;

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
        return 15 * 60;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 7 * 60;
    }

    public function run(): void
    {
        if (!$this->acquire_lock()) {
            self::debug('feed generation skipped: existing generation lock is active');
            return;
        }

        try {
            (new GunMadeFeedGenerator())->generate();
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
