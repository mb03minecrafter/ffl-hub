<?php

namespace FFLHub\Distributor\Services\Orders\Jobs;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderPlacementKeys
{

    /* ===================== Pipeline meta (order-level) ===================== */

    public const META_PIPELINE_STARTED     = 'fflhub_place_pipeline_started';
    public const META_PIPELINE_STARTED_AT  = 'fflhub_place_pipeline_started_at';
    public const META_PIPELINE_STARTED_BY  = 'fflhub_place_pipeline_started_by';

    // We use this to suspend jobs if an order is trashed, cancelled/refunded/failed, or manually paused.
    public const META_ORDER_SUSPENDED = 'fflhub_order_jobs_suspended';

    // Set only when suspension was caused by an order status change, so restoration can safely resume rows.
    public const META_ORDER_STATUS_SUSPENDED = 'fflhub_order_jobs_suspended_by_order_status';

    /* ===================== Job registry (order-level) ===================== */

    /** JSON array of job keys */
    public const META_JOBS_INDEX = 'fflhub_place_jobs_index';

    /** Prefix for all per-job meta */
    public const META_JOB_PREFIX = 'fflhub_place_job_';

    /* ===================== Per-job meta suffixes ===================== */

    public const META_JOB_STATUS          = '_status';
    public const META_JOB_ATTEMPTS        = '_attempts';
    public const META_JOB_CREATED         = '_created_at';
    public const META_JOB_PAYLOAD         = '_payload';
    public const META_JOB_ACTION_ID       = '_action_id';
    public const META_JOB_LAST_ERR        = '_last_error';
    public const META_JOB_DONE_AT         = '_done_at';

    // NEW per-job fields for retries + debugging
    public const META_JOB_LAST_CODES      = '_last_codes';      // JSON array
    public const META_JOB_NEXT_RUN_AT     = '_next_run_at';     // ISO8601
    public const META_JOB_LAST_STEP       = '_last_step';       // 'validate'|'place'|''

    // Snapshots
    public const META_JOB_VALIDATE_RESULT = '_validate_result'; // JSON
    public const META_JOB_PLACE_RESULT    = '_place_result';    // JSON

    /* ===================== Job status values ===================== */

    public const JOB_STATUS_QUEUED          = 'queued';
    public const JOB_STATUS_SCHEDULED       = 'scheduled';
    public const JOB_STATUS_RUNNING         = 'running';
    public const JOB_STATUS_SUCCESS         = 'success';
    public const JOB_STATUS_MANUAL          = 'manual';
    public const JOB_STATUS_FAILED          = 'failed';
    public const JOB_STATUS_RETRY_SCHEDULED = 'retry_scheduled';
    public const JOB_STATUS_BATCH_PENDING   = 'batch_pending';
    public const JOB_STATUS_AWAITING_ACK    = 'awaiting_ack';
    public const JOB_STATUS_PAUSED = 'paused';

    /* ===================== Action Scheduler ===================== */

    public const AS_GROUP = 'fflhub_order_placement';

    /* ===================== Helpers ===================== */

    public static function job_key_for_meta(string $job_key): string
    {
        $job_key = strtolower(trim($job_key));
        $job_key = str_replace('|', '__', $job_key);
        $job_key = preg_replace('/[^a-z0-9_]/', '_', $job_key) ?: $job_key;
        return $job_key;
    }

    public static function build_job_meta_key(string $job_key, string $suffix): string
    {
        return self::META_JOB_PREFIX . self::job_key_for_meta($job_key) . $suffix;
    }
}
