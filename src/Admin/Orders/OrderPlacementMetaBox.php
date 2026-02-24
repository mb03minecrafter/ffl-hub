<?php

namespace FFLHub\Admin\Orders;

use WC_Order;

use FFLHub\Distributor\Models\OrderPlacementJobPatch;
use FFLHub\Distributor\Services\Orders\Cron\OrderingCronService;
use FFLHub\Distributor\Services\Orders\Jobs\Lifecycle\OrderPlacementJobLifeCycle;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobsRepository;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementJobWriter;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementKeys;
use FFLHub\Distributor\Services\Orders\Jobs\OrderPlacementPipelineMetaStore;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin meta box that displays the Order Placement pipeline + per-job status.
 *
 * Architecture notes:
 * - Jobs index is derived from the jobs TABLE (not order meta).
 * - "Retry" is DB-only: status=retry_scheduled + next_run_at set, so the dispatcher picks it up.
 *
 * This class is instance-based so we can inject the OrderPlacementJobsTable manager.
 */
final class OrderPlacementMetaBox
{
    private const META_BOX_ID    = 'fflhub_order_placement_jobs';
    private const META_BOX_TITLE = 'FFL Hub - Order Placement Jobs';

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    /**
     * Register hooks for the meta box + assets + retry handler.
     */
    public function register(): void
    {
        // Classic orders screen
        add_action('add_meta_boxes_shop_order', [$this, 'register_metabox']);

        // HPOS orders screen (wc-orders)
        add_action('add_meta_boxes_woocommerce_page_wc-orders', [$this, 'register_metabox']);

        // CSS for the "pretty boxes"
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Manual retry action (admin-post)
        add_action('admin_post_fflhub_retry_order_job', [$this, 'handle_retry_job_post']);
    }

    public function register_metabox(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            self::META_BOX_TITLE,
            [$this, 'render_metabox'],
            null,
            'side',
            'high'
        );
    }

    /**
     * Enqueue metabox CSS only on order screens.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $is_order_screen =
            ($screen->id === 'shop_order') ||
            ($screen->id === 'woocommerce_page_wc-orders') ||
            ($screen->post_type === 'shop_order');

        if (!$is_order_screen) {
            return;
        }

        $css_rel_path = 'assets/css/admin-order-placement-metabox.css';
        $css_abs_path = FFLHUB_PLUGIN_PATH . $css_rel_path;
        $css_version  = file_exists($css_abs_path) ? (string) filemtime($css_abs_path) : FFLHUB_PLUGIN_VERSION;

        wp_enqueue_style(
            'fflhub-order-placement-metabox',
            plugins_url($css_rel_path, FFLHUB_PLUGIN_FILE),
            [],
            $css_version
        );
    }

    /**
     * Render the meta box.
     *
     * @param mixed $post_or_order WP_Post|WC_Order|order-like object depending on screen.
     */
    public function render_metabox($post_or_order): void
    {
        $order = self::resolve_order($post_or_order);
        if (!($order instanceof WC_Order)) {
            echo '<div class="fflhub-muted">Order not available.</div>';
            return;
        }

        // Pipeline meta still lives on the WC order
        $started    = OrderPlacementPipelineMetaStore::get_pipeline_started($order);
        $started_at = OrderPlacementPipelineMetaStore::get_pipeline_started_at($order);
        $started_by = OrderPlacementPipelineMetaStore::get_pipeline_started_by($order);

        // Jobs index is derived from the jobs table (new architecture)
        $job_keys = OrderPlacementJobsRepository::get_jobs_index($this->jobs_table, $order);

        echo '<div class="fflhub-wrap">';

        // Pipeline header
        echo '<div class="fflhub-card">';
        echo '<div class="fflhub-card-title">Pipeline</div>';
        echo '<div class="fflhub-kv">';
        echo self::kv('Started', $started ? self::pill('yes', 'success') : self::pill('no', 'muted'));
        echo self::kv('Started at', $started_at !== '' ? esc_html($started_at) : '<span class="fflhub-muted">-</span>');
        echo self::kv('Started by', $started_by !== '' ? esc_html($started_by) : '<span class="fflhub-muted">-</span>');
        echo '</div>';
        echo '</div>';

        // Index
        echo '<div class="fflhub-card">';
        echo '<div class="fflhub-card-title">Jobs Index</div>';
        if (empty($job_keys)) {
            echo '<div class="fflhub-muted">No jobs found (table empty).</div>';
        } else {
            echo '<div class="fflhub-badges">';
            foreach ($job_keys as $k) {
                echo '<span class="fflhub-badge">' . esc_html((string) $k) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Job cards
        foreach ($job_keys as $job_key) {
            $this->render_job_card($order, (string) $job_key);
        }

        echo '</div>'; // wrap
    }

    /**
     * Render a single job status card.
     */
    private function render_job_card(WC_Order $order, string $job_key): void
    {
        $job_key = trim((string) $job_key);
        if ($job_key === '') {
            return;
        }

        $row = $this->get_job_row_for_admin((int) $order->get_id(), $job_key);

        $status   = isset($row['status']) ? (string) $row['status'] : '';
        $attempts = isset($row['attempts']) ? (string) $row['attempts'] : '';
        $created  = isset($row['created_at']) ? (string) $row['created_at'] : '';
        $done_at  = isset($row['done_at']) ? (string) $row['done_at'] : '';
        $actionid = isset($row['action_id']) ? (string) $row['action_id'] : '';
        $last_err = isset($row['last_error']) ? (string) $row['last_error'] : '';
        $payload  = isset($row['payload_json']) ? (string) $row['payload_json'] : '';

        $validate_raw = isset($row['validate_result_json']) ? (string) $row['validate_result_json'] : '';
        $place_raw    = isset($row['place_result_json']) ? (string) $row['place_result_json'] : '';

        // shipment fields (new structure)
        $shipped_at        = isset($row['shipped_at']) ? (string) $row['shipped_at'] : '';
        $tracking_json     = isset($row['tracking_numbers_json']) ? (string) $row['tracking_numbers_json'] : '';
        $invoice_json      = isset($row['invoice_numbers_json']) ? (string) $row['invoice_numbers_json'] : '';
        $ship_service      = isset($row['shipping_service']) ? (string) $row['shipping_service'] : '';
        $ship_weight       = isset($row['shipping_weight']) ? (string) $row['shipping_weight'] : '';
        $ship_poll_at      = isset($row['last_shipping_poll_at']) ? (string) $row['last_shipping_poll_at'] : '';
        $shipment_raw_json = isset($row['shipment_raw_json']) ? (string) $row['shipment_raw_json'] : '';

        $pill = self::pill($status !== '' ? $status : '-', self::status_class($status));

        echo '<div class="fflhub-card fflhub-job">';
        echo '<div class="fflhub-job-head">';
        echo '<div class="fflhub-job-title">' . esc_html($job_key) . '</div>';
        echo '<div>' . $pill . '</div>';
        echo '</div>';

        // Retry button (only if failed)
        if (strtolower(trim($status)) === 'failed') {
            echo self::render_retry_button($order, $job_key);
        }

        echo '<div class="fflhub-kv">';
        echo self::kv('Attempts', $attempts !== '' ? esc_html($attempts) : '<span class="fflhub-muted">0</span>');
        echo self::kv('Action ID', $actionid !== '' ? esc_html($actionid) : '<span class="fflhub-muted">-</span>');
        echo self::kv('Created', $created !== '' ? esc_html($created) : '<span class="fflhub-muted">-</span>');
        echo self::kv('Done', $done_at !== '' ? esc_html($done_at) : '<span class="fflhub-muted">-</span>');
        echo '</div>';

        if ($last_err !== '') {
            echo '<div class="fflhub-error">';
            echo '<div class="fflhub-error-title">Last error</div>';
            echo '<div class="fflhub-error-msg">' . esc_html($last_err) . '</div>';
            echo '</div>';
        }

        // ---------------- Validation section ----------------
        echo '<div class="fflhub-subcard">';
        echo '<div class="fflhub-subcard-title">Validation</div>';

        if ($validate_raw !== '') {
            $val = json_decode($validate_raw, true);

            if (is_array($val)) {
                $ok    = !empty($val['ok']);
                $code  = isset($val['code']) ? (string) $val['code'] : '';
                $msg   = isset($val['message']) ? (string) $val['message'] : '';
                $at    = isset($val['at']) ? (string) $val['at'] : '';
                $codes = isset($val['codes']) && is_array($val['codes']) ? $val['codes'] : [];

                $v_pill_class = 'muted';
                if ($ok && strtoupper($code) === 'ALLOW') {
                    $v_pill_class = 'success';
                } elseif (strtoupper($code) === 'BLOCK_RETRYABLE') {
                    $v_pill_class = 'warning';
                } elseif (strtoupper($code) === 'BLOCK_FATAL') {
                    $v_pill_class = 'danger';
                }

                echo '<div class="fflhub-kv">';
                echo self::kv('Result', self::pill($code !== '' ? $code : ($ok ? 'ALLOW' : 'BLOCK'), $v_pill_class));
                echo self::kv('Validated at', $at !== '' ? esc_html($at) : '<span class="fflhub-muted">-</span>');

                if (!empty($codes)) {
                    $codes_str = implode(', ', array_slice(array_map('strval', $codes), 0, 12));
                    if (count($codes) > 12) {
                        $codes_str .= ', ...';
                    }
                    echo self::kv('Codes', '<span class="fflhub-mono">' . esc_html($codes_str) . '</span>');
                } else {
                    echo self::kv('Codes', '<span class="fflhub-muted">-</span>');
                }

                if ($msg !== '') {
                    echo self::kv('Message', '<span class="fflhub-mono">' . esc_html($msg) . '</span>');
                } else {
                    echo self::kv('Message', '<span class="fflhub-muted">-</span>');
                }

                echo '</div>';

                $pretty_val = self::pretty_json($validate_raw);
                echo '<details class="fflhub-details">';
                echo '<summary>Raw validation JSON</summary>';
                echo '<pre class="fflhub-pre">' . esc_html($pretty_val) . '</pre>';
                echo '</details>';
            } else {
                echo '<div class="fflhub-muted">Validation result present but not valid JSON.</div>';
                echo '<details class="fflhub-details"><summary>Raw validation</summary><pre class="fflhub-pre">'
                    . esc_html($validate_raw) . '</pre></details>';
            }
        } else {
            echo '<div class="fflhub-muted">No validation stored.</div>';
        }

        echo '</div>'; // subcard (validation)

        // ---------------- Place section ----------------
        echo '<div class="fflhub-subcard">';
        echo '<div class="fflhub-subcard-title">Place</div>';

        if ($place_raw !== '') {
            $pl = json_decode($place_raw, true);

            if (is_array($pl)) {
                $ok    = !empty($pl['ok']);
                $code  = isset($pl['code']) ? (string) $pl['code'] : '';
                $msg   = isset($pl['message']) ? (string) $pl['message'] : '';
                $at    = isset($pl['at']) ? (string) $pl['at'] : '';
                $codes = isset($pl['codes']) && is_array($pl['codes']) ? $pl['codes'] : [];
                $http  = isset($pl['http']) ? (int) $pl['http'] : 0;
                $ext   = isset($pl['ext_ids']) && is_array($pl['ext_ids']) ? $pl['ext_ids'] : [];

                $p_pill_class = self::place_pill_class($ok, $code, $codes);

                echo '<div class="fflhub-kv">';
                echo self::kv('Result', self::pill($code !== '' ? $code : ($ok ? 'OK' : 'ERROR'), $p_pill_class));
                echo self::kv('Placed at', $at !== '' ? esc_html($at) : '<span class="fflhub-muted">-</span>');

                if ($http > 0) {
                    echo self::kv('HTTP', '<span class="fflhub-mono">' . esc_html((string) $http) . '</span>');
                } else {
                    echo self::kv('HTTP', '<span class="fflhub-muted">-</span>');
                }

                if (!empty($ext)) {
                    $ext_str = implode(', ', array_slice(array_map('strval', $ext), 0, 8));
                    if (count($ext) > 8) {
                        $ext_str .= ', ...';
                    }
                    echo self::kv('External IDs', '<span class="fflhub-mono">' . esc_html($ext_str) . '</span>');
                } else {
                    echo self::kv('External IDs', '<span class="fflhub-muted">-</span>');
                }

                if (!empty($codes)) {
                    $codes_str = implode(', ', array_slice(array_map('strval', $codes), 0, 12));
                    if (count($codes) > 12) {
                        $codes_str .= ', ...';
                    }
                    echo self::kv('Codes', '<span class="fflhub-mono">' . esc_html($codes_str) . '</span>');
                } else {
                    echo self::kv('Codes', '<span class="fflhub-muted">-</span>');
                }

                if ($msg !== '') {
                    echo self::kv('Message', '<span class="fflhub-mono">' . esc_html($msg) . '</span>');
                } else {
                    echo self::kv('Message', '<span class="fflhub-muted">-</span>');
                }

                echo '</div>';

                $pretty_pl = self::pretty_json($place_raw);
                echo '<details class="fflhub-details">';
                echo '<summary>Raw place JSON</summary>';
                echo '<pre class="fflhub-pre">' . esc_html($pretty_pl) . '</pre>';
                echo '</details>';
            } else {
                echo '<div class="fflhub-muted">Place result present but not valid JSON.</div>';
                echo '<details class="fflhub-details"><summary>Raw place</summary><pre class="fflhub-pre">'
                    . esc_html($place_raw) . '</pre></details>';
            }
        } else {
            echo '<div class="fflhub-muted">No place result stored.</div>';
        }

        echo '</div>'; // subcard (place)

        // ---------------- Shipment section ----------------
        echo '<div class="fflhub-subcard">';
        echo '<div class="fflhub-subcard-title">Shipment</div>';

        $tracking_list = self::decode_string_list_json($tracking_json);
        $invoice_list  = self::decode_string_list_json($invoice_json);

        $has_shipment = false;
        if ($shipped_at !== '' && $shipped_at !== '0000-00-00 00:00:00') {
            $has_shipment = true;
        }
        if (!empty($tracking_list) || !empty($invoice_list)) {
            $has_shipment = true;
        }
        if ($ship_poll_at !== '' && $ship_poll_at !== '0000-00-00 00:00:00') {
            $has_shipment = true;
        }

        if (!$has_shipment) {
            echo '<div class="fflhub-muted">No shipment data yet.</div>';
        } else {
            echo '<div class="fflhub-kv">';

            $ship_pill = ($shipped_at !== '' && $shipped_at !== '0000-00-00 00:00:00')
                ? self::pill('shipped', 'success')
                : self::pill('pending', 'muted');

            echo self::kv('Status', $ship_pill);
            echo self::kv('Shipped at', ($shipped_at !== '' && $shipped_at !== '0000-00-00 00:00:00') ? esc_html($shipped_at) : '<span class="fflhub-muted">-</span>');
            echo self::kv('Last poll', ($ship_poll_at !== '' && $ship_poll_at !== '0000-00-00 00:00:00') ? esc_html($ship_poll_at) : '<span class="fflhub-muted">-</span>');

            if (!empty($tracking_list)) {
                $t_str = implode(', ', array_slice($tracking_list, 0, 8));
                if (count($tracking_list) > 8) {
                    $t_str .= ', ...';
                }
                echo self::kv('Tracking', '<span class="fflhub-mono">' . esc_html($t_str) . '</span>');
            } else {
                echo self::kv('Tracking', '<span class="fflhub-muted">-</span>');
            }

            if (!empty($invoice_list)) {
                $i_str = implode(', ', array_slice($invoice_list, 0, 8));
                if (count($invoice_list) > 8) {
                    $i_str .= ', ...';
                }
                echo self::kv('Invoices', '<span class="fflhub-mono">' . esc_html($i_str) . '</span>');
            } else {
                echo self::kv('Invoices', '<span class="fflhub-muted">-</span>');
            }

            echo self::kv('Service', $ship_service !== '' ? '<span class="fflhub-mono">' . esc_html($ship_service) . '</span>' : '<span class="fflhub-muted">-</span>');
            echo self::kv('Weight', $ship_weight !== '' ? '<span class="fflhub-mono">' . esc_html($ship_weight) . '</span>' : '<span class="fflhub-muted">-</span>');

            echo '</div>';

            if ($shipment_raw_json !== '') {
                $pretty_ship = self::pretty_json($shipment_raw_json);
                echo '<details class="fflhub-details">';
                echo '<summary>Raw shipment JSON</summary>';
                echo '<pre class="fflhub-pre">' . esc_html($pretty_ship) . '</pre>';
                echo '</details>';
            }
        }

        echo '</div>'; // subcard (shipment)

        // Payload
        if ($payload !== '') {
            $pretty = self::pretty_json($payload);
            echo '<details class="fflhub-details">';
            echo '<summary>Payload</summary>';
            echo '<pre class="fflhub-pre">' . esc_html($pretty) . '</pre>';
            echo '</details>';
        } else {
            echo '<div class="fflhub-muted">No payload stored.</div>';
        }

        echo '</div>'; // card
    }

    /**
     * Admin-only read: returns a single job row as an associative array.
     *
     * @return array<string,mixed>
     */
    private function get_job_row_for_admin(int $order_id, string $job_key): array
    {
        global $wpdb;

        $table  = $this->jobs_table->get_table_name();
        $job_key = strtolower(trim((string) $job_key));

        $sql = "
            SELECT
              status,
              attempts,
              created_at,
              done_at,
              action_id,
              last_error,
              payload_json,
              validate_result_json,
              place_result_json,
              shipped_at,
              tracking_numbers_json,
              invoice_numbers_json,
              shipping_service,
              shipping_weight,
              shipment_raw_json,
              last_shipping_poll_at
            FROM {$table}
            WHERE order_id = %d AND job_key = %s
            LIMIT 1
        ";

        $row = $wpdb->get_row(
            $wpdb->prepare($sql, $order_id, $job_key),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * Handle manual retry request from admin UI.
     *
     * Behavior:
     * - only allows retry if job is currently FAILED
     * - sets status=retry_scheduled and next_run_at=now
     * - optionally kicks the dispatcher hook for faster UX
     */
    public function handle_retry_job_post(): void
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
            wp_die('Insufficient permissions.');
        }

        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $job_key  = isset($_GET['job_key'])
            ? OrderPlacementKeysUtil::normalize_job_key(sanitize_text_field(wp_unslash((string) $_GET['job_key'])))
            : '';

        if ($order_id <= 0 || $job_key === '') {
            wp_die('Missing order_id or job_key.');
        }

        check_admin_referer('fflhub_retry_order_job_' . $order_id . '|' . $job_key);

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            wp_die('Order not found.');
        }

        $job_keys = OrderPlacementJobsRepository::get_jobs_index($this->jobs_table, $order);
        if (!in_array($job_key, $job_keys, true)) {
            wp_die('Job key not found on this order.');
        }

        $status = OrderPlacementJobLifeCycle::get_job_status($this->jobs_table, $order, $job_key);
        if (strtolower(trim((string) $status)) !== 'failed') {
            self::redirect_back($order_id, $job_key, 'not_failed');
            return;
        }

        // DB-only retry scheduling (dispatcher model)
        $patch = OrderPlacementJobPatch::empty()
            ->with_status(OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED)
            ->with_next_run_at_mysql(self::now_mysql_utc_plus(0))
            ->with_last_error(''); // optional: clear terminal error when user retries

        OrderPlacementJobWriter::apply_patch($this->jobs_table, $order_id, $job_key, $patch);

        // Kick dispatcher for UX (best-effort)
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + 1,
                OrderingCronService::CRON_HOOK,
                [],
                'fflhub_place'
            );
        }

        self::redirect_back($order_id, $job_key, 'scheduled');
    }

    private static function resolve_order($post_or_order): ?WC_Order
    {
        if ($post_or_order instanceof WC_Order) {
            return $post_or_order;
        }

        if (is_object($post_or_order) && isset($post_or_order->ID)) {
            $oid = (int) $post_or_order->ID;
            $o = wc_get_order($oid);
            return ($o instanceof WC_Order) ? $o : null;
        }

        if (is_object($post_or_order) && method_exists($post_or_order, 'get_id')) {
            $oid = (int) $post_or_order->get_id();
            $o = wc_get_order($oid);
            return ($o instanceof WC_Order) ? $o : null;
        }

        return null;
    }

    private static function status_class(string $status): string
    {
        $s = strtolower(trim($status));

        if ($s === 'success')   return 'success';
        if ($s === 'failed')    return 'danger';
        if ($s === 'running')   return 'warning';
        if ($s === 'scheduled') return 'info';
        if ($s === 'queued')    return 'muted';
        if ($s === strtolower(OrderPlacementKeys::JOB_STATUS_RETRY_SCHEDULED)) return 'warning';

        return 'muted';
    }

    private static function pill(string $text, string $class): string
    {
        return '<span class="fflhub-pill fflhub-pill-' . esc_attr($class) . '">' . esc_html($text) . '</span>';
    }

    private static function kv(string $k, string $v_html): string
    {
        return '<div class="fflhub-row"><div class="fflhub-key">' . esc_html($k) . '</div><div class="fflhub-val">' . $v_html . '</div></div>';
    }

    /**
     * @return array<int,string>
     */
    private static function decode_string_list_json(string $raw_json): array
    {
        $raw_json = trim((string) $raw_json);
        if ($raw_json === '') return [];

        $decoded = json_decode($raw_json, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $v) {
            $s = trim((string) $v);
            if ($s !== '') $out[] = $s;
        }

        return $out;
    }

    private static function pretty_json(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';

        $decoded = json_decode($raw, true);
        if ($decoded === null || (!is_array($decoded) && !is_object($decoded))) {
            return $raw;
        }

        return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param bool $ok
     * @param string $code
     * @param array<int,mixed> $codes
     */
    private static function place_pill_class(bool $ok, string $code, array $codes): string
    {
        $code_u = strtoupper(trim($code));

        if ($ok && $code_u === 'OK') {
            return 'success';
        }

        if ($code_u === 'BLOCK_RETRYABLE') {
            return 'warning';
        }

        $codes_lc = array_map('strtolower', array_map('strval', $codes));
        foreach ($codes_lc as $c) {
            if (strpos($c, 'rate') !== false || strpos($c, 'quota') !== false || strpos($c, 'timeout') !== false) {
                return 'warning';
            }
        }

        return 'danger';
    }

    private static function render_retry_button(WC_Order $order, string $job_key): string
    {
        $order_id = (int) $order->get_id();
        $job_key  = OrderPlacementKeysUtil::normalize_job_key($job_key);

        if ($job_key === '') {
            return '';
        }

        $url = add_query_arg([
            'action'   => 'fflhub_retry_order_job',
            'order_id' => $order_id,
            'job_key'  => $job_key,
        ], admin_url('admin-post.php'));

        $url = wp_nonce_url($url, 'fflhub_retry_order_job_' . $order_id . '|' . $job_key);

        return '<div class="fflhub-retry-wrap">'
            . '<a class="button button-secondary" href="' . esc_url($url) . '" '
            . 'onclick="return confirm(\'Retry this job now?\');">'
            . 'Retry Job</a>'
            . '</div>';
    }

    /**
     * UTC mysql datetime string for now + N seconds.
     */
    private static function now_mysql_utc_plus(int $seconds): string
    {
        $ts = time() + max(0, $seconds);
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function redirect_back(int $order_id, string $job_key, string $result): void
    {
        $ref = wp_get_referer();
        if (!$ref) {
            $ref = admin_url('post.php?post=' . $order_id . '&action=edit');
        }

        $ref = add_query_arg([
            'fflhub_retry' => $result,
            'fflhub_job'   => $job_key,
        ], $ref);

        wp_safe_redirect($ref);
        exit;
    }

}

