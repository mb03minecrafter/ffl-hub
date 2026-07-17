<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Receiving\ReceivingEventsStore;
use FFLHub\Receiving\ReceivingShipmentService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Warehouse receiving screen for dealer-fulfilled inbound shipments.
 *
 * The page deliberately reuses dealer shipment tracker job rows as the expected
 * shipment source. It only adds a scan/audit trail through ReceivingEventsStore.
 */
final class ReceivingPage
{
    private const PAGE_SLUG = 'fflhub-receiving';
    private const NONCE_ACTION = 'fflhub_receiving';

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_fflhub_receiving_lookup_tracking', [$this, 'ajax_lookup_tracking']);
        add_action('wp_ajax_fflhub_receiving_lookup_po', [$this, 'ajax_lookup_po']);
        add_action('wp_ajax_fflhub_receiving_get_shipment', [$this, 'ajax_get_shipment']);
        add_action('wp_ajax_fflhub_receiving_scan_product', [$this, 'ajax_scan_product']);
        add_action('wp_ajax_fflhub_receiving_history', [$this, 'ajax_history']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Receiving', 'ffl-hub'),
            __('Receiving', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        $plugin_file = dirname(__DIR__, 3) . '/ffl-hub.php';
        wp_enqueue_style(
            'fflhub-receiving',
            plugins_url('assets/css/fflhub-receiving.css', $plugin_file),
            [],
            filemtime(dirname(__DIR__, 3) . '/assets/css/fflhub-receiving.css')
        );
        wp_enqueue_script(
            'fflhub-receiving',
            plugins_url('assets/js/fflhub-receiving.js', $plugin_file),
            ['jquery'],
            filemtime(dirname(__DIR__, 3) . '/assets/js/fflhub-receiving.js'),
            true
        );
        wp_localize_script(
            'fflhub-receiving',
            'FFLHubReceiving',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
            ]
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        ReceivingEventsStore::ensure_schema();
        ?>
        <div class="wrap fflhub-receiving-page">
            <div class="fflhub-receiving-header">
                <div>
                    <h1><?php esc_html_e('Receiving', 'ffl-hub'); ?></h1>
                    <p>
                        <?php esc_html_e('Scan inbound dealer-fulfilled shipments against existing tracked order-job rows.', 'ffl-hub'); ?>
                    </p>
                </div>
                <button type="button" class="button fflhub-receiving-mute" data-receiving-mute>
                    <?php esc_html_e('Mute Sounds', 'ffl-hub'); ?>
                </button>
            </div>

            <div id="fflhub-receiving-app" class="fflhub-receiving-app" data-receiving-app>
                <div class="fflhub-receiving-step is-active" data-step="identify">
                    <div class="fflhub-receiving-step-number">1</div>
                    <div class="fflhub-receiving-step-body">
                        <h2><?php esc_html_e('Identify Shipment', 'ffl-hub'); ?></h2>
                        <p><?php esc_html_e('Scan a tracking barcode or manually enter the distributor PO/order number.', 'ffl-hub'); ?></p>
                        <label class="fflhub-receiving-debug-toggle">
                            <input type="checkbox" value="1" data-receiving-debug-old />
                            <span>
                                <strong><?php esc_html_e('Debug: include old/completed shipments', 'ffl-hub'); ?></strong>
                                <?php esc_html_e('Use this for testing old boxes. Normal receiving keeps completed Woo orders hidden.', 'ffl-hub'); ?>
                            </span>
                        </label>

                        <div class="fflhub-receiving-identify-grid">
                            <label class="fflhub-receiving-field">
                                <span><?php esc_html_e('Tracking Scan', 'ffl-hub'); ?></span>
                                <input type="text" inputmode="text" autocomplete="off" data-receiving-tracking-input />
                                <button type="button" class="button button-primary" data-receiving-tracking-submit>
                                    <?php esc_html_e('Find Tracking', 'ffl-hub'); ?>
                                </button>
                            </label>

                            <label class="fflhub-receiving-field">
                                <span><?php esc_html_e('Manual PO / Distributor Order', 'ffl-hub'); ?></span>
                                <input type="text" inputmode="text" autocomplete="off" data-receiving-po-input />
                                <button type="button" class="button" data-receiving-po-submit>
                                    <?php esc_html_e('Find PO', 'ffl-hub'); ?>
                                </button>
                            </label>
                        </div>

                        <div class="fflhub-receiving-feedback" data-receiving-feedback aria-live="polite"></div>
                        <div class="fflhub-receiving-matches" data-receiving-matches></div>
                    </div>
                </div>

                <div class="fflhub-receiving-step" data-step="review">
                    <div class="fflhub-receiving-step-number">2</div>
                    <div class="fflhub-receiving-step-body" data-receiving-review></div>
                </div>

                <div class="fflhub-receiving-step" data-step="scan">
                    <div class="fflhub-receiving-step-number">3</div>
                    <div class="fflhub-receiving-step-body" data-receiving-scan></div>
                </div>

                <div class="fflhub-receiving-step" data-step="complete">
                    <div class="fflhub-receiving-step-number">4</div>
                    <div class="fflhub-receiving-step-body" data-receiving-complete></div>
                </div>

                <section class="fflhub-receiving-history">
                    <div class="fflhub-receiving-history-head">
                        <h2><?php esc_html_e('Recent Receiving Activity', 'ffl-hub'); ?></h2>
                        <button type="button" class="button" data-receiving-history-refresh>
                            <?php esc_html_e('Refresh', 'ffl-hub'); ?>
                        </button>
                    </div>
                    <div data-receiving-history></div>
                </section>
            </div>
        </div>
        <?php
    }

    public function ajax_lookup_tracking(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->lookup_by_tracking($this->request_text('tracking')));
    }

    public function ajax_lookup_po(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->lookup_by_po($this->request_text('po')));
    }

    public function ajax_get_shipment(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->get_shipment($this->request_text('shipment_key')));
    }

    public function ajax_scan_product(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->scan_product(
            $this->request_text('shipment_key'),
            $this->request_text('scan'),
            $this->request_text('request_token')
        ));
    }

    public function ajax_history(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->recent_history());
    }

    private function service(): ReceivingShipmentService
    {
        return new ReceivingShipmentService($this->jobs_table, null, $this->request_bool('debug_include_old'));
    }

    private function send(array $payload): void
    {
        wp_send_json_success($payload);
    }

    private function assert_ajax_access(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to access this page.', 'ffl-hub')], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private function request_text(string $key): string
    {
        return isset($_POST[$key])
            ? sanitize_text_field(wp_unslash((string) $_POST[$key]))
            : '';
    }

    private function request_bool(string $key): bool
    {
        $value = isset($_POST[$key])
            ? strtolower(trim(sanitize_text_field(wp_unslash((string) $_POST[$key]))))
            : '';

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
