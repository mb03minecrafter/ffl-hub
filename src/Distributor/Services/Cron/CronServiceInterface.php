<?php

namespace FFLHub\Distributor\Services\Cron;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CronServiceInterface
 *
 * Common contract for all FFLHub scheduled services backed by
 * WooCommerce Action Scheduler.
 *
 * Design goals:
 * - Make cron services self-contained and declarative.
 * - Allow consistent lifecycle handling (register / activate / deactivate).
 * - Keep Action Scheduler–specific concepts explicit (hook, group, run).
 *
 * Notes:
 * - Implementations are expected to be idempotent.
 * - Scheduling mechanics are typically handled by AbstractCronService.
 */
interface CronServiceInterface
{
    /**
     * Unique action hook name.
     *
     * This is the WordPress hook that Action Scheduler will execute.
     * There should be exactly one recurring action per:
     *   hook + args + group
     *
     * Example:
     *   'fflhub_rsr_inventory_sync'
     */
    public function get_cron_hook_name(): string;

    /**
     * Action Scheduler group name.
     *
     * Used for logical grouping and prioritization in the
     * Action Scheduler UI and runners.
     *
     * Example:
     *   'fflhub_catalog'
     */
    public function get_action_group(): string;

    /**
     * Register hooks/filters required for this service.
     *
     * Typically:
     * - add_action( get_cron_hook_name(), [ $this, 'run' ] )
     * - init-time scheduling hooks
     *
     * Usually called from a central runtime bootstrap
     * (e.g. DistributorServices::register_runtime_services()).
     */
    public function register(): void;

    /**
     * Plugin activation hook.
     *
     * Implementations should ensure their recurring action
     * is scheduled (idempotently).
     */
    public function on_activation(): void;

    /**
     * Plugin deactivation hook.
     *
     * Implementations should clean up scheduled actions
     * for their hook/group/args.
     */
    public function on_deactivation(): void;

    /**
     * Main scheduled execution entry point.
     *
     * This method is invoked by Action Scheduler when the
     * cron job runs.
     */
    public function run(): void;
}
