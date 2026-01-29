<?php

namespace FFLHub\Distributor\Services\Orders;

use WC_Order;

use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementJobsStoreUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifecycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementTimeUtil;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OrderPlacementJobStateMachine
 *
 * IMPORTANT (NEW ARCHITECTURE):
 * - Per-job Action Scheduler actions are DISABLED.
 * - Retries are DB-only:
 *     - status = retry_scheduled
 *     - next_run_at = <datetime>
 *     - action_id = NULL
 * - A dispatcher cron (single AS recurring action) pulls ready rows ordered by next_run_at.
 *
 * DO NOT schedule OrderPlacementKeys::AS_HOOK (fflhub_place_distributor_bucket) anywhere.
 */
final class OrderPlacementJobStateMachine
{
    private const LOG_PREFIX  = '[FFLHUB][OrderPlacementStateMachine]';
    private const DEBUG_CONST = 'FFLHUB_STATE_MACHINE_DEBUG';

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
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            $this->log_ctx('exit_invalid_job_key', [
                'phase'      => 'validate',
                'order_id'   => $order_id,
                'job_key_in' => $job_key_in,
                'attempt_n'  => $attempt_n,
            ]);
            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        $codes = OrderPlacementJobsStoreUtil::normalize_external_ids(is_array($vr->codes) ? $vr->codes : []);

        $this->log_ctx('apply_validation_result', [
            'order_id'  => $order_id,
            'job_key'   => $job_key,
            'attempt_n' => $attempt_n,
            'vr_ok'     => $vr->ok ? '1' : '0',
            'vr_code'   => (string) ($vr->code ?? ''),
            'vr_msg'    => (string) ($vr->message ?? ''),
            'codes'     => $codes,
        ]);

        // Single write: step + codes
        OrderPlacementJobWriter::apply_patch_for_order(
            $order,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_last_step('validate')
                ->with_last_codes($codes)
        );

        if ($vr->ok && $vr->code === DistributorOrderValidationResult::CODE_ALLOW) {
            $this->log_ctx('validation_allow_continue', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'attempt_n' => $attempt_n,
            ]);
            return ['action' => 'continue'];
        }

        // Retryable validation block
        if (method_exists($vr, 'is_retryable') && $vr->is_retryable()) {
            return $this->schedule_retry_and_exit(
                $order,
                $job_key,
                $attempt_n,
                (string) $vr->message,
                $codes,
                'validate'
            );
        }

        // Fatal validation block
        return $this->fail_and_exit(
            $order,
            $job_key,
            'Validation fatal: ' . (string) $vr->message,
            $codes,
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
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            $this->log_ctx('exit_invalid_job_key', [
                'phase'      => 'place',
                'order_id'   => $order_id,
                'job_key_in' => $job_key_in,
                'attempt_n'  => $attempt_n,
            ]);
            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        $codes = OrderPlacementJobsStoreUtil::normalize_external_ids(is_array($or->codes) ? $or->codes : []);

        $this->log_ctx('apply_place_order_result', [
            'order_id'  => $order_id,
            'job_key'   => $job_key,
            'attempt_n' => $attempt_n,
            'or_ok'     => $or->ok ? '1' : '0',
            'or_code'   => (string) ($or->code ?? ''),
            'or_msg'    => (string) ($or->message ?? ''),
            'codes'     => $codes,
        ]);

        // Single write: step + codes
        OrderPlacementJobWriter::apply_patch_for_order(
            $order,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_last_step('place')
                ->with_last_codes($codes)
        );

        if ($or->ok && $or->code === DistributorOrderResult::CODE_OK) {
            $this->log_ctx('place_ok_continue', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'attempt_n' => $attempt_n,
            ]);
            return ['action' => 'continue'];
        }

        if ($or->code === DistributorOrderResult::CODE_BLOCK_RETRYABLE) {
            return $this->schedule_retry_and_exit(
                $order,
                $job_key,
                $attempt_n,
                (string) $or->message,
                $codes,
                'place'
            );
        }

        return $this->fail_and_exit(
            $order,
            $job_key,
            'Place-order fatal: ' . (string) $or->message,
            $codes,
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
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            $this->log_ctx('retry_exit_invalid_job_key', [
                'order_id'   => $order_id,
                'job_key_in' => $job_key_in,
                'attempt_n'  => $attempt_n,
                'step'       => $step,
            ]);
            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        $codes = OrderPlacementJobsStoreUtil::normalize_external_ids($codes);

        if ($attempt_n >= self::MAX_ATTEMPTS) {
            $this->log_ctx('retry_exit_max_attempts', [
                'order_id'  => $order_id,
                'job_key'   => $job_key,
                'attempt_n' => $attempt_n,
                'step'      => $step,
                'reason'    => $reason,
                'codes'     => $codes,
            ]);

            return $this->fail_and_exit(
                $order,
                $job_key,
                'Max attempts reached (' . $attempt_n . '): ' . $reason,
                $codes,
                $step
            );
        }

        $delay = $this->compute_backoff_seconds($attempt_n, $codes);
        $desired_run_at_unix = $delay; //DEBUG TO GET THROUGH QUEUE QUICKER TO TEST STATE MACHINE HANDLING 

        // Dispatcher model: DB-only retry scheduling (NO per-job AS action).
        // next_run_at should be a MySQL UTC datetime string.
        $run_at_mysql_utc = OrderPlacementTimeUtil::unix_to_mysql_utc((int) $desired_run_at_unix);

        // One write covers: status + reason + codes + step + next_run_at (and ensures action_id stays NULL).
        OrderPlacementJobLifecycle::mark_job_retry_scheduled(
            $order,
            $job_key,
            $run_at_mysql_utc,
            $reason,
            $codes,
            $step
        );

        $this->log_ctx('retry_scheduled_db_only', [
            'order_id'    => $order_id,
            'job_key'     => $job_key,
            'attempt_n'   => $attempt_n,
            'step'        => $step,
            'reason'      => $reason,
            'codes'       => $codes,
            'delay_s'     => $delay,
            'run_at_mysql_utc' => $run_at_mysql_utc,
        ]);

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
        $order_id = (int) $order->get_id();

        $job_key_in = (string) $job_key;
        $job_key = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            $this->log_ctx('fail_exit_invalid_job_key', [
                'order_id'   => $order_id,
                'job_key_in' => $job_key_in,
                'step'       => $step,
                'reason'     => $reason,
            ]);
            return ['action' => 'exit', 'reason' => 'invalid_job_key'];
        }

        $codes = OrderPlacementJobsStoreUtil::normalize_external_ids($codes);

        $this->log_ctx('mark_failed', [
            'order_id' => $order_id,
            'job_key'  => $job_key,
            'step'     => $step,
            'reason'   => $reason,
            'codes'    => $codes,
        ]);

        // Ensure quick glance fields reflect terminal state in one shot
        OrderPlacementJobWriter::apply_patch_for_order(
            $order,
            $job_key,
            OrderPlacementJobPatch::empty()
                ->with_last_step($step)
                ->with_last_codes($codes)
        );

        // Terminal failure
        OrderPlacementJobLifecycle::mark_job_failed($order, $job_key, $reason);

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

        $final = (int) min($delay, self::MAX_DELAY_SECONDS);

        $this->log_ctx('backoff_computed', [
            'attempt_n'    => $attempt_n,
            'codes'        => $codes,
            'base_delay_s' => self::BASE_DELAY_SECONDS,
            'computed_s'   => $final,
        ]);

        return $final;
    }

    // ---------------------------------------------------------------------
    // LEGACY (DO NOT USE):
    // Per-job Action Scheduler scheduling has been removed in favor of a
    // dispatcher that pulls DB rows ordered by next_run_at.
    //
    // This method is intentionally left commented out for reference.
    // ---------------------------------------------------------------------

    /*
    private function schedule_job_retry_action(
        WC_Order $order,
        string $job_key,
        int $desired_run_at_unix
    ): array {
        // LEGACY: do not schedule OrderPlacementKeys::AS_HOOK anymore.
        return ['', $desired_run_at_unix];
    }
    */

    private function log(string $msg): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $msg);
    }

    /** @param array<string,mixed> $ctx */
    private function log_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
    }
}
