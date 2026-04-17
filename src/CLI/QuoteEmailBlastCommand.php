<?php
declare(strict_types=1);

namespace FFLHub\CLI;

use FFLHub\Product\Tables\QuoteEmailJobsSchema;
use FFLHub\Product\Tables\QuoteEmailJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Send a one-time plain-text email to unique recipients from the quote-email jobs table.
 */
final class QuoteEmailBlastCommand
{
    /**
     * Send a one-time plain-text email to unique quote-request emails.
     *
     * ## OPTIONS
     *
     * --subject=<subject>
     * : Email subject line.
     *
     * [--message=<message>]
     * : Plain-text message body.
     *
     * [--message-file=<path>]
     * : Plain-text message body loaded from a file.
     *
     * [--from-email=<email>]
     * : Optional sender email address for the From header.
     *
     * [--reply-to=<email>]
     * : Optional Reply-To header.
     *
     * [--since-days=<days>]
     * : Only include quote submissions from the last N days.
     *
     * [--limit=<n>]
     * : Limit number of recipients after dedupe (default: 0 = no limit).
     *
     * [--offset=<n>]
     * : Offset into recipient list (default: 0).
     *
     * [--sleep-ms=<n>]
     * : Sleep N milliseconds between sends (default: 0).
     *
     * [--test-email=<email>]
     * : Send only to this email (ignores table recipients).
     *
     * [--dry-run]
     * : Preview recipients and counts without sending.
     *
     * ## EXAMPLES
     *
     *     wp fflhub quote-email-blast --subject="Service Update" --message-file=/tmp/msg.txt --dry-run
     *     wp fflhub quote-email-blast --subject="Service Update" --message="Hello from FFL Hub" --since-days=365 --sleep-ms=100
     *     wp fflhub quote-email-blast --subject="Test" --message="Test message" --test-email=me@example.com
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        if (!class_exists('\WP_CLI')) {
            return;
        }

        $subject = trim((string) ($assoc_args['subject'] ?? ''));
        $message_inline = (string) ($assoc_args['message'] ?? '');
        $message_file = trim((string) ($assoc_args['message-file'] ?? ''));
        $from_email = sanitize_email((string) ($assoc_args['from-email'] ?? ''));
        $reply_to = sanitize_email((string) ($assoc_args['reply-to'] ?? ''));
        $since_days = max(0, (int) ($assoc_args['since-days'] ?? 0));
        $limit = max(0, (int) ($assoc_args['limit'] ?? 0));
        $offset = max(0, (int) ($assoc_args['offset'] ?? 0));
        $sleep_ms = max(0, (int) ($assoc_args['sleep-ms'] ?? 0));
        $test_email = sanitize_email((string) ($assoc_args['test-email'] ?? ''));
        $dry_run = isset($assoc_args['dry-run']);

        if ($subject === '') {
            \WP_CLI::error('Missing required --subject.');
            return;
        }

        $message = '';
        if ($message_file !== '') {
            $message_from_file = @file_get_contents($message_file);
            if ($message_from_file === false) {
                \WP_CLI::error("Could not read --message-file: {$message_file}");
                return;
            }
            $message = (string) $message_from_file;
        } else {
            $message = $message_inline;
        }

        if (trim($message) === '') {
            \WP_CLI::error('Provide a non-empty --message or --message-file.');
            return;
        }

        if ($from_email !== '' && !is_email($from_email)) {
            \WP_CLI::error('Invalid --from-email.');
            return;
        }

        if ($reply_to !== '' && !is_email($reply_to)) {
            \WP_CLI::error('Invalid --reply-to.');
            return;
        }

        if ($test_email !== '' && !is_email($test_email)) {
            \WP_CLI::error('Invalid --test-email.');
            return;
        }

        $emails = ($test_email !== '')
            ? [$test_email]
            : $this->load_unique_quote_request_emails($since_days, $limit, $offset);

        if (empty($emails)) {
            \WP_CLI::warning('No recipients found.');
            return;
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if ($from_email !== '') {
            $headers[] = 'From: ' . $from_email;
        }
        if ($reply_to !== '') {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        \WP_CLI::log('Recipients: ' . count($emails));
        \WP_CLI::log('Subject: ' . $subject);
        if ($dry_run) {
            $preview = array_slice($emails, 0, 25);
            foreach ($preview as $recipient) {
                \WP_CLI::log('[dry-run] ' . $recipient);
            }
            if (count($emails) > count($preview)) {
                \WP_CLI::log('[dry-run] ...and ' . (count($emails) - count($preview)) . ' more');
            }
            \WP_CLI::success('Dry run complete. No emails were sent.');
            return;
        }

        $sent = 0;
        $failed = 0;

        foreach ($emails as $recipient) {
            $ok = wp_mail($recipient, $subject, $message, $headers);
            if ($ok) {
                $sent++;
            } else {
                $failed++;
                \WP_CLI::warning("Send failed: {$recipient}");
            }

            if ($sleep_ms > 0) {
                usleep($sleep_ms * 1000);
            }
        }

        if ($failed > 0) {
            \WP_CLI::warning("Completed with failures. sent={$sent} failed={$failed}");
            return;
        }

        \WP_CLI::success("Completed. sent={$sent} failed=0");
    }

    /**
     * @return string[]
     */
    private function load_unique_quote_request_emails(int $since_days, int $limit, int $offset): array
    {
        global $wpdb;

        $schema = new QuoteEmailJobsSchema();
        $table = new QuoteEmailJobsTable($schema);
        $table_name = $table->get_table_name();

        $where = ["request_email <> ''"];
        $params = [];

        if ($since_days > 0) {
            $since_utc = gmdate('Y-m-d H:i:s', time() - ($since_days * DAY_IN_SECONDS));
            $where[] = 'submitted_at >= %s';
            $params[] = $since_utc;
        }

        $sql = "SELECT DISTINCT LOWER(TRIM(request_email)) AS email
                FROM {$table_name}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY email ASC";

        if ($limit > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = $offset;
        } elseif ($offset > 0) {
            // MySQL requires LIMIT when OFFSET is present.
            $sql .= ' LIMIT 18446744073709551615 OFFSET %d';
            $params[] = $offset;
        }

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $raw = $wpdb->get_col($sql);
        if (!is_array($raw)) {
            \WP_CLI::warning('Recipient query failed: ' . (string) $wpdb->last_error);
            return [];
        }

        $seen = [];
        $emails = [];

        foreach ($raw as $value) {
            $email = sanitize_email((string) $value);
            if ($email === '' || !is_email($email)) {
                continue;
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $emails[] = $email;
        }

        return $emails;
    }
}

