<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Receiving\LocalStockReceivingService;
use FFLHub\Receiving\ReceivingEventsStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scanner workflow for inbound inventory that is not tied to an inbound PO.
 *
 * Operators scan UPCs/serials first, then either add the count to local stock
 * or attach the received units to an existing successful local-stock job row.
 */
final class ReceivingLocalStockPage
{
    private const PAGE_SLUG = 'fflhub-receiving-local-stock';
    private const NONCE_ACTION = 'fflhub_receiving_local_stock';

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_fflhub_receiving_local_lookup_product', [$this, 'ajax_lookup_product']);
        add_action('wp_ajax_fflhub_receiving_local_matching_orders', [$this, 'ajax_matching_orders']);
        add_action('wp_ajax_fflhub_receiving_local_commit_stock', [$this, 'ajax_commit_stock']);
        add_action('wp_ajax_fflhub_receiving_local_assign_order', [$this, 'ajax_assign_order']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            WMSAdminPage::MENU_SLUG,
            __('Receiving (Local Stock)', 'ffl-hub'),
            __('Receiving (Local Stock)', 'ffl-hub'),
            WMSAdminPage::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        $root = dirname(__DIR__, 3);
        $plugin_file = $root . '/ffl-hub.php';

        wp_enqueue_style(
            'fflhub-receiving-local-stock',
            plugins_url('assets/css/fflhub-receiving-local-stock.css', $plugin_file),
            [],
            filemtime($root . '/assets/css/fflhub-receiving-local-stock.css')
        );
        wp_enqueue_script(
            'fflhub-receiving-local-stock',
            plugins_url('assets/js/fflhub-receiving-local-stock.js', $plugin_file),
            ['jquery'],
            filemtime($root . '/assets/js/fflhub-receiving-local-stock.js'),
            true
        );
        wp_localize_script(
            'fflhub-receiving-local-stock',
            'FFLHubReceivingLocalStock',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'context' => $this->service()->page_context(),
            ]
        );
    }

    public function render_page(): void
    {
        WMSAdminPage::ensure_access();
        ReceivingEventsStore::ensure_schema();

        $context = $this->service()->page_context();
        $contacts = is_array($context['source_contacts'] ?? null) ? $context['source_contacts'] : [];
        ?>
        <div class="wrap fflhub-local-receiving-page">
            <div class="fflhub-local-receiving-header">
                <div>
                    <h1><?php esc_html_e('Receiving (Local Stock)', 'ffl-hub'); ?></h1>
                    <p>
                        <?php esc_html_e('Scan inventory that is not tied to an inbound order shipment, acquire serialized items, then stock it or attach it to an existing local-stock order row.', 'ffl-hub'); ?>
                    </p>
                </div>
            </div>

            <div id="fflhub-local-receiving-app" class="fflhub-local-receiving-app" data-local-receiving-app>
                <section class="fflhub-local-card">
                    <div class="fflhub-local-card-head">
                        <span class="fflhub-local-step">1</span>
                        <div>
                            <h2><?php esc_html_e('FastBound Source', 'ffl-hub'); ?></h2>
                            <p><?php esc_html_e('Choose the distributor contact the physical inventory came from. Serialized items are acquired from this contact.', 'ffl-hub'); ?></p>
                        </div>
                    </div>
                    <label class="fflhub-local-field">
                        <span><?php esc_html_e('Distributor / Contact', 'ffl-hub'); ?></span>
                        <select data-source-contact>
                            <option value=""><?php esc_html_e('Select source contact...', 'ffl-hub'); ?></option>
                            <?php foreach ($contacts as $contact) : ?>
                                <option
                                    value="<?php echo esc_attr((string) ($contact['contact_id'] ?? '')); ?>"
                                    data-distributor-id="<?php echo esc_attr((string) ($contact['distributor_id'] ?? '')); ?>"
                                >
                                    <?php echo esc_html((string) ($contact['label'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if (empty($contacts)) : ?>
                        <div class="notice notice-warning inline">
                            <p>
                                <?php esc_html_e('No enabled FastBound distributor contacts are configured yet.', 'ffl-hub'); ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=fflhub-fastbound-integration-settings')); ?>">
                                    <?php esc_html_e('Open FastBound settings', 'ffl-hub'); ?>
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="fflhub-local-card">
                    <div class="fflhub-local-card-head">
                        <span class="fflhub-local-step">2</span>
                        <div>
                            <h2><?php esc_html_e('Scan Items', 'ffl-hub'); ?></h2>
                            <p><?php esc_html_e('Scan UPCs. Firearm rows will ask for a serial number before they are added.', 'ffl-hub'); ?></p>
                        </div>
                    </div>

                    <div class="fflhub-local-scan-grid">
                        <label class="fflhub-local-field">
                            <span><?php esc_html_e('UPC', 'ffl-hub'); ?></span>
                            <input type="text" inputmode="text" autocomplete="off" data-upc-input />
                        </label>
                        <label class="fflhub-local-field" data-serial-wrap>
                            <span><?php esc_html_e('Serial Number', 'ffl-hub'); ?></span>
                            <input type="text" inputmode="text" autocomplete="off" data-serial-input disabled />
                        </label>
                        <button type="button" class="button button-primary" data-add-scan>
                            <?php esc_html_e('Add Scan', 'ffl-hub'); ?>
                        </button>
                    </div>

                    <div class="fflhub-local-feedback" data-feedback aria-live="polite"></div>
                    <div class="fflhub-local-scanned" data-scanned-table></div>
                </section>

                <section class="fflhub-local-card" data-firearm-fields-card hidden>
                    <div class="fflhub-local-card-head">
                        <span class="fflhub-local-step">3</span>
                        <div>
                            <h2><?php esc_html_e('Serialized Item Details', 'ffl-hub'); ?></h2>
                            <p><?php esc_html_e('FastBound requires these fields for every firearm acquisition. Model defaults to the Woo product name if left blank.', 'ffl-hub'); ?></p>
                        </div>
                    </div>
                    <div data-firearm-fields></div>
                </section>

                <section class="fflhub-local-card">
                    <div class="fflhub-local-card-head">
                        <span class="fflhub-local-step">4</span>
                        <div>
                            <h2><?php esc_html_e('Finish Receiving', 'ffl-hub'); ?></h2>
                            <p><?php esc_html_e('Commit the scans to local stock, or assign them to matching order rows that already have successful local-stock jobs.', 'ffl-hub'); ?></p>
                        </div>
                    </div>
                    <div class="fflhub-local-actions">
                        <button type="button" class="button button-primary" data-commit-stock>
                            <?php esc_html_e('Commit to Local Stock', 'ffl-hub'); ?>
                        </button>
                        <button type="button" class="button" data-load-matches>
                            <?php esc_html_e('Find Matching Orders', 'ffl-hub'); ?>
                        </button>
                    </div>
                    <div class="fflhub-local-matches" data-matches></div>
                    <div class="fflhub-local-result" data-result></div>
                </section>
            </div>
        </div>
        <?php
    }

    public function ajax_lookup_product(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->lookup_product($this->request_text('upc')));
    }

    public function ajax_matching_orders(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->matching_orders($this->request_json('upcs')));
    }

    public function ajax_commit_stock(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->commit_to_local_stock(
            $this->request_json('items'),
            [
                'distributor_id' => $this->request_text('source_distributor_id'),
                'contact_id' => $this->request_text('source_contact_id'),
            ],
            $this->request_json('firearm_fields')
        ));
    }

    public function ajax_assign_order(): void
    {
        $this->assert_ajax_access();
        $this->send($this->service()->assign_to_orders(
            $this->request_json('items'),
            [
                'distributor_id' => $this->request_text('source_distributor_id'),
                'contact_id' => $this->request_text('source_contact_id'),
            ],
            $this->request_json('assignments'),
            $this->request_json('firearm_fields')
        ));
    }

    private function service(): LocalStockReceivingService
    {
        return new LocalStockReceivingService($this->jobs_table);
    }

    private function send(array $payload): void
    {
        wp_send_json_success($payload);
    }

    private function assert_ajax_access(): void
    {
        if (!current_user_can(WMSAdminPage::CAPABILITY)) {
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

    /**
     * @return mixed
     */
    private function request_json(string $key)
    {
        $raw = isset($_POST[$key]) ? wp_unslash((string) $_POST[$key]) : '';
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
