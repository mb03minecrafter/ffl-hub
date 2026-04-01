<?php

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Polls due quote-email jobs every minute and iterates each due row.
 *
 * Note: This class intentionally does NOT implement customer email sending yet.
 */
final class QuoteEmailJobsCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_quote_email_jobs_poll';

    private const BATCH_LIMIT = 100;

    private QuoteEmailJobsTable $jobs_table;

    public function __construct()
    {
        $schema = new QuoteEmailJobsSchema();
        $this->jobs_table = new QuoteEmailJobsTable($schema);
    }

    protected function get_interval_seconds(): int
    {
        return 60;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 60;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_quote_email';
    }

    public function run(): void
    {
        global $wpdb;

        $table_name = $this->jobs_table->get_table_name();
        $now_utc = (string) current_time('mysql', true);
        $limit = max(1, (int) self::BATCH_LIMIT);

        $sql = $wpdb->prepare(
            "SELECT id, request_first_name, request_last_name, request_email, quote_upc, quote_product_name, submitted_at, random_delay_minutes, email_sent
             FROM {$table_name}
             WHERE email_sent = 0
               AND DATE_ADD(submitted_at, INTERVAL random_delay_minutes MINUTE) <= %s
             ORDER BY submitted_at ASC, id ASC
             LIMIT %d",
            $now_utc,
            $limit
        );

        $due_jobs = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($due_jobs) || empty($due_jobs)) {
            return;
        }

        foreach ($due_jobs as $job_row) {
            if (!is_array($job_row)) {
                continue;
            }

            $this->handle_due_job($job_row);
        }
    }

    /**
     * Placeholder for future customer quote email dispatch.
     *
     * @param array<string,mixed> $job_row
     */
    private function handle_due_job(array $job_row): void
    {
        /**
         * Emits each due quote-email job row so send/update logic can be added later.
         */
        do_action('fflhub_quote_email_job_due', $job_row);
    }
}

