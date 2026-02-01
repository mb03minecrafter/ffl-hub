<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

use WC_Order;

use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;

use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifecycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;

use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementProductUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobStateMachine
 *
 * Responsibility:
 * - Interpret distributor validation/place results and translate them into durable job-row state:
 *     - quick-glance diagnostics (last_step, last_codes_json)
 *     - scheduling decisions (retry_scheduled with next_run_at)
 *     - terminal decisions (failed)
 *
 * This class DOES NOT:
 * - Execute distributor calls (runner does that).
 * - Select which jobs to run (dispatcher/repository does that).
 * - Schedule Action Scheduler actions per job (legacy architecture; disabled).
 *
 * IMPORTANT (DB-only retry architecture):
 * - Per-job Action Scheduler actions are disabled.
 * - Retries are represented purely in the jobs table:
 *     - status      = retry_scheduled
 *     - next_run_at = MySQL UTC datetime
 *     - action_id   = NULL
 * - A separate recurring dispatcher (single Action Scheduler recurring action)
 *   pulls ready rows ordered by next_run_at ASC and runs them.
 *
 * Invariants:
 * - job_key is always normalized via OrderPlacementKeysUtil::normalize_job_key()
 * - codes are always normalized to unique string[] via OrderPlacementProductUtil::normalize_external_ids()
 *
 * Return convention:
 * - All public methods return a small decision array used by the runner:
 *     - ['action' => 'continue'] means proceed to next stage
 *     - ['action' => 'exit', 'reason' => <string>] means stop the runner for this job
 */
final class OrderPlacementJobStateMachine
{
    private const LOG_PREFIX  = '[FFLHUB][OrderPlacementStateMachine]';
    private const DEBUG_CONST = 'FFLHUB_STATE_MACHINE_DEBUG';

    /** Base backoff delay for attempt 1 (seconds). */
    private const BASE_DELAY_SECONDS = 60;     // 1 minute

    /** Max backoff delay cap (seconds). */
    private const MAX_DELAY_SECONDS  = 3600;   // 1 hour

    /** Hard attempt ceiling; after this we force a terminal failure. */
    private const MAX_ATTEMPTS       = 8;

    /**
     * Decision return type for state-machine methods.
     *
     * @phpstan-type Decision array{action:'continue'|'exit', reason?:string}
     * @return array{action:'continue'|'exit', reason?:string}
     */

    /**
     * Apply a validation result:
     * - Always writes quick-glance fields: last_step='validate', last_codes_json=<codes>.
     * - If ALLOW => continue
     * - If retryable => schedule DB-only retry and exit
     * - Otherwise => mark failed and exit
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table helper.
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key (dist|bucket). May be non-normalized; will be normalized.
     * @param DistributorOrderValidationResult $vr Distributor validation result.
     * @param int $attempt_n Current attempt count (already incremented by lifecycle claim).
     * @return array{action:'continue'|'exit', reason?:string}
     */
    public function apply_validation_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        DistributorOrderValidationResult $vr,
        int $attempt_n
    ): array {
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        /** @var string[] $codes */
        $codes = OrderPlacementProductUtil::normalize_external_ids(is_array($vr->codes) ? $vr->codes : []);

        // Quick-glance write: last_step + last_codes_json
        OrderPlacementJobWriter::apply_patch_for_order(
            $jobs_table,
            $order,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_last_step('validate')
                ->with_last_codes($codes)
        );

        if ($vr->ok && $vr->code === DistributorOrderValidationResult::CODE_ALLOW) {
            return ['action' => 'continue'];
        }

        // Retryable validation block (defensive check: method exists on result model)
        if (method_exists($vr, 'is_retryable') && $vr->is_retryable()) {
            return $this->schedule_retry_and_exit(
                $jobs_table,
                $order,
                $job_key,
                $attempt_n,
                (string) ($vr->message ?? ''),
                $codes,
                'validate'
            );
        }

        // Fatal validation block
        return $this->fail_and_exit(
            $jobs_table,
            $order,
            $job_key,
            'Validation fatal: ' . (string) ($vr->message ?? ''),
            $codes,
            'validate'
        );
    }

    /**
     * Apply a place-order result:
     * - Always writes quick-glance fields: last_step='place', last_codes_json=<codes>.
     * - If OK => continue
     * - If retryable block => schedule DB-only retry and exit
     * - Otherwise => mark failed and exit
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table helper.
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key (dist|bucket). May be non-normalized; will be normalized.
     * @param DistributorOrderResult $or Distributor place-order result.
     * @param int $attempt_n Current attempt count.
     * @return array{action:'continue'|'exit', reason?:string}
     */
    public function apply_place_order_result(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        DistributorOrderResult $or,
        int $attempt_n
    ): array {
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        /** @var string[] $codes */
        $codes = OrderPlacementProductUtil::normalize_external_ids(is_array($or->codes) ? $or->codes : []);

        // Quick-glance write: last_step + last_codes_json
        OrderPlacementJobWriter::apply_patch_for_order(
            $jobs_table,
            $order,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_last_step('place')
                ->with_last_codes($codes)
        );

        if ($or->ok && $or->code === DistributorOrderResult::CODE_OK) {

            return ['action' => 'continue'];
        }

        if ($or->code === DistributorOrderResult::CODE_BLOCK_RETRYABLE) {
            return $this->schedule_retry_and_exit(
                $jobs_table,
                $order,
                $job_key,
                $attempt_n,
                (string) ($or->message ?? ''),
                $codes,
                'place'
            );
        }

        return $this->fail_and_exit(
            $jobs_table,
            $order,
            $job_key,
            'Place-order fatal: ' . (string) ($or->message ?? ''),
            $codes,
            'place'
        );
    }

    /**
     * Schedule a DB-only retry and return an exit decision.
     *
     * Semantics:
     * - If attempt_n >= MAX_ATTEMPTS => forces a terminal failure.
     * - Otherwise computes a backoff delay, writes retry scheduling via lifecycle:
     *     - status=retry_scheduled
     *     - next_run_at=<mysql utc>
     *     - last_error=<reason>
     *     - last_codes_json=<codes>
     *     - last_step=<step>
     *     - action_id=NULL
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table helper.
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Normalized job key.
     * @param int $attempt_n Current attempt.
     * @param string $reason Human readable reason for retry.
     * @param string[] $codes Normalized codes (deduped).
     * @param string $step Stage name ('validate'|'place' etc).
     * @return array{action:'exit', reason:string}
     */
    private function schedule_retry_and_exit(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        int $attempt_n,
        string $reason,
        array $codes,
        string $step
    ): array {
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {

            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        /** @var string[] $codes */
        $codes = OrderPlacementProductUtil::normalize_external_ids($codes);
        $reason = trim((string) $reason);

        if ($attempt_n >= self::MAX_ATTEMPTS) {

            return $this->fail_and_exit(
                $jobs_table,
                $order,
                $job_key,
                'Max attempts reached (' . $attempt_n . '): ' . $reason,
                $codes,
                $step
            );
        }

        $delay_s = $this->compute_backoff_seconds($attempt_n, $codes);

        // If you want "fast debug retries", clamp the delay here.
        // Example: $delay_s = min($delay_s, 5);
        // Keeping behavior safe by default.
        $desired_run_at_unix = time() + $delay_s;

        // next_run_at should be MySQL UTC datetime string.
        $run_at_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc((int) $desired_run_at_unix);

        OrderPlacementJobLifecycle::mark_job_retry_scheduled(
            $jobs_table,
            $order,
            $job_key,
            $run_at_mysql_utc,
            $reason,
            $codes,
            $step
        );
        return ['action' => 'exit', 'reason' => 'retry_scheduled'];
    }

    /**
     * Mark a job as terminal failed and return an exit decision.
     *
     * Semantics:
     * - Writes quick-glance fields: last_step, last_codes_json (best-effort).
     * - Marks terminal failure via lifecycle helper:
     *     - status=failed
     *     - last_error=<reason> (or equivalent)
     *     - next_run_at cleared
     *
     * @param OrderPlacementJobsTable $jobs_table Jobs table helper.
     * @param WC_Order $order WooCommerce order.
     * @param string $job_key Job key (normalized).
     * @param string $reason Human readable failure reason.
     * @param string[] $codes Normalized codes.
     * @param string $step Stage name.
     * @return array{action:'exit', reason:string}
     */
    private function fail_and_exit(
        OrderPlacementJobsTable $jobs_table,
        WC_Order $order,
        string $job_key,
        string $reason,
        array $codes,
        string $step
    ): array {
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        /** @var string[] $codes */
        $codes = OrderPlacementProductUtil::normalize_external_ids($codes);
        $reason = trim((string) $reason);

        // Best-effort: keep quick glance fields aligned with terminal state.
        OrderPlacementJobWriter::apply_patch_for_order(
            $jobs_table,
            $order,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_last_step($step)
                ->with_last_codes($codes)
        );

        OrderPlacementJobLifecycle::mark_job_failed($jobs_table, $order, $job_key, $reason);

        return ['action' => 'exit', 'reason' => 'failed'];
    }

    /**
     * Compute an exponential backoff delay (seconds) for a retry.
     *
     * Inputs:
     * - attempt_n: 1-based attempt number (attempt 1 => BASE_DELAY).
     * - codes: normalized string codes; "rate"/"quota" bumps delay floor to 10 minutes.
     *
     * Output:
     * - delay in seconds, capped at MAX_DELAY_SECONDS and jittered by 0-15s.
     *
     * @param int $attempt_n Attempt number (>= 1).
     * @param string[] $codes Normalized codes.
     * @return int Delay in seconds.
     */
    private function compute_backoff_seconds(int $attempt_n, array $codes): int
    {
        $attempt_n = max(1, (int) $attempt_n);

        // Exponential backoff: BASE * 2^(attempt_n-1)
        $delay = (int) (self::BASE_DELAY_SECONDS * (int) pow(2, $attempt_n - 1));

        // Rate limit / quota floor
        $codes_lc = array_map('strtolower', array_map('strval', $codes));
        foreach ($codes_lc as $c) {
            if (strpos($c, 'rate') !== false || strpos($c, 'quota') !== false) {
                $delay = (int) max($delay, 10 * 60); // 10 minutes minimum
                break;
            }
        }

        // Jitter 0-15s to avoid retry herds
        $delay += random_int(0, 15);

        $final = (int) min($delay, self::MAX_DELAY_SECONDS);

        return $final;
    }

}
