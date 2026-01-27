<?php

namespace FFLHub\Distributor\Services\Cron;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Common contract for distributor scheduled services (Action Scheduler-backed).
 */
interface CronServiceInterface
{
    /**
     * Unique action hook name. This is the WordPress hook Action Scheduler will fire.
     *
     * Example: 'fflhub_rsr_inventory_sync'
     */
    public function get_cron_hook_name(): string;

    /**
     * Action Scheduler group name for this job.
     *
     * Used for organization/priority control in the AS UI and runners.
     * Example: 'fflhub_catalog'
     */
    public function get_action_group(): string;

    /**
     * Register all actions/filters for this scheduled job.
     * Typically called from DistributorServices::register_runtime_services().
     */
    public function register(): void;

    /**
     * Called on plugin activation to ensure the recurring action is scheduled.
     */
    public function on_activation(): void;

    /**
     * Called on plugin deactivation to clear scheduled actions.
     */
    public function on_deactivation(): void;

    /**
     * Main scheduled callback (what Action Scheduler runs).
     */
    public function run(): void;
}
