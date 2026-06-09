<?php
declare(strict_types=1);

namespace FFLHub\Distributor\Services\Cron;

use FFLHub\Util\DebugLogUtil;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron-specific profiling/logging wrapper around DebugLogUtil.
 *
 * This centralizes the common timing, memory, and summary formatting used by
 * distributor cron services while keeping the actual log emission on the
 * existing DebugLogUtil/DebugLogFileRouter path.
 */
final class CronRunLogger
{
    private string $debugConstant;
    private string $logPrefix;

    public function __construct(string $debugConstant, string $logPrefix)
    {
        $this->debugConstant = $debugConstant;
        $this->logPrefix = $logPrefix;
    }

    public static function create(string $debugConstant, string $logPrefix): self
    {
        return new self($debugConstant, $logPrefix);
    }

    public function now(): float
    {
        return microtime(true);
    }

    /**
     * Profile a callable step and return its result.
     *
     * @template T
     * @param callable():T $callback
     * @param array<string,mixed>|callable(mixed):array<string,mixed> $context
     * @return T
     */
    public function step(string $label, callable $callback, $context = [], bool $includeMemory = false)
    {
        $started = $this->now();
        $result = $callback();

        $ctx = (!is_array($context) && is_callable($context))
            ? (array) $context($result)
            : (array) $context;
        $this->profile($label, $started, $ctx, $includeMemory);

        return $result;
    }

    /** @param array<string,mixed> $ctx */
    public function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log($this->debugConstant, $this->logPrefix, $message);
            return;
        }

        DebugLogUtil::log_ctx($this->debugConstant, $this->logPrefix, $message, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function logAlways(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log_if(true, $this->logPrefix, $message, $this->debugConstant);
            return;
        }

        DebugLogUtil::log_if_ctx(true, $this->logPrefix, $message, $ctx, $this->debugConstant);
    }

    /** @param array<string,mixed> $ctx */
    public function profile(string $label, float $started, array $ctx = [], bool $includeMemory = false, bool $includePeakMemory = false): void
    {
        $ctx['elapsed_ms'] = $this->formatElapsedMs($started);

        if ($includeMemory) {
            $ctx['memory_kb'] = $this->memoryKb();
        }

        if ($includePeakMemory) {
            $ctx['memory_peak_kb'] = $this->memoryPeakKb();
        }

        $this->log('PROFILE: ' . $label, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function profileAlways(string $label, float $started, array $ctx = [], bool $includeMemory = false, bool $includePeakMemory = false): void
    {
        $ctx['elapsed_ms'] = $this->formatElapsedMs($started);

        if ($includeMemory) {
            $ctx['memory_kb'] = $this->memoryKb();
        }

        if ($includePeakMemory) {
            $ctx['memory_peak_kb'] = $this->memoryPeakKb();
        }

        $this->logAlways('PROFILE: ' . $label, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function phaseStart(string $label, array $ctx = []): void
    {
        $this->log('PHASE START: ' . $label, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function runStart(array $ctx = []): void
    {
        $this->log('---- RUN START ----', $ctx);
    }

    /**
     * Common distributor-cron finalizer that preserves the existing three-line
     * format: total profile, memory summary, and RUN END with status in message.
     *
     * @param array<string,mixed> $ctx
     */
    public function finishWithTotalProfile(float $started, int $memoryStart, string $status, array $ctx = []): void
    {
        $this->profile('Total cron run', $started, [
            'status' => (string) $status,
        ]);

        $this->logMemorySummary($memoryStart);

        if (!empty($ctx)) {
            $this->log("---- RUN END ({$status}) ----", $ctx);
            return;
        }

        $this->log("---- RUN END ({$status}) ----");
    }

    /**
     * Finalizer used by crons that preserve status/elapsed/memory inside the
     * final RUN END context payload.
     *
     * @param array<string,mixed> $ctx
     */
    public function finishWithContextSummary(
        float $started,
        int $memoryStart,
        string $status,
        array $ctx = [],
        bool $includePeakMemory = false,
        bool $always = false
    ): void {
        $ctx['status'] = $status;
        $ctx['elapsed_ms'] = $this->formatElapsedMs($started);

        if ($memoryStart > 0) {
            $memory = $this->memorySummary($memoryStart);
            if (!empty($memory)) {
                $ctx['memory_start_kb'] = $memory['start_kb'];
                $ctx['memory_end_kb'] = $memory['end_kb'];
                $ctx['memory_delta_kb'] = $memory['delta_kb'];
            }
        }

        if ($includePeakMemory) {
            $ctx['memory_peak_kb'] = $this->memoryPeakKb();
        }

        if ($always) {
            $this->logAlways('---- RUN END ----', $ctx);
            return;
        }

        $this->log('---- RUN END ----', $ctx);
    }

    /**
     * Finalizer used by CSSI-style crons that profile total runtime with the
     * supplied context, then log memory as a separate summary.
     *
     * @param array<string,mixed> $ctx
     */
    public function finishWithProfileAndEndMessage(float $started, int $memoryStart, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $this->profile('Total cron run', $started, $ctx);
        $this->logMemorySummary($memoryStart);
        $this->log("---- RUN END ({$status}) ----");
    }

    /** @return array{start_kb:int,end_kb:int,delta_kb:int} */
    public function memorySummary(int $memoryStart): array
    {
        $memoryEnd = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;

        if ($memoryStart <= 0 || $memoryEnd <= 0) {
            return [
                'start_kb' => 0,
                'end_kb' => 0,
                'delta_kb' => 0,
            ];
        }

        return [
            'start_kb' => (int) round($memoryStart / 1024),
            'end_kb' => (int) round($memoryEnd / 1024),
            'delta_kb' => (int) round(($memoryEnd - $memoryStart) / 1024),
        ];
    }

    public function logMemorySummary(int $memoryStart): void
    {
        $summary = $this->memorySummary($memoryStart);
        if ($summary['start_kb'] <= 0 || $summary['end_kb'] <= 0) {
            return;
        }

        $this->log('Memory usage summary', $summary);
    }

    public function memoryKb(): int
    {
        return function_exists('memory_get_usage') ? (int) round(memory_get_usage(true) / 1024) : 0;
    }

    public function memoryPeakKb(): int
    {
        return function_exists('memory_get_peak_usage') ? (int) round(memory_get_peak_usage(true) / 1024) : 0;
    }

    public function formatElapsedMs(float $started): string
    {
        return $this->formatMs((microtime(true) - $started) * 1000.0);
    }

    public function formatMs(float $milliseconds): string
    {
        return number_format($milliseconds, 2, '.', '');
    }
}
