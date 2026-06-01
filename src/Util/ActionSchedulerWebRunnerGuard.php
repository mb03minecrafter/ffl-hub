<?php

namespace FFLHub\Util;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps Action Scheduler queue processing out of frontend/admin web requests.
 *
 * FFLHub distributor jobs can spend a long time in remote API/FTP calls. Those
 * jobs should be processed by WP-CLI cron lanes, not by PHP-FPM workers serving
 * customer traffic or wp-admin requests.
 */
final class ActionSchedulerWebRunnerGuard
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        if (defined('WP_CLI') && WP_CLI) {
            return;
        }

        add_action('action_scheduler_init', [self::class, 'disable_web_runner'], 20);

        if (did_action('action_scheduler_init')) {
            self::disable_web_runner();
        }
    }

    public static function disable_web_runner(): void
    {
        if (!class_exists('\ActionScheduler')) {
            return;
        }

        $runner = \ActionScheduler::runner();
        if (!is_object($runner)) {
            return;
        }

        remove_action('action_scheduler_run_queue', [$runner, 'run']);

        if (method_exists($runner, 'unhook_dispatch_async_request')) {
            $runner->unhook_dispatch_async_request();
        }
    }
}
