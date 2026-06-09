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
    private const PROFILER_DEBUG_CONST = 'FFLHUB_CRON_DEBUG';
    private const PROFILER_LOG_PREFIX = '[FFLHub][CronProfiler]';

    /**
     * Register this service with WordPress/Action Scheduler.
     *
     * This should be called during plugin bootstrap.
     *
     * - Hooks `$this->get_cron_hook_name()` to a lightweight profiler wrapper.
     * - Ensures a recurring AS action is scheduled (idempotent) via `init`.
     */
    final public function register(): void
    {
        // Action Scheduler runs actions by firing the WP hook name.
        add_action($this->get_cron_hook_name(), [$this, 'run_with_profile']);

        // Ensure the recurring action exists (idempotent).
        add_action('init', [$this, 'maybe_schedule_action']);
    }

    /**
     * Run the cron and emit one start/end profiler line for cross-job timing.
     *
     * Individual cron services can still log their own internals; this wrapper
     * gives us a consistent way to identify which scheduled hook was slow.
     */
    final public function run_with_profile(): void
    {
        $logger = CronRunLogger::create(self::PROFILER_DEBUG_CONST, self::PROFILER_LOG_PREFIX);
        $started = $logger->now();
        $hook = (string) $this->get_cron_hook_name();
        $group = (string) $this->get_action_group();
        $class = static::class;
        $status = 'SUCCESS';
        $error = '';
        $start_memory = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $logger->log('START', [
            'hook' => $hook,
            'group' => $group,
            'class' => $class,
            'memory_kb' => $start_memory > 0 ? (int) round($start_memory / 1024) : 0,
        ]);

        try {
            $this->run();
        } catch (\Throwable $e) {
            $status = 'ERROR';
            $error = $e->getMessage();
            throw $e;
        } finally {
            $end_memory = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
            $logger->log('END', [
                'hook' => $hook,
                'group' => $group,
                'class' => $class,
                'status' => $status,
                'elapsed_ms' => $logger->formatElapsedMs($started),
                'memory_kb' => $end_memory > 0 ? (int) round($end_memory / 1024) : 0,
                'memory_delta_kb' => ($start_memory > 0 && $end_memory > 0)
                    ? (int) round(($end_memory - $start_memory) / 1024)
                    : 0,
                'error' => $error,
            ]);
        }
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
     * - I also added deduping to ensure we dont have a double scheduled action of the same kind!
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

        // ------------------------------------------------------------
        // DEDUPE: if multiple pending actions exist for this signature,
        // keep one and cancel the rest.
        //
        // Why: you can end up with duplicate recurring entries (like
        // Lipsey’s 20740/20741) due to double registration, old code,
        // activation glitches, etc. This self-heals.
        // ------------------------------------------------------------
        try {
            if (class_exists('\ActionScheduler_Store')) {
                /** @var \ActionScheduler_Store $store */
                $store = \ActionScheduler_Store::instance();

                // Query pending actions for this exact hook + args + group.
                // Keep this fairly small; we only care if there's >1.
                $ids = $store->query_actions([
                    'hook'     => $hook,
                    'group'    => $group,
                    'args'     => $args,
                    'status'   => \ActionScheduler_Store::STATUS_PENDING,
                    'claimed'  => false,
                    'per_page' => 20,
                ]);

                if (is_array($ids) && count($ids) > 1) {
                    // Keep the oldest/lowest id; cancel the rest.
                    sort($ids, SORT_NUMERIC);
                    $keep = array_shift($ids);

                    foreach ($ids as $id) {
                        try {
                            // Prefer store cancel if present.
                            if (method_exists($store, 'cancel_action')) {
                                $store->cancel_action((int) $id);
                            } elseif (method_exists($store, 'mark_failure')) {
                                // Fallback (not ideal), but avoids leaving dupes.
                                $store->mark_failure((int) $id);
                            }
                        } catch (\Throwable $inner) {
                            // Swallow: dedupe should never break scheduling.
                        }
                    }

                    // If we still have a valid next run, don't create another.
                    $next = as_next_scheduled_action($hook, $args, $group);
                    if ($next !== false) {
                        return;
                    }
                    // Otherwise we’ll fall through and schedule a fresh one.
                }
            }
        } catch (\Throwable $e) {
            // Never let dedupe failures break scheduling.
        }

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
