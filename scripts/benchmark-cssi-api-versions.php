<?php
/**
 * Benchmark Chattanooga/CSSI REST API versions without touching live tables.
 *
 * Usage:
 *   wp --path="$WP_PATH" --allow-root --skip-themes eval-file \
 *     "$PLUGIN/scripts/benchmark-cssi-api-versions.php" -- \
 *     mode=inventory versions=v5,v6 cursor=current
 *
 * Optional args:
 *   mode=inventory|product-feed|both  Default: inventory
 *   versions=v5,v6                    Default: v5,v6
 *   cursor=current|none|<UTC date>      Default: current
 *   per-page=50                        Default: 50
 *   max-pages=0                        0 means all returned pages
 *   runs=1                             Repeat each version N times
 *   timeout=90                         JSON request timeout seconds
 *   download-feed=0|1                  Only for product-feed/both; default 0
 *   output-dir=/tmp/cssi-api-benchmark Default: /tmp/cssi-api-benchmark
 *   keep-files=0|1                     Keep downloaded feed files; default 0
 *
 * This is intentionally a test harness: it does not call the cron service,
 * does not update wp_options cursors, does not write distributor tables, and
 * does not swap live/staging tables.
 */

use FFLHub\Distributor\Services\CSSI\CSSIProductParser;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(Options::class) || !class_exists(CSSIProductParser::class)) {
    fwrite(STDERR, "Required FFLHub classes are not loaded.\n");
    exit(1);
}

$args = cssi_bench_parse_args($argv ?? []);
$mode = strtolower((string) cssi_bench_arg($args, 'mode', 'inventory'));
if (!in_array($mode, ['inventory', 'product-feed', 'both'], true)) {
    cssi_bench_fail('Invalid mode. Use inventory, product-feed, or both.');
}

$versions = array_filter(array_map('trim', explode(',', (string) cssi_bench_arg($args, 'versions', 'v5,v6'))));
$versions = array_values(array_map(static function (string $version): string {
    $version = strtolower(trim($version));
    if ($version !== '' && $version[0] !== 'v') {
        $version = 'v' . $version;
    }
    return $version;
}, $versions));

if (empty($versions)) {
    cssi_bench_fail('No versions supplied.');
}

$sid = trim((string) Options::get_distributor_option('cssi', 'sid', ''));
$token = trim((string) Options::get_distributor_option('cssi', 'token', ''));
if ($sid === '' || $token === '') {
    cssi_bench_fail('Missing CSSI SID/token settings.');
}

$cursorArg = trim((string) cssi_bench_arg($args, 'cursor', 'current'));
$cursorUtc = '';
if (strcasecmp($cursorArg, 'current') === 0) {
    $cursorUtc = trim((string) get_option('fflhub_cssi_inventory_cursor_utc', ''));
} elseif (strcasecmp($cursorArg, 'none') !== 0 && $cursorArg !== '') {
    $cursorUtc = $cursorArg;
}

$perPage = max(1, min(50, (int) cssi_bench_arg($args, 'per-page', 50)));
$maxPages = max(0, (int) cssi_bench_arg($args, 'max-pages', 0));
$runs = max(1, min(10, (int) cssi_bench_arg($args, 'runs', 1)));
$timeout = max(10, min(300, (int) cssi_bench_arg($args, 'timeout', 90)));
$downloadFeed = cssi_bench_truthy(cssi_bench_arg($args, 'download-feed', '0'));
$keepFiles = cssi_bench_truthy(cssi_bench_arg($args, 'keep-files', '0'));
$outputDir = rtrim((string) cssi_bench_arg($args, 'output-dir', '/tmp/cssi-api-benchmark'), '/\\');

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    cssi_bench_fail('Could not create output directory: ' . $outputDir);
}

$report = [
    'started_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'mode' => $mode,
    'versions' => $versions,
    'cursor_arg' => $cursorArg,
    'cursor_utc' => $cursorUtc !== '' ? $cursorUtc : null,
    'per_page' => $perPage,
    'max_pages' => $maxPages,
    'runs' => $runs,
    'timeout_sec' => $timeout,
    'download_feed' => $downloadFeed ? 1 : 0,
    'results' => [],
];

cssi_bench_line('CSSI API version benchmark');
cssi_bench_line('Mode: ' . $mode);
cssi_bench_line('Versions: ' . implode(', ', $versions));
cssi_bench_line('Cursor: ' . ($cursorUtc !== '' ? $cursorUtc : '[none/full]'));
cssi_bench_line('Per page: ' . $perPage . '; max pages: ' . ($maxPages > 0 ? (string) $maxPages : 'all'));
cssi_bench_line('');

foreach ($versions as $version) {
    for ($run = 1; $run <= $runs; $run++) {
        $baseUrl = 'https://api.chattanoogashooting.com/rest/' . $version . '/';
        cssi_bench_line('--- ' . strtoupper($version) . ' run ' . $run . ' ---');

        $versionResult = [
            'version' => $version,
            'run' => $run,
            'base_url' => $baseUrl,
        ];

        if ($mode === 'inventory' || $mode === 'both') {
            $inventory = cssi_bench_inventory($baseUrl, $sid, $token, $cursorUtc, $perPage, $maxPages, $timeout);
            $versionResult['inventory'] = $inventory;
            cssi_bench_line(cssi_bench_inventory_summary($inventory));
        }

        if ($mode === 'product-feed' || $mode === 'both') {
            $feed = cssi_bench_product_feed($baseUrl, $sid, $token, $outputDir, $downloadFeed, $keepFiles, $timeout);
            $versionResult['product_feed'] = $feed;
            cssi_bench_line(cssi_bench_product_feed_summary($feed));
        }

        $report['results'][] = $versionResult;
        cssi_bench_line('');
    }
}

$report['finished_utc'] = gmdate('Y-m-d\TH:i:s\Z');
$report['comparison'] = cssi_bench_compare($report['results'], $mode);

$reportPath = $outputDir . '/cssi-api-version-benchmark-' . gmdate('Ymd_His') . '.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

cssi_bench_line('--- comparison ---');
foreach ($report['comparison'] as $line) {
    cssi_bench_line($line);
}
cssi_bench_line('');
cssi_bench_line('Report: ' . $reportPath);

/**
 * @param array<int,string> $argv
 * @return array<string,string>
 */
function cssi_bench_parse_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        $arg = trim((string) $arg);
        if ($arg === '') {
            continue;
        }
        $arg = ltrim($arg, '-');
        if (strpos($arg, '=') === false) {
            $out[$arg] = '1';
            continue;
        }
        [$key, $value] = array_pad(explode('=', $arg, 2), 2, '');
        $key = trim($key);
        if ($key !== '') {
            $out[$key] = trim($value);
        }
    }
    return $out;
}

/**
 * @param array<string,string> $args
 * @return mixed
 */
function cssi_bench_arg(array $args, string $key, $default)
{
    return array_key_exists($key, $args) ? $args[$key] : $default;
}

/**
 * @param mixed $value
 */
function cssi_bench_truthy($value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on'], true);
}

function cssi_bench_line(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::line($message);
        return;
    }
    echo $message . PHP_EOL;
}

function cssi_bench_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * @return array<string,mixed>
 */
function cssi_bench_inventory(
    string $baseUrl,
    string $sid,
    string $token,
    string $cursorUtc,
    int $perPage,
    int $maxPages,
    int $timeout
): array {
    $parser = new CSSIProductParser();
    $tStart = microtime(true);
    $rowsByKey = [];
    $page = 1;
    $pagesSeen = 0;
    $pageCount = 1;
    $itemsSeen = 0;
    $rowsParsed = 0;
    $rowsNoKey = 0;
    $parseSkipped = 0;
    $dedupeReplaced = 0;
    $bodyBytes = 0;
    $httpMs = 0.0;
    $decodeMs = 0.0;
    $parseMs = 0.0;
    $pageStats = [];
    $errors = [];

    $baseQuery = [];
    if ($cursorUtc !== '') {
        $baseQuery['qas_last_updated_after'] = $cursorUtc;
    }

    while (true) {
        $query = array_merge($baseQuery, [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $pageStart = microtime(true);
        $res = cssi_bench_request_json($baseUrl, $sid, $token, 'items', $query, $timeout);
        $pageWallMs = (microtime(true) - $pageStart) * 1000.0;

        $httpMs += (float) ($res['http_ms'] ?? 0.0);
        $decodeMs += (float) ($res['decode_ms'] ?? 0.0);
        $bodyBytes += (int) ($res['body_bytes'] ?? 0);

        if (empty($res['ok'])) {
            $errors[] = [
                'page' => $page,
                'status' => (int) ($res['status'] ?? 0),
                'error' => (string) ($res['error'] ?? 'request failed'),
            ];
            break;
        }

        $data = is_array($res['data'] ?? null) ? (array) $res['data'] : [];
        $items = isset($data['items']) && is_array($data['items']) ? (array) $data['items'] : [];
        $pagination = isset($data['pagination']) && is_array($data['pagination']) ? (array) $data['pagination'] : [];
        $pageCount = max(1, (int) ($pagination['page_count'] ?? $pageCount));

        $pageParsed = 0;
        $pageSkipped = 0;
        $pageNoKey = 0;
        $pageDedupe = 0;

        $tParse = microtime(true);
        foreach ($items as $item) {
            if (!is_array($item)) {
                $parseSkipped++;
                $pageSkipped++;
                continue;
            }

            $row = $parser->parse_api_item($item);
            if (!is_array($row)) {
                $parseSkipped++;
                $pageSkipped++;
                continue;
            }

            $rowsParsed++;
            $pageParsed++;

            $key = cssi_bench_stage_key($row);
            if ($key === '') {
                $rowsNoKey++;
                $pageNoKey++;
                continue;
            }

            if (isset($rowsByKey[$key])) {
                $dedupeReplaced++;
                $pageDedupe++;
            }

            $rowsByKey[$key] = $row;
        }
        $parseMs += (microtime(true) - $tParse) * 1000.0;

        $itemsSeen += count($items);
        $pagesSeen = max($pagesSeen, $page);
        $pageStats[] = [
            'page' => $page,
            'page_count' => $pageCount,
            'status' => (int) ($res['status'] ?? 0),
            'raw_items' => count($items),
            'parsed_rows' => $pageParsed,
            'parse_skipped' => $pageSkipped,
            'rows_no_key' => $pageNoKey,
            'dedupe_replaced' => $pageDedupe,
            'unique_rows_so_far' => count($rowsByKey),
            'body_bytes' => (int) ($res['body_bytes'] ?? 0),
            'http_ms' => round((float) ($res['http_ms'] ?? 0.0), 2),
            'decode_ms' => round((float) ($res['decode_ms'] ?? 0.0), 2),
            'wall_ms' => round($pageWallMs, 2),
        ];

        if (empty($items) || $page >= $pageCount) {
            break;
        }

        if ($maxPages > 0 && $page >= $maxPages) {
            break;
        }

        $page++;
    }

    return [
        'ok' => empty($errors),
        'cursor_utc' => $cursorUtc !== '' ? $cursorUtc : null,
        'per_page' => $perPage,
        'max_pages' => $maxPages,
        'pages_seen' => $pagesSeen,
        'page_count_reported' => $pageCount,
        'items_seen' => $itemsSeen,
        'rows_parsed' => $rowsParsed,
        'unique_rows' => count($rowsByKey),
        'rows_no_key' => $rowsNoKey,
        'parse_skipped' => $parseSkipped,
        'dedupe_replaced' => $dedupeReplaced,
        'body_bytes' => $bodyBytes,
        'http_ms' => round($httpMs, 2),
        'json_decode_ms' => round($decodeMs, 2),
        'parse_ms' => round($parseMs, 2),
        'wall_ms' => round((microtime(true) - $tStart) * 1000.0, 2),
        'errors' => $errors,
        'pages' => $pageStats,
    ];
}

/**
 * @return array<string,mixed>
 */
function cssi_bench_product_feed(
    string $baseUrl,
    string $sid,
    string $token,
    string $outputDir,
    bool $downloadFeed,
    bool $keepFiles,
    int $timeout
): array {
    $tStart = microtime(true);
    $feedRes = cssi_bench_request_json($baseUrl, $sid, $token, 'items/product-feed', [
        'optional_columns' => 'specifications,retail_map',
    ], max($timeout, 120));

    $data = is_array($feedRes['data'] ?? null) ? (array) $feedRes['data'] : [];
    $feed = isset($data['product_feed']) && is_array($data['product_feed']) ? (array) $data['product_feed'] : [];
    $url = trim((string) ($feed['url'] ?? ''));
    $url = cssi_bench_normalize_feed_url($url);

    $out = [
        'ok' => !empty($feedRes['ok']) && $url !== '',
        'url_request_status' => (int) ($feedRes['status'] ?? 0),
        'url_request_ms' => round((float) ($feedRes['http_ms'] ?? 0.0), 2),
        'url_decode_ms' => round((float) ($feedRes['decode_ms'] ?? 0.0), 2),
        'url_body_bytes' => (int) ($feedRes['body_bytes'] ?? 0),
        'feed_url_head' => substr($url, 0, 220),
        'download_requested' => $downloadFeed ? 1 : 0,
    ];

    if (!$out['ok'] || !$downloadFeed) {
        $out['wall_ms'] = round((microtime(true) - $tStart) * 1000.0, 2);
        if (!$out['ok']) {
            $out['error'] = (string) ($feedRes['error'] ?? 'product feed URL missing');
        }
        return $out;
    }

    $versionPath = trim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
    $versionSlug = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $versionPath);
    $versionSlug = is_string($versionSlug) && $versionSlug !== '' ? $versionSlug : 'unknown-version';
    $file = rtrim($outputDir, '/\\') . '/cssi-product-feed-' . $versionSlug . '.csv';

    $download = cssi_bench_download_file($url, $sid, $token, $file, max($timeout, 240));
    $out['download'] = $download;
    $out['download_ok'] = !empty($download['ok']) ? 1 : 0;
    $out['download_ms'] = round((float) ($download['wall_ms'] ?? 0.0), 2);
    $out['download_bytes'] = (int) ($download['bytes'] ?? 0);

    if (!$keepFiles && is_file($file)) {
        @unlink($file);
    }

    $out['wall_ms'] = round((microtime(true) - $tStart) * 1000.0, 2);
    return $out;
}

/**
 * @param array<string,mixed> $query
 * @return array<string,mixed>
 */
function cssi_bench_request_json(string $baseUrl, string $sid, string $token, string $path, array $query, int $timeout): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'cURL not available'];
    }

    $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl_init failed'];
    }

    $headers = [
        'Authorization: Basic ' . $sid . ':' . md5($token),
        'Accept: application/json',
        'User-Agent: FFLHub-CSSI-Benchmark/1.0',
    ];

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'FFLHub-CSSI-Benchmark/1.0',
        CURLOPT_ENCODING => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
    ]);

    $raw = curl_exec($ch);
    $errno = (int) curl_errno($ch);
    $error = (string) curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $info = [
        'total_time_ms' => round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000.0, 2),
        'primary_ip' => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'size_download' => (float) curl_getinfo($ch, defined('CURLINFO_SIZE_DOWNLOAD_T') ? CURLINFO_SIZE_DOWNLOAD_T : CURLINFO_SIZE_DOWNLOAD),
        'speed_download' => (float) curl_getinfo($ch, CURLINFO_SPEED_DOWNLOAD),
    ];
    curl_close($ch);

    $body = is_string($raw) ? $raw : '';
    $decodeStart = microtime(true);
    $decoded = json_decode($body, true);
    $decodeMs = (microtime(true) - $decodeStart) * 1000.0;

    if ($raw === false || $errno !== 0) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $error !== '' ? $error : 'cURL transport failure',
            'curl_errno' => $errno,
            'body_bytes' => strlen($body),
            'http_ms' => (float) $info['total_time_ms'],
            'decode_ms' => round($decodeMs, 2),
            'info' => $info,
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'HTTP status ' . $status,
            'body_preview' => substr($body, 0, 500),
            'body_bytes' => strlen($body),
            'http_ms' => (float) $info['total_time_ms'],
            'decode_ms' => round($decodeMs, 2),
            'info' => $info,
        ];
    }

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'Invalid JSON: ' . json_last_error_msg(),
            'body_preview' => substr($body, 0, 500),
            'body_bytes' => strlen($body),
            'http_ms' => (float) $info['total_time_ms'],
            'decode_ms' => round($decodeMs, 2),
            'info' => $info,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'data' => $decoded,
        'body_bytes' => strlen($body),
        'http_ms' => (float) $info['total_time_ms'],
        'decode_ms' => round($decodeMs, 2),
        'info' => $info,
    ];
}

/**
 * @return array<string,mixed>
 */
function cssi_bench_download_file(string $url, string $sid, string $token, string $file, int $timeout): array
{
    $tStart = microtime(true);
    $tmp = $file . '.part';
    $fh = @fopen($tmp, 'wb');
    if (!is_resource($fh)) {
        return ['ok' => false, 'error' => 'Could not open temp file', 'file' => $tmp];
    }

    $bytes = 0;
    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fh);
        @unlink($tmp);
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $sid . ':' . md5($token),
            'Accept: */*',
            'User-Agent: FFLHub-CSSI-Benchmark/1.0',
        ],
        CURLOPT_USERAGENT => 'FFLHub-CSSI-Benchmark/1.0',
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($fh, &$bytes): int {
            $len = strlen($data);
            $written = fwrite($fh, $data);
            if ($written === false) {
                return 0;
            }
            $bytes += $written;
            return $len;
        },
    ]);

    $ok = curl_exec($ch);
    $errno = (int) curl_errno($ch);
    $error = (string) curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $info = [
        'total_time_ms' => round(((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000.0, 2),
        'primary_ip' => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'size_download' => (float) curl_getinfo($ch, defined('CURLINFO_SIZE_DOWNLOAD_T') ? CURLINFO_SIZE_DOWNLOAD_T : CURLINFO_SIZE_DOWNLOAD),
        'speed_download' => (float) curl_getinfo($ch, CURLINFO_SPEED_DOWNLOAD),
    ];
    curl_close($ch);
    fclose($fh);

    if ($ok === false || $errno !== 0 || $status < 200 || $status >= 300 || $bytes <= 0) {
        @unlink($tmp);
        return [
            'ok' => false,
            'status' => $status,
            'curl_errno' => $errno,
            'error' => $error !== '' ? $error : 'download failed',
            'bytes' => $bytes,
            'wall_ms' => round((microtime(true) - $tStart) * 1000.0, 2),
            'info' => $info,
        ];
    }

    @rename($tmp, $file);
    return [
        'ok' => true,
        'status' => $status,
        'bytes' => is_file($file) ? (int) filesize($file) : $bytes,
        'file' => $file,
        'wall_ms' => round((microtime(true) - $tStart) * 1000.0, 2),
        'info' => $info,
    ];
}

/**
 * @param array<string,mixed> $row
 */
function cssi_bench_stage_key(array $row): string
{
    $upc = trim((string) ($row['upc'] ?? ''));
    if ($upc !== '') {
        return 'upc:' . $upc;
    }

    $itemNumber = trim((string) ($row['cssi_item_number'] ?? ''));
    if ($itemNumber !== '') {
        return 'item:' . strtoupper($itemNumber);
    }

    return '';
}

function cssi_bench_normalize_feed_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $normalized = preg_replace('#^http://api\.chattanoogashooting\.com/#i', 'https://api.chattanoogashooting.com/', $url);
    return is_string($normalized) && trim($normalized) !== '' ? trim($normalized) : $url;
}

/**
 * @param array<string,mixed> $inventory
 */
function cssi_bench_inventory_summary(array $inventory): string
{
    return sprintf(
        'inventory ok=%d pages=%d/%d items=%d unique=%d http_ms=%.2f decode_ms=%.2f parse_ms=%.2f wall_ms=%.2f errors=%d',
        !empty($inventory['ok']) ? 1 : 0,
        (int) ($inventory['pages_seen'] ?? 0),
        (int) ($inventory['page_count_reported'] ?? 0),
        (int) ($inventory['items_seen'] ?? 0),
        (int) ($inventory['unique_rows'] ?? 0),
        (float) ($inventory['http_ms'] ?? 0),
        (float) ($inventory['json_decode_ms'] ?? 0),
        (float) ($inventory['parse_ms'] ?? 0),
        (float) ($inventory['wall_ms'] ?? 0),
        count((array) ($inventory['errors'] ?? []))
    );
}

/**
 * @param array<string,mixed> $feed
 */
function cssi_bench_product_feed_summary(array $feed): string
{
    return sprintf(
        'product-feed ok=%d url_ms=%.2f download=%d download_ms=%.2f bytes=%d wall_ms=%.2f',
        !empty($feed['ok']) ? 1 : 0,
        (float) ($feed['url_request_ms'] ?? 0),
        !empty($feed['download_requested']) ? 1 : 0,
        (float) ($feed['download_ms'] ?? 0),
        (int) ($feed['download_bytes'] ?? 0),
        (float) ($feed['wall_ms'] ?? 0)
    );
}

/**
 * @param array<int,array<string,mixed>> $results
 * @return array<int,string>
 */
function cssi_bench_compare(array $results, string $mode): array
{
    $lines = [];
    $modeKey = str_replace('-', '_', $mode);
    foreach (['inventory', 'product_feed'] as $section) {
        if ($modeKey !== $section && $mode !== 'both') {
            continue;
        }

        $byVersion = [];
        foreach ($results as $result) {
            $version = (string) ($result['version'] ?? '');
            if ($version === '' || !isset($result[$section]) || !is_array($result[$section])) {
                continue;
            }
            $wall = (float) ($result[$section]['wall_ms'] ?? 0);
            if ($wall <= 0) {
                continue;
            }
            $byVersion[$version][] = $wall;
        }

        if (count($byVersion) < 2) {
            continue;
        }

        $averages = [];
        foreach ($byVersion as $version => $walls) {
            $averages[$version] = array_sum($walls) / max(1, count($walls));
        }

        asort($averages);
        $averageKeys = array_keys($averages);
        $fastestVersion = (string) ($averageKeys[0] ?? '');
        $fastestMs = (float) reset($averages);
        $lines[] = $section . ': fastest=' . $fastestVersion . ' avg_wall_ms=' . number_format($fastestMs, 2, '.', '');

        foreach ($averages as $version => $avgMs) {
            if ($version === $fastestVersion) {
                continue;
            }
            $delta = $avgMs - $fastestMs;
            $ratio = $fastestMs > 0 ? ($avgMs / $fastestMs) : 0;
            $lines[] = sprintf(
                '%s: %s avg_wall_ms=%.2f delta_vs_%s=%.2f ratio=%.3f',
                $section,
                $version,
                $avgMs,
                $fastestVersion,
                $delta,
                $ratio
            );
        }
    }

    if (empty($lines)) {
        $lines[] = 'No comparable timing pairs were produced.';
    }

    return $lines;
}
