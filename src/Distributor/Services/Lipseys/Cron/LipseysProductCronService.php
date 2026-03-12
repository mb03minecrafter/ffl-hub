<?php

namespace FFLHub\Distributor\Services\Lipseys\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\Lipseys\LipseysProductImporterService;
use FFLHub\Distributor\Services\Lipseys\LipseysProductParser;
use FFLHub\Distributor\Services\Lipseys\LipseysRawAPI\LipseysClient;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class LipseysProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_lipseys_fulfillment_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHUB][LipseysProductCron]';

    /**
     * 🔁 TOGGLE:
     * false = old Catalog() → array → importer
     * true  = new CatalogToTsv() streaming pipeline
     */
    private const USE_STREAMING_CATALOG = true;

    private ?LipseysProductImporterService $importer = null;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->importer = new LipseysProductImporterService($table);
        parent::__construct($table);
    }

    private function get_importer(): LipseysProductImporterService
    {
        if ($this->importer === null) {
            $this->importer = new LipseysProductImporterService($this->table);
        }
        return $this->importer;
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return 4 * HOUR_IN_SECONDS;
    }

    public function get_action_group(): string
    {
        return 'fflhub_catalog';
    }

    protected function get_initial_delay_seconds(): int
    {
        return 5 * MINUTE_IN_SECONDS;
    }

    public function run(): void
    {
        $t_start   = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $force_update = $this->should_force_update();

        @set_time_limit(0);

        $this->log('---- RUN START ----', [
            'pid'          => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb'    => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
            'mode'         => self::USE_STREAMING_CATALOG ? 'STREAMING_TSV' : 'ARRAY_IMPORT',
            'force_update' => $force_update ? 1 : 0,
        ]);

        if ($force_update) {
            $this->log('FORCE_UPDATE enabled - no freshness gate for Lipseys product cron');
        }

        // Credentials
        $dealer_email    = trim((string) Options::get_distributor_option('lipseys', 'dealer_email', ''));
        $dealer_password = trim((string) Options::get_distributor_option('lipseys', 'dealer_password', ''));

        if ($dealer_email === '' || $dealer_password === '') {
            $this->log('ERROR: missing Lipseys credentials');
            $this->finalize_run($t_start, $mem_start, 'ERROR');
            return;
        }

        // Client
        try {
            $client = new LipseysClient($dealer_email, $dealer_password);
        } catch (\Throwable $e) {
            $this->log('ERROR: LipseysClient init failed', ['error' => $e->getMessage()]);
            $this->finalize_run($t_start, $mem_start, 'ERROR');
            return;
        }

        /**
         * ======================================================
         * NEW PIPELINE: streaming JSON → TSV → LOAD DATA INFILE
         * ======================================================
         */
        if (self::USE_STREAMING_CATALOG) {
            $t_stream = microtime(true);

            // 1) Determine TSV output path (uploads/fflhub/lipseys/)
            $uploads = wp_upload_dir();
            $base_dir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
            if ($base_dir === '' || !is_dir($base_dir) || !is_writable($base_dir)) {
                $this->log('ERROR: uploads base dir not writable', ['basedir' => $base_dir]);
                $this->finalize_run($t_start, $mem_start, 'ERROR');
                return;
            }

            $out_dir = rtrim($base_dir, '/\\') . '/fflhub/lipseys';
            if (!is_dir($out_dir)) {
                @wp_mkdir_p($out_dir);
            }
            if (!is_dir($out_dir) || !is_writable($out_dir)) {
                $this->log('ERROR: TSV output dir not writable', ['out_dir' => $out_dir]);
                $this->finalize_run($t_start, $mem_start, 'ERROR');
                return;
            }

            $tsv_path = $out_dir . '/catalog_' . gmdate('Ymd_His') . '.tsv';

            // 2) Column order must match table insert order
            $schema = $this->table->get_schema();
            $columns = $schema->get_insert_columns();

            // 3) Item → Row mapper uses your existing parser (dropship filter happens there)
            $parser = new LipseysProductParser();

            // Enforce "first wins" de-dupe in the streaming mapper.
            $seen_upcs = [];

            $item_to_row = function (array $item) use ($parser, &$seen_upcs): ?array {
                $row = $parser->parse_item($item); // returns null if not dropship, etc.
                if (!is_array($row)) {
                    return null;
                }

                $upc = isset($row['upc']) ? trim((string) $row['upc']) : '';
                if ($upc === '' || strcasecmp($upc, 'null') === 0) {
                    return null;
                }

                // first wins
                if (isset($seen_upcs[$upc])) {
                    return null;
                }
                $seen_upcs[$upc] = true;

                return $row;
            };

            // 4) Stream API → TSV (NO huge in-memory array)
            try {
                $result = $client->CatalogToTsv($tsv_path, $columns, $item_to_row);
            } catch (\Throwable $e) {
                $this->log('ERROR: CatalogToTsv threw', ['error' => $e->getMessage()]);
                $this->finalize_run($t_start, $mem_start, 'ERROR');
                return;
            }

            $this->profile('CatalogToTsv()', $t_stream, [
                'tsv_path' => (string) ($result['tsv_path'] ?? $tsv_path),
                'items_seen' => (int) ($result['items_seen'] ?? 0),
                'rows_written' => (int) ($result['rows_written'] ?? 0),
                'items_skipped' => (int) ($result['items_skipped'] ?? 0),
                'bytes_received' => (int) ($result['bytes_received'] ?? 0),
                'json_decode_fails' => (int) ($result['json_decode_fails'] ?? 0),
            ]);

            // 5) Handle errors/unauthorized
            if (!is_array($result) || empty($result) || (isset($result['success']) && $result['success'] !== true)) {
                $this->log('ERROR: CatalogToTsv failed', [
                    'authorized'        => $result['authorized'] ?? null,
                    'errors'            => $result['errors'] ?? null,
                    'http_code'         => $result['http_code'] ?? null,
                    'curl_errno'        => $result['curl_errno'] ?? null,
                    'curl_error'        => $result['curl_error'] ?? null,
                    'bytes_received'    => $result['bytes_received'] ?? null,
                    'login_diagnostics' => $result['login_diagnostics'] ?? null,
                ]);
                $this->finalize_run($t_start, $mem_start, 'ERROR');
                return;
            }

            if ((int) ($result['rows_written'] ?? 0) <= 0) {
                $this->log('WARNING: streaming produced 0 rows (not swapping)', [
                    'items_seen' => (int) ($result['items_seen'] ?? 0),
                    'items_skipped' => (int) ($result['items_skipped'] ?? 0),
                ]);
                $this->finalize_run($t_start, $mem_start, 'NO SWAP (0 rows)');
                return;
            }

            // 6) Import TSV -> staging (mirrors RSR)
            $t_import = microtime(true);

            $imported = 0;
            try {
                $imported = (int) $this->get_importer()->import_from_tsv_file(
                    (string) ($result['tsv_path'] ?? $tsv_path),
                    $columns
                );
            } catch (\Throwable $e) {
                $this->log('ERROR: TSV import threw', [
                    'error'    => $e->getMessage(),
                    'tsv_path' => (string) ($result['tsv_path'] ?? $tsv_path),
                ]);
                $this->profile('Import TSV into staging (failed)', $t_import);
                $this->finalize_run($t_start, $mem_start, 'ERROR (TSV import exception)');
                return;
            }

            $this->profile('Import TSV into staging', $t_import, [
                'imported_rows' => (int) $imported,
            ]);

            if ($imported <= 0) {
                $this->log('WARNING: TSV import produced 0 rows, not swapping', [
                    'tsv_path'     => (string) ($result['tsv_path'] ?? $tsv_path),
                    'rows_written' => (int) ($result['rows_written'] ?? 0),
                ]);
                $this->finalize_run($t_start, $mem_start, 'NO SWAP (0 imported)', [
                    'rows_written' => (int) ($result['rows_written'] ?? 0),
                    'tsv_path'     => (string) ($result['tsv_path'] ?? $tsv_path),
                ]);
                return;
            }

            // 7) Swap staging ↔ live
            $t_swap = microtime(true);
            $new_live = '';

            try {
                $new_live = (string) $this->table->swap_live_and_staging();
            } catch (\Throwable $e) {
                $this->log('ERROR: swap threw', [
                    'error' => $e->getMessage(),
                ]);
                $this->profile('Swap staging ↔ live (failed)', $t_swap, [
                    'imported_rows' => (int) $imported,
                ]);
                $this->finalize_run($t_start, $mem_start, 'ERROR (swap exception)');
                return;
            }

            $this->profile('Swap staging ↔ live', $t_swap, [
                'new_live' => (string) $new_live,
            ]);

            // 8) Mark success (keep same option names as old pipeline)
            update_option('fflhub_lipseys_fulfillment_last_refresh', current_time('mysql'));
            update_option('fflhub_lipseys_fulfillment_last_refresh_count', (int) $imported);
            update_option('fflhub_lipseys_fulfillment_last_swap', current_time('mysql'));

            $this->finalize_run($t_start, $mem_start, 'SUCCESS (STREAMING)', [
                'rows_written'    => (int) ($result['rows_written'] ?? 0),
                'imported_rows'   => (int) $imported,
                'new_live'        => (string) $new_live,
                'tsv_path'        => (string) ($result['tsv_path'] ?? $tsv_path),
                'items_seen'      => (int) ($result['items_seen'] ?? 0),
                'items_skipped'   => (int) ($result['items_skipped'] ?? 0),
                'bytes_received'  => (int) ($result['bytes_received'] ?? 0),
                'json_decode_fails' => (int) ($result['json_decode_fails'] ?? 0),
            ]);
            return;
        }

    }

    // ---------------------------------------------

    private function log(string $msg, array $ctx = []): void
    {
        empty($ctx)
            ? DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $msg)
            : DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $msg, $ctx);
    }

    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000, 2);
        $this->log("PROFILE: {$label}", $ctx);
    }

    private function finalize_run(float $t_start, int $mem_start, string $status): void
    {
        $this->profile('Total cron run', $t_start, ['status' => $status]);

        $mem_end = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($mem_start && $mem_end) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($mem_start / 1024),
                'end_kb'   => (int) round($mem_end / 1024),
                'delta_kb' => (int) round(($mem_end - $mem_start) / 1024),
            ]);
        }

        $this->log("---- RUN END ({$status}) ----");
    }
}
