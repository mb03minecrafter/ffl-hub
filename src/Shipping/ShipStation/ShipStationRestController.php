<?php
declare(strict_types=1);

namespace FFLHub\Shipping\ShipStation;

use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Shipping\Packing\PackingSlipService;
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
        add_action('admin_post_fflhub_shipstation_print_label_with_slip', [__CLASS__, 'print_label_with_slip']);
        add_action('admin_post_fflhub_shipstation_packing_slip', [__CLASS__, 'packing_slip']);
        add_action('admin_post_fflhub_shipstation_packing_slip_zpl', [__CLASS__, 'packing_slip_zpl']);
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

        register_rest_route(self::REST_NAMESPACE, '/shipstation/order/(?P<order_id>\d+)/local-deactivate-label', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'local_deactivate_label_route'],
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

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function local_deactivate_label_route(WP_REST_Request $request)
    {
        $order = self::order_from_request($request);
        if (is_wp_error($order)) {
            return $order;
        }

        $params = self::json_params($request);
        return self::service()->deactivate_label_locally(
            $order,
            (string) ($params['label_id'] ?? ''),
            (string) ($params['reason'] ?? '')
        );
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

    public static function print_label_with_slip(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to print this label.', 'ffl-hub'), '', ['response' => 403]);
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $label_id = isset($_GET['label_id']) ? sanitize_text_field(wp_unslash((string) $_GET['label_id'])) : '';
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        if ($order_id <= 0 || $label_id === '' || !wp_verify_nonce($nonce, self::print_label_with_slip_nonce_action($order_id, $label_id))) {
            wp_die(esc_html__('Invalid label print request.', 'ffl-hub'), '', ['response' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            wp_die(esc_html__('Order not found.', 'ffl-hub'), '', ['response' => 404]);
        }

        $label = ShipStationOrderMeta::find_label($order, $label_id);
        if (!is_array($label)) {
            wp_die(esc_html__('Shipping label not found.', 'ffl-hub'), '', ['response' => 404]);
        }

        $format = strtolower(trim((string) ($label['label_format'] ?? 'pdf')));
        if ($format !== 'zpl') {
            wp_die(
                esc_html__('Combined label + packing slip output is only available when the purchased carrier label is ZPL. Switch the global label format to ZPL for printer-native combined output.', 'ffl-hub'),
                '',
                ['response' => 400]
            );
        }

        $download = self::service()->download_label($order, $label_id);
        if (is_wp_error($download)) {
            wp_die(esc_html($download->get_error_message()), '', ['response' => 500]);
        }

        $carrier_zpl = trim((string) ($download['body'] ?? ''));
        if ($carrier_zpl === '' || stripos($carrier_zpl, '^XA') === false || stripos($carrier_zpl, '^XZ') === false) {
            wp_die(esc_html__('The saved carrier label did not look like valid ZPL.', 'ffl-hub'), '', ['response' => 500]);
        }

        $slip_zpl = self::packing_slip_zpl_documents($order, $label);
        if (is_wp_error($slip_zpl)) {
            wp_die(esc_html($slip_zpl->get_error_message()), '', ['response' => 500]);
        }

        $body = $carrier_zpl . "\n\n" . implode("\n", $slip_zpl) . "\n";

        nocache_headers();
        header('Content-Type: application/vnd.zebra-zpl');
        header('Content-Disposition: inline; filename="' . sanitize_file_name('label-and-packing-slip-order-' . $order->get_order_number() . '.zpl') . '"');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function print_label_with_slip_url(WC_Order $order, string $label_id): string
    {
        $order_id = (int) $order->get_id();

        return add_query_arg([
            'action' => 'fflhub_shipstation_print_label_with_slip',
            'order_id' => $order_id,
            'label_id' => $label_id,
            '_wpnonce' => wp_create_nonce(self::print_label_with_slip_nonce_action($order_id, $label_id)),
        ], admin_url('admin-post.php'));
    }

    public static function packing_slip(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to print this packing slip.', 'ffl-hub'), '', ['response' => 403]);
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $label_id = isset($_GET['label_id']) ? sanitize_text_field(wp_unslash((string) $_GET['label_id'])) : '';
        $package_index = isset($_GET['package_index']) ? max(0, absint($_GET['package_index'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        if ($order_id <= 0 || $label_id === '' || !wp_verify_nonce($nonce, self::packing_slip_nonce_action($order_id, $label_id, $package_index))) {
            wp_die(esc_html__('Invalid packing slip request.', 'ffl-hub'), '', ['response' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            wp_die(esc_html__('Order not found.', 'ffl-hub'), '', ['response' => 404]);
        }

        $label = ShipStationOrderMeta::find_label($order, $label_id);
        if (!is_array($label)) {
            wp_die(esc_html__('Shipping label not found.', 'ffl-hub'), '', ['response' => 404]);
        }

        $slip = (new PackingSlipService())->generate_for_label($order, $label, $package_index);
        if (is_wp_error($slip)) {
            wp_die(esc_html($slip->get_error_message()), '', ['response' => 500]);
        }

        nocache_headers();
        header('Content-Type: ' . (string) ($slip['content_type'] ?? 'text/html; charset=UTF-8'));
        header('Content-Disposition: inline; filename="' . sanitize_file_name((string) ($slip['filename'] ?? 'packing-slip.html')) . '"');
        echo (string) ($slip['body'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function packing_slip_zpl(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to print this packing slip.', 'ffl-hub'), '', ['response' => 403]);
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $label_id = isset($_GET['label_id']) ? sanitize_text_field(wp_unslash((string) $_GET['label_id'])) : '';
        $package_index = isset($_GET['package_index']) ? max(0, absint($_GET['package_index'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
        if ($order_id <= 0 || $label_id === '' || !wp_verify_nonce($nonce, self::packing_slip_zpl_nonce_action($order_id, $label_id, $package_index))) {
            wp_die(esc_html__('Invalid packing slip request.', 'ffl-hub'), '', ['response' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            wp_die(esc_html__('Order not found.', 'ffl-hub'), '', ['response' => 404]);
        }

        $label = ShipStationOrderMeta::find_label($order, $label_id);
        if (!is_array($label)) {
            wp_die(esc_html__('Shipping label not found.', 'ffl-hub'), '', ['response' => 404]);
        }

        $slip = (new PackingSlipService())->generate_zpl_for_label($order, $label, $package_index);
        if (is_wp_error($slip)) {
            wp_die(esc_html($slip->get_error_message()), '', ['response' => 500]);
        }

        nocache_headers();
        header('Content-Type: application/vnd.zebra-zpl');
        header('Content-Disposition: inline; filename="' . sanitize_file_name((string) ($slip['filename'] ?? 'packing-slip.zpl')) . '"');
        echo (string) ($slip['body'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function packing_slip_url(WC_Order $order, string $label_id, int $package_index = 0): string
    {
        $order_id = (int) $order->get_id();
        $package_index = max(0, $package_index);

        return add_query_arg([
            'action' => 'fflhub_shipstation_packing_slip',
            'order_id' => $order_id,
            'label_id' => $label_id,
            'package_index' => $package_index,
            '_wpnonce' => wp_create_nonce(self::packing_slip_nonce_action($order_id, $label_id, $package_index)),
        ], admin_url('admin-post.php'));
    }

    public static function packing_slip_zpl_url(WC_Order $order, string $label_id, int $package_index = 0): string
    {
        $order_id = (int) $order->get_id();
        $package_index = max(0, $package_index);

        return add_query_arg([
            'action' => 'fflhub_shipstation_packing_slip_zpl',
            'order_id' => $order_id,
            'label_id' => $label_id,
            'package_index' => $package_index,
            '_wpnonce' => wp_create_nonce(self::packing_slip_zpl_nonce_action($order_id, $label_id, $package_index)),
        ], admin_url('admin-post.php'));
    }

    /**
     * @param array<string,mixed> $label
     * @return array<int,string>|WP_Error
     */
    private static function packing_slip_zpl_documents(WC_Order $order, array $label)
    {
        $service = new PackingSlipService();
        $package_count = self::label_package_count($label);
        $documents = [];

        for ($package_index = 0; $package_index < $package_count; $package_index++) {
            $slip = $service->generate_zpl_for_label($order, $label, $package_index);
            if (is_wp_error($slip)) {
                return $slip;
            }

            $zpl = trim((string) ($slip['body'] ?? ''));
            if ($zpl === '' || stripos($zpl, '^XA') === false || stripos($zpl, '^XZ') === false) {
                return new WP_Error(
                    'fflhub_packing_slip_render_failed',
                    'The packing slip could not be rendered as ZPL.'
                );
            }

            $documents[] = $zpl;
        }

        return $documents;
    }

    /**
     * @param array<string,mixed> $label
     */
    private static function label_package_count(array $label): int
    {
        $snapshot = is_array($label['shipment_snapshot'] ?? null) ? $label['shipment_snapshot'] : [];
        $packages = isset($snapshot['packages']) && is_array($snapshot['packages'])
            ? $snapshot['packages']
            : [];
        $details = isset($label['package_details']) && is_array($label['package_details'])
            ? $label['package_details']
            : [];
        $items = isset($label['package_items']) && is_array($label['package_items'])
            ? $label['package_items']
            : [];

        return max(1, count($packages), count($details), count($items));
    }

    private static function extract_packing_slip_page(string $html): string
    {
        if (preg_match('/<main\b[^>]*class="[^"]*\bfflhub-slip\b[^"]*"[^>]*>.*?<\/main>/is', $html, $matches)) {
            return (string) $matches[0];
        }

        return '';
    }

    private static function extract_packing_slip_css(string $html): string
    {
        if (preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $html, $matches)) {
            return (string) $matches[1];
        }

        return '';
    }

    private static function download_nonce_action(int $order_id, string $label_id): string
    {
        return 'fflhub_shipstation_download_label_' . $order_id . '_' . $label_id;
    }

    private static function print_label_with_slip_nonce_action(int $order_id, string $label_id): string
    {
        return 'fflhub_shipstation_print_label_with_slip_' . $order_id . '_' . $label_id;
    }

    private static function packing_slip_nonce_action(int $order_id, string $label_id, int $package_index): string
    {
        return 'fflhub_shipstation_packing_slip_' . $order_id . '_' . $label_id . '_' . $package_index;
    }

    private static function packing_slip_zpl_nonce_action(int $order_id, string $label_id, int $package_index): string
    {
        return 'fflhub_shipstation_packing_slip_zpl_' . $order_id . '_' . $label_id . '_' . $package_index;
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
