<?php

namespace FFLHub\Distributor\Services\Cron;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Common contract for distributor cron services.
 */
interface CronServiceInterface
{
    /**
     * Unique WP-Cron hook name.
     */
    public function get_cron_hook_name(): string;

    /**
     * Register all actions/filters for this cron.
     * Typically called from DistributorServices::register_runtime_services().
     */
    public function register(): void;

    /**
     * Called on plugin activation to ensure the event is scheduled.
     */
    public function on_activation(): void;

    /**
     * Called on plugin deactivation to clear the scheduled event.
     */
    public function on_deactivation(): void;

    /**
     * Main cron callback (what WP-Cron runs).
     */
    public function run(): void;
}
