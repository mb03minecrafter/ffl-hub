<?php

namespace FFLHub\Distributor\Services\Cron;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AbstractCronService
 *
 * Base implementation that wires a “cron-like” service into Action Scheduler.
 *
 * Subclasses must provide:
 * - get_cron_hook_name(): string  (from CronServiceInterface)
 * - get_interval_seconds(): int
 * - run(): void                  (from CronServiceInterface)
 *
 * Subclasses may override:
 * - get_initial_delay_seconds(): int
 * - get_action_group(): string
 * - get_action_args(): array
 *
 * Scheduling model:
 * - register() binds the Action Scheduler hook to ->run().
 * - register() also attaches ->maybe_schedule_action() to `init`.
 * - maybe_schedule_action() is idempotent: it ensures exactly one recurring action
 *   exists per (hook + args + group).
 *
 * Deactivation:
 * - on_deactivation() removes all scheduled actions for (hook + args + group),
 *   including recurring and pending instances.
 *
 * Legacy compatibility:
 * - get_schedule_key() and get_interval_display() are kept as deprecated stubs
 *   to avoid forcing immediate subclass churn. They are not used by Action Scheduler.
 */
abstract class AbstractCronService implements CronServiceInterface
{
    /**
     * Register this service with WordPress/Action Scheduler.
     *
     * This should be called during plugin bootstrap.
     *
     * - Hooks `$this->get_cron_hook_name()` to `$this->run()`.
     * - Ensures a recurring AS action is scheduled (idempotent) via `init`.
     */
    final public function register(): void
    {
        // Action Scheduler runs actions by firing the WP hook name.
        add_action($this->get_cron_hook_name(), [$this, 'run']);

        // Ensure the recurring action exists (idempotent).
        add_action('init', [$this, 'maybe_schedule_action']);
    }

    /**
     * Plugin activation hook handler.
     *
     * Ensures the recurring Action Scheduler action exists immediately on activation.
     */
    public function on_activation(): void
    {
        $this->maybe_schedule_action();
    }

    /**
     * Plugin deactivation hook handler.
     *
     * Removes all scheduled actions for this hook/args/group combination.
     */
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
     * This must be idempotent because it can be called on every request (init),
     * on activation, or from other bootstrap code.
     *
     * Safety:
     * - If Action Scheduler functions are unavailable (Woo/AS inactive), this is a no-op.
     */
    final public function maybe_schedule_action(): void
    {
        // If AS isn't available, do nothing (prevents fatals if Woo/AS inactive).
        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        $hook  = (string) $this->get_cron_hook_name();
        $args  = $this->get_action_args();
        $group = (string) $this->get_action_group();

        // If an action is already scheduled (pending) for this hook/args/group, don't duplicate.
        $next = as_next_scheduled_action($hook, $args, $group);
        if ($next !== false) {
            return;
        }

        $delay = (int) $this->get_initial_delay_seconds();
        if ($delay < 0) {
            $delay = 0;
        }

        $interval = (int) $this->get_interval_seconds();
        if ($interval <= 0) {
            // Avoid scheduling a broken recurring action.
            return;
        }

        $start = time() + $delay;

        // Creates a recurring action: first run at $start, then every interval seconds.
        as_schedule_recurring_action(
            $start,
            $interval,
            $hook,
            $args,
            $group
        );
    }

    /**
     * Action Scheduler group name for this job.
     *
     * Override in subclasses to isolate/organize by distributor or purpose.
     */
    public function get_action_group(): string
    {
        return 'fflhub';
    }

    /**
     * Action arguments.
     *
     * Default: empty args (one recurring action per hook+group).
     * Override ONLY if you intentionally want distinct schedules per arg set.
     *
     * @return array<string,mixed>
     */
    protected function get_action_args(): array
    {
        return [];
    }

    /**
     * Interval length in seconds (e.g. 2 * HOUR_IN_SECONDS).
     *
     * Must be > 0.
     */
    abstract protected function get_interval_seconds(): int;

    /**
     * How long after "now" to schedule the first run.
     *
     * Subclasses can override for special cases (e.g. wait 2 minutes after boot).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 0;
    }

    // ---------------------------------------------------------------------
    // Deprecated WP-Cron era methods (kept to avoid immediate subclass churn)
    // ---------------------------------------------------------------------

    /**
     * Legacy WP-Cron schedule key (no longer used).
     *
     * @deprecated No longer used (WP-Cron only). Keep until subclasses are cleaned up.
     */
    protected function get_schedule_key(): string
    {
        return '';
    }

    /**
     * Legacy interval display label (no longer used).
     *
     * @deprecated No longer used (WP-Cron only). Keep until subclasses are cleaned up.
     */
    protected function get_interval_display(): string
    {
        return '';
    }
}
