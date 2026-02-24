<?php

namespace FFLHub\Util;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Routes debug lines to split log files by distributor/component/date.
 *
 * Layout:
 *   wp-content/uploads/fflhub-logs/{distributor}/{component}/YYYY-MM-DD.log
 */
final class DebugLogFileRouter
{
    private const CLEANUP_LAST_RUN_OPTION = 'fflhub_log_retention_last_run_utc';
    private const DEFAULT_RETENTION_DAYS = 14;
    private const DEFAULT_CLEANUP_INTERVAL_SECONDS = 3600;

    /** @var array<string,bool> */
    private static $guardedBaseDirs = [];
    private static bool $cleanupCheckedThisRequest = false;

    private function __construct() {}

    public static function write(string $line, string $prefix = '', string $debugConstant = ''): void
    {
        $line = rtrim($line, "\r\n");

        $file = self::resolve_log_file($prefix, $debugConstant);
        if (is_string($file) && $file !== '') {
            $ok = @error_log($line . PHP_EOL, 3, $file);
            if ($ok) {
                return;
            }
        }

        error_log($line);
    }

    private static function resolve_log_file(string $prefix, string $debugConstant): ?string
    {
        $baseDir = self::resolve_base_dir();
        if (!is_string($baseDir) || $baseDir === '') {
            return null;
        }

        $route = self::resolve_route($prefix, $debugConstant);
        $distributor = self::sanitize_segment((string) ($route['distributor'] ?? 'core'));
        $component = self::sanitize_segment((string) ($route['component'] ?? 'general'));

        $dir = self::trail($baseDir) . $distributor . DIRECTORY_SEPARATOR . $component;
        if (!self::ensure_dir($dir)) {
            return null;
        }

        self::ensure_base_guards($baseDir);
        self::maybe_run_retention_cleanup($baseDir);

        return self::trail($dir) . gmdate('Y-m-d') . '.log';
    }

    private static function resolve_base_dir(): ?string
    {
        $base = '';

        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (is_array($uploads) && empty($uploads['error']) && !empty($uploads['basedir'])) {
                $base = self::trail((string) $uploads['basedir']) . 'fflhub-logs';
            }
        }

        if ($base === '' && defined('WP_CONTENT_DIR')) {
            $base = self::trail((string) WP_CONTENT_DIR) . 'uploads' . DIRECTORY_SEPARATOR . 'fflhub-logs';
        }

        if (function_exists('apply_filters')) {
            $base = (string) apply_filters('fflhub_log_base_dir', $base);
        }

        $base = trim($base);

        if ($base === '') {
            return null;
        }

        return $base;
    }

    /**
     * @return array{distributor:string,component:string}
     */
    private static function resolve_route(string $prefix, string $debugConstant): array
    {
        $haystack = strtolower(trim($prefix . ' ' . $debugConstant));

        $distributor = 'core';
        if (strpos($haystack, 'zanders') !== false) {
            $distributor = 'zanders';
        } elseif (strpos($haystack, 'lipseys') !== false || strpos($haystack, "lipsey") !== false) {
            $distributor = 'lipseys';
        } elseif (strpos($haystack, 'rsr') !== false) {
            $distributor = 'rsr';
        }

        $component = 'general';
        $componentMap = [
            'soap' => 'soap',
            'api' => 'api',
            'cron' => 'cron',
            'import' => 'importer',
            'inventory' => 'inventory',
            'fulfillment' => 'fulfillment',
            'shipping' => 'shipping',
            'tracking' => 'shipping',
            'order' => 'orders',
            'dispatch' => 'orders',
            'distributor' => 'distributor',
            'admin' => 'admin',
            'checkout' => 'checkout',
            'cart' => 'checkout',
            'email' => 'email',
            'ffl' => 'ffl',
            'cli' => 'cli',
        ];

        foreach ($componentMap as $token => $name) {
            if (strpos($haystack, $token) !== false) {
                $component = $name;
                break;
            }
        }

        if ($component === 'general') {
            $dc = strtoupper($debugConstant);
            if (strpos($dc, 'CRON') !== false) {
                $component = 'cron';
            } elseif (strpos($dc, 'SHIPPING') !== false) {
                $component = 'shipping';
            } elseif (strpos($dc, 'ADMIN') !== false) {
                $component = 'admin';
            } elseif (strpos($dc, 'API') !== false) {
                $component = 'api';
            }
        }

        $route = [
            'distributor' => $distributor,
            'component' => $component,
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('fflhub_log_route', $route, $prefix, $debugConstant);
            if (is_array($filtered)) {
                if (!empty($filtered['distributor'])) {
                    $route['distributor'] = (string) $filtered['distributor'];
                }
                if (!empty($filtered['component'])) {
                    $route['component'] = (string) $filtered['component'];
                }
            }
        }

        return $route;
    }

    private static function ensure_dir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        if (function_exists('wp_mkdir_p')) {
            return (bool) wp_mkdir_p($dir);
        }

        return @mkdir($dir, 0775, true);
    }

    private static function ensure_base_guards(string $baseDir): void
    {
        if (isset(self::$guardedBaseDirs[$baseDir])) {
            return;
        }

        if (!is_dir($baseDir)) {
            if (!self::ensure_dir($baseDir)) {
                return;
            }
        }

        $index = self::trail($baseDir) . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        self::$guardedBaseDirs[$baseDir] = true;
    }

    private static function maybe_run_retention_cleanup(string $baseDir): void
    {
        if (self::$cleanupCheckedThisRequest) {
            return;
        }
        self::$cleanupCheckedThisRequest = true;

        $retentionDays = self::retention_days();
        if ($retentionDays <= 0) {
            return;
        }

        $now = time();
        $interval = self::cleanup_interval_seconds();
        $lastRun = self::last_cleanup_ts();
        if ($interval > 0 && $lastRun > 0 && ($now - $lastRun) < $interval) {
            return;
        }

        $daySeconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        $cutoffTs = $now - ($retentionDays * $daySeconds);
        if ($cutoffTs <= 0) {
            return;
        }

        self::cleanup_old_logs($baseDir, $cutoffTs);
        self::set_last_cleanup_ts($now);
    }

    private static function retention_days(): int
    {
        $days = self::DEFAULT_RETENTION_DAYS;

        if (defined('FFLHUB_LOG_RETENTION_DAYS')) {
            $days = (int) constant('FFLHUB_LOG_RETENTION_DAYS');
        }

        if (function_exists('apply_filters')) {
            $days = (int) apply_filters('fflhub_log_retention_days', $days);
        }

        return max(0, $days);
    }

    private static function cleanup_interval_seconds(): int
    {
        $seconds = self::DEFAULT_CLEANUP_INTERVAL_SECONDS;

        if (defined('FFLHUB_LOG_RETENTION_CHECK_INTERVAL_SECONDS')) {
            $seconds = (int) constant('FFLHUB_LOG_RETENTION_CHECK_INTERVAL_SECONDS');
        }

        if (function_exists('apply_filters')) {
            $seconds = (int) apply_filters('fflhub_log_retention_check_interval_seconds', $seconds);
        }

        return max(0, $seconds);
    }

    private static function last_cleanup_ts(): int
    {
        if (!function_exists('get_option')) {
            return 0;
        }

        return max(0, (int) get_option(self::CLEANUP_LAST_RUN_OPTION, 0));
    }

    private static function set_last_cleanup_ts(int $ts): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        update_option(self::CLEANUP_LAST_RUN_OPTION, $ts, false);
    }

    private static function cleanup_old_logs(string $baseDir, int $cutoffTs): void
    {
        if (!is_dir($baseDir)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\Throwable $e) {
            return;
        }

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            $path = $entry->getPathname();

            if ($entry->isFile()) {
                if (strtolower((string) $entry->getExtension()) !== 'log') {
                    continue;
                }

                if ($entry->getMTime() >= $cutoffTs) {
                    continue;
                }

                @unlink($path);
                continue;
            }

            if (!$entry->isDir()) {
                continue;
            }

            self::remove_empty_dir($path, $baseDir);
        }
    }

    private static function remove_empty_dir(string $dir, string $baseDir): void
    {
        $dirNorm = str_replace('\\', '/', rtrim($dir, '/\\'));
        $baseNorm = str_replace('\\', '/', rtrim($baseDir, '/\\'));
        if ($dirNorm === $baseNorm) {
            return;
        }

        $scan = @scandir($dir);
        if (!is_array($scan)) {
            return;
        }

        $allowed = ['.', '..', 'index.php'];
        $remaining = array_values(array_diff($scan, $allowed));
        if (!empty($remaining)) {
            return;
        }

        $indexPath = self::trail($dir) . 'index.php';
        if (file_exists($indexPath)) {
            @unlink($indexPath);
        }

        @rmdir($dir);
    }

    private static function sanitize_segment(string $segment): string
    {
        $segment = strtolower(trim($segment));
        $segment = preg_replace('/[^a-z0-9_\-]/', '-', $segment);
        $segment = trim((string) $segment, '-');

        if ($segment === '') {
            return 'general';
        }

        return $segment;
    }

    private static function trail(string $path): string
    {
        return rtrim($path, "/\\") . DIRECTORY_SEPARATOR;
    }
}
