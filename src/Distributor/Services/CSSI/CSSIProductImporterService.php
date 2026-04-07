<?php

namespace FFLHub\Distributor\Services\CSSI;

use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Imports CSSI product-feed CSV rows into the staging table.
 */
class CSSIProductImporterService
{
    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][CSSIImporter]';

    private DoubleBufferedProductTable $table;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    public function import_from_csv_file(string $filePath): int
    {
        $tStart = microtime(true);
        $memStart = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        $this->log('---- IMPORT START ----', [
            'file_path' => $filePath,
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'memory_kb' => $memStart > 0 ? (int) round($memStart / 1024) : 0,
        ]);

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->finalize($tStart, $memStart, 'ERROR (missing/unreadable file)', [
                'file_path' => $filePath,
            ]);
            return 0;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->finalize($tStart, $memStart, 'ERROR (fopen failed)', [
                'file_path' => $filePath,
            ]);
            return 0;
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header) || empty($header)) {
            fclose($handle);
            $this->finalize($tStart, $memStart, 'ERROR (missing header)', []);
            return 0;
        }

        $parser = new CSSIProductParser();
        $headerMap = $parser->build_header_map($header);

        $this->log('Header parsed', [
            'header_column_count' => count($header),
            'normalized_key_count' => count($headerMap),
            'header_sample' => array_slice(array_values(array_map('strval', $header)), 0, 12),
        ]);

        if (!$parser->has_required_columns($headerMap)) {
            fclose($handle);
            $this->finalize($tStart, $memStart, 'ERROR (required columns missing)', [
                'required_hint' => 'Expected at least cssi_id/item_id or upc/upc_code style headers',
            ]);
            return 0;
        }

        $tTruncate = microtime(true);
        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            fclose($handle);
            $this->profile('truncate_staging failed', $tTruncate, ['error' => $e->getMessage()]);
            $this->finalize($tStart, $memStart, 'ERROR (truncate failed)', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
        $this->profile('truncate_staging', $tTruncate);

        $batchSize = 1000;
        $batchRows = [];

        $totalInserted = 0;
        $rowsSeen = 0;
        $rowsSkipped = 0;
        $rowsMissingUpc = 0;
        $rowsDupeUpc = 0;
        $batchFlushes = 0;
        $batchFailures = 0;
        $seenUpcs = [];

        $tParseTotal = 0.0;
        $tInsertTotal = 0.0;

        $flushBatch = function () use (&$batchRows, &$totalInserted, &$batchFlushes, &$batchFailures, &$tInsertTotal): void {
            if (empty($batchRows)) {
                return;
            }

            $batchFlushes++;
            $tIns = microtime(true);

            try {
                $inserted = (int) $this->table->insert_rows_into_staging($batchRows);
                $totalInserted += $inserted;
            } catch (\Throwable $e) {
                $batchFailures++;
                $this->log('Batch insert failed', [
                    'batch_size' => count($batchRows),
                    'error' => $e->getMessage(),
                ]);
            }

            $tInsertTotal += (microtime(true) - $tIns);
            $batchRows = [];
        };

        while (($csv = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowsSeen++;

            $tParse = microtime(true);
            $row = $parser->parse_csv_row($csv, $headerMap);
            $tParseTotal += (microtime(true) - $tParse);

            if ($row === null) {
                $rowsSkipped++;
                continue;
            }

            $upc = trim((string) ($row['upc'] ?? ''));
            if ($upc === '') {
                $rowsMissingUpc++;
                continue;
            }

            if (isset($seenUpcs[$upc])) {
                $rowsDupeUpc++;
                continue;
            }
            $seenUpcs[$upc] = true;

            $batchRows[] = $row;

            if (count($batchRows) >= $batchSize) {
                $flushBatch();
            }
        }

        fclose($handle);

        if (!empty($batchRows)) {
            $flushBatch();
        }

        $this->log('Import stats', [
            'rows_seen' => $rowsSeen,
            'inserted_rows' => $totalInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_missing_upc' => $rowsMissingUpc,
            'rows_dupe_upc' => $rowsDupeUpc,
            'batch_flushes' => $batchFlushes,
            'batch_failures' => $batchFailures,
            'parse_total_ms' => number_format($tParseTotal * 1000, 2, '.', ''),
            'insert_total_ms' => number_format($tInsertTotal * 1000, 2, '.', ''),
        ]);

        $status = $totalInserted > 0 ? 'SUCCESS' : 'NO ROWS';
        $this->finalize($tStart, $memStart, $status, [
            'inserted_rows' => $totalInserted,
            'rows_seen' => $rowsSeen,
        ]);

        return (int) $totalInserted;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log(self::DEBUG_FLAG, self::LOG_PREFIX, $message);
            return;
        }

        DebugLogUtil::log_ctx(self::DEBUG_FLAG, self::LOG_PREFIX, $message, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000, 2);
        $this->log('PROFILE: ' . $label, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize(float $tStart, int $memStart, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $this->profile('Total CSSI import', $tStart, $ctx);

        $memEnd = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        if ($memStart > 0 && $memEnd > 0) {
            $this->log('Memory usage summary', [
                'start_kb' => (int) round($memStart / 1024),
                'end_kb' => (int) round($memEnd / 1024),
                'delta_kb' => (int) round(($memEnd - $memStart) / 1024),
            ]);
        }

        $this->log('---- IMPORT END (' . $status . ') ----');
    }
}
