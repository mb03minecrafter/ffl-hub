<?php

namespace FFLHub\Admin\Orders;

use FFLHub\Distributor\Orders\OrderPlacementKeys;
use FFLHub\Distributor\Orders\OrderPlacementStore;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class OrderPlacementMetaBox
{
    public static function init(): void
    {
        // Classic orders screen
        add_action('add_meta_boxes_shop_order', [self::class, 'register_metabox']);

        // HPOS orders screen (wc-orders)
        add_action('add_meta_boxes_woocommerce_page_wc-orders', [self::class, 'register_metabox']);

        // CSS for the “pretty boxes”
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);


        add_action('admin_post_fflhub_retry_order_job', [self::class, 'handle_retry_job_post']);
    }

    public static function register_metabox(): void
    {
        add_meta_box(
            'fflhub_order_placement_jobs',
            'FFL Hub — Order Placement Jobs',
            [self::class, 'render_metabox'],
            null,
            'side',
            'high'
        );
    }

    public static function enqueue_assets(string $hook_suffix): void
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

        wp_register_style('fflhub-order-placement-metabox', false);
        wp_enqueue_style('fflhub-order-placement-metabox');
        wp_add_inline_style('fflhub-order-placement-metabox', self::css());
    }

    public static function render_metabox($post_or_order): void
    {
        $order = self::resolve_order($post_or_order);
        if (!($order instanceof WC_Order)) {
            echo '<div class="fflhub-muted">Order not available.</div>';
            return;
        }

        // Prefer Store helpers where it makes sense (types normalized)
        $started    = OrderPlacementStore::get_pipeline_started($order);
        $started_at = OrderPlacementStore::get_pipeline_started_at($order);
        $started_by = OrderPlacementStore::get_pipeline_started_by($order);

        $job_keys   = OrderPlacementStore::get_jobs_index($order);
        $index_json = (string) $order->get_meta(OrderPlacementKeys::META_JOBS_INDEX, true);

        echo '<div class="fflhub-wrap">';

        // Pipeline header
        echo '<div class="fflhub-card">';
        echo '<div class="fflhub-card-title">Pipeline</div>';
        echo '<div class="fflhub-kv">';
        echo self::kv('Started', $started ? self::pill('yes', 'success') : self::pill('no', 'muted'));
        echo self::kv('Started at', $started_at !== '' ? esc_html($started_at) : '<span class="fflhub-muted">—</span>');
        echo self::kv('Started by', $started_by !== '' ? esc_html($started_by) : '<span class="fflhub-muted">—</span>');
        echo '</div>';
        echo '</div>';

        // Index
        echo '<div class="fflhub-card">';
        echo '<div class="fflhub-card-title">Jobs Index</div>';
        if (empty($job_keys)) {
            echo '<div class="fflhub-muted">No jobs found (index empty).</div>';
            if ($index_json !== '') {
                echo '<details class="fflhub-details"><summary>Raw index JSON</summary><pre class="fflhub-pre">'
                    . esc_html($index_json) . '</pre></details>';
            }
        } else {
            echo '<div class="fflhub-badges">';
            foreach ($job_keys as $k) {
                echo '<span class="fflhub-badge">' . esc_html($k) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Job cards
        foreach ($job_keys as $job_key) {
            self::render_job_card($order, $job_key);
        }

        echo '</div>'; // wrap
    }

    private static function render_job_card(WC_Order $order, string $job_key): void
    {
        $keys = OrderPlacementStore::job_meta_keys($job_key);

        $status   = (string) $order->get_meta($keys['status'], true);
        $attempts = (string) $order->get_meta($keys['attempts'], true);
        $created  = (string) $order->get_meta($keys['created'], true);
        $done_at  = (string) $order->get_meta($keys['done_at'], true);
        $actionid = (string) $order->get_meta($keys['action_id'], true);
        $last_err = (string) $order->get_meta($keys['last_error'], true);
        $payload  = (string) $order->get_meta($keys['payload'], true);


        if (strtolower(trim($status)) === 'failed') {
            echo self::render_retry_button($order, $job_key);
        }

        // ✅ Validation result (stored as JSON string)
        $validate_raw = isset($keys['validate_result'])
            ? (string) $order->get_meta($keys['validate_result'], true)
            : '';

        // ✅ Place result (stored as JSON string)
        $place_raw = isset($keys['place_result'])
            ? (string) $order->get_meta($keys['place_result'], true)
            : '';

        $pill = self::pill($status !== '' ? $status : '—', self::status_class($status));

        echo '<div class="fflhub-card fflhub-job">';
        echo '<div class="fflhub-job-head">';
        echo '<div class="fflhub-job-title">' . esc_html($job_key) . '</div>';
        echo '<div>' . $pill . '</div>';
        echo '</div>';

        echo '<div class="fflhub-kv">';
        echo self::kv('Attempts', $attempts !== '' ? esc_html($attempts) : '<span class="fflhub-muted">0</span>');
        echo self::kv('Action ID', $actionid !== '' ? esc_html($actionid) : '<span class="fflhub-muted">—</span>');
        echo self::kv('Created', $created !== '' ? esc_html($created) : '<span class="fflhub-muted">—</span>');
        echo self::kv('Done', $done_at !== '' ? esc_html($done_at) : '<span class="fflhub-muted">—</span>');
        echo '</div>';

        if ($last_err !== '') {
            echo '<div class="fflhub-error">';
            echo '<div class="fflhub-error-title">Last error</div>';
            echo '<div class="fflhub-error-msg">' . esc_html($last_err) . '</div>';
            echo '</div>';
        }

        // ✅ Validation section
        echo '<div class="fflhub-subcard">';
        echo '<div class="fflhub-subcard-title">Validation</div>';

        if ($validate_raw !== '') {
            $val = json_decode($validate_raw, true);

            if (is_array($val)) {
                $ok      = !empty($val['ok']);
                $code    = isset($val['code']) ? (string) $val['code'] : '';
                $msg     = isset($val['message']) ? (string) $val['message'] : '';
                $at      = isset($val['at']) ? (string) $val['at'] : '';
                $codes   = isset($val['codes']) && is_array($val['codes']) ? $val['codes'] : [];

                $v_pill_class = 'muted';
                if ($ok && $code === 'ALLOW') {
                    $v_pill_class = 'success';
                } elseif ($code === 'BLOCK_RETRYABLE') {
                    $v_pill_class = 'warning';
                } elseif ($code === 'BLOCK_FATAL') {
                    $v_pill_class = 'danger';
                }

                echo '<div class="fflhub-kv">';
                echo self::kv('Result', self::pill($code !== '' ? $code : ($ok ? 'ALLOW' : 'BLOCK'), $v_pill_class));
                echo self::kv('Validated at', $at !== '' ? esc_html($at) : '<span class="fflhub-muted">—</span>');

                if (!empty($codes)) {
                    $codes_str = implode(', ', array_slice(array_map('strval', $codes), 0, 12));
                    if (count($codes) > 12) $codes_str .= ', …';
                    echo self::kv('Codes', '<span class="fflhub-mono">' . esc_html($codes_str) . '</span>');
                } else {
                    echo self::kv('Codes', '<span class="fflhub-muted">—</span>');
                }

                if ($msg !== '') {
                    echo self::kv('Message', '<span class="fflhub-mono">' . esc_html($msg) . '</span>');
                } else {
                    echo self::kv('Message', '<span class="fflhub-muted">—</span>');
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

        // ✅ Place section
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
                echo self::kv('Placed at', $at !== '' ? esc_html($at) : '<span class="fflhub-muted">—</span>');

                if ($http > 0) {
                    echo self::kv('HTTP', '<span class="fflhub-mono">' . esc_html((string) $http) . '</span>');
                } else {
                    echo self::kv('HTTP', '<span class="fflhub-muted">—</span>');
                }

                if (!empty($ext)) {
                    $ext_str = implode(', ', array_slice(array_map('strval', $ext), 0, 8));
                    if (count($ext) > 8) $ext_str .= ', …';
                    echo self::kv('External IDs', '<span class="fflhub-mono">' . esc_html($ext_str) . '</span>');
                } else {
                    echo self::kv('External IDs', '<span class="fflhub-muted">—</span>');
                }

                if (!empty($codes)) {
                    $codes_str = implode(', ', array_slice(array_map('strval', $codes), 0, 12));
                    if (count($codes) > 12) $codes_str .= ', …';
                    echo self::kv('Codes', '<span class="fflhub-mono">' . esc_html($codes_str) . '</span>');
                } else {
                    echo self::kv('Codes', '<span class="fflhub-muted">—</span>');
                }

                if ($msg !== '') {
                    echo self::kv('Message', '<span class="fflhub-mono">' . esc_html($msg) . '</span>');
                } else {
                    echo self::kv('Message', '<span class="fflhub-muted">—</span>');
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

        // Payload: show prettified JSON if possible
        if ($payload !== '') {
            $pretty = self::pretty_json($payload);
            echo '<details class="fflhub-details">';
            echo '<summary>Payload</summary>';
            echo '<pre class="fflhub-pre">' . esc_html($pretty) . '</pre>';
            echo '</details>';
        } else {
            echo '<div class="fflhub-muted">No payload stored.</div>';
        }

        echo '</div>';
    }

    private static function resolve_order($post_or_order): ?WC_Order
    {
        if ($post_or_order instanceof WC_Order) {
            return $post_or_order;
        }

        // Classic edit screen passes WP_Post
        if (is_object($post_or_order) && isset($post_or_order->ID)) {
            $oid = (int) $post_or_order->ID;
            $o = wc_get_order($oid);
            return ($o instanceof WC_Order) ? $o : null;
        }

        // HPOS sometimes passes an order-like object; try common getters
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
     * Decide pill class for place results.
     *
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

        // If your place_order uses CODE_BLOCK_RETRYABLE, show warning
        if ($code_u === 'BLOCK_RETRYABLE') {
            return 'warning';
        }

        // Heuristic: if any code looks like rate/quota/timeout, show warning
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

        $url = add_query_arg([
            'action'   => 'fflhub_retry_order_job',
            'order_id' => $order_id,
            'job_key'  => rawurlencode($job_key),
        ], admin_url('admin-post.php'));

        $url = wp_nonce_url($url, 'fflhub_retry_order_job_' . $order_id . '|' . $job_key);

        return '<div style="margin-top:8px;">'
            . '<a class="button button-secondary" href="' . esc_url($url) . '" '
            . 'onclick="return confirm(\'Retry this job now?\');">'
            . 'Retry Job</a>'
            . '</div>';
    }

    public static function handle_retry_job_post(): void
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('edit_shop_orders')) {
            wp_die('Insufficient permissions.');
        }

        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        $job_key  = isset($_GET['job_key']) ? (string) wp_unslash($_GET['job_key']) : '';
        $job_key  = rawurldecode($job_key);

        if ($order_id <= 0 || $job_key === '') {
            wp_die('Missing order_id or job_key.');
        }

        check_admin_referer('fflhub_retry_order_job_' . $order_id . '|' . $job_key);

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            wp_die('Order not found.');
        }

        // Ensure this job is actually part of the job index
        $job_keys = OrderPlacementStore::get_jobs_index($order);
        if (!in_array($job_key, $job_keys, true)) {
            wp_die('Job key not found on this order.');
        }

        // Only allow retry if terminally failed
        $status = OrderPlacementStore::get_job_status($order, $job_key);
        if (strtolower(trim($status)) !== 'failed') {
            self::redirect_back($order_id, $job_key, 'not_failed');
            return;
        }

        // Schedule retry (idempotent: if one is already pending, reuse it)
        $action_id = self::schedule_retry_action($order, $job_key);

        // Update meta to reflect "scheduled" immediately
        if ($action_id !== '') {
            OrderPlacementStore::set_job_action_id($order, $job_key, $action_id);
        } else {
            OrderPlacementStore::set_job_action_id($order, $job_key, '');
        }

        OrderPlacementStore::set_job_status($order, $job_key, OrderPlacementKeys::JOB_STATUS_SCHEDULED);
        OrderPlacementStore::set_job_next_run_at($order, $job_key, gmdate('c', time() + 5));
        OrderPlacementStore::set_job_last_error($order, $job_key, '');
        OrderPlacementStore::set_job_last_error_codes($order, $job_key, []);

        $order->save();

        self::redirect_back($order_id, $job_key, $action_id !== '' ? 'scheduled' : 'as_missing');
    }

    private static function schedule_retry_action(WC_Order $order, string $job_key): string
    {
        if (!function_exists('as_schedule_single_action')) {
            return '';
        }

        $args = [
            'order_id' => (int) $order->get_id(),
            'job_key'  => (string) $job_key,
        ];

        // If already pending, reuse it (strong idempotency)
        if (function_exists('as_next_scheduled_action')) {
            $existing = as_next_scheduled_action(
                OrderPlacementKeys::AS_HOOK,
                $args,
                OrderPlacementKeys::AS_GROUP
            );
            if (is_numeric($existing) && (int) $existing > 0) {
                return (string) (int) $existing;
            }
        }

        // Small delay helps avoid “immediate same-request” weirdness
        $run_at = time() + 5;

        $action_id = as_schedule_single_action(
            $run_at,
            OrderPlacementKeys::AS_HOOK,
            $args,
            OrderPlacementKeys::AS_GROUP
        );

        return is_numeric($action_id) ? (string) (int) $action_id : '';
    }

    private static function redirect_back(int $order_id, string $job_key, string $result): void
    {
        $ref = wp_get_referer();
        if (!$ref) {
            $ref = admin_url('post.php?post=' . $order_id . '&action=edit');
        }

        $ref = add_query_arg([
            'fflhub_retry' => $result,
            'fflhub_job'   => rawurlencode($job_key),
        ], $ref);

        wp_safe_redirect($ref);
        exit;
    }


    private static function css(): string
    {
        return <<<CSS
.fflhub-wrap { display:flex; flex-direction:column; gap:10px; }
.fflhub-card {
  background:#111827; border:1px solid #243043; border-radius:10px;
  padding:10px; color:#e5e7eb;
}
.fflhub-card-title { font-weight:700; margin-bottom:8px; }
.fflhub-muted { color:#9ca3af; }
.fflhub-kv { display:flex; flex-direction:column; gap:6px; }
.fflhub-row { display:flex; justify-content:space-between; gap:10px; }
.fflhub-key { color:#9ca3af; font-weight:600; }
.fflhub-val { text-align:right; word-break:break-word; max-width:70%; }
.fflhub-badges { display:flex; flex-wrap:wrap; gap:6px; }
.fflhub-badge {
  background:#0b1220; border:1px solid #22314a; color:#e5e7eb;
  border-radius:999px; padding:2px 8px; font-size:12px;
}
.fflhub-job-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:8px; }
.fflhub-job-title { font-weight:800; font-size:13px; }
.fflhub-pill { border-radius:999px; padding:2px 8px; font-size:12px; font-weight:800; border:1px solid transparent; }
.fflhub-pill-success { background:#052e1a; border-color:#14532d; color:#bbf7d0; }
.fflhub-pill-danger  { background:#3b0a0a; border-color:#7f1d1d; color:#fecaca; }
.fflhub-pill-warning { background:#3a2a07; border-color:#78350f; color:#fde68a; }
.fflhub-pill-info    { background:#061b2a; border-color:#164e63; color:#a5f3fc; }
.fflhub-pill-muted   { background:#0b1220; border-color:#22314a; color:#cbd5e1; }
.fflhub-details summary { cursor:pointer; color:#93c5fd; font-weight:700; margin-top:8px; }
.fflhub-pre {
  background:#0b1220; border:1px solid #22314a; border-radius:8px;
  padding:8px; overflow:auto; color:#e5e7eb; font-size:12px; line-height:1.35;
  max-height:260px;
}
.fflhub-error { margin-top:8px; background:#2a0d0d; border:1px solid #7f1d1d; border-radius:8px; padding:8px; }
.fflhub-error-title { font-weight:800; color:#fecaca; margin-bottom:4px; }
.fflhub-error-msg { color:#fee2e2; font-size:12px; white-space:pre-wrap; word-break:break-word; }

/* small inner card for validation/place blocks */
.fflhub-subcard {
  margin-top:8px;
  background:#0b1220;
  border:1px solid #22314a;
  border-radius:8px;
  padding:8px;
}
.fflhub-subcard-title {
  font-weight:800;
  margin-bottom:6px;
  color:#e5e7eb;
}
.fflhub-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:12px; }
CSS;
    }
}
