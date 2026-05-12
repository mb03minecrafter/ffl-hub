<?php

namespace FFLHub\Checkout;

use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

final class MailPoetAutoConfirmCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_mailpoet_auto_confirm_subscribers';

    private const DEBUG_CONST = 'FFLHUB_MAILPOET_AUTO_CONFIRM_DEBUG';
    private const LOG_PREFIX = '[FFLHub][MailPoetAutoConfirm]';

    /**
     * Production MailPoet list id for the public deals and updates list.
     *
     * @var int[]
     */
    private const DEFAULT_TARGET_LIST_IDS = [4];

    protected function get_interval_seconds(): int
    {
        return 30 * 60;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 10 * 60;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    public function get_action_group(): string
    {
        return 'fflhub_mailpoet';
    }

    public function run(): void
    {
        global $wpdb;

        $started = microtime(true);

        $segments_table = $wpdb->prefix . 'mailpoet_segments';
        $subscribers_table = $wpdb->prefix . 'mailpoet_subscribers';
        $relation_table = $wpdb->prefix . 'mailpoet_subscriber_segment';

        $missing_tables = $this->missing_tables([$segments_table, $subscribers_table, $relation_table]);
        if (!empty($missing_tables)) {
            self::debug_ctx('run skipped: MailPoet tables missing', [
                'missing_tables' => $missing_tables,
            ]);
            return;
        }

        $target_list_ids = $this->target_list_ids();
        if (empty($target_list_ids)) {
            self::debug('run skipped: no target MailPoet list ids configured');
            return;
        }

        $existing_list_ids = $this->existing_list_ids($segments_table, $target_list_ids);
        if (empty($existing_list_ids)) {
            self::debug_ctx('run skipped: target MailPoet lists not found', [
                'target_list_ids' => $target_list_ids,
            ]);
            return;
        }

        $before = $this->count_unconfirmed_targets(
            $subscribers_table,
            $relation_table,
            $existing_list_ids
        );

        if ($before < 1) {
            self::debug_ctx('run complete: nothing to confirm', [
                'target_list_ids' => $existing_list_ids,
                'elapsed_ms' => round((microtime(true) - $started) * 1000.0, 2),
            ]);
            return;
        }

        $wpdb->query('START TRANSACTION');

        $relation_rows = $this->mark_relations_subscribed(
            $subscribers_table,
            $relation_table,
            $existing_list_ids
        );

        $subscriber_rows = $this->mark_subscribers_confirmed(
            $subscribers_table,
            $relation_table,
            $existing_list_ids
        );

        if ($wpdb->last_error !== '') {
            $error = $wpdb->last_error;
            $wpdb->query('ROLLBACK');

            self::debug_ctx('run failed: database error', [
                'target_list_ids' => $existing_list_ids,
                'error' => $error,
            ]);
            return;
        }

        $wpdb->query('COMMIT');

        $after = $this->count_unconfirmed_targets(
            $subscribers_table,
            $relation_table,
            $existing_list_ids
        );

        self::debug_ctx('run complete', [
            'target_list_ids' => $existing_list_ids,
            'unconfirmed_before' => $before,
            'subscriber_rows_confirmed' => $subscriber_rows,
            'relation_rows_corrected' => $relation_rows,
            'unconfirmed_after' => $after,
            'elapsed_ms' => round((microtime(true) - $started) * 1000.0, 2),
        ]);
    }

    /**
     * @param string[] $tables
     * @return string[]
     */
    private function missing_tables(array $tables): array
    {
        global $wpdb;

        $missing = [];
        foreach ($tables as $table) {
            $found = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found !== $table) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return int[]
     */
    private function target_list_ids(): array
    {
        $ids = apply_filters('fflhub_mailpoet_auto_confirm_list_ids', self::DEFAULT_TARGET_LIST_IDS);
        if (!is_array($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * @param int[] $target_list_ids
     * @return int[]
     */
    private function existing_list_ids(string $segments_table, array $target_list_ids): array
    {
        global $wpdb;

        $in = $this->prepare_in_clause($target_list_ids);
        if ($in === '') {
            return [];
        }

        $sql = "
            SELECT id
            FROM {$segments_table}
            WHERE deleted_at IS NULL
              AND id IN ({$in})
        ";

        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    /**
     * @param int[] $list_ids
     */
    private function count_unconfirmed_targets(string $subscribers_table, string $relation_table, array $list_ids): int
    {
        global $wpdb;

        $in = $this->prepare_in_clause($list_ids);
        if ($in === '') {
            return 0;
        }

        $sql = "
            SELECT COUNT(DISTINCT s.id)
            FROM {$subscribers_table} s
            INNER JOIN {$relation_table} r ON r.subscriber_id = s.id
            WHERE r.segment_id IN ({$in})
              AND s.deleted_at IS NULL
              AND s.status = 'unconfirmed'
              AND r.status <> 'unsubscribed'
        ";

        return (int) $wpdb->get_var($sql);
    }

    /**
     * @param int[] $list_ids
     */
    private function mark_relations_subscribed(string $subscribers_table, string $relation_table, array $list_ids): int
    {
        global $wpdb;

        $in = $this->prepare_in_clause($list_ids);
        if ($in === '') {
            return 0;
        }

        $sql = "
            UPDATE {$relation_table} r
            INNER JOIN {$subscribers_table} s ON s.id = r.subscriber_id
            SET r.status = 'subscribed',
                r.updated_at = UTC_TIMESTAMP()
            WHERE r.segment_id IN ({$in})
              AND s.deleted_at IS NULL
              AND s.status = 'unconfirmed'
              AND r.status = 'unconfirmed'
        ";

        $rows = $wpdb->query($sql);
        return is_int($rows) ? $rows : 0;
    }

    /**
     * @param int[] $list_ids
     */
    private function mark_subscribers_confirmed(string $subscribers_table, string $relation_table, array $list_ids): int
    {
        global $wpdb;

        $in = $this->prepare_in_clause($list_ids);
        if ($in === '') {
            return 0;
        }

        $sql = "
            UPDATE {$subscribers_table} s
            INNER JOIN {$relation_table} r ON r.subscriber_id = s.id
            SET s.status = 'subscribed',
                s.confirmed_at = COALESCE(s.confirmed_at, UTC_TIMESTAMP()),
                s.last_subscribed_at = COALESCE(s.last_subscribed_at, UTC_TIMESTAMP()),
                s.updated_at = UTC_TIMESTAMP()
            WHERE r.segment_id IN ({$in})
              AND s.deleted_at IS NULL
              AND s.status = 'unconfirmed'
              AND r.status = 'subscribed'
        ";

        $rows = $wpdb->query($sql);
        return is_int($rows) ? $rows : 0;
    }

    /**
     * @param int[] $ids
     */
    private function prepare_in_clause(array $ids): string
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if (empty($ids)) {
            return '';
        }

        return implode(',', $ids);
    }

    private static function debug(string $message): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $message);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function debug_ctx(string $message, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $message, $ctx);
    }
}
