<?php

namespace FFLHub\Distributor\Services\Orders;

use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderPlacementJobStateMachine
{
    private const LOG_PREFIX = '[FFLHUB][OrderPlacementStateMachine]';

    private const BASE_DELAY_SECONDS = 60;     // 1 minute
    private const MAX_DELAY_SECONDS  = 3600;   // 1 hour
    private const MAX_ATTEMPTS       = 8;

    /**
     * @return array{action:string, reason?:string} action in {continue, exit}
     */
    public function apply_validation_result(
        WC_Order $order,
        string $job_key,
        DistributorOrderValidationResult $vr,
        int $attempt_n
    ): array {
        OrderPlacementJobsStore::set_job_last_step($order, $job_key, 'validate');
        OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, is_array($vr->codes) ? $vr->codes : []);

        if ($vr->ok && $vr->code === DistributorOrderValidationResult::CODE_ALLOW) {
            return ['action' => 'continue'];
        }

        // Retryable validation block
        if (method_exists($vr, 'is_retryable') && $vr->is_retryable()) {
            return $this->schedule_retry_and_exit(
                $order,
                $job_key,
                $attempt_n,
                (string) $vr->message,
                is_array($vr->codes) ? $vr->codes : [],
                'validate'
            );
        }

        // Fatal validation block
        return $this->fail_and_exit(
            $order,
            $job_key,
            'Validation fatal: ' . (string) $vr->message,
            is_array($vr->codes) ? $vr->codes : [],
            'validate'
        );
    }

    /**
     * @return array{action:string, reason?:string} action in {continue, exit}
     */
    public function apply_place_order_result(
        WC_Order $order,
        string $job_key,
        DistributorOrderResult $or,
        int $attempt_n
    ): array {
        OrderPlacementJobsStore::set_job_last_step($order, $job_key, 'place');
        OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, is_array($or->codes) ? $or->codes : []);

        if ($or->ok && $or->code === DistributorOrderResult::CODE_OK) {
            return ['action' => 'continue'];
        }

        if ($or->code === DistributorOrderResult::CODE_BLOCK_RETRYABLE) {
            return $this->schedule_retry_and_exit(
                $order,
                $job_key,
                $attempt_n,
                (string) $or->message,
                is_array($or->codes) ? $or->codes : [],
                'place'
            );
        }

        return $this->fail_and_exit(
            $order,
            $job_key,
            'Place-order fatal: ' . (string) $or->message,
            is_array($or->codes) ? $or->codes : [],
            'place'
        );
    }

    /** @param string[] $codes @return array{action:string, reason:string} */
    private function schedule_retry_and_exit(
        WC_Order $order,
        string $job_key,
        int $attempt_n,
        string $reason,
        array $codes,
        string $step
    ): array {
        if ($attempt_n >= self::MAX_ATTEMPTS) {
            return $this->fail_and_exit(
                $order,
                $job_key,
                'Max attempts reached (' . $attempt_n . '): ' . $reason,
                $codes,
                $step
            );
        }

        $delay = $this->compute_backoff_seconds($attempt_n, $codes);
        $desired_run_at = time() + $delay;

        // Schedule (or reuse existing). Returns: [action_id, run_at_unix_actual]
        [$action_id, $run_at_unix] = $this->schedule_job_retry_action($order, $job_key, $desired_run_at);

        $run_at_iso = gmdate('c', $run_at_unix);

        // Persist job state (table-backed)
        OrderPlacementJobsStore::mark_job_retry_scheduled($order, $job_key, $run_at_iso, $reason, $codes, $step);

        // action_id reflects "currently pending retry" (if any)
        if ($action_id !== '') {
            OrderPlacementJobsStore::set_job_action_id($order, $job_key, $action_id);
        } else {
            // If AS missing, keep action_id empty; job will require manual retry
            OrderPlacementJobsStore::clear_job_action_id($order, $job_key);
        }

        error_log(self::LOG_PREFIX . " retry scheduled key={$job_key} order=" . (int) $order->get_id()
            . " step={$step} attempt={$attempt_n} delay={$delay}s run_at={$run_at_iso} action_id={$action_id}");

        return ['action' => 'exit', 'reason' => 'retry_scheduled'];
    }

    /** @param string[] $codes @return array{action:string, reason:string} */
    private function fail_and_exit(
        WC_Order $order,
        string $job_key,
        string $reason,
        array $codes,
        string $step
    ): array {
        OrderPlacementJobsStore::set_job_last_step($order, $job_key, $step);
        OrderPlacementJobsStore::set_job_last_error_codes($order, $job_key, $codes);

        // Terminal failure
        OrderPlacementJobsStore::mark_job_failed($order, $job_key, $reason);

        // No pending action anymore
        OrderPlacementJobsStore::clear_job_action_id($order, $job_key);

        error_log(self::LOG_PREFIX . " failed key={$job_key} order=" . (int) $order->get_id()
            . " step={$step} reason={$reason}");

        return ['action' => 'exit', 'reason' => 'failed'];
    }

    /** @param string[] $codes */
    private function compute_backoff_seconds(int $attempt_n, array $codes): int
    {
        $attempt_n = max(1, $attempt_n);

        // Exponential backoff
        $delay = self::BASE_DELAY_SECONDS * (int) pow(2, $attempt_n - 1);

        // Rate limit bump
        $codes_lc = array_map('strtolower', array_map('strval', $codes));
        foreach ($codes_lc as $c) {
            if (strpos($c, 'rate') !== false || strpos($c, 'quota') !== false) {
                $delay = (int) max($delay, 10 * 60); // 10 minutes minimum
                break;
            }
        }

        // Jitter 0-15s to avoid herd
        $delay += random_int(0, 15);

        return (int) min($delay, self::MAX_DELAY_SECONDS);
    }

    /**
     * Schedule (or reuse) a retry action.
     *
     * IMPORTANT:
     * - We do NOT introspect Action Scheduler's schedule object because API differs by version.
     * - Our single source of truth for "next run" is the job table we write.
     *
     * @return array{0:string,1:int} [action_id, run_at_unix]
     */
    private function schedule_job_retry_action(
        WC_Order $order,
        string $job_key,
        int $desired_run_at_unix
    ): array {
        if (!function_exists('as_schedule_single_action')) {
            error_log(self::LOG_PREFIX . ' Action Scheduler missing; cannot schedule retry');
            return ['', $desired_run_at_unix];
        }

        $args = [
            'order_id' => (int) $order->get_id(),
            'job_key'  => (string) $job_key,
        ];

        // Strong idempotency: reuse pending action if one already exists
        if (function_exists('as_next_scheduled_action')) {
            $existing = as_next_scheduled_action(
                OrderPlacementKeys::AS_HOOK,
                $args,
                OrderPlacementKeys::AS_GROUP
            );

            if (is_numeric($existing) && (int) $existing > 0) {
                // We cannot reliably fetch its schedule time across AS versions.
                // Keep our desired time as the canonical stored time.
                return [(string) (int) $existing, $desired_run_at_unix];
            }
        }

        $action_id = as_schedule_single_action(
            $desired_run_at_unix,
            OrderPlacementKeys::AS_HOOK,
            $args,
            OrderPlacementKeys::AS_GROUP
        );

        $id = is_numeric($action_id) ? (string) (int) $action_id : '';

        return [$id, $desired_run_at_unix];
    }
}
