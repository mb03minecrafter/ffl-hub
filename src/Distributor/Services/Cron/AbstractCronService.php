<?php

namespace FFLHub\Distributor\Services\Cron;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Base implementation that wires a cron-like service into Action Scheduler.
 *
 * Subclasses only need to provide:
 *  - get_cron_hook_name()
 *  - get_interval_seconds()
 *  - optionally override get_initial_delay_seconds()
 *  - optionally override get_action_group()
 *  - run()
 *
 * Note:
 * - We intentionally keep legacy WP-Cron schedule methods (get_schedule_key / get_interval_display)
 *   as DEPRECATED to avoid forcing immediate subclass churn. They are not used anymore.
 */
abstract class AbstractCronService implements CronServiceInterface
{
    /**
     * Register this service with WordPress/Action Scheduler.
     */
    final public function register(): void
    {
        // Action Scheduler runs actions by firing the WP hook name.
        add_action($this->get_cron_hook_name(), [$this, 'run']);

        // Ensure the recurring action exists (idempotent).
        add_action('init', [$this, 'maybe_schedule_action']);
    }

    public function on_activation(): void
    {
        $this->maybe_schedule_action();
    }

    public function on_deactivation(): void
    {
        // Remove all scheduled instances (including recurring) for this hook/group/args.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(
                $this->get_cron_hook_name(),
                $this->get_action_args(),
                $this->get_action_group()
            );
        }
    }

    /**
     * Ensure the recurring Action Scheduler action exists.
     *
     * This must be idempotent because it is called on every request (init).
     */
    final public function maybe_schedule_action(): void
    {
        // If AS isn't available, do nothing (prevents fatals if Woo/AS inactive).
        if (! function_exists('as_next_scheduled_action') || ! function_exists('as_schedule_recurring_action')) {
            return;
        }

        $hook  = $this->get_cron_hook_name();
        $args  = $this->get_action_args();
        $group = $this->get_action_group();

        // If an action is already scheduled (pending) for this hook/args/group, don't duplicate.
        $next = as_next_scheduled_action($hook, $args, $group);
        if (false !== $next) {
            return;
        }

        $start = time() + $this->get_initial_delay_seconds();

        // Creates a recurring action: first run at $start, then every interval seconds.
        as_schedule_recurring_action(
            $start,
            $this->get_interval_seconds(),
            $hook,
            $args,
            $group
        );
    }

    /**
     * Action Scheduler group name for this job.
     * Override in subclasses to isolate/organize by distributor or purpose.
     */
    public function get_action_group(): string
    {
        return 'fflhub';
    }

    /**
     * Action arguments. Default is empty args (one recurring action per hook+group).
     * Override ONLY if you intentionally want distinct schedules per arg set.
     */
    protected function get_action_args(): array
    {
        return [];
    }

    /**
     * Interval length in seconds (e.g. 2 * HOUR_IN_SECONDS).
     */
    abstract protected function get_interval_seconds(): int;

    /**
     * How long after "now" to schedule the first run.
     * Subclasses can override for special cases (e.g. 2 minutes vs 5).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 0;
    }

    // ---------------------------------------------------------------------
    // Deprecated WP-Cron era methods (kept to avoid immediate subclass churn)
    // ---------------------------------------------------------------------

    /**
     * @deprecated No longer used (WP-Cron only). Keep until subclasses are cleaned up.
     */
    protected function get_schedule_key(): string
    {
        return '';
    }

    /**
     * @deprecated No longer used (WP-Cron only). Keep until subclasses are cleaned up.
     */
    protected function get_interval_display(): string
    {
        return '';
    }
}
