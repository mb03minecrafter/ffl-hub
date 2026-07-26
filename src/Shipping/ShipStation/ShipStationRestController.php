<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

use FFLHub\FFL\Tables\FFLTable;
use WC_Order;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-only REST/admin-post endpoints for ShipStation order labels.
 */
final class ShipStationRestController
{
    private const REST_NAMESPACE = 'fflhub/v1';

    private static ?FFLTable $ffl_table = null;

    public static function init(FFLTable $ffl_table): void
    {
        self::$ffl_table = $ffl_table;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_fflhub_shipstation_download_label', [__CLASS__, 'download_label']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/shipstation/order/(?P<order_id>\d+)/context', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'context'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/shipstation/order/(?P<order_id>\d+)/validate-address', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'validate_address'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/shipstation/order/(?P<order_id>\d+)/auto-pack', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'auto_pack'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/shipstation/order/(?P<order_id>\d+)/rates', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rates'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/shipstation/order/(?P<order_id>\d+)/purchase', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'purchase'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/shipstation/order/(?P<order_id>\d+)/void', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'void_label_route'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);
    }

    /**
     * @param mixed $request
     */
    public static function can_manage_shipping($request = null): bool
    {
        return current_user_can('manage_woocommerce');
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function context(WP_REST_Request $request)
    {
        $order = self::order_from_request($request);
        if (is_wp_error($order)) {
            return $order;
        }

        return self::service()->build_context($order);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function validate_address(WP_REST_Request $request)
    {
        $order = self::order_from_request($request);
        if (is_wp_error($order)) {
            return $order;
        }

        return self::service()->validate_address(self::json_params($request));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function auto_pack(WP_REST_Request $request)
    {
        $order = self::order_from_request($request);
        if (is_wp_error($order)) {
            return $order;
        }

        return self::service()->auto_pack_order($order);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function rates(WP_REST_Request $request)
    {
        $order = self::order_from_request($request);
        if (is_wp_error($order)) {
            return $order;
        }

        return self::service()->rate_order($order, self::json_params($request));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function purchase(WP_REST_Request $request)
    {
        $order = self::order_from_request($request);
        if (is_wp_error($order)) {
            return $order;
        }

        return self::service()->purchase_label($order, self::json_params($request));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function void_label_route(WP_REST_Request $request)
    {
        $order = self::order_from_request($request);
        if (is_wp_error($order)) {
            return $order;
        }

        $params = self::json_params($request);
        return self::service()->void_label($order, (string) ($params['label_id'] ?? ''));
    }

    public static function download_label(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to download this label.', 'ffl-hub'), '', ['response' => 403]);
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $label_id = isset($_GET['label_id']) ? sanitize_text_field(wp_unslash((string) $_GET['label_id'])) : '';
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        if ($order_id <= 0 || $label_id === '' || !wp_verify_nonce($nonce, self::download_nonce_action($order_id, $label_id))) {
            wp_die(esc_html__('Invalid label download request.', 'ffl-hub'), '', ['response' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            wp_die(esc_html__('Order not found.', 'ffl-hub'), '', ['response' => 404]);
        }

        $download = self::service()->download_label($order, $label_id);
        if (is_wp_error($download)) {
            wp_die(esc_html($download->get_error_message()), '', ['response' => 500]);
        }

        $filename = (string) ($download['filename'] ?? 'shipstation-label');
        $content_type = (string) ($download['content_type'] ?? 'application/octet-stream');
        $disposition = isset($_GET['download']) && (string) $_GET['download'] === '1' ? 'attachment' : 'inline';

        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: ' . $disposition . '; filename="' . sanitize_file_name($filename) . '"');
        echo (string) ($download['body'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function download_url(WC_Order $order, string $label_id, bool $force_download = false): string
    {
        $order_id = (int) $order->get_id();
        $args = [
            'action' => 'fflhub_shipstation_download_label',
            'order_id' => $order_id,
            'label_id' => $label_id,
            '_wpnonce' => wp_create_nonce(self::download_nonce_action($order_id, $label_id)),
        ];

        if ($force_download) {
            $args['download'] = '1';
        }

        return add_query_arg($args, admin_url('admin-post.php'));
    }

    private static function download_nonce_action(int $order_id, string $label_id): string
    {
        return 'fflhub_shipstation_download_label_' . $order_id . '_' . $label_id;
    }

    /**
     * @return WC_Order|WP_Error
     */
    private static function order_from_request(WP_REST_Request $request)
    {
        $order_id = absint($request['order_id'] ?? 0);
        if ($order_id <= 0) {
            return new WP_Error('fflhub_shipstation_missing_order', 'Missing order ID.', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            return new WP_Error('fflhub_shipstation_order_not_found', 'Order not found.', ['status' => 404]);
        }

        return $order;
    }

    /**
     * @return array<string,mixed>
     */
    private static function json_params(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        return is_array($params) ? $params : [];
    }

    private static function service(): ShipStationShipmentService
    {
        if (!(self::$ffl_table instanceof FFLTable)) {
            self::$ffl_table = new FFLTable(new \FFLHub\FFL\Tables\FFLSchema());
        }

        return new ShipStationShipmentService(self::$ffl_table);
    }
}
