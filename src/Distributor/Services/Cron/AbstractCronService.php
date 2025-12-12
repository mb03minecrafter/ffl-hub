<?php

namespace FFLHub\Distributor\Services\Cron;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base implementation that wires a cron service into WP-Cron.
 *
 * Subclasses only need to provide:
 *  - get_cron_hook_name()
 *  - get_schedule_key()
 *  - get_interval_seconds()
 *  - get_interval_display()
 *  - optionally override get_initial_delay_seconds()
 *  - run()
 */
abstract class AbstractCronService implements CronServiceInterface
{
    final public function register(): void
    {
        add_filter( 'cron_schedules', [ $this, 'register_intervals' ] );
        add_action( $this->get_cron_hook_name(), [ $this, 'run' ] );
        add_action( 'init', [ $this, 'maybe_schedule_event' ] );
    }

    public function on_activation(): void
    {
        $this->maybe_schedule_event();
    }

    public function on_deactivation(): void
    {
        $hook = $this->get_cron_hook_name();
        $ts   = wp_next_scheduled( $hook );

        if ( $ts ) {
            wp_unschedule_event( $ts, $hook );
        }
    }

    /**
     * Add our custom interval to the cron schedules array.
     */
    public function register_intervals( array $schedules ): array
    {
        $key = $this->get_schedule_key();

        if ( ! isset( $schedules[ $key ] ) ) {
            $schedules[ $key ] = [
                'interval' => $this->get_interval_seconds(),
                'display'  => $this->get_interval_display(),
            ];
        }

        return $schedules;
    }

    /**
     * Ensure the event is scheduled.
     */
    public function maybe_schedule_event(): void
    {
        $hook = $this->get_cron_hook_name();

        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event(
                time() + $this->get_initial_delay_seconds(),
                $this->get_schedule_key(),
                $hook
            );
        }
    }

    /**
     * The schedule key we add to cron_schedules (e.g. 'fflhub_two_hours').
     */
    abstract protected function get_schedule_key(): string;

    /**
     * Interval length in seconds (e.g. 2 * HOUR_IN_SECONDS).
     */
    abstract protected function get_interval_seconds(): int;

    /**
     * Human-readable label for wp-cron UI (Settings → Cron plugins).
     */
    abstract protected function get_interval_display(): string;

    /**
     * How long after "now" to schedule the first event.
     * Subclasses can override for special cases (e.g. 2 minutes vs 5).
     */
    protected function get_initial_delay_seconds(): int
    {
        return 0;
    }
}
