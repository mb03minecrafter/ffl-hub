<?php
declare(strict_types=1);

namespace FFLHub\Shipping\EasyPost;

use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-only provider-level REST endpoints for EasyPost.
 *
 * Order label UI can call these once we add an EasyPost selector. For now they
 * provide a thin, testable boundary around the provider adapter.
 */
final class EasyPostRestController
{
    private const REST_NAMESPACE = 'fflhub/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/easypost/validate-address', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'validate_address'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/easypost/rates', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rates'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/easypost/purchase', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'purchase'],
            'permission_callback' => [__CLASS__, 'can_manage_shipping'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/easypost/void', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'void_label'],
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
    public static function validate_address(WP_REST_Request $request)
    {
        if (!EasyPostOptions::is_enabled()) {
            return new WP_Error('fflhub_easypost_disabled', 'EasyPost is disabled.', ['status' => 400]);
        }

        $params = self::json_params($request);
        $address = isset($params['address']) && is_array($params['address']) ? $params['address'] : $params;

        return self::provider()->validate_address($address);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function rates(WP_REST_Request $request)
    {
        if (!EasyPostOptions::is_enabled()) {
            return new WP_Error('fflhub_easypost_disabled', 'EasyPost is disabled.', ['status' => 400]);
        }

        return self::provider()->get_rates(self::json_params($request));
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function purchase(WP_REST_Request $request)
    {
        if (!EasyPostOptions::is_enabled()) {
            return new WP_Error('fflhub_easypost_disabled', 'EasyPost is disabled.', ['status' => 400]);
        }

        $params = self::json_params($request);
        $rate_id = sanitize_text_field((string) ($params['rate_id'] ?? ''));
        if ($rate_id === '') {
            return new WP_Error('fflhub_easypost_missing_rate_id', 'Missing EasyPost rate ID.', ['status' => 400]);
        }

        return self::provider()->purchase_label_from_rate($rate_id, $params);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function void_label(WP_REST_Request $request)
    {
        if (!EasyPostOptions::is_enabled()) {
            return new WP_Error('fflhub_easypost_disabled', 'EasyPost is disabled.', ['status' => 400]);
        }

        $params = self::json_params($request);
        $label_id = sanitize_text_field((string) ($params['label_id'] ?? ''));
        if ($label_id === '') {
            return new WP_Error('fflhub_easypost_missing_label_id', 'Missing EasyPost label token.', ['status' => 400]);
        }

        return self::provider()->void_label($label_id);
    }

    /**
     * @return array<string,mixed>
     */
    private static function json_params(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        return is_array($params) ? $params : [];
    }

    private static function provider(): EasyPostShippingProvider
    {
        return new EasyPostShippingProvider(new EasyPostClient());
    }
}
