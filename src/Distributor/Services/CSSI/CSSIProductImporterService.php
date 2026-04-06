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
    private DoubleBufferedProductTable $table;

    public function __construct(DoubleBufferedProductTable $table)
    {
        $this->table = $table;
    }

    public function import_from_csv_file(string $filePath): int
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->log_debug('CSSI import file missing or unreadable: ' . $filePath);
            return 0;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->log_debug('Could not open CSSI CSV file: ' . $filePath);
            return 0;
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header) || empty($header)) {
            fclose($handle);
            $this->log_debug('CSSI CSV missing header row.');
            return 0;
        }

        $parser = new CSSIProductParser();
        $headerMap = $parser->build_header_map($header);
        if (!$parser->has_required_columns($headerMap)) {
            fclose($handle);
            $this->log_debug('CSSI CSV header missing expected item/upc columns.');
            return 0;
        }

        try {
            $this->table->truncate_staging();
        } catch (\Throwable $e) {
            fclose($handle);
            $this->log_debug('truncate_staging failed for CSSI import: ' . $e->getMessage());
            return 0;
        }

        $batchSize = 1000;
        $batchRows = [];

        $totalInserted = 0;
        $rowsSeen = 0;
        $rowsSkipped = 0;
        $rowsMissingUpc = 0;
        $rowsDupeUpc = 0;
        $seenUpcs = [];

        while (($csv = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowsSeen++;

            $row = $parser->parse_csv_row($csv, $headerMap);
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
                try {
                    $inserted = (int) $this->table->insert_rows_into_staging($batchRows);
                } catch (\Throwable $e) {
                    $this->log_debug('Batch insert failed during CSSI import: ' . $e->getMessage());
                    $inserted = 0;
                }

                $totalInserted += $inserted;
                $batchRows = [];
            }
        }

        fclose($handle);

        if (!empty($batchRows)) {
            try {
                $inserted = (int) $this->table->insert_rows_into_staging($batchRows);
            } catch (\Throwable $e) {
                $this->log_debug('Final batch insert failed during CSSI import: ' . $e->getMessage());
                $inserted = 0;
            }

            $totalInserted += $inserted;
        }

        $this->log_debug(
            sprintf(
                'CSSI import complete: rows_seen=%d, inserted=%d, skipped=%d, missing_upc=%d, dupe_upc=%d',
                $rowsSeen,
                $totalInserted,
                $rowsSkipped,
                $rowsMissingUpc,
                $rowsDupeUpc
            )
        );

        return (int) $totalInserted;
    }

    private function log_debug(string $message): void
    {
        DebugLogUtil::log('FFLHUB_CRON_DEBUG', '[FFLHub][CSSIImporter]', $message);
    }
}
