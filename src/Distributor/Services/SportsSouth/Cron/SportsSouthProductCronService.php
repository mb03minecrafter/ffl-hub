<?php

namespace FFLHub\Distributor\Services\SportsSouth\Cron;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Services\Cron\AbstractTableCronService;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInventoryClient;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductImporterService;
use FFLHub\Distributor\Services\SportsSouth\SportsSouthProductParser;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Settings\Options;
use FFLHub\Util\DebugLogUtil;

final class SportsSouthProductCronService extends AbstractTableCronService
{
    public const CRON_HOOK = 'fflhub_sports_south_product_update';

    private const DEBUG_FLAG = 'FFLHUB_CRON_DEBUG';
    private const LOG_PREFIX = '[FFLHub][SportsSouthProductCron]';

    /** @var string[] */
    private array $artifact_paths = [];

    public function __construct(DoubleBufferedProductTable $table)
    {
        parent::__construct($table);
    }

    public function get_cron_hook_name(): string
    {
        return self::CRON_HOOK;
    }

    protected function get_interval_seconds(): int
    {
        return DAY_IN_SECONDS;
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
        $t_start = microtime(true);
        $mem_start = function_exists('memory_get_usage') ? (int) memory_get_usage(true) : 0;
        $this->artifact_paths = [];

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->log('---- RUN START ----', [
            'pid' => function_exists('getmypid') ? (int) getmypid() : 0,
            'hook' => self::CRON_HOOK,
            'group' => $this->get_action_group(),
            'memory_kb' => $mem_start > 0 ? (int) round($mem_start / 1024) : 0,
        ]);
        update_option('fflhub_sports_south_fulfillment_last_stage', 'run_start', false);
        update_option('fflhub_sports_south_fulfillment_last_run', current_time('mysql'), false);

        update_option('fflhub_sports_south_fulfillment_last_stage', 'make_client', false);
        $client = $this->make_client();
        if (!$client->has_credentials()) {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'missing_credentials', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Missing Sports South credentials; product import skipped.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (missing credentials)');
            return;
        }

        $last_update = trim(Options::get_distributor_option('sports_south', 'daily_item_last_update', '1/1/1990'));
        if ($last_update === '') {
            $last_update = '1/1/1990';
        }

        $last_item = (int) Options::get_distributor_option('sports_south', 'daily_item_last_item', '-1');

        update_option('fflhub_sports_south_fulfillment_last_stage', 'daily_item_update_request', false);
        $this->log('DailyItemUpdate request starting.', [
            'last_update' => $last_update,
            'last_item' => $last_item,
        ]);

        $parser = new SportsSouthProductParser();
        $brand_map = $this->download_brand_map($client, $parser);
        $category_map = $this->download_category_map($client, $parser);

        update_option('fflhub_sports_south_fulfillment_last_stage', 'download_xml', false);
        $xml_path = $this->build_xml_file_path('daily_item_update');
        if ($xml_path === '') {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'xml_path_failed', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->finalize_run($t_start, $mem_start, 'ERROR (xml path failed)');
            return;
        }

        $t_api = microtime(true);
        $response = $client->daily_item_update_to_file($xml_path, $last_update, $last_item);
        $this->profile('DailyItemUpdate request', $t_api, [
            'ok' => empty($response['ok']) ? 0 : 1,
            'status' => (int) ($response['status'] ?? 0),
            'last_update' => $last_update,
            'last_item' => $last_item,
            'xml_path' => $xml_path,
            'xml_bytes' => (int) ($response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($response['body_bytes'] ?? 0),
            'raw_path' => (string) ($response['raw_path'] ?? ''),
        ]);

        if (empty($response['ok'])) {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'daily_item_update_failed', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Sports South DailyItemUpdate failed.', [
                'status' => (int) ($response['status'] ?? 0),
                'error' => (string) ($response['error'] ?? ''),
            ]);
            $this->finalize_run($t_start, $mem_start, 'ERROR (DailyItemUpdate failed)');
            return;
        }
        $this->remember_artifact_paths($xml_path, (string) ($response['raw_path'] ?? ''));

        update_option('fflhub_sports_south_fulfillment_last_download', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_download_path', $xml_path, false);
        update_option('fflhub_sports_south_fulfillment_last_download_size', (string) (file_exists($xml_path) ? filesize($xml_path) : 0), false);
        delete_option('fflhub_sports_south_fulfillment_last_download_error');

        update_option('fflhub_sports_south_fulfillment_last_stage', 'import_xml', false);
        $t_import = microtime(true);
        $importer = new SportsSouthProductImporterService($this->table, $parser);
        $count = $importer->import_catalog_file($xml_path, $brand_map, $category_map);
        $this->profile('Import DailyItemUpdate XML', $t_import, [
            'rows_imported' => (int) $count,
            'xml_path' => $xml_path,
            'brand_map_count' => count($brand_map),
            'category_map_count' => count($category_map),
        ]);

        if ($count <= 0) {
            update_option('fflhub_sports_south_fulfillment_last_stage', 'zero_rows_imported', false);
            update_option('fflhub_sports_south_fulfillment_last_error', current_time('mysql'), false);
            $this->log('Sports South catalog import produced zero rows; not swapping.');
            $this->finalize_run($t_start, $mem_start, 'ERROR (0 imported)');
            return;
        }

        update_option('fflhub_sports_south_fulfillment_last_stage', 'swap_live_table', false);
        $t_swap = microtime(true);
        $new_live = $this->table->swap_live_and_staging();
        $this->profile('Swap staging/live', $t_swap, [
            'new_live' => (string) $new_live,
        ]);

        update_option('fflhub_sports_south_fulfillment_last_refresh', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_refresh_count', (int) $count, false);
        update_option('fflhub_sports_south_fulfillment_last_swap', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_stage', 'success', false);
        delete_option('fflhub_sports_south_fulfillment_last_error');

        $deleted_artifacts = $importer->cleanup_last_artifacts();
        $deleted_artifacts += $this->cleanup_artifact_paths($this->artifact_paths);
        $deleted_old_artifacts = $this->cleanup_old_generated_artifacts($this->uploads_subdir());

        $this->finalize_run($t_start, $mem_start, 'SUCCESS', [
            'rows_imported' => (int) $count,
            'new_live' => (string) $new_live,
            'deleted_artifacts' => (int) $deleted_artifacts,
            'deleted_old_artifacts' => (int) $deleted_old_artifacts,
        ]);
    }

    private function make_client(): SportsSouthInventoryClient
    {
        $customer = Options::get_distributor_option('sports_south', 'customer_number', '');
        $username = Options::get_distributor_option('sports_south', 'username', '');
        $password = Options::get_distributor_option('sports_south', 'password', '');
        $source = Options::get_distributor_option('sports_south', 'source', '');
        $base_url = Options::get_distributor_option('sports_south', 'inventory_api_base_url', SportsSouthInventoryClient::DEFAULT_BASE_URL);

        $base_url = (string) apply_filters('fflhub_sports_south_inventory_api_base_url', $base_url);

        return new SportsSouthInventoryClient($customer, $username, $password, $source, $base_url, 240);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function download_brand_map(SportsSouthInventoryClient $client, SportsSouthProductParser $parser): array
    {
        update_option('fflhub_sports_south_fulfillment_last_stage', 'brand_update_request', false);
        $brand_path = $this->build_xml_file_path('brand_update');
        if ($brand_path === '') {
            $this->log('Sports South BrandUpdate skipped: failed to build XML path.');
            return [];
        }

        $t_brand = microtime(true);
        $response = $client->brand_update_to_file($brand_path);
        $this->profile('BrandUpdate request', $t_brand, [
            'ok' => empty($response['ok']) ? 0 : 1,
            'status' => (int) ($response['status'] ?? 0),
            'xml_path' => $brand_path,
            'xml_bytes' => (int) ($response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($response['body_bytes'] ?? 0),
            'raw_path' => (string) ($response['raw_path'] ?? ''),
        ]);

        if (empty($response['ok'])) {
            $this->log('Sports South BrandUpdate failed; catalog import will use raw feed brand fields.', [
                'status' => (int) ($response['status'] ?? 0),
                'error' => (string) ($response['error'] ?? ''),
            ]);
            return [];
        }
        $this->remember_artifact_paths($brand_path, (string) ($response['raw_path'] ?? ''));

        update_option('fflhub_sports_south_fulfillment_last_brand_download', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_brand_download_path', $brand_path, false);
        update_option('fflhub_sports_south_fulfillment_last_brand_download_size', (string) (file_exists($brand_path) ? filesize($brand_path) : 0), false);

        $brand_map = [];
        $t_map = microtime(true);
        $brand_rows_seen = $parser->each_brand_row($brand_path, function (array $brand) use (&$brand_map): void {
            $brand_number = trim((string) ($brand['brand_number'] ?? ''));
            $brand_name = trim((string) ($brand['brand_name'] ?? ''));
            if ($brand_number === '' || $brand_name === '') {
                return;
            }

            $brand_map[$brand_number] = $brand;
        });
        $this->profile('BrandUpdate map parse', $t_map, [
            'xml_rows_seen' => (int) $brand_rows_seen,
            'brand_count' => count($brand_map),
            'xml_path' => $brand_path,
        ]);

        update_option('fflhub_sports_south_fulfillment_last_brand_count', count($brand_map), false);
        $this->log('Sports South BrandUpdate map ready.', [
            'brand_count' => count($brand_map),
            'xml_path' => $brand_path,
        ]);

        return $brand_map;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function download_category_map(SportsSouthInventoryClient $client, SportsSouthProductParser $parser): array
    {
        update_option('fflhub_sports_south_fulfillment_last_stage', 'category_update_request', false);
        $category_path = $this->build_xml_file_path('category_update');
        if ($category_path === '') {
            $this->log('Sports South CategoryUpdate skipped: failed to build XML path.');
            return [];
        }

        $t_category = microtime(true);
        $response = $client->category_update_to_file($category_path);
        $this->profile('CategoryUpdate request', $t_category, [
            'ok' => empty($response['ok']) ? 0 : 1,
            'status' => (int) ($response['status'] ?? 0),
            'xml_path' => $category_path,
            'xml_bytes' => (int) ($response['xml_bytes'] ?? 0),
            'body_bytes' => (int) ($response['body_bytes'] ?? 0),
            'raw_path' => (string) ($response['raw_path'] ?? ''),
        ]);

        if (empty($response['ok'])) {
            $this->log('Sports South CategoryUpdate failed; FFL/category mapping will use raw feed fields.', [
                'status' => (int) ($response['status'] ?? 0),
                'error' => (string) ($response['error'] ?? ''),
            ]);
            return [];
        }
        $this->remember_artifact_paths($category_path, (string) ($response['raw_path'] ?? ''));

        update_option('fflhub_sports_south_fulfillment_last_category_download', current_time('mysql'), false);
        update_option('fflhub_sports_south_fulfillment_last_category_download_path', $category_path, false);
        update_option('fflhub_sports_south_fulfillment_last_category_download_size', (string) (file_exists($category_path) ? filesize($category_path) : 0), false);

        $category_map = [];
        $t_map = microtime(true);
        $category_rows_seen = $parser->each_category_row($category_path, function (array $category) use (&$category_map): void {
            $category_id = trim((string) ($category['category_id'] ?? ''));
            if ($category_id === '') {
                return;
            }

            $category_map[$category_id] = $category;
        });
        $this->profile('CategoryUpdate map parse', $t_map, [
            'xml_rows_seen' => (int) $category_rows_seen,
            'category_count' => count($category_map),
            'xml_path' => $category_path,
        ]);

        update_option('fflhub_sports_south_fulfillment_last_category_count', count($category_map), false);
        $this->log('Sports South CategoryUpdate map ready.', [
            'category_count' => count($category_map),
            'xml_path' => $category_path,
        ]);

        return $category_map;
    }

    private function build_xml_file_path(string $prefix): string
    {
        $dir = $this->uploads_subdir();
        if ($dir === '') {
            return '';
        }

        return $dir . '/' . $prefix . '_' . gmdate('Ymd_His') . '.xml';
    }

    private function uploads_subdir(): string
    {
        $uploads = wp_upload_dir();
        $base_dir = rtrim((string) ($uploads['basedir'] ?? ''), '/\\');
        if ($base_dir === '') {
            return '';
        }

        $dir = $base_dir . '/fflhub-sports-south';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            $this->log('Failed to create Sports South XML directory.', [
                'dir' => $dir,
            ]);
            return '';
        }

        return $dir;
    }

    private function remember_artifact_paths(string ...$paths): void
    {
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path !== '') {
                $this->artifact_paths[] = $path;
            }
        }
    }

    /**
     * @param string[] $paths
     */
    private function cleanup_artifact_paths(array $paths): int
    {
        $deleted = 0;
        foreach (array_values(array_unique($paths)) as $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }

            if (@unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function cleanup_old_generated_artifacts(string $dir): int
    {
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }

        $retention = (int) apply_filters(
            'fflhub_sports_south_success_artifact_retention_seconds',
            DAY_IN_SECONDS
        );
        $cutoff = time() - max(HOUR_IN_SECONDS, $retention);
        $deleted = 0;

        $patterns = [
            'daily_item_update_*.xml',
            'daily_item_update_*.xml.raw-response.xml',
            'daily_item_update_catalog_*.tsv',
            'brand_update_*.xml',
            'brand_update_*.xml.raw-response.xml',
            'category_update_*.xml',
            'category_update_*.xml.raw-response.xml',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob(rtrim($dir, '/\\') . '/' . $pattern);
            if (!is_array($matches)) {
                continue;
            }

            foreach ($matches as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $mtime = (int) @filemtime($path);
                if ($mtime > 0 && $mtime > $cutoff) {
                    continue;
                }

                if (@unlink($path)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function log(string $message, array $ctx = []): void
    {
        if (empty($ctx)) {
            DebugLogUtil::log_if(true, self::LOG_PREFIX, $message, self::DEBUG_FLAG);
            return;
        }

        DebugLogUtil::log_if_ctx(true, self::LOG_PREFIX, $message, $ctx, self::DEBUG_FLAG);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function profile(string $label, float $t0, array $ctx = []): void
    {
        $ctx['elapsed_ms'] = number_format((microtime(true) - $t0) * 1000.0, 2, '.', '');
        $this->log('PROFILE: ' . $label, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private function finalize_run(float $tStart, int $memStart, string $status, array $ctx = []): void
    {
        $ctx['status'] = $status;
        $ctx['elapsed_ms'] = number_format((microtime(true) - $tStart) * 1000.0, 2, '.', '');

        if ($memStart > 0 && function_exists('memory_get_usage')) {
            $mem_end = (int) memory_get_usage(true);
            $ctx['memory_start_kb'] = (int) round($memStart / 1024);
            $ctx['memory_end_kb'] = (int) round($mem_end / 1024);
            $ctx['memory_delta_kb'] = (int) round(($mem_end - $memStart) / 1024);
        }

        $this->log('---- RUN END ----', $ctx);
    }
}
