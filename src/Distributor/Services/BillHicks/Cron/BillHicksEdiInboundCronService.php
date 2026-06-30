<?php

namespace FFLHub\Distributor\Services\BillHicks\Cron;

use FFLHub\Distributor\Services\BillHicks\BillHicksEdiFtpExchange;
use FFLHub\Distributor\Services\BillHicks\BillHicksEdiInboundParser;
use FFLHub\Distributor\Services\BillHicks\BillHicksEdiStore;
use FFLHub\Distributor\Services\Cron\AbstractCronService;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Polls Bill Hicks inbound EDI files.
 *
 * 855 ACK files decide whether an uploaded 850 order becomes successful or
 * needs manual handling. 856 ASN files cache tracking data for the existing
 * shipping poller via DistributorBillHicks::get_shipment_by_po().
 */
final class BillHicksEdiInboundCronService extends AbstractCronService
{
    public const CRON_HOOK = 'fflhub_bill_hicks_edi_inbound_poll';

    private const LOG_PREFIX = '[FFLHUB][BillHicksEdiInboundCron]';
    private const LOCAL_DIR = 'fflhub-bill-hicks-edi/inbound';

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 10 * MINUTE_IN_SECONDS;
    }

    protected function get_initial_delay_seconds(): int
    {
        return 3 * MINUTE_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_orders';
    }

    public function on_activation(): void
    {
        (new BillHicksEdiStore())->ensure_tables();
        parent::on_activation();
    }

    public function run(): void
    {
        $started = microtime(true);
        $stats = [
            'listed' => 0,
            'already_processed' => 0,
            'downloaded' => 0,
            'files_recorded' => 0,
            'acks' => 0,
            'shipments' => 0,
            'ack_jobs_touched' => 0,
            'stored_ack_jobs_touched' => 0,
            'errors' => 0,
        ];

        if (!Options::is_distributor_enabled('bill_hicks')) {
            $this->log('skip disabled distributor');
            return;
        }

        $store = new BillHicksEdiStore();
        $store->ensure_tables();

        $exchange = new BillHicksEdiFtpExchange();
        $parser = new BillHicksEdiInboundParser();

        $uploads = wp_upload_dir();
        $local_dir = trailingslashit((string) ($uploads['basedir'] ?? '')) . self::LOCAL_DIR;
        if (!wp_mkdir_p($local_dir)) {
            $this->log('ERROR failed to create local inbound dir', ['local_dir' => $local_dir]);
            return;
        }

        $remote_files = $exchange->list_inbound_files();
        $stats['listed'] = count($remote_files);

        foreach ($remote_files as $remote_path) {
            $remote_path = trim((string) $remote_path);
            if ($remote_path === '') {
                continue;
            }

            $mtime = $exchange->remote_mtime($remote_path);
            $size = $exchange->remote_size($remote_path);
            if ($store->is_file_processed($remote_path, $mtime, $size)) {
                $stats['already_processed']++;
                continue;
            }

            $download = $exchange->download_inbound_file($remote_path, $local_dir);
            if (empty($download['ok'])) {
                $stats['errors']++;
                $this->log('ERROR download failed', [
                    'remote_path' => $remote_path,
                    'error' => (string) ($download['error'] ?? ''),
                ]);
                continue;
            }

            $stats['downloaded']++;
            $local_path = (string) ($download['local_path'] ?? '');
            $mtime = (int) ($download['mtime'] ?? $mtime);
            $size = (int) ($download['size'] ?? $size);
            $parsed = $parser->parse_file($local_path);

            $acks = is_array($parsed['acks'] ?? null) ? $parsed['acks'] : [];
            $shipments = is_array($parsed['shipments'] ?? null) ? $parsed['shipments'] : [];
            $errors = is_array($parsed['errors'] ?? null) ? $parsed['errors'] : [];
            $file_type = (string) ($parsed['file_type'] ?? 'unknown');

            $file_id = $store->insert_file_record(
                $remote_path,
                $mtime,
                $size,
                $local_path,
                $file_type,
                empty($errors) ? 'processed' : 'processed_with_errors',
                implode(' | ', array_slice($errors, 0, 5)),
                [
                    'ack_rows' => count($acks),
                    'shipment_rows' => count($shipments),
                    'errors' => $errors,
                ]
            );
            $stats['files_recorded']++;

            $ack_groups = $store->group_ack_rows_by_po($acks);
            foreach ($ack_groups as $po => $group) {
                $store->insert_ack_group($file_id, (string) $po, $group);
                $stats['acks']++;
                $stats['ack_jobs_touched'] += $store->apply_ack_to_jobs((string) $po, $group);
            }

            foreach ($shipments as $row) {
                $store->insert_shipment_row($file_id, $row);
                $stats['shipments']++;
            }

            if (!empty($errors)) {
                $stats['errors'] += count($errors);
                $this->log('parser warnings', [
                    'remote_path' => $remote_path,
                    'errors' => $errors,
                ]);
            }
        }

        $stats['stored_ack_jobs_touched'] = $store->reconcile_stored_acks_to_waiting_jobs();
        $stats['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        $this->log('run complete', $stats);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', self::LOG_PREFIX, $message . (!empty($ctx) ? ' ' . wp_json_encode($ctx) : ''));
    }
}
