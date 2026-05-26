<?php

namespace FFLHub\Admin\Pages;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Settings\Options;
use FFLHub\Distributor\Core\DistributorRegistry;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Integrations\Lipseys\LipseysIntegrationAPI;
use FFLHub\Distributor\Integrations\RSR\RSRDirectConnectAPI;
use FFLHub\Distributor\Integrations\Zanders\ZandersDirectShipAPI;
use FFLHub\Distributor\Services\CSSI\API\CSSIClient;
use FFLHub\Distributor\Services\FTP\FTPClientService;
use FFLHub\Distributor\Services\Kinseys\API\KinseysApiClient;
use FFLHub\Distributor\Services\Orion\API\OrionApiClient;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInventoryClient;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthInvoicesClient;
use FFLHub\Distributor\Services\SportsSouth\API\SportsSouthOrdersClient;
use FFLHub\Distributor\Services\Zanders\API\ZandersSoapCurlClient;

/**
 * Renders the main FFL Hub admin page and loads its assets.
 */
class AdminPage
{
    /**
     * Slug of the settings page (used by the top-level FFL Hub menu).
     */
    private const PAGE_SLUG = 'ffl-hub-settings';
    private DistributorHandler $handler;

    public function __construct(DistributorHandler $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Get the slug of the settings page so subpages can attach to it.
     */
    public static function get_page_slug(): string
    {
        return self::PAGE_SLUG;
    }

    /**
     * Initialize hooks for the main FFL Hub admin page.
     */
    public function register(): void
    {
        // Register the top-level FFL Hub menu.
        add_action('admin_menu', [$this, 'register_menu_page']);

        // Enqueue assets for the FFL Hub settings page.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Settings registration is owned by SettingsRegistrar.
        // This page handles UI rendering + admin actions only.

        // Handle enable/disable distributor actions.
        add_action('admin_post_fflhub_toggle_distributor', [$this, 'handle_toggle_distributor']);
        add_action('wp_ajax_fflhub_test_cssi_credentials', [$this, 'handle_test_cssi_credentials']);
        add_action('wp_ajax_fflhub_test_kinseys_credentials', [$this, 'handle_test_kinseys_credentials']);
        add_action('wp_ajax_fflhub_test_lipseys_credentials', [$this, 'handle_test_lipseys_credentials']);
        add_action('wp_ajax_fflhub_test_orion_credentials', [$this, 'handle_test_orion_credentials']);
        add_action('wp_ajax_fflhub_test_rsr_credentials', [$this, 'handle_test_rsr_credentials']);
        add_action('wp_ajax_fflhub_test_sports_south_credentials', [$this, 'handle_test_sports_south_credentials']);
        add_action('wp_ajax_fflhub_test_zanders_soap_credentials', [$this, 'handle_test_zanders_soap_credentials']);
    }


    /**
     * Enqueue CSS/JS only on our FFL Hub settings page.
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $base_url = FFLHUB_PLUGIN_URL . 'assets/';
        $css_path = FFLHUB_PLUGIN_PATH . 'assets/css/admin.css';
        $js_path  = FFLHUB_PLUGIN_PATH . 'assets/js/admin.js';
        $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : FFLHUB_PLUGIN_VERSION;
        $js_ver   = file_exists($js_path) ? (string) filemtime($js_path) : FFLHUB_PLUGIN_VERSION;

        wp_enqueue_style(
            'fflhub-admin',
            $base_url . 'css/admin.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'fflhub-admin',
            $base_url . 'js/admin.js',
            ['jquery'],
            $js_ver,
            true
        );

        wp_localize_script(
            'fflhub-admin',
            'FFLHubAdmin',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'cssiCredentialNonce' => wp_create_nonce('fflhub_test_cssi_credentials'),
                'kinseysCredentialNonce' => wp_create_nonce('fflhub_test_kinseys_credentials'),
                'lipseysCredentialNonce' => wp_create_nonce('fflhub_test_lipseys_credentials'),
                'orionCredentialNonce' => wp_create_nonce('fflhub_test_orion_credentials'),
                'rsrCredentialNonce' => wp_create_nonce('fflhub_test_rsr_credentials'),
                'sportsSouthCredentialNonce' => wp_create_nonce('fflhub_test_sports_south_credentials'),
                'zandersSoapNonce' => wp_create_nonce('fflhub_test_zanders_soap_credentials'),
            ]
        );
    }

    /**
     * Plugin's main page renderer, wired to the menu callback.
     */
    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        // IMPORTANT:
        // Admin UI is module-driven so distributors never "disappear" when disabled.
        $modules = DistributorRegistry::get_modules();

        self::render($modules);
    }

    /**
     * Register the top-level "FFL Hub" menu item.
     */
    public function register_menu_page(): void
    {
        add_menu_page(
            __('FFL Hub Settings', 'ffl-hub'),
            __('FFL Hub', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-admin-generic',
            56
        );
    }

    /**
     * Handle enable/disable distributor actions (from modal).
     */
    public function handle_toggle_distributor(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'ffl-hub'));
        }

        $id = isset($_POST['distributor_id'])
            ? sanitize_text_field(wp_unslash($_POST['distributor_id']))
            : '';

        if ($id === '') {
            wp_die(esc_html__('Invalid distributor ID.', 'ffl-hub'));
        }

        check_admin_referer('fflhub_toggle_distributor_' . $id);

        $enable_flag = isset($_POST['enable'])
            ? sanitize_text_field(wp_unslash((string) $_POST['enable']))
            : '0';
        $enabled     = ($enable_flag === '1');

        // Centralize behavior in the handler so disabling halts cron/services.
        $this->handler->set_enabled($id, $enabled);

        $redirect = add_query_arg(
            [
                'page'               => self::PAGE_SLUG,
                'fflhub_toggle_done' => $id,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_test_zanders_soap_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'ffl-hub')], 403);
        }

        check_ajax_referer('fflhub_test_zanders_soap_credentials', 'nonce');

        $profile = isset($_POST['profile']) ? sanitize_key(wp_unslash((string) $_POST['profile'])) : '';
        $profile_config = self::zanders_credential_test_profile($profile);
        if (empty($profile_config)) {
            wp_send_json_success([
                'ok' => false,
                'message' => __('Unknown Zanders credential profile.', 'ffl-hub'),
            ]);
        }

        $posted_fields = self::posted_zanders_settings_fields();
        $username = self::zanders_posted_or_saved_setting($posted_fields, (string) $profile_config['username_key']);
        $password = self::zanders_posted_or_saved_setting($posted_fields, (string) $profile_config['password_key']);

        if ($username === '' || $password === '') {
            wp_send_json_success([
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => (string) $profile_config['label'],
                'message' => sprintf(
                    __('Missing username or password for %s.', 'ffl-hub'),
                    (string) $profile_config['label']
                ),
            ]);
        }

        $fake_order = 'FFLHUBTEST' . gmdate('YmdHis') . (string) wp_rand(100, 999);
        $verify_tls = (bool) apply_filters('fflhub_zanders_verify_tls', true);
        $timeout = (int) apply_filters('fflhub_zanders_credential_test_timeout_sec', 30);

        try {
            $client = new ZandersSoapCurlClient(
                ZandersDirectShipAPI::ORDERS_WSDL,
                max(10, $timeout),
                $verify_tls,
                'FFLHUB-Zanders-CredTest'
            );

            $soap = ZandersDirectShipAPI::get_tracking_info(
                $client,
                [
                    'username' => $username,
                    'password' => $password,
                ],
                $fake_order,
                false
            );
        } catch (\Throwable $e) {
            wp_send_json_success([
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => (string) $profile_config['label'],
                'message' => 'Zanders SOAP credential test failed before a usable response: ' . $e->getMessage(),
                'fakeOrder' => $fake_order,
            ]);
        }

        $http_status = (int) ($soap['http_status'] ?? 0);
        if (empty($soap['ok'])) {
            wp_send_json_success([
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => (string) $profile_config['label'],
                'message' => (string) ($soap['message'] ?? __('Zanders SOAP call failed.', 'ffl-hub')),
                'httpStatus' => $http_status,
                'fakeOrder' => $fake_order,
            ]);
        }

        $norm = ZandersDirectShipAPI::normalize_tracking_response($soap, 'Zanders credential test');
        $return_code = (int) ($norm['return_code'] ?? -1);
        $message = (string) ($norm['message'] ?? ($soap['message'] ?? ''));

        if (
            !self::zanders_message_looks_known_fake_order_response($return_code, $message)
            && self::zanders_message_looks_auth_failure($message)
        ) {
            wp_send_json_success([
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => (string) $profile_config['label'],
                'message' => $message !== '' ? $message : __('Zanders reported an authentication failure.', 'ffl-hub'),
                'httpStatus' => $http_status,
                'returnCode' => $return_code,
                'fakeOrder' => $fake_order,
            ]);
        }

        if ($return_code === -1) {
            wp_send_json_success([
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => (string) $profile_config['label'],
                'message' => __('SOAP endpoint responded, but FFLHub could not read a Zanders returnCode from the fake tracking lookup.', 'ffl-hub'),
                'httpStatus' => $http_status,
                'returnCode' => $return_code,
                'fakeOrder' => $fake_order,
            ]);
        }

        $success_message = self::zanders_credential_test_success_message(
            (string) $profile_config['label'],
            $fake_order,
            $return_code,
            $message
        );

        wp_send_json_success([
            'ok' => true,
            'profile' => $profile,
            'profileLabel' => (string) $profile_config['label'],
            'message' => $success_message,
            'httpStatus' => $http_status,
            'returnCode' => $return_code,
            'fakeOrder' => $fake_order,
        ]);
    }

    public function handle_test_rsr_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'ffl-hub')], 403);
        }

        check_ajax_referer('fflhub_test_rsr_credentials', 'nonce');

        $profile = isset($_POST['profile']) ? sanitize_key(wp_unslash((string) $_POST['profile'])) : '';
        $profile_config = self::rsr_credential_test_profile($profile);
        if (empty($profile_config)) {
            wp_send_json_success([
                'ok' => false,
                'message' => __('Unknown RSR credential profile.', 'ffl-hub'),
            ]);
        }

        $posted_fields = self::posted_distributor_settings_fields();

        if ((string) $profile_config['mode'] === 'ftp') {
            wp_send_json_success(self::test_rsr_ftp_credentials($profile, $profile_config, $posted_fields));
        }

        wp_send_json_success(self::test_rsr_directconnect_credentials($profile, $profile_config, $posted_fields));
    }

    public function handle_test_lipseys_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'ffl-hub')], 403);
        }

        check_ajax_referer('fflhub_test_lipseys_credentials', 'nonce');

        $profile = isset($_POST['profile']) ? sanitize_key(wp_unslash((string) $_POST['profile'])) : '';
        $profile_config = self::lipseys_credential_test_profile($profile);
        if (empty($profile_config)) {
            wp_send_json_success([
                'ok' => false,
                'message' => __('Unknown Lipsey\'s credential profile.', 'ffl-hub'),
            ]);
        }

        $posted_fields = self::posted_distributor_settings_fields();

        wp_send_json_success(self::test_lipseys_api_credentials($profile, $profile_config, $posted_fields));
    }

    public function handle_test_cssi_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'ffl-hub')], 403);
        }

        check_ajax_referer('fflhub_test_cssi_credentials', 'nonce');

        $posted_fields = self::posted_distributor_settings_fields();

        wp_send_json_success(self::test_cssi_api_credentials($posted_fields));
    }

    public function handle_test_kinseys_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'ffl-hub')], 403);
        }

        check_ajax_referer('fflhub_test_kinseys_credentials', 'nonce');

        $posted_fields = self::posted_distributor_settings_fields();

        wp_send_json_success(self::test_kinseys_api_credentials($posted_fields));
    }

    public function handle_test_orion_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'ffl-hub')], 403);
        }

        check_ajax_referer('fflhub_test_orion_credentials', 'nonce');

        $posted_fields = self::posted_distributor_settings_fields();

        wp_send_json_success(self::test_orion_api_credentials($posted_fields));
    }

    public function handle_test_sports_south_credentials(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'ffl-hub')], 403);
        }

        check_ajax_referer('fflhub_test_sports_south_credentials', 'nonce');

        $profile = isset($_POST['profile']) ? sanitize_key(wp_unslash((string) $_POST['profile'])) : '';
        $profile_config = self::sports_south_credential_test_profile($profile);
        if (empty($profile_config)) {
            wp_send_json_success([
                'ok' => false,
                'message' => __('Unknown Sports South credential profile.', 'ffl-hub'),
            ]);
        }

        $posted_fields = self::posted_distributor_settings_fields();

        wp_send_json_success(self::test_sports_south_api_credentials($profile, $profile_config, $posted_fields));
    }

    /**
     * @return array{label:string,username_key:string,password_key:string}|null
     */
    private static function zanders_credential_test_profile(string $profile): ?array
    {
        $profiles = [
            'main' => [
                'label' => 'Main / Dealer SOAP',
                'username_key' => 'main_username',
                'password_key' => 'main_password',
            ],
            'accessory' => [
                'label' => 'Accessory SOAP',
                'username_key' => 'accessory_username',
                'password_key' => 'accessory_password',
            ],
            'gun' => [
                'label' => 'Gun SOAP',
                'username_key' => 'gun_username',
                'password_key' => 'gun_password',
            ],
        ];

        return $profiles[$profile] ?? null;
    }

    /**
     * @return array<string,string>
     */
    private static function posted_zanders_settings_fields(): array
    {
        return self::posted_distributor_settings_fields();
    }

    /**
     * @return array<string,string>
     */
    private static function posted_distributor_settings_fields(): array
    {
        $raw = (isset($_POST['fields']) && is_array($_POST['fields'])) ? wp_unslash($_POST['fields']) : [];
        if (!is_array($raw)) {
            return [];
        }

        $fields = [];
        foreach ($raw as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $fields[(string) $key] = trim((string) $value);
        }

        return $fields;
    }

    /**
     * @param array<string,string> $posted_fields
     */
    private static function zanders_posted_or_saved_setting(array $posted_fields, string $key): string
    {
        return self::distributor_posted_or_saved_setting('zanders', $posted_fields, $key);
    }

    /**
     * @param array<string,string> $posted_fields
     */
    private static function distributor_posted_or_saved_setting(string $distributor_id, array $posted_fields, string $key): string
    {
        $option_name = Options::distributor_option_name($distributor_id, $key);
        if (array_key_exists($option_name, $posted_fields)) {
            return trim((string) $posted_fields[$option_name]);
        }

        return trim((string) Options::get_distributor_option($distributor_id, $key, ''));
    }

    /**
     * @return array<string,string>|null
     */
    private static function rsr_credential_test_profile(string $profile): ?array
    {
        $profiles = [
            'main' => [
                'label' => 'Main DirectConnect',
                'mode' => 'directconnect',
                'username_key' => 'main_account_number',
                'password_key' => 'main_account_password',
            ],
            'dropship' => [
                'label' => 'Drop-Ship DirectConnect',
                'mode' => 'directconnect',
                'username_key' => 'dropship_account_number',
                'password_key' => 'dropship_account_password',
            ],
            'ftp' => [
                'label' => 'FTP Feed',
                'mode' => 'ftp',
                'host_key' => 'ftp_host',
                'username_key' => 'ftp_username',
                'password_key' => 'ftp_password',
                'ssl_key' => 'ftp_use_ssl',
            ],
        ];

        return $profiles[$profile] ?? null;
    }

    /**
     * @return array<string,string>|null
     */
    private static function lipseys_credential_test_profile(string $profile): ?array
    {
        $profiles = [
            'main' => [
                'label' => 'Main Account API',
                'email_key' => 'main_account_email',
                'password_key' => 'main_account_password',
            ],
            'dealer' => [
                'label' => 'Dealer API',
                'email_key' => 'dealer_email',
                'password_key' => 'dealer_password',
            ],
        ];

        return $profiles[$profile] ?? null;
    }

    /**
     * @return array<string,string>|null
     */
    private static function sports_south_credential_test_profile(string $profile): ?array
    {
        $profiles = [
            'inventory' => [
                'label' => 'Inventory API',
                'mode' => 'inventory',
            ],
            'orders' => [
                'label' => 'Orders API',
                'mode' => 'orders',
            ],
            'invoices' => [
                'label' => 'Invoices / Tracking API',
                'mode' => 'invoices',
            ],
        ];

        return $profiles[$profile] ?? null;
    }

    /**
     * @param array<string,string> $profile_config
     * @param array<string,string> $posted_fields
     * @return array<string,mixed>
     */
    private static function test_lipseys_api_credentials(string $profile, array $profile_config, array $posted_fields): array
    {
        $label = (string) $profile_config['label'];
        $email = self::distributor_posted_or_saved_setting('lipseys', $posted_fields, (string) $profile_config['email_key']);
        $password = self::distributor_posted_or_saved_setting('lipseys', $posted_fields, (string) $profile_config['password_key']);

        if ($email === '' || $password === '') {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => sprintf(
                    __('Missing email or password for %s.', 'ffl-hub'),
                    $label
                ),
            ];
        }

        $res = LipseysIntegrationAPI::authenticate_credentials($email, $password);
        $message = (string) ($res['message'] ?? __('Lipsey\'s authentication call failed.', 'ffl-hub'));
        $provider_code = strtoupper(trim((string) ($res['provider_error_code'] ?? '')));
        $http_status = (int) ($res['http_status'] ?? 0);
        $likely_cause = trim((string) ($res['likely_cause'] ?? ''));
        $raw_response = self::lipseys_format_raw_response($res['raw'] ?? null);

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => $message,
                'providerCode' => $provider_code,
                'httpStatus' => $http_status,
                'likelyCause' => $likely_cause,
                'rawResponse' => $raw_response,
            ];
        }

        return [
            'ok' => true,
            'profile' => $profile,
            'profileLabel' => $label,
            'message' => sprintf(
                __('Credentials accepted for %s. Lipsey\'s authentication/login endpoint returned a valid token.', 'ffl-hub'),
                $label
            ),
            'providerCode' => $provider_code,
            'httpStatus' => $http_status,
            'likelyCause' => $likely_cause,
            'rawResponse' => $raw_response,
        ];
    }

    /**
     * @param mixed $raw
     */
    private static function lipseys_format_raw_response($raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        if (is_scalar($raw)) {
            return trim((string) $raw);
        }

        $encoded = wp_json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string,string> $posted_fields
     * @return array<string,mixed>
     */
    private static function test_cssi_api_credentials(array $posted_fields): array
    {
        $sid = self::distributor_posted_or_saved_setting('cssi', $posted_fields, 'sid');
        $token = self::distributor_posted_or_saved_setting('cssi', $posted_fields, 'token');

        if ($sid === '' || $token === '') {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'CSSI REST API',
                'message' => __('Missing SID or token for CSSI REST API.', 'ffl-hub'),
            ];
        }

        try {
            $client = new CSSIClient($sid, $token);
            $res = $client->test_credentials((int) apply_filters('fflhub_cssi_credential_test_timeout_sec', 30));
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'CSSI REST API',
                'message' => 'CSSI credential test failed before a usable response: ' . $e->getMessage(),
                'httpStatus' => 0,
            ];
        }

        $http_status = (int) ($res['status'] ?? 0);
        $provider_code = self::cssi_provider_code_from_response($res);
        $item_count = isset($res['items']) && is_array($res['items']) ? count($res['items']) : null;
        $pagination = isset($res['pagination']) && is_array($res['pagination']) ? (array) $res['pagination'] : [];
        $page_count = isset($pagination['page_count']) ? (int) $pagination['page_count'] : null;
        $raw_response = self::cssi_format_raw_response($res);

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'CSSI REST API',
                'message' => (string) ($res['error'] ?? __('CSSI REST API credential probe failed.', 'ffl-hub')),
                'providerCode' => $provider_code,
                'httpStatus' => $http_status,
                'rawResponse' => $raw_response,
            ];
        }

        if (empty($res['credentials_confirmed'])) {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'CSSI REST API',
                'message' => __('CSSI returned HTTP JSON, but the response did not include the expected items payload. Treating this credential test as inconclusive.', 'ffl-hub'),
                'providerCode' => $provider_code,
                'httpStatus' => $http_status,
                'itemsCount' => $item_count,
                'pageCount' => $page_count,
                'rawResponse' => $raw_response,
            ];
        }

        return [
            'ok' => true,
            'profile' => 'api',
            'profileLabel' => 'CSSI REST API',
            'message' => __('Credentials accepted for CSSI REST API. The read-only /items probe returned the expected items response shape.', 'ffl-hub'),
            'providerCode' => $provider_code,
            'httpStatus' => $http_status,
            'itemsCount' => $item_count,
            'pageCount' => $page_count,
            'rawResponse' => $raw_response,
        ];
    }

    /**
     * @param array<string,mixed> $res
     */
    private static function cssi_provider_code_from_response(array $res): string
    {
        $data = isset($res['data']) && is_array($res['data']) ? (array) $res['data'] : [];

        return strtoupper(trim((string) ($data['error_code'] ?? '')));
    }

    /**
     * @param array<string,mixed> $res
     */
    private static function cssi_format_raw_response(array $res): string
    {
        $data = isset($res['data']) && is_array($res['data']) ? (array) $res['data'] : [];
        $pagination = isset($res['pagination']) && is_array($res['pagination']) ? (array) $res['pagination'] : [];
        $raw = [
            'ok' => !empty($res['ok']),
            'status' => (int) ($res['status'] ?? 0),
        ];

        foreach (['error', 'content_type', 'curl_errno', 'curl_error', 'json_error', 'raw_body_excerpt'] as $key) {
            if (array_key_exists($key, $res) && $res[$key] !== '' && $res[$key] !== null) {
                $raw[$key] = $res[$key];
            }
        }

        if (!empty($data)) {
            $raw['data_keys'] = array_values(array_map('strval', array_slice(array_keys($data), 0, 30)));
        }
        if (isset($data['message'])) {
            $raw['message'] = $data['message'];
        }
        if (isset($data['error_code'])) {
            $raw['error_code'] = $data['error_code'];
        }
        if (array_key_exists('items', $data)) {
            $raw['items_count'] = is_array($data['items']) ? count($data['items']) : null;
        }
        if (!empty($pagination)) {
            $raw['pagination'] = $pagination;
        } elseif (isset($data['pagination']) && is_array($data['pagination'])) {
            $raw['pagination'] = $data['pagination'];
        }

        $encoded = wp_json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string,string> $profile_config
     * @param array<string,string> $posted_fields
     * @return array<string,mixed>
     */
    private static function test_sports_south_api_credentials(string $profile, array $profile_config, array $posted_fields): array
    {
        $label = (string) $profile_config['label'];
        $mode = (string) $profile_config['mode'];
        $customer = self::distributor_posted_or_saved_setting('sports_south', $posted_fields, 'customer_number');
        $username = self::distributor_posted_or_saved_setting('sports_south', $posted_fields, 'username');
        $password = self::distributor_posted_or_saved_setting('sports_south', $posted_fields, 'password');
        $source = self::distributor_posted_or_saved_setting('sports_south', $posted_fields, 'source');

        if ($customer === '' || $username === '' || $password === '') {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => sprintf(
                    __('Missing customer number, username, or password for Sports South %s.', 'ffl-hub'),
                    $label
                ),
            ];
        }

        $source = $source !== '' ? $source : $customer;

        try {
            if ($mode === 'inventory') {
                $client = new SportsSouthInventoryClient(
                    $customer,
                    $username,
                    $password,
                    $source,
                    self::sports_south_posted_or_saved_base_url($posted_fields, 'inventory_api_base_url', SportsSouthInventoryClient::DEFAULT_BASE_URL),
                    (int) apply_filters('fflhub_sports_south_credential_test_timeout_sec', 30)
                );
                $res = $client->test_credentials();
            } elseif ($mode === 'orders') {
                $fake_order_number = (string) (2147483000 + wp_rand(0, 499));
                $client = new SportsSouthOrdersClient(
                    $customer,
                    $username,
                    $password,
                    $source,
                    self::sports_south_posted_or_saved_base_url($posted_fields, 'orders_api_base_url', SportsSouthOrdersClient::DEFAULT_BASE_URL),
                    (int) apply_filters('fflhub_sports_south_credential_test_timeout_sec', 30)
                );
                $res = $client->test_credentials($fake_order_number);
            } else {
                $fake_po = 'FFLHUBSS' . gmdate('ymdHis') . (string) wp_rand(100, 999);
                $client = new SportsSouthInvoicesClient(
                    $customer,
                    $username,
                    $password,
                    $source,
                    self::sports_south_posted_or_saved_base_url($posted_fields, 'invoices_api_base_url', SportsSouthInvoicesClient::DEFAULT_BASE_URL),
                    (int) apply_filters('fflhub_sports_south_credential_test_timeout_sec', 30)
                );
                $res = $client->test_credentials($fake_po);
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => 'Sports South ' . $label . ' credential test failed before a usable response: ' . $e->getMessage(),
                'httpStatus' => 0,
            ];
        }

        $http_status = (int) ($res['status'] ?? 0);
        $raw_response = self::sports_south_format_raw_response($res);
        $fake_po = (string) ($res['fake_po'] ?? '');
        $fake_order_number = (string) ($res['fake_order_number'] ?? '');
        $operation = (string) ($res['operation'] ?? '');
        $rows_count = isset($res['rows_count']) ? (int) $res['rows_count'] : null;
        $response_bytes = isset($res['response_bytes']) ? (int) $res['response_bytes'] : null;
        $xml_bytes = isset($res['xml_bytes']) ? (int) $res['xml_bytes'] : null;

        if (empty($res['credentials_confirmed'])) {
            $message = (string) ($res['error'] ?? '');
            if ($message === '') {
                $message = __('Sports South endpoint responded, but the response did not match a known credential-confirming shape.', 'ffl-hub');
            }

            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => $message,
                'httpStatus' => $http_status,
                'operation' => $operation,
                'fakePo' => $fake_po,
                'fakeOrderNumber' => $fake_order_number,
                'rowsCount' => $rows_count,
                'responseBytes' => $response_bytes,
                'xmlBytes' => $xml_bytes,
                'rawResponse' => $raw_response,
            ];
        }

        return [
            'ok' => true,
            'profile' => $profile,
            'profileLabel' => $label,
            'message' => sprintf(
                __('Credentials accepted for Sports South %s. The probe reached a credential-protected endpoint and returned the expected response shape.', 'ffl-hub'),
                $label
            ),
            'httpStatus' => $http_status,
            'operation' => $operation,
            'fakePo' => $fake_po,
            'fakeOrderNumber' => $fake_order_number,
            'rowsCount' => $rows_count,
            'responseBytes' => $response_bytes,
            'xmlBytes' => $xml_bytes,
            'rawResponse' => $raw_response,
        ];
    }

    /**
     * @param array<string,string> $posted_fields
     */
    private static function sports_south_posted_or_saved_base_url(array $posted_fields, string $key, string $default): string
    {
        $url = self::distributor_posted_or_saved_setting('sports_south', $posted_fields, $key);

        return $url !== '' ? $url : $default;
    }

    /**
     * @param array<string,mixed> $res
     */
    private static function sports_south_format_raw_response(array $res): string
    {
        $raw = [
            'ok' => !empty($res['ok']),
            'credentials_confirmed' => !empty($res['credentials_confirmed']),
            'status' => (int) ($res['status'] ?? 0),
        ];

        foreach ([
            'operation',
            'error',
            'scalar',
            'fake_po',
            'fake_order_number',
            'since_datetime',
            'body_excerpt',
            'xml_excerpt',
            'response_bytes',
            'body_bytes',
            'xml_bytes',
            'rows_count',
            'wp_error_code',
        ] as $key) {
            if (array_key_exists($key, $res) && $res[$key] !== '' && $res[$key] !== null) {
                $raw[$key] = $res[$key];
            }
        }

        $encoded = wp_json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string,string> $posted_fields
     * @return array<string,mixed>
     */
    private static function test_orion_api_credentials(array $posted_fields): array
    {
        $connection_key = self::distributor_posted_or_saved_setting('orion', $posted_fields, 'connection_key');

        if ($connection_key === '') {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Orion API',
                'message' => __('Missing Orion connection key.', 'ffl-hub'),
            ];
        }

        $base_url = trim((string) apply_filters('fflhub_orion_api_base_url', OrionApiClient::DEFAULT_BASE_URL));
        if ($base_url === '') {
            $base_url = OrionApiClient::DEFAULT_BASE_URL;
        }

        try {
            $client = new OrionApiClient(
                $connection_key,
                $base_url,
                (int) apply_filters('fflhub_orion_credential_test_timeout_sec', 30)
            );
            $res = $client->test_credentials();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Orion API',
                'message' => 'Orion credential test failed before a usable response: ' . $e->getMessage(),
                'httpStatus' => 0,
            ];
        }

        $http_status = (int) ($res['status'] ?? 0);
        $data = isset($res['data']) && is_array($res['data']) ? (array) $res['data'] : [];
        $api_result = strtoupper(trim((string) ($data['result'] ?? '')));
        $raw_response = self::orion_format_raw_response($res);

        if (empty($res['ok']) || $http_status < 200 || $http_status >= 300) {
            $message = trim((string) ($res['error'] ?? ''));
            if ($message === '') {
                $message = __('Orion test_credentials request failed.', 'ffl-hub');
            }

            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Orion API',
                'message' => $message,
                'httpStatus' => $http_status,
                'apiResult' => $api_result,
                'rawResponse' => $raw_response,
            ];
        }

        if ($api_result !== 'OK') {
            $message = self::orion_response_message($data);
            if ($message === '' || $message === $api_result) {
                $message = __('Orion endpoint responded, but test_credentials did not return result=OK. Treating this credential test as inconclusive.', 'ffl-hub');
            }

            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Orion API',
                'message' => $message,
                'httpStatus' => $http_status,
                'apiResult' => $api_result,
                'rawResponse' => $raw_response,
            ];
        }

        return [
            'ok' => true,
            'profile' => 'api',
            'profileLabel' => 'Orion API',
            'message' => __('Credentials accepted for Orion API. The test_credentials endpoint returned result=OK.', 'ffl-hub'),
            'httpStatus' => $http_status,
            'apiResult' => $api_result,
            'rawResponse' => $raw_response,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function orion_response_message(array $data): string
    {
        foreach (['error_message', 'message', 'error', 'result'] as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $res
     */
    private static function orion_format_raw_response(array $res): string
    {
        $data = isset($res['data']) && is_array($res['data']) ? (array) $res['data'] : [];
        $raw = [
            'ok' => !empty($res['ok']),
            'status' => (int) ($res['status'] ?? 0),
        ];

        foreach (['error', 'body_excerpt', 'response_bytes'] as $key) {
            if (array_key_exists($key, $res) && $res[$key] !== '' && $res[$key] !== null) {
                $raw[$key] = $res[$key];
            }
        }

        if (!empty($data)) {
            $raw['data'] = $data;
        }

        $encoded = wp_json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string,string> $posted_fields
     * @return array<string,mixed>
     */
    private static function test_kinseys_api_credentials(array $posted_fields): array
    {
        $api_identifier = self::distributor_posted_or_saved_setting('kinseys', $posted_fields, 'api_identifier');
        $api_key = self::distributor_posted_or_saved_setting('kinseys', $posted_fields, 'api_key');
        $source = self::distributor_posted_or_saved_setting('kinseys', $posted_fields, 'source');
        if ($source === '') {
            $source = 'FFLHub';
        }

        if ($api_identifier === '' || $api_key === '') {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Kinsey\'s API',
                'message' => __('Missing Kinsey\'s API Identifier or API key.', 'ffl-hub'),
            ];
        }

        $base_url = trim((string) apply_filters('fflhub_kinseys_api_base_url', KinseysApiClient::DEFAULT_BASE_URL));
        if ($base_url === '') {
            $base_url = KinseysApiClient::DEFAULT_BASE_URL;
        }

        $probe_product_id = trim((string) apply_filters('fflhub_kinseys_credential_test_product_id', '10113'));
        if ($probe_product_id === '') {
            $probe_product_id = '10113';
        }

        try {
            $client = new KinseysApiClient(
                $api_identifier,
                $api_key,
                $source,
                $base_url,
                (int) apply_filters('fflhub_kinseys_credential_test_timeout_sec', 30)
            );
            $res = $client->test_credentials($probe_product_id);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Kinsey\'s API',
                'message' => 'Kinsey\'s credential test failed before a usable response: ' . $e->getMessage(),
                'httpStatus' => 0,
            ];
        }

        $http_status = (int) ($res['status'] ?? 0);
        $data = isset($res['data']) && is_array($res['data']) ? (array) $res['data'] : [];
        $raw_response = self::kinseys_format_raw_response($res);

        if (empty($res['ok']) || $http_status < 200 || $http_status >= 300) {
            $message = trim((string) ($res['error'] ?? ''));
            if ($message === '') {
                $message = __('Kinsey\'s inventory probe failed.', 'ffl-hub');
            }

            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Kinsey\'s API',
                'message' => $message,
                'httpStatus' => $http_status,
                'rawResponse' => $raw_response,
            ];
        }

        $has_expected_shape = array_key_exists('recordsCount', $data)
            || (isset($data['Products']) && is_array($data['Products']))
            || (isset($data['products']) && is_array($data['products']));

        if (!$has_expected_shape) {
            return [
                'ok' => false,
                'profile' => 'api',
                'profileLabel' => 'Kinsey\'s API',
                'message' => __('Kinsey\'s endpoint responded, but the inventory probe did not return the expected response shape.', 'ffl-hub'),
                'httpStatus' => $http_status,
                'rawResponse' => $raw_response,
            ];
        }

        return [
            'ok' => true,
            'profile' => 'api',
            'profileLabel' => 'Kinsey\'s API',
            'message' => __('Credentials accepted for Kinsey\'s API. The inventory probe returned an expected response.', 'ffl-hub'),
            'httpStatus' => $http_status,
            'recordsCount' => isset($data['recordsCount']) ? (int) $data['recordsCount'] : null,
            'rawResponse' => $raw_response,
        ];
    }

    /**
     * @param array<string,mixed> $res
     */
    private static function kinseys_format_raw_response(array $res): string
    {
        $data = isset($res['data']) && is_array($res['data']) ? (array) $res['data'] : [];
        $raw = [
            'ok' => !empty($res['ok']),
            'status' => (int) ($res['status'] ?? 0),
        ];

        foreach (['error', 'body_excerpt', 'response_bytes'] as $key) {
            if (array_key_exists($key, $res) && $res[$key] !== '' && $res[$key] !== null) {
                $raw[$key] = $res[$key];
            }
        }

        if (!empty($data)) {
            $raw['data'] = $data;
        }

        $encoded = wp_json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param array<string,string> $profile_config
     * @param array<string,string> $posted_fields
     * @return array<string,mixed>
     */
    private static function test_rsr_directconnect_credentials(string $profile, array $profile_config, array $posted_fields): array
    {
        $label = (string) $profile_config['label'];
        $username = self::distributor_posted_or_saved_setting('rsr', $posted_fields, (string) $profile_config['username_key']);
        $password = self::distributor_posted_or_saved_setting('rsr', $posted_fields, (string) $profile_config['password_key']);
        $pos = self::distributor_posted_or_saved_setting('rsr', $posted_fields, 'pos_indicator');

        if ($username === '' || $password === '' || $pos === '') {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => sprintf(
                    __('Missing username, password, or POS indicator for %s.', 'ffl-hub'),
                    $label
                ),
            ];
        }

        $fake_po = RSRDirectConnectAPI::sanitize_rsr_po('FFLHUB' . gmdate('ymdHis') . (string) wp_rand(100, 999));
        if ($fake_po === '') {
            $fake_po = 'FFLHUBTEST';
        }

        $timeout = max(10, (int) apply_filters('fflhub_rsr_credential_test_timeout_sec', 30));
        $base_url = self::rsr_api_base_url();

        try {
            $res = RSRDirectConnectAPI::check_order_report_all(
                [
                    'Username' => $username,
                    'Password' => $password,
                    'POS' => $pos,
                ],
                $fake_po,
                $base_url,
                $timeout
            );
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => 'RSR DirectConnect credential test failed before a usable response: ' . $e->getMessage(),
                'fakePo' => $fake_po,
            ];
        }

        $http_status = (int) ($res['http_status'] ?? 0);
        $auth_failure = self::rsr_extract_auth_failure_message($res);
        if ($auth_failure !== '') {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => $auth_failure,
                'httpStatus' => $http_status,
                'fakePo' => $fake_po,
            ];
        }

        if (empty($res['ok'])) {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => (string) ($res['message'] ?? __('RSR DirectConnect call failed.', 'ffl-hub')),
                'httpStatus' => $http_status,
                'fakePo' => $fake_po,
            ];
        }

        $confirmation = self::rsr_check_order_credential_confirmation($res, $label, $fake_po);
        if (empty($confirmation['ok'])) {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => (string) ($confirmation['message'] ?? __('RSR DirectConnect responded, but FFLHub could not confirm that credentials were accepted.', 'ffl-hub')),
                'httpStatus' => $http_status,
                'fakePo' => $fake_po,
                'rsrStatusCode' => (string) ($confirmation['rsrStatusCode'] ?? ''),
            ];
        }

        $items_count = (isset($res['items']) && is_array($res['items'])) ? count($res['items']) : 0;

        return [
            'ok' => true,
            'profile' => $profile,
            'profileLabel' => $label,
            'message' => (string) ($confirmation['message'] ?? sprintf(
                __('Credentials accepted for %1$s. RSR check-order confirmed access using fake PO %2$s.', 'ffl-hub'),
                $label,
                $fake_po
            )),
            'httpStatus' => $http_status,
            'fakePo' => $fake_po,
            'itemsCount' => $items_count,
            'rsrStatusCode' => (string) ($confirmation['rsrStatusCode'] ?? ''),
        ];
    }

    /**
     * @param array<string,string> $profile_config
     * @param array<string,string> $posted_fields
     * @return array<string,mixed>
     */
    private static function test_rsr_ftp_credentials(string $profile, array $profile_config, array $posted_fields): array
    {
        $label = (string) $profile_config['label'];
        $host = self::distributor_posted_or_saved_setting('rsr', $posted_fields, (string) $profile_config['host_key']);
        $username = self::distributor_posted_or_saved_setting('rsr', $posted_fields, (string) $profile_config['username_key']);
        $password = self::distributor_posted_or_saved_setting('rsr', $posted_fields, (string) $profile_config['password_key']);
        $use_ssl_raw = self::distributor_posted_or_saved_setting('rsr', $posted_fields, (string) $profile_config['ssl_key']);
        $use_ssl = self::boolish_from_setting($use_ssl_raw);

        if ($host === '' || $username === '' || $password === '') {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => __('Missing FTP host, username, or password for RSR.', 'ffl-hub'),
            ];
        }

        $timeout = max(5, (int) apply_filters('fflhub_rsr_ftp_credential_test_timeout_sec', 15));
        $port = max(1, (int) apply_filters('fflhub_rsr_ftp_credential_test_port', 2222));

        try {
            $client = new FTPClientService(
                $host,
                $username,
                $password,
                $use_ssl,
                $port,
                $timeout,
                true,
                '[FFLHub][RSR][FTP Credential Test]',
                true
            );
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => 'RSR FTP credential test failed before login completed: ' . $e->getMessage(),
                'host' => $host,
            ];
        }

        if (!$client->is_connected()) {
            $last_error = (string) ($client->get_last_error() ?? '');

            return [
                'ok' => false,
                'profile' => $profile,
                'profileLabel' => $label,
                'message' => $last_error !== '' ? $last_error : __('RSR FTP login failed.', 'ffl-hub'),
                'host' => $host,
            ];
        }

        $probe_files = apply_filters(
            'fflhub_rsr_ftp_credential_test_probe_files',
            [
                '/ftpdownloads/rsrinventory-new.zip',
                '/ftpdownloads/IM-QTY-CSV.csv',
            ]
        );

        $found_files = [];
        if (is_array($probe_files)) {
            foreach ($probe_files as $remote_path) {
                $remote_path = is_scalar($remote_path) ? trim((string) $remote_path) : '';
                if ($remote_path === '') {
                    continue;
                }

                $mtime = $client->get_remote_mtime($remote_path);
                $size = $client->get_remote_size($remote_path);
                if ($mtime > 0 || $size >= 0) {
                    $found_files[] = [
                        'path' => $remote_path,
                        'mtime' => $mtime,
                        'size' => $size,
                    ];
                }
            }
        }

        $client->close();

        $message = empty($found_files)
            ? __('RSR FTP login accepted. No known feed metadata was readable, but credentials reached a logged-in FTP session.', 'ffl-hub')
            : sprintf(
                __('RSR FTP login accepted. Found metadata for %d known feed file(s).', 'ffl-hub'),
                count($found_files)
            );

        return [
            'ok' => true,
            'profile' => $profile,
            'profileLabel' => $label,
            'message' => $message,
            'host' => $host,
            'useSsl' => $use_ssl,
            'foundFiles' => count($found_files),
        ];
    }

    private static function boolish_from_setting(string $raw): bool
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function rsr_api_base_url(): string
    {
        $default = RSRDirectConnectAPI::DEFAULT_API_BASE_URL;
        $base = apply_filters('fflhub_rsr_api_base_url', $default);
        $base = is_string($base) ? trim($base) : '';

        return $base !== '' ? $base : $default;
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function rsr_extract_auth_failure_message(array $result): string
    {
        $http_status = (int) ($result['http_status'] ?? 0);
        $strings = [];

        if (isset($result['message']) && is_scalar($result['message'])) {
            $strings[] = trim((string) $result['message']);
        }
        if (isset($result['raw'])) {
            self::collect_scalar_strings($result['raw'], $strings);
        }

        foreach ($strings as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (self::rsr_message_looks_auth_failure($candidate)) {
                return sprintf(
                    __('RSR reported an authentication failure: %s', 'ffl-hub'),
                    $candidate
                );
            }
        }

        if ($http_status === 401 || $http_status === 403) {
            return sprintf(
                __('RSR rejected the credentials with HTTP %d.', 'ffl-hub'),
                $http_status
            );
        }

        return '';
    }

    /**
     * @param array<string,mixed> $result
     * @return array{ok:bool,message:string,rsrStatusCode:string}
     */
    private static function rsr_check_order_credential_confirmation(array $result, string $profile_label, string $fake_po): array
    {
        $raw = $result['raw'] ?? null;
        $http_status = (int) ($result['http_status'] ?? 0);

        if (!is_array($raw)) {
            return [
                'ok' => false,
                'message' => __('RSR DirectConnect did not return a JSON object that can confirm credentials.', 'ffl-hub'),
                'rsrStatusCode' => '',
            ];
        }

        $status = self::rsr_extract_status_fields($raw);
        $status_code = (string) ($status['code'] ?? '');
        $status_message = (string) ($status['message'] ?? '');
        $status_summary = trim($status_code . ' ' . $status_message);

        if ($status_code === '00') {
            return [
                'ok' => true,
                'message' => sprintf(
                    __('Credentials accepted for %1$s. RSR check-order returned StatusCode=00 for fake PO %2$s.', 'ffl-hub'),
                    $profile_label,
                    $fake_po
                ),
                'rsrStatusCode' => $status_code,
            ];
        }

        if ($status_summary !== '' && self::rsr_message_looks_auth_failure($status_summary)) {
            return [
                'ok' => false,
                'message' => sprintf(
                    __('RSR reported an authentication failure: %s', 'ffl-hub'),
                    $status_summary
                ),
                'rsrStatusCode' => $status_code,
            ];
        }

        if (self::rsr_message_looks_webref_required_response($status_code, $status_message)) {
            return [
                'ok' => true,
                'message' => sprintf(
                    __('Credentials accepted for %1$s. RSR returned StatusCode=96 / WebRef required for fake PO %2$s, which means the check-order call reached account-level order validation.', 'ffl-hub'),
                    $profile_label,
                    $fake_po
                ),
                'rsrStatusCode' => $status_code,
            ];
        }

        if ($status_summary !== '' && self::rsr_message_looks_fake_order_response($status_summary)) {
            return [
                'ok' => true,
                'message' => sprintf(
                    __('Credentials accepted for %1$s. RSR rejected fake PO %2$s as not found/no order data, which means the check-order call reached account-level validation.', 'ffl-hub'),
                    $profile_label,
                    $fake_po
                ),
                'rsrStatusCode' => $status_code,
            ];
        }

        if (self::rsr_raw_has_order_items_container($raw)) {
            return [
                'ok' => true,
                'message' => sprintf(
                    __('Credentials accepted for %1$s. RSR check-order returned an order/items response container for fake PO %2$s.', 'ffl-hub'),
                    $profile_label,
                    $fake_po
                ),
                'rsrStatusCode' => $status_code,
            ];
        }

        $success_flag = self::rsr_extract_bool_field($raw, ['authorized', 'Authorized', 'success', 'Success']);
        if ($success_flag === true) {
            return [
                'ok' => true,
                'message' => sprintf(
                    __('Credentials accepted for %1$s. RSR returned an explicit success/authorized flag for fake PO %2$s.', 'ffl-hub'),
                    $profile_label,
                    $fake_po
                ),
                'rsrStatusCode' => $status_code,
            ];
        }
        if ($success_flag === false) {
            return [
                'ok' => false,
                'message' => __('RSR returned an explicit failed success/authorized flag for the credential test.', 'ffl-hub'),
                'rsrStatusCode' => $status_code,
            ];
        }

        if ($status_summary !== '') {
            return [
                'ok' => false,
                'message' => sprintf(
                    __('RSR responded with StatusCode=%1$s, but it was not a known fake-order/no-order success signal. Message: %2$s', 'ffl-hub'),
                    $status_code !== '' ? $status_code : '(missing)',
                    $status_message !== '' ? $status_message : '(empty)'
                ),
                'rsrStatusCode' => $status_code,
            ];
        }

        return [
            'ok' => false,
            'message' => sprintf(
                __('RSR returned HTTP %d JSON, but no StatusCode, order/items container, or explicit success/authorized flag was present. Treating this credential test as inconclusive.', 'ffl-hub'),
                $http_status
            ),
            'rsrStatusCode' => '',
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{code:string,message:string}
     */
    private static function rsr_extract_status_fields(array $raw): array
    {
        $code = self::rsr_first_scalar_field($raw, ['StatusCode', 'statusCode', 'status_code', 'Code', 'code']);
        $message = self::rsr_first_scalar_field($raw, ['StatusMssg', 'StatusMsg', 'statusMessage', 'status_message', 'Message', 'message', 'Error', 'error']);

        if (isset($raw['Response']) && is_array($raw['Response'])) {
            $response = $raw['Response'];
            if ($code === '') {
                $code = self::rsr_first_scalar_field($response, ['StatusCode', 'statusCode', 'status_code', 'Code', 'code']);
            }
            if ($message === '') {
                $message = self::rsr_first_scalar_field($response, ['StatusMssg', 'StatusMsg', 'statusMessage', 'status_message', 'Message', 'message', 'Error', 'error']);
            }
        }

        return [
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<int,string> $keys
     */
    private static function rsr_first_scalar_field(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && is_scalar($raw[$key])) {
                return trim((string) $raw[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $raw
     */
    private static function rsr_raw_has_order_items_container(array $raw): bool
    {
        if (array_key_exists('Items', $raw) && is_array($raw['Items'])) {
            return true;
        }
        if (array_key_exists('Orders', $raw) && is_array($raw['Orders'])) {
            return true;
        }
        if (array_key_exists('Order', $raw) && (is_array($raw['Order']) || is_scalar($raw['Order']))) {
            return true;
        }
        if (isset($raw['Response']) && is_array($raw['Response'])) {
            $response = $raw['Response'];
            return (array_key_exists('Items', $response) && is_array($response['Items']))
                || (array_key_exists('Orders', $response) && is_array($response['Orders']))
                || (array_key_exists('Order', $response) && (is_array($response['Order']) || is_scalar($response['Order'])));
        }

        return false;
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<int,string> $keys
     */
    private static function rsr_extract_bool_field(array $raw, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return ((int) $value) === 1;
            }
            if (is_string($value)) {
                $value = strtolower(trim($value));
                if (in_array($value, ['1', 'true', 'yes', 'y', 'ok', 'success'], true)) {
                    return true;
                }
                if (in_array($value, ['0', 'false', 'no', 'n', 'fail', 'failed'], true)) {
                    return false;
                }
            }
        }

        if (isset($raw['Response']) && is_array($raw['Response'])) {
            return self::rsr_extract_bool_field($raw['Response'], $keys);
        }

        return null;
    }

    private static function rsr_message_looks_webref_required_response(string $status_code, string $status_message): bool
    {
        $status_code = trim($status_code);
        $message = strtolower(trim($status_message));

        return $status_code === '96'
            && strpos($message, 'webref') !== false
            && strpos($message, 'required') !== false;
    }

    private static function rsr_message_looks_fake_order_response(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        $needles = [
            'no order',
            'no orders',
            'order not found',
            'po not found',
            'purchase order not found',
            'no record',
            'no records',
            'no data',
            'not found',
            'invalid po',
            'invalid purchase order',
            'not connected',
            'not associated',
        ];

        foreach ($needles as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @param array<int,string> $strings
     */
    private static function collect_scalar_strings($value, array &$strings, int $depth = 0): void
    {
        if ($depth > 5 || count($strings) >= 40) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $child) {
                self::collect_scalar_strings($child, $strings, $depth + 1);
                if (count($strings) >= 40) {
                    break;
                }
            }
            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return;
        }

        $strings[] = strlen($string) > 300 ? substr($string, 0, 300) : $string;
    }

    private static function rsr_message_looks_auth_failure(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        $needles = [
            'auth',
            'credential',
            'login',
            'password',
            'username',
            'unauthorized',
            'not authorized',
            'access denied',
            'invalid user',
            'invalid pass',
            'invalid account',
            'invalid pos',
            'pos indicator',
            'forbidden',
            'permission',
        ];

        foreach ($needles as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function zanders_message_looks_known_fake_order_response(int $return_code, string $message): bool
    {
        $message = strtolower(trim($message));

        return $return_code === 21
            || strpos($message, 'not connected to your customer number') !== false
            || strpos($message, 'order number supplied') !== false;
    }

    private static function zanders_credential_test_success_message(
        string $profile_label,
        string $fake_order,
        int $return_code,
        string $raw_message
    ): string {
        if (self::zanders_message_looks_known_fake_order_response($return_code, $raw_message)) {
            return sprintf(
                __('Credentials accepted for %1$s. Zanders rejected fake order %2$s because it is not connected to your customer number (returnCode=%3$d), which means the SOAP login reached account-level validation.', 'ffl-hub'),
                $profile_label,
                $fake_order,
                $return_code
            );
        }

        if ($return_code === 0) {
            return sprintf(
                __('Credentials accepted for %1$s. The fake tracking lookup returned returnCode=0 for %2$s.', 'ffl-hub'),
                $profile_label,
                $fake_order
            );
        }

        return sprintf(
            __('SOAP endpoint accepted the %1$s fake tracking lookup. Fake order %2$s returned returnCode=%3$d, which is expected for a non-real order.', 'ffl-hub'),
            $profile_label,
            $fake_order,
            $return_code
        );
    }

    private static function zanders_message_looks_auth_failure(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }

        $needles = [
            'auth',
            'credential',
            'login',
            'password',
            'username',
            'unauthorized',
            'not authorized',
            'access denied',
            'denied',
            'invalid user',
            'invalid pass',
            'invalid account',
            'forbidden',
            'permission',
        ];

        foreach ($needles as $needle) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Entry point used internally once we have the modules.
     *
     * @param DistributorModuleInterface[] $modules
     */
    public static function render(array $modules): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $global_settings = [
            'payment_fee_percent'   => (string) Options::get_payment_processor_fee_percent(),
            'global_markup_percent' => (string) Options::get_global_markup(),
            'free_shipping_max_profit_spend_percent' => (string) Options::get_free_shipping_max_profit_spend_percent(),
            'test_order_debug_enabled' => Options::get_test_order_debug_enabled() ? '1' : '0',
            'holosun_image_notice_enabled' => Options::get_holosun_image_notice_enabled() ? '1' : '0',
            'holosun_show_price_override_enabled' => Options::get_holosun_show_price_override_enabled() ? '1' : '0',
            'pretty_random_email_quotes_enabled' => Options::get_pretty_random_email_quotes_enabled() ? '1' : '0',
            'public_brand_name' => Options::get_public_brand_name(),
            'quote_email_rep_names' => Options::get_quote_email_rep_names_text(),
            'quote_email_team_signature' => Options::get_quote_email_team_signature(),
            'quote_email_button_background_color' => Options::get_quote_email_button_background_color(),
            'quote_email_button_text_color' => Options::get_quote_email_button_text_color(),
            'batch_order_notification_email' => Options::get_batch_order_notification_email(),
            'distributor_priority_list' => (string) Options::get_distributor_priority_csv(),
            'dealer_ship_to' => Options::get_dealer_ship_to_address(),
            'relay_ship_to' => Options::get_relay_ship_to_address(),

            'usps_estimate_enabled' => Options::get_usps_estimate_enabled() ? '1' : '0',
            'usps_use_test_env'     => Options::get_usps_use_test_env() ? '1' : '0',
            'usps_base_url'         => (string) Options::get_usps_base_url(),
            'usps_client_id'        => (string) Options::get_usps_client_id(),
            'usps_client_secret'    => (string) Options::get_usps_client_secret(),
            'usps_origin_zip'       => (string) Options::get_usps_origin_zip(),
            'usps_account_type'     => (string) Options::get_usps_account_type(),
            'usps_account_number'   => (string) Options::get_usps_account_number(),
            'usps_mail_class'       => (string) Options::get_usps_mail_class(),
            'usps_processing_category' => (string) Options::get_usps_processing_category(),
            'usps_destination_entry_facility_type' => (string) Options::get_usps_destination_entry_facility_type(),
            'usps_rate_indicator'   => (string) Options::get_usps_rate_indicator(),
            'usps_price_type'       => (string) Options::get_usps_price_type(),
            'usps_timeout_sec'      => (string) Options::get_usps_timeout_sec(),
            'usps_tare_weight_oz'   => (string) Options::get_usps_tare_weight_oz(),
        ];
?>
        <div class="wrap fflhub-wrap">
            <?php self::render_header(); ?>
            <?php self::render_global_settings_form($global_settings); ?>
            <?php self::render_usps_settings_shortcut($global_settings); ?>
            <?php self::render_distributor_grid($modules); ?>
            <?php self::render_modal($modules, $global_settings); ?>
        </div>
    <?php
    }

    /**
     * Renders the page heading / intro.
     */
    private static function render_header(): void
    {
    ?>
        <h1 class="fflhub-title"><?php esc_html_e('FFL Hub Settings', 'ffl-hub'); ?></h1>
        <p class="fflhub-description">
            <?php esc_html_e(
                'Select a distributor to configure its API credentials and options.',
                'ffl-hub'
            ); ?>
        </p>
    <?php
    }

    /**
     * Renders the global settings block (payment fee + markup).
     */
    /**
     * @param array<string,string> $settings
     */
    private static function render_global_settings_form(array $settings): void
    {
        $payment_fee_percent   = (string) ($settings['payment_fee_percent'] ?? '');
        $global_markup_percent = (string) ($settings['global_markup_percent'] ?? '');
        $free_shipping_max_profit_spend_percent = (string) (
            $settings['free_shipping_max_profit_spend_percent']
            ?? Options::default_free_shipping_max_profit_spend_percent()
        );
        $test_order_debug_enabled = ((string) ($settings['test_order_debug_enabled'] ?? '0') === '1');
        $holosun_image_notice_enabled = ((string) ($settings['holosun_image_notice_enabled'] ?? '0') === '1');
        $holosun_show_price_override_enabled = ((string) ($settings['holosun_show_price_override_enabled'] ?? '0') === '1');
        $pretty_random_email_quotes_enabled = ((string) ($settings['pretty_random_email_quotes_enabled'] ?? '1') === '1');
        $public_brand_name = (string) ($settings['public_brand_name'] ?? Options::default_public_brand_name());
        $quote_email_rep_names = (string) ($settings['quote_email_rep_names'] ?? Options::default_quote_email_rep_names());
        $quote_email_team_signature = (string) ($settings['quote_email_team_signature'] ?? Options::default_quote_email_team_signature());
        $quote_email_button_background_color = (string) (
            $settings['quote_email_button_background_color']
            ?? Options::default_quote_email_button_background_color()
        );
        $quote_email_button_text_color = (string) (
            $settings['quote_email_button_text_color']
            ?? Options::default_quote_email_button_text_color()
        );
        $batch_order_notification_email = (string) ($settings['batch_order_notification_email'] ?? Options::default_batch_order_notification_email());
        $distributor_priority_list = (string) ($settings['distributor_priority_list'] ?? '');
        $dealer_ship_to = isset($settings['dealer_ship_to']) && is_array($settings['dealer_ship_to'])
            ? $settings['dealer_ship_to']
            : [];
        $relay_ship_to = isset($settings['relay_ship_to']) && is_array($settings['relay_ship_to'])
            ? $settings['relay_ship_to']
            : [];
        $dealer_ship_to_fields = [
            [
                'key' => 'name',
                'option' => Options::OPTION_DEALER_SHIP_TO_NAME,
                'label' => __('Ship-to name', 'ffl-hub'),
                'placeholder' => Options::get_public_brand_name(),
            ],
            [
                'key' => 'company',
                'option' => Options::OPTION_DEALER_SHIP_TO_COMPANY,
                'label' => __('Company', 'ffl-hub'),
                'placeholder' => Options::get_public_brand_name(),
            ],
            [
                'key' => 'address1',
                'option' => Options::OPTION_DEALER_SHIP_TO_ADDRESS1,
                'label' => __('Address line 1', 'ffl-hub'),
                'placeholder' => __('10322 BLACK ROAD', 'ffl-hub'),
            ],
            [
                'key' => 'address2',
                'option' => Options::OPTION_DEALER_SHIP_TO_ADDRESS2,
                'label' => __('Address line 2', 'ffl-hub'),
                'placeholder' => '',
            ],
            [
                'key' => 'city',
                'option' => Options::OPTION_DEALER_SHIP_TO_CITY,
                'label' => __('City', 'ffl-hub'),
                'placeholder' => __('ZACHARY', 'ffl-hub'),
            ],
            [
                'key' => 'state',
                'option' => Options::OPTION_DEALER_SHIP_TO_STATE,
                'label' => __('State', 'ffl-hub'),
                'placeholder' => __('LA', 'ffl-hub'),
            ],
            [
                'key' => 'zip',
                'option' => Options::OPTION_DEALER_SHIP_TO_ZIP,
                'label' => __('ZIP', 'ffl-hub'),
                'placeholder' => __('70791', 'ffl-hub'),
            ],
            [
                'key' => 'phone',
                'option' => Options::OPTION_DEALER_SHIP_TO_PHONE,
                'label' => __('Phone', 'ffl-hub'),
                'placeholder' => '',
            ],
            [
                'key' => 'email',
                'option' => Options::OPTION_DEALER_SHIP_TO_EMAIL,
                'label' => __('Email', 'ffl-hub'),
                'placeholder' => '',
            ],
        ];
        $relay_ship_to_fields = [
            [
                'key' => 'name',
                'option' => Options::OPTION_RELAY_SHIP_TO_NAME,
                'label' => __('Ship-to name', 'ffl-hub'),
                'placeholder' => __('Relay recipient name', 'ffl-hub'),
            ],
            [
                'key' => 'company',
                'option' => Options::OPTION_RELAY_SHIP_TO_COMPANY,
                'label' => __('Company', 'ffl-hub'),
                'placeholder' => '',
            ],
            [
                'key' => 'address1',
                'option' => Options::OPTION_RELAY_SHIP_TO_ADDRESS1,
                'label' => __('Address line 1', 'ffl-hub'),
                'placeholder' => __('Home address line 1', 'ffl-hub'),
            ],
            [
                'key' => 'address2',
                'option' => Options::OPTION_RELAY_SHIP_TO_ADDRESS2,
                'label' => __('Address line 2', 'ffl-hub'),
                'placeholder' => '',
            ],
            [
                'key' => 'city',
                'option' => Options::OPTION_RELAY_SHIP_TO_CITY,
                'label' => __('City', 'ffl-hub'),
                'placeholder' => '',
            ],
            [
                'key' => 'state',
                'option' => Options::OPTION_RELAY_SHIP_TO_STATE,
                'label' => __('State', 'ffl-hub'),
                'placeholder' => __('LA', 'ffl-hub'),
            ],
            [
                'key' => 'zip',
                'option' => Options::OPTION_RELAY_SHIP_TO_ZIP,
                'label' => __('ZIP', 'ffl-hub'),
                'placeholder' => '',
            ],
            [
                'key' => 'phone',
                'option' => Options::OPTION_RELAY_SHIP_TO_PHONE,
                'label' => __('Phone', 'ffl-hub'),
                'placeholder' => '',
            ],
            [
                'key' => 'email',
                'option' => Options::OPTION_RELAY_SHIP_TO_EMAIL,
                'label' => __('Email', 'ffl-hub'),
                'placeholder' => '',
            ],
        ];
        $priority_choices = [];
        foreach (DistributorRegistry::get_modules() as $module) {
            if (!($module instanceof DistributorModuleInterface)) {
                continue;
            }
            $priority_choices[] = $module->name() . ' (' . $module->id() . ')';
        }
        $priority_choices_text = implode(', ', $priority_choices);
        $priority_default_text = Options::default_distributor_priority_csv();

    ?>
        <form method="post" action="options.php" class="fflhub-global-settings-form">
            <?php settings_fields('fflhub_global_settings'); ?>

            <div class="fflhub-global-settings-card">
                <h2 class="fflhub-section-title">
                    <?php esc_html_e('Global Pricing Settings', 'ffl-hub'); ?>
                </h2>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_payment_processor_fee_percent"
                        class="fflhub-field-label">
                        <?php esc_html_e('Payment processor fee (%)', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_payment_processor_fee_percent"
                        name="fflhub_payment_processor_fee_percent"
                        type="number"
                        step="0.01"
                        min="0"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($payment_fee_percent); ?>" />
                    <span class="fflhub-field-suffix">%</span>
                    <p class="description">
                        <?php esc_html_e(
                            'Enter your payment processor fee as a percent (e.g. 2.9).',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_global_markup"
                        class="fflhub-field-label">
                        <?php esc_html_e('Global markup (%)', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_global_markup"
                        name="fflhub_global_markup"
                        type="number"
                        step="0.01"
                        min="0"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($global_markup_percent); ?>" />
                    <span class="fflhub-field-suffix">%</span>
                    <p class="description">
                        <?php esc_html_e(
                            'Default markup applied to your true cost when calculating prices.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_free_shipping_max_profit_spend_percent"
                        class="fflhub-field-label">
                        <?php esc_html_e('Free shipping max profit spend (%)', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_free_shipping_max_profit_spend_percent"
                        name="fflhub_free_shipping_max_profit_spend_percent"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($free_shipping_max_profit_spend_percent); ?>" />
                    <span class="fflhub-field-suffix">%</span>
                    <p class="description">
                        <?php esc_html_e(
                            'Percent of net cart profit you are willing to spend to make checkout shipping free. 50 keeps the current half-profit rule. 100 waives shipping as long as at least $0.01 profit remains after eating shipping.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_test_order_debug_enabled"
                        class="fflhub-field-label">
                        <?php esc_html_e('Test order debug mode', 'ffl-hub'); ?>
                    </label>
                    <input type="hidden" name="fflhub_test_order_debug_enabled" value="0" />
                    <input
                        id="fflhub_test_order_debug_enabled"
                        name="fflhub_test_order_debug_enabled"
                        type="checkbox"
                        value="1"
                        <?php checked($test_order_debug_enabled); ?> />
                    <p class="description">
                        <?php esc_html_e(
                            'When enabled, order jobs build distributor payloads but stop before outbound API calls. Place result stores the exact outbound message (JSON for Lipsey\'s/RSR, SOAP XML for Zanders).',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_holosun_image_notice_enabled"
                        class="fflhub-field-label">
                        <?php esc_html_e('Holosun image-side notice', 'ffl-hub'); ?>
                    </label>
                    <input type="hidden" name="fflhub_holosun_image_notice_enabled" value="0" />
                    <input
                        id="fflhub_holosun_image_notice_enabled"
                        name="fflhub_holosun_image_notice_enabled"
                        type="checkbox"
                        value="1"
                        <?php checked($holosun_image_notice_enabled); ?> />
                    <p class="description">
                        <?php esc_html_e(
                            'When enabled, Holosun-branded products show a bold information message in the single-product summary (same area as Email for Quote), force MSRP-only price display unless the show-price override is enabled, and convert loop Read More actions into an Add to cart label that opens the Holosun notice modal.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_holosun_show_price_override_enabled"
                        class="fflhub-field-label">
                        <?php esc_html_e('Holosun show-price override', 'ffl-hub'); ?>
                    </label>
                    <input type="hidden" name="fflhub_holosun_show_price_override_enabled" value="0" />
                    <input
                        id="fflhub_holosun_show_price_override_enabled"
                        name="fflhub_holosun_show_price_override_enabled"
                        type="checkbox"
                        value="1"
                        <?php checked($holosun_show_price_override_enabled); ?> />
                    <p class="description">
                        <?php esc_html_e(
                            'When enabled, Holosun-branded products use normal WooCommerce price output even when the sale price is below MAP. This takes priority over Holosun MSRP replacement and MAP hide/quote display rules for Holosun products only.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_pretty_random_email_quotes_enabled"
                        class="fflhub-field-label">
                        <?php esc_html_e('Styled quote emails enabled', 'ffl-hub'); ?>
                    </label>
                    <input type="hidden" name="fflhub_pretty_random_email_quotes_enabled" value="0" />
                    <input
                        id="fflhub_pretty_random_email_quotes_enabled"
                        name="fflhub_pretty_random_email_quotes_enabled"
                        type="checkbox"
                        value="1"
                        <?php checked($pretty_random_email_quotes_enabled); ?> />
                    <p class="description">
                        <?php esc_html_e(
                            'Enabled: sends the styled quote email with product link, coupon code, and checkout button. Disabled: sends the same essentials as plain text.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_public_brand_name"
                        class="fflhub-field-label">
                        <?php esc_html_e('Public brand name', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_public_brand_name"
                        name="fflhub_public_brand_name"
                        type="text"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($public_brand_name); ?>"
                        placeholder="<?php echo esc_attr(Options::default_public_brand_name()); ?>" />
                    <p class="description">
                        <?php esc_html_e(
                            'Customer-facing fallback brand name used by quote emails, scripts, and small admin placeholders. Leave blank to use the WordPress site name.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_quote_email_rep_names"
                        class="fflhub-field-label">
                        <?php esc_html_e('Quote email rep names', 'ffl-hub'); ?>
                    </label>
                    <textarea
                        id="fflhub_quote_email_rep_names"
                        name="fflhub_quote_email_rep_names"
                        class="fflhub-field-input"
                        rows="3"
                        placeholder="<?php echo esc_attr(Options::default_quote_email_rep_names()); ?>"><?php echo esc_textarea($quote_email_rep_names); ?></textarea>
                    <p class="description">
                        <?php esc_html_e(
                            'One display name per line. Quote emails rotate through these names so customer emails are no longer tied to a hard-coded person.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_quote_email_team_signature"
                        class="fflhub-field-label">
                        <?php esc_html_e('Quote email team signature', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_quote_email_team_signature"
                        name="fflhub_quote_email_team_signature"
                        type="text"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($quote_email_team_signature); ?>"
                        placeholder="<?php echo esc_attr(Options::default_quote_email_team_signature()); ?>" />
                    <p class="description">
                        <?php esc_html_e(
                            'Footer line shown below the rep name in styled and plain quote emails.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_quote_email_button_background_color"
                        class="fflhub-field-label">
                        <?php esc_html_e('Quote email button color', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_quote_email_button_background_color"
                        name="fflhub_quote_email_button_background_color"
                        type="color"
                        class="fflhub-field-input fflhub-color-input"
                        value="<?php echo esc_attr($quote_email_button_background_color); ?>" />
                    <p class="description">
                        <?php esc_html_e(
                            'Background color for the Add to cart button in styled quote emails.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_quote_email_button_text_color"
                        class="fflhub-field-label">
                        <?php esc_html_e('Quote email button text color', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_quote_email_button_text_color"
                        name="fflhub_quote_email_button_text_color"
                        type="color"
                        class="fflhub-field-input fflhub-color-input"
                        value="<?php echo esc_attr($quote_email_button_text_color); ?>" />
                    <p class="description">
                        <?php esc_html_e(
                            'Text color for the Add to cart button in styled quote emails.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_batch_order_notification_email"
                        class="fflhub-field-label">
                        <?php esc_html_e('Batch order notification email', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_batch_order_notification_email"
                        name="fflhub_batch_order_notification_email"
                        type="text"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($batch_order_notification_email); ?>"
                        placeholder="<?php echo esc_attr(Options::default_batch_order_notification_email()); ?>" />
                    <p class="description">
                        <?php esc_html_e(
                            'Receives internal emails when dealer batch or CA relay batch orders are successfully sent. Multiple emails can be separated by commas.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <label
                        for="fflhub_distributor_priority_list"
                        class="fflhub-field-label">
                        <?php esc_html_e('Distributor tie-break priority', 'ffl-hub'); ?>
                    </label>
                    <input
                        id="fflhub_distributor_priority_list"
                        name="fflhub_distributor_priority_list"
                        type="text"
                        class="fflhub-field-input"
                        value="<?php echo esc_attr($distributor_priority_list); ?>"
                        placeholder="<?php echo esc_attr($priority_default_text); ?>" />
                    <p class="description">
                        <?php
                        echo esc_html(
                            sprintf(
                                __('Used only when true-costs tie. Enter distributor ids in priority order, separated by commas. Available: %s', 'ffl-hub'),
                                $priority_choices_text
                            )
                        );
                        ?>
                    </p>
                </div>

                <div class="fflhub-field-row">
                    <h3><?php esc_html_e('Dealer Fulfillment Ship-To', 'ffl-hub'); ?></h3>
                    <p class="description">
                        <?php esc_html_e(
                            'Used when a distributor order is dealer-fulfilled: the distributor ships to your shop, then you ship to the customer.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <?php foreach ($dealer_ship_to_fields as $field) : ?>
                    <?php
                    $key = (string) ($field['key'] ?? '');
                    $option = (string) ($field['option'] ?? '');
                    $value = (string) ($dealer_ship_to[$key] ?? '');
                    ?>
                    <div class="fflhub-field-row">
                        <label
                            for="<?php echo esc_attr($option); ?>"
                            class="fflhub-field-label">
                            <?php echo esc_html((string) ($field['label'] ?? $option)); ?>
                        </label>
                        <input
                            id="<?php echo esc_attr($option); ?>"
                            name="<?php echo esc_attr($option); ?>"
                            type="text"
                            class="fflhub-field-input"
                            value="<?php echo esc_attr($value); ?>"
                            placeholder="<?php echo esc_attr((string) ($field['placeholder'] ?? '')); ?>" />
                    </div>
                <?php endforeach; ?>

                <div class="fflhub-field-row">
                    <h3><?php esc_html_e('CA Relay Ship-To', 'ffl-hub'); ?></h3>
                    <p class="description">
                        <?php esc_html_e(
                            'Used only for CA-restricted non-FFL relay batches from Lipsey\'s and Zanders. These drop-ship orders ship here first, then you ship to the customer.',
                            'ffl-hub'
                        ); ?>
                    </p>
                </div>

                <?php foreach ($relay_ship_to_fields as $field) : ?>
                    <?php
                    $key = (string) ($field['key'] ?? '');
                    $option = (string) ($field['option'] ?? '');
                    $value = (string) ($relay_ship_to[$key] ?? '');
                    ?>
                    <div class="fflhub-field-row">
                        <label
                            for="<?php echo esc_attr($option); ?>"
                            class="fflhub-field-label">
                            <?php echo esc_html((string) ($field['label'] ?? $option)); ?>
                        </label>
                        <input
                            id="<?php echo esc_attr($option); ?>"
                            name="<?php echo esc_attr($option); ?>"
                            type="text"
                            class="fflhub-field-input"
                            value="<?php echo esc_attr($value); ?>"
                            placeholder="<?php echo esc_attr((string) ($field['placeholder'] ?? '')); ?>" />
                    </div>
                <?php endforeach; ?>

                <?php submit_button(__('Save Global Settings', 'ffl-hub')); ?>
            </div>
        </form>
    <?php
    }

    /**
     * USPS settings shortcut card that opens a dedicated modal panel.
     *
     * @param array<string,string> $settings
     */
    private static function render_usps_settings_shortcut(array $settings): void
    {
        $enabled = ((string) ($settings['usps_estimate_enabled'] ?? '0') === '1');
        $logo_url = FFLHUB_PLUGIN_URL . 'assets/icons/logo-usps.svg';
    ?>
        <div class="fflhub-usps-shortcut-wrap">
            <h2 class="fflhub-section-title"><?php esc_html_e('Shipping Integrations', 'ffl-hub'); ?></h2>
            <button
                type="button"
                class="fflhub-distributor-card fflhub-usps-card"
                data-fflhub-target="fflhub-panel-usps-settings">
                <div class="fflhub-distributor-card-icon fflhub-usps-card-icon">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="USPS logo" />
                </div>
                <div class="fflhub-distributor-card-text">
                    <span class="fflhub-distributor-name"><?php esc_html_e('USPS', 'ffl-hub'); ?></span>
                    <span class="fflhub-distributor-description">
                        <?php esc_html_e('Configure API credentials and outbound estimate behavior.', 'ffl-hub'); ?>
                    </span>
                </div>
                <div class="fflhub-distributor-status">
                    <?php if ($enabled) : ?>
                        <span class="fflhub-status-badge fflhub-status-enabled">
                            <?php esc_html_e('Enabled', 'ffl-hub'); ?>
                        </span>
                    <?php else : ?>
                        <span class="fflhub-status-badge fflhub-status-disabled">
                            <?php esc_html_e('Disabled', 'ffl-hub'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    <?php
    }

    /**
     * Renders the clickable distributor cards.
     * These just open the modal; toggling happens inside the modal.
     *
     * @param DistributorModuleInterface[] $modules
     */
    private static function render_distributor_grid(array $modules): void
    {
    ?>
        <div class="fflhub-distributor-grid">
            <?php foreach ($modules as $module) :
                if (! ($module instanceof DistributorModuleInterface)) {
                    continue;
                }

                $id          = $module->id();
                $name        = $module->name();
                $label       = $module->label();
                $description = $module->description();
                $icon_url    = $module->icon_url();

                $enabled = Options::is_distributor_enabled($id);
            ?>
                <button
                    type="button"
                    class="fflhub-distributor-card <?php echo $enabled ? 'enabled' : 'disabled'; ?>"
                    data-fflhub-target="fflhub-panel-<?php echo esc_attr($id); ?>">
                    <div class="fflhub-distributor-card-icon">
                        <?php if ($icon_url) : ?>
                            <img
                                src="<?php echo esc_url($icon_url); ?>"
                                alt="<?php echo esc_attr($name); ?> icon" />
                        <?php else : ?>
                            <span class="fflhub-distributor-label">
                                <?php echo esc_html($label ?: $name); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="fflhub-distributor-card-text">
                        <span class="fflhub-distributor-name">
                            <?php echo esc_html($name); ?>
                        </span>
                        <?php if ($description) : ?>
                            <span class="fflhub-distributor-description">
                                <?php echo esc_html($description); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="fflhub-distributor-status">
                        <?php if ($enabled) : ?>
                            <span class="fflhub-status-badge fflhub-status-enabled">
                                <?php esc_html_e('Enabled', 'ffl-hub'); ?>
                            </span>
                        <?php else : ?>
                            <span class="fflhub-status-badge fflhub-status-disabled">
                                <?php esc_html_e('Disabled', 'ffl-hub'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
    <?php
    }

    /**
     * Renders modal panels for each distributor.
     *
     * @param DistributorModuleInterface[] $modules
     */
    private static function render_modal(array $modules, array $global_settings): void
    {
    ?>
        <div id="fflhub-modal" class="fflhub-modal" aria-hidden="true">
            <div class="fflhub-modal-overlay" data-fflhub-close="true"></div>

            <div class="fflhub-modal-dialog" role="dialog" aria-modal="true">
                <button
                    type="button"
                    class="fflhub-modal-close"
                    aria-label="<?php esc_attr_e('Close', 'ffl-hub'); ?>"
                    data-fflhub-close="true">
                    &times;
                </button>

                <div class="fflhub-modal-content">
                    <div
                        id="fflhub-panel-usps-settings"
                        class="fflhub-modal-panel"
                        aria-hidden="true">
                        <?php self::render_usps_settings_modal_panel($global_settings); ?>
                    </div>

                    <?php foreach ($modules as $module) :
                        if (! ($module instanceof DistributorModuleInterface)) {
                            continue;
                        }

                        $id = $module->id(); ?>
                        <div
                            id="fflhub-panel-<?php echo esc_attr($id); ?>"
                            class="fflhub-modal-panel"
                            aria-hidden="true">
                            <?php self::render_distributor_settings_form($module); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * @param array<string,string> $settings
     */
    private static function render_usps_settings_modal_panel(array $settings): void
    {
        $usps_estimate_enabled = (string) ($settings['usps_estimate_enabled'] ?? '0');
        $usps_use_test_env     = (string) ($settings['usps_use_test_env'] ?? '1');
        $usps_base_url         = (string) ($settings['usps_base_url'] ?? '');
        $usps_client_id        = (string) ($settings['usps_client_id'] ?? '');
        $usps_client_secret    = (string) ($settings['usps_client_secret'] ?? '');
        $usps_origin_zip       = (string) ($settings['usps_origin_zip'] ?? '');
        $usps_account_type     = (string) ($settings['usps_account_type'] ?? '');
        $usps_account_number   = (string) ($settings['usps_account_number'] ?? '');
        $usps_mail_class       = (string) ($settings['usps_mail_class'] ?? '');
        $usps_processing_category = (string) ($settings['usps_processing_category'] ?? '');
        $usps_destination_entry_facility_type = (string) ($settings['usps_destination_entry_facility_type'] ?? '');
        $usps_rate_indicator   = (string) ($settings['usps_rate_indicator'] ?? '');
        $usps_price_type       = (string) ($settings['usps_price_type'] ?? '');
        $usps_timeout_sec      = (string) ($settings['usps_timeout_sec'] ?? '');
        $usps_tare_weight_oz   = (string) ($settings['usps_tare_weight_oz'] ?? '0');
    ?>
        <div class="fflhub-distributor-settings-wrapper">
            <h2><?php esc_html_e('USPS Settings', 'ffl-hub'); ?></h2>
            <p class="description">
                <?php esc_html_e(
                    'These settings are used for dealer outbound shipping estimates during checkout. If USPS fails, the shipping method falls back to your formula pricing.',
                    'ffl-hub'
                ); ?>
            </p>

            <form method="post" action="options.php" class="fflhub-distributor-settings-form">
                <?php settings_fields(Options::usps_settings_group()); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_estimate_enabled"><?php esc_html_e('Enable USPS Outbound Estimates', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input type="hidden" name="fflhub_usps_estimate_enabled" value="0" />
                                <input
                                    id="fflhub_usps_estimate_enabled"
                                    name="fflhub_usps_estimate_enabled"
                                    type="checkbox"
                                    value="1"
                                    <?php checked($usps_estimate_enabled, '1'); ?> />
                                <p class="description"><?php esc_html_e('Turns USPS API pricing on for dealer->home and dealer->FFL LANES.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_use_test_env"><?php esc_html_e('Use Test Environment', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input type="hidden" name="fflhub_usps_use_test_env" value="0" />
                                <input
                                    id="fflhub_usps_use_test_env"
                                    name="fflhub_usps_use_test_env"
                                    type="checkbox"
                                    value="1"
                                    <?php checked($usps_use_test_env, '1'); ?> />
                                <p class="description"><?php esc_html_e('Checked uses apis-tem.usps.com. Unchecked uses production apis.usps.com.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_base_url"><?php esc_html_e('Base URL Override', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_base_url"
                                    name="fflhub_usps_base_url"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_base_url); ?>"
                                    placeholder="https://apis-tem.usps.com" />
                                <p class="description"><?php esc_html_e('Optional custom USPS API base URL. Leave blank to use the test/prod default.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_client_id"><?php esc_html_e('Client ID', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_client_id"
                                    name="fflhub_usps_client_id"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_client_id); ?>" />
                                <p class="description"><?php esc_html_e('USPS OAuth client ID from the USPS developer portal.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_client_secret"><?php esc_html_e('Client Secret', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_client_secret"
                                    name="fflhub_usps_client_secret"
                                    type="password"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_client_secret); ?>" />
                                <p class="description"><?php esc_html_e('USPS OAuth client secret. Stored in wp_options.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_origin_zip"><?php esc_html_e('Origin ZIP (Dealer)', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_origin_zip"
                                    name="fflhub_usps_origin_zip"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_origin_zip); ?>"
                                    placeholder="70801" />
                                <p class="description"><?php esc_html_e('Your shipping origin ZIP. Used as originZIPCode in quote requests.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_account_type"><?php esc_html_e('Account Type', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_account_type"
                                    name="fflhub_usps_account_type"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_account_type); ?>"
                                    placeholder="EPS" />
                                <p class="description"><?php esc_html_e('USPS account type value sent with quote requests when account number is present.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_account_number"><?php esc_html_e('Account Number', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_account_number"
                                    name="fflhub_usps_account_number"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_account_number); ?>" />
                                <p class="description"><?php esc_html_e('Optional USPS account number for negotiated/commercial quote context.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_mail_class"><?php esc_html_e('Mail Class', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_mail_class"
                                    name="fflhub_usps_mail_class"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_mail_class); ?>"
                                    placeholder="USPS_GROUND_ADVANTAGE" />
                                <p class="description"><?php esc_html_e('Service class to request from USPS, e.g. USPS_GROUND_ADVANTAGE.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_processing_category"><?php esc_html_e('Processing Category', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_processing_category"
                                    name="fflhub_usps_processing_category"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_processing_category); ?>"
                                    placeholder="MACHINABLE" />
                                <p class="description"><?php esc_html_e('Package handling category used by USPS rate logic.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_destination_entry_facility_type"><?php esc_html_e('Destination Facility Type', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_destination_entry_facility_type"
                                    name="fflhub_usps_destination_entry_facility_type"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_destination_entry_facility_type); ?>"
                                    placeholder="NONE" />
                                <p class="description"><?php esc_html_e('USPS destinationEntryFacilityType parameter (commonly NONE for estimates).', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_rate_indicator"><?php esc_html_e('Rate Indicator', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_rate_indicator"
                                    name="fflhub_usps_rate_indicator"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_rate_indicator); ?>"
                                    placeholder="<?php esc_attr_e('Optional', 'ffl-hub'); ?>" />
                                <p class="description"><?php esc_html_e('Optional USPS rateIndicator override for specific mail classes.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_price_type"><?php esc_html_e('Price Type', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_price_type"
                                    name="fflhub_usps_price_type"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr($usps_price_type); ?>"
                                    placeholder="COMMERCIAL" />
                                <p class="description"><?php esc_html_e('USPS priceType parameter, typically COMMERCIAL for merchant estimates.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_timeout_sec"><?php esc_html_e('Timeout (seconds)', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_timeout_sec"
                                    name="fflhub_usps_timeout_sec"
                                    type="number"
                                    min="3"
                                    step="1"
                                    class="small-text"
                                    value="<?php echo esc_attr($usps_timeout_sec); ?>" />
                                <p class="description"><?php esc_html_e('HTTP timeout for USPS token/rate requests.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fflhub_usps_tare_weight_oz"><?php esc_html_e('Tare Weight (oz)', 'ffl-hub'); ?></label>
                            </th>
                            <td>
                                <input
                                    id="fflhub_usps_tare_weight_oz"
                                    name="fflhub_usps_tare_weight_oz"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="small-text"
                                    value="<?php echo esc_attr($usps_tare_weight_oz); ?>" />
                                <p class="description"><?php esc_html_e('Added to each USPS package quote weight to account for packaging materials.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save USPS Settings', 'ffl-hub')); ?>
            </form>
        </div>
    <?php
    }

    private static function render_zanders_credential_tools(): void
    {
        ?>
        <section class="fflhub-credential-tools fflhub-zanders-tools" aria-label="<?php esc_attr_e('Zanders SOAP credential tests', 'ffl-hub'); ?>">
            <h3><?php esc_html_e('SOAP Credential Tests', 'ffl-hub'); ?></h3>
            <div class="fflhub-credential-test-actions fflhub-zanders-test-actions">
                <button type="button" class="button button-secondary fflhub-zanders-test-credentials" data-profile="main">
                    <?php esc_html_e('Test Main / Dealer SOAP', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button button-secondary fflhub-zanders-test-credentials" data-profile="accessory">
                    <?php esc_html_e('Test Accessory SOAP', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button button-secondary fflhub-zanders-test-credentials" data-profile="gun">
                    <?php esc_html_e('Test Gun SOAP', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-credential-test-status fflhub-zanders-test-status" aria-live="polite"></div>
        </section>
        <?php
    }

    private static function render_rsr_credential_tools(): void
    {
        ?>
        <section class="fflhub-credential-tools fflhub-rsr-tools" aria-label="<?php esc_attr_e('RSR credential tests', 'ffl-hub'); ?>">
            <h3><?php esc_html_e('Credential Tests', 'ffl-hub'); ?></h3>
            <div class="fflhub-credential-test-actions fflhub-rsr-test-actions">
                <button type="button" class="button button-secondary fflhub-rsr-test-credentials" data-profile="main">
                    <?php esc_html_e('Test Main DirectConnect', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button button-secondary fflhub-rsr-test-credentials" data-profile="dropship">
                    <?php esc_html_e('Test Drop-Ship DirectConnect', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button button-secondary fflhub-rsr-test-credentials" data-profile="ftp">
                    <?php esc_html_e('Test FTP Feed', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-credential-test-status fflhub-rsr-test-status" aria-live="polite"></div>
        </section>
        <?php
    }

    private static function render_lipseys_credential_tools(): void
    {
        ?>
        <section class="fflhub-credential-tools fflhub-lipseys-tools" aria-label="<?php esc_attr_e('Lipsey\'s credential tests', 'ffl-hub'); ?>">
            <h3><?php esc_html_e('Credential Tests', 'ffl-hub'); ?></h3>
            <div class="fflhub-credential-test-actions fflhub-lipseys-test-actions">
                <button type="button" class="button button-secondary fflhub-lipseys-test-credentials" data-profile="main">
                    <?php esc_html_e('Test Main Account Login', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button button-secondary fflhub-lipseys-test-credentials" data-profile="dealer">
                    <?php esc_html_e('Test Dealer Login', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-credential-test-status fflhub-lipseys-test-status" aria-live="polite"></div>
        </section>
        <?php
    }

    private static function render_cssi_credential_tools(): void
    {
        ?>
        <section class="fflhub-credential-tools fflhub-cssi-tools" aria-label="<?php esc_attr_e('CSSI credential tests', 'ffl-hub'); ?>">
            <h3><?php esc_html_e('Credential Tests', 'ffl-hub'); ?></h3>
            <div class="fflhub-credential-test-actions fflhub-cssi-test-actions">
                <button type="button" class="button button-secondary fflhub-cssi-test-credentials" data-profile="api">
                    <?php esc_html_e('Test REST API', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-credential-test-status fflhub-cssi-test-status" aria-live="polite"></div>
        </section>
        <?php
    }

    private static function render_sports_south_credential_tools(): void
    {
        ?>
        <section class="fflhub-credential-tools fflhub-sports-south-tools" aria-label="<?php esc_attr_e('Sports South credential tests', 'ffl-hub'); ?>">
            <h3><?php esc_html_e('Credential Tests', 'ffl-hub'); ?></h3>
            <div class="fflhub-credential-test-actions fflhub-sports-south-test-actions">
                <button type="button" class="button button-secondary fflhub-sports-south-test-credentials" data-profile="inventory">
                    <?php esc_html_e('Test Inventory API', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button button-secondary fflhub-sports-south-test-credentials" data-profile="orders">
                    <?php esc_html_e('Test Orders API', 'ffl-hub'); ?>
                </button>
                <button type="button" class="button button-secondary fflhub-sports-south-test-credentials" data-profile="invoices">
                    <?php esc_html_e('Test Invoices / Tracking API', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-credential-test-status fflhub-sports-south-test-status" aria-live="polite"></div>
        </section>
        <?php
    }

    private static function render_orion_credential_tools(): void
    {
        ?>
        <section class="fflhub-credential-tools fflhub-orion-tools" aria-label="<?php esc_attr_e('Orion credential tests', 'ffl-hub'); ?>">
            <h3><?php esc_html_e('Credential Tests', 'ffl-hub'); ?></h3>
            <div class="fflhub-credential-test-actions fflhub-orion-test-actions">
                <button type="button" class="button button-secondary fflhub-orion-test-credentials" data-profile="api">
                    <?php esc_html_e('Test Connection Key', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-credential-test-status fflhub-orion-test-status" aria-live="polite"></div>
        </section>
        <?php
    }

    private static function render_kinseys_credential_tools(): void
    {
        ?>
        <section class="fflhub-credential-tools fflhub-kinseys-tools" aria-label="<?php esc_attr_e('Kinsey\'s credential tests', 'ffl-hub'); ?>">
            <h3><?php esc_html_e('Credential Tests', 'ffl-hub'); ?></h3>
            <div class="fflhub-credential-test-actions fflhub-kinseys-test-actions">
                <button type="button" class="button button-secondary fflhub-kinseys-test-credentials" data-profile="api">
                    <?php esc_html_e('Test Customer API', 'ffl-hub'); ?>
                </button>
            </div>
            <div class="fflhub-credential-test-status fflhub-kinseys-test-status" aria-live="polite"></div>
        </section>
        <?php
    }

    /**
     * Render a full distributor settings form inside the modal,
     * including the Enable/Disable button.
     */
    private static function render_distributor_settings_form(DistributorModuleInterface $module): void
    {
        $id      = $module->id();
        $name    = $module->name();
        $fields  = $module->settings_schema();
        if (!is_array($fields)) {
            $fields = [];
        }
        $enabled = Options::is_distributor_enabled($id);

        $group = Options::distributor_settings_group($id);
        $credit_limit_option_name = Options::distributor_credit_limit_option_name($id);
        $credit_limit_default = (string) Options::default_distributor_credit_limit($id);
        $credit_limit_value = (string) Options::get_distributor_credit_limit($id, (float) $credit_limit_default);
        $non_dropship_blocked_option_name = Options::distributor_non_dropship_blocked_option_name($id);
        $non_dropship_blocked_enabled = Options::is_distributor_non_dropship_blocked($id);
        $sig_approved_option_name = Options::distributor_sig_approved_option_name($id);
        $sig_approved_enabled = Options::is_distributor_sig_approved($id);

    ?>
        <div class="fflhub-distributor-settings-wrapper">
            <h2>
                <?php echo esc_html($name); ?>
                <?php esc_html_e(' Settings', 'ffl-hub'); ?>
            </h2>

            <!-- Enable / Disable controls -->
            <div class="fflhub-distributor-toggle">
                <p>
                    <strong><?php esc_html_e('Current status:', 'ffl-hub'); ?></strong>
                    <?php if ($enabled) : ?>
                        <span class="fflhub-status-badge fflhub-status-enabled">
                            <?php esc_html_e('Enabled', 'ffl-hub'); ?>
                        </span>
                    <?php else : ?>
                        <span class="fflhub-status-badge fflhub-status-disabled">
                            <?php esc_html_e('Disabled', 'ffl-hub'); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('fflhub_toggle_distributor_' . $id); ?>
                    <input type="hidden" name="action" value="fflhub_toggle_distributor">
                    <input type="hidden" name="distributor_id" value="<?php echo esc_attr($id); ?>">
                    <input type="hidden" name="enable" value="<?php echo $enabled ? '0' : '1'; ?>">

                    <?php if ($enabled) : ?>
                        <button type="submit" class="button button-secondary">
                            <?php esc_html_e('Disable Distributor', 'ffl-hub'); ?>
                        </button>
                    <?php else : ?>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Enable Distributor', 'ffl-hub'); ?>
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($id === 'zanders') : ?>
                <?php self::render_zanders_credential_tools(); ?>
            <?php endif; ?>
            <?php if ($id === 'rsr') : ?>
                <?php self::render_rsr_credential_tools(); ?>
            <?php endif; ?>
            <?php if ($id === 'lipseys') : ?>
                <?php self::render_lipseys_credential_tools(); ?>
            <?php endif; ?>
            <?php if ($id === 'cssi') : ?>
                <?php self::render_cssi_credential_tools(); ?>
            <?php endif; ?>
            <?php if ($id === 'sports_south') : ?>
                <?php self::render_sports_south_credential_tools(); ?>
            <?php endif; ?>
            <?php if ($id === 'orion') : ?>
                <?php self::render_orion_credential_tools(); ?>
            <?php endif; ?>
            <?php if ($id === 'kinseys') : ?>
                <?php self::render_kinseys_credential_tools(); ?>
            <?php endif; ?>

            <!-- Distributor settings form -->
            <form method="post" action="options.php" class="fflhub-distributor-settings-form">
                <?php settings_fields($group); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($credit_limit_option_name); ?>">
                                    <?php esc_html_e('Credit Limit ($)', 'ffl-hub'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    id="<?php echo esc_attr($credit_limit_option_name); ?>"
                                    name="<?php echo esc_attr($credit_limit_option_name); ?>"
                                    value="<?php echo esc_attr((string) $credit_limit_value); ?>"
                                    class="regular-text" />
                                <p class="description">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            __('Used as the credit limit on the %s credit/status page.', 'ffl-hub'),
                                            $name
                                        )
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($non_dropship_blocked_option_name); ?>">
                                    <?php esc_html_e('Block Non-Drop-Ship Items', 'ffl-hub'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="hidden" name="<?php echo esc_attr($non_dropship_blocked_option_name); ?>" value="0" />
                                <input
                                    type="checkbox"
                                    id="<?php echo esc_attr($non_dropship_blocked_option_name); ?>"
                                    name="<?php echo esc_attr($non_dropship_blocked_option_name); ?>"
                                    value="1"
                                    <?php checked($non_dropship_blocked_enabled); ?> />
                                <p class="description">
                                    <?php esc_html_e('When enabled, this distributor is treated as drop-ship only. Non-drop-ship offers are ignored for product creation and product sync source selection.', 'ffl-hub'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($sig_approved_option_name); ?>">
                                    <?php esc_html_e('Sig Approved', 'ffl-hub'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="hidden" name="<?php echo esc_attr($sig_approved_option_name); ?>" value="0" />
                                <input
                                    type="checkbox"
                                    id="<?php echo esc_attr($sig_approved_option_name); ?>"
                                    name="<?php echo esc_attr($sig_approved_option_name); ?>"
                                    value="1"
                                    <?php checked($sig_approved_enabled); ?> />
                                <p class="description">
                                    <?php esc_html_e('When enabled, SIG SAUER products from this distributor are forced to drop-ship eligible during product and inventory updates.', 'ffl-hub'); ?>
                                </p>
                            </td>
                        </tr>
                        <?php foreach ($fields as $key => $field) :
                            $option_name = Options::distributor_option_name($id, $key);
                            $type        = isset($field['type']) ? strtolower((string) $field['type']) : 'text';
                            $label       = $field['label'] ?? $key;
                            $placeholder = $field['placeholder'] ?? '';
                            $desc        = $field['description'] ?? '';
                            $default     = isset($field['default']) ? (string) $field['default'] : '';
                            $value       = Options::get_distributor_option($id, $key, $default);
                            $options     = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];

                        ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($option_name); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </th>
                                <td>
                                    <?php if ($type === 'textarea') : ?>
                                        <textarea
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            placeholder="<?php echo esc_attr($placeholder); ?>"
                                            class="large-text"
                                            rows="4"><?php echo esc_textarea((string) $value); ?></textarea>
                                    <?php elseif ($type === 'select' && !empty($options)) : ?>
                                        <select
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            class="regular-text">
                                            <?php foreach ($options as $opt_key => $opt_label) :
                                                $option_value = is_string($opt_key) ? $opt_key : (string) $opt_label;
                                                $option_label = is_scalar($opt_label) ? (string) $opt_label : $option_value;
                                            ?>
                                                <option value="<?php echo esc_attr($option_value); ?>" <?php selected((string) $value, $option_value); ?>>
                                                    <?php echo esc_html($option_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'checkbox') : ?>
                                        <input type="hidden" name="<?php echo esc_attr($option_name); ?>" value="0" />
                                        <input
                                            type="checkbox"
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            value="1"
                                            <?php checked((string) $value, '1'); ?> />
                                    <?php else : ?>
                                        <?php
                                        $input_type = in_array($type, ['text', 'password', 'number', 'email', 'url'], true)
                                            ? $type
                                            : 'text';
                                        ?>
                                        <input
                                            type="<?php echo esc_attr($input_type); ?>"
                                            id="<?php echo esc_attr($option_name); ?>"
                                            name="<?php echo esc_attr($option_name); ?>"
                                            value="<?php echo esc_attr((string) $value); ?>"
                                            placeholder="<?php echo esc_attr($placeholder); ?>"
                                            class="regular-text" />
                                    <?php endif; ?>
                                    <?php if ($desc) : ?>
                                        <p class="description"><?php echo esc_html($desc); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(__('Save Settings', 'ffl-hub')); ?>
            </form>
        </div>
<?php
    }
}

