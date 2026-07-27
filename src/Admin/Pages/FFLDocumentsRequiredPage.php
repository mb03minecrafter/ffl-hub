<?php

declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Checkout\Builders\CheckoutOrderRequestBuilder;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Models\OrderPlacementJobRow;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\FFL\Tables\FFLTable;
use FFLHub\Settings\Options;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Worklist for distributor FFL document follow-up.
 *
 * Sports South accepts the order electronically but still requires the receiving
 * FFL document by email. This page keeps that manual document step attached to
 * the same direct_ship_ffl job rows that created the distributor order.
 */
final class FFLDocumentsRequiredPage
{
    private const PAGE_SLUG = 'fflhub-ffl-documents-required';
    private const FORM_ACTION_SEND = 'fflhub_send_ffl_document';
    private const NONCE_ACTION = 'fflhub_send_ffl_document';
    private const NONCE_FIELD = 'fflhub_ffl_document_nonce';
    private const FILE_FIELD = 'fflhub_ffl_document_file';
    private const ORDER_META_LOG = '_fflhub_ffl_documents_required_log';

    /** @var array<string,string> */
    private const SUPPORTED_DISTRIBUTORS = [
        'sports_south' => 'Sports South',
        'kinseys' => 'Kinsey\'s',
    ];

    private OrderPlacementJobsTable $jobs_table;
    private FFLTable $ffl_table;

    public function __construct(OrderPlacementJobsTable $jobs_table, FFLTable $ffl_table)
    {
        $this->jobs_table = $jobs_table;
        $this->ffl_table = $ffl_table;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            DistributorOrderingAdminPage::MENU_SLUG,
            __('FFL Documents Required', 'ffl-hub'),
            __('FFL Documents Required', 'ffl-hub'),
            DistributorOrderingAdminPage::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        DistributorOrderingAdminPage::ensure_access();

        $notice = $this->maybe_handle_document_send();
        $selected_job_id = $this->selected_job_id($notice);
        $jobs = $this->find_supported_direct_ship_ffl_jobs();
        $selected_job = $selected_job_id > 0 ? $this->find_job_by_id($selected_job_id) : null;
        ?>
        <div class="wrap fflhub-ffl-documents-page">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('FFL Documents Required', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Sports South and Kinsey\'s direct-ship firearm jobs that may need a receiving FFL copy emailed after the distributor order is placed.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_notice($notice); ?>
            <?php $this->render_summary($jobs); ?>

            <?php if ($selected_job instanceof OrderPlacementJobRow) : ?>
                <?php $this->render_job_detail($selected_job); ?>
            <?php endif; ?>

            <?php $this->render_jobs_table($jobs, $selected_job_id); ?>
        </div>
        <?php
    }

    /**
     * @param array{type:string,message:string,job_id?:int}|null $notice
     */
    private function selected_job_id(?array $notice): int
    {
        if (isset($notice['job_id'])) {
            return max(0, (int) $notice['job_id']);
        }

        return isset($_GET['job_id']) ? max(0, (int) $_GET['job_id']) : 0;
    }

    /**
     * @return array{type:string,message:string,job_id?:int}|null
     */
    private function maybe_handle_document_send(): ?array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        $action = isset($_POST['fflhub_ffl_document_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_ffl_document_action']))
            : '';
        if ($action !== self::FORM_ACTION_SEND) {
            return null;
        }

        $job_id = isset($_POST['fflhub_ffl_document_job_id']) ? max(0, (int) $_POST['fflhub_ffl_document_job_id']) : 0;
        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])),
                self::NONCE_ACTION . '_' . (string) $job_id
            )
        ) {
            return ['type' => 'error', 'message' => __('Security check failed. Please refresh and try again.', 'ffl-hub'), 'job_id' => $job_id];
        }

        $job = $this->find_job_by_id($job_id);
        if (!($job instanceof OrderPlacementJobRow)) {
            return ['type' => 'error', 'message' => __('Job row was not found.', 'ffl-hub'), 'job_id' => $job_id];
        }

        $dist_id = $job->dist_id_norm();
        if (!isset(self::SUPPORTED_DISTRIBUTORS[$dist_id]) || !OrderPlacementKeysUtil::is_direct_ship_ffl_lane($job->lane_norm())) {
            return ['type' => 'error', 'message' => __('That job is not a supported drop-ship FFL row.', 'ffl-hub'), 'job_id' => $job_id];
        }

        $order = wc_get_order((int) $job->order_id);
        if (!($order instanceof WC_Order)) {
            return ['type' => 'error', 'message' => __('WooCommerce order was not found for this job.', 'ffl-hub'), 'job_id' => $job_id];
        }

        $recipients = $this->configured_recipients_for($dist_id);
        if (empty($recipients)) {
            return [
                'type' => 'error',
                'message' => sprintf(
                    __('No FFL document email recipient is configured for %s.', 'ffl-hub'),
                    self::SUPPORTED_DISTRIBUTORS[$dist_id]
                ),
                'job_id' => $job_id,
            ];
        }

        $upload = $this->handle_document_upload();
        if (isset($upload['error'])) {
            return ['type' => 'error', 'message' => (string) $upload['error'], 'job_id' => $job_id];
        }

        [$ffl_number, $ffl_ship_to] = CheckoutOrderRequestBuilder::build_ship_to_ffl_from_order_or_null($this->ffl_table, $order);
        $items = $this->build_item_rows($order, $job);
        [$subject, $body] = $this->build_email_shape($dist_id, $order, $job, (string) $ffl_number, $ffl_ship_to, $items);

        $path = (string) ($upload['file'] ?? '');
        $url = (string) ($upload['url'] ?? '');
        $filename = basename($path);

        $sent = wp_mail(
            $recipients,
            $subject,
            $body,
            ['Content-Type: text/plain; charset=UTF-8'],
            [$path]
        );

        if (!$sent) {
            return [
                'type' => 'error',
                'message' => __('WordPress could not send the FFL document email. The uploaded file is still in uploads for inspection.', 'ffl-hub'),
                'job_id' => $job_id,
            ];
        }

        $this->record_document_email($order, $job, $recipients, $subject, $url, $filename);

        return [
            'type' => 'success',
            'message' => sprintf(
                __('FFL document emailed to %1$s for %2$s job #%3$d.', 'ffl-hub'),
                implode(', ', $recipients),
                self::SUPPORTED_DISTRIBUTORS[$dist_id],
                (int) $job->id
            ),
            'job_id' => $job_id,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function handle_document_upload(): array
    {
        if (!isset($_FILES[self::FILE_FIELD]) || !is_array($_FILES[self::FILE_FIELD])) {
            return ['error' => __('Please choose an FFL document to upload.', 'ffl-hub')];
        }

        $file = $_FILES[self::FILE_FIELD];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => __('The upload failed before WordPress could process the file.', 'ffl-hub')];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload(
            $file,
            [
                'test_form' => false,
                'mimes' => [
                    'pdf' => 'application/pdf',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                ],
            ]
        );

        if (!is_array($upload)) {
            return ['error' => __('WordPress did not return an upload result.', 'ffl-hub')];
        }

        if (isset($upload['error'])) {
            return ['error' => (string) $upload['error']];
        }

        $path = isset($upload['file']) ? (string) $upload['file'] : '';
        if ($path === '' || !is_readable($path)) {
            return ['error' => __('The uploaded document could not be read for attachment.', 'ffl-hub')];
        }

        return [
            'file' => $path,
            'url' => isset($upload['url']) ? (string) $upload['url'] : '',
        ];
    }

    /**
     * @return string[]
     */
    private function configured_recipients_for(string $dist_id): array
    {
        $default = $dist_id === 'sports_south' ? 'fulfillment@sportssouth.biz' : '';
        $raw = Options::get_distributor_option($dist_id, 'ffl_document_email_to', $default);
        $parts = preg_split('/[,;\s]+/', (string) $raw) ?: [];

        $emails = [];
        foreach ($parts as $part) {
            $email = sanitize_email((string) $part);
            if ($email !== '' && is_email($email)) {
                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    /**
     * @param array<int,array{upc:string,qty:int,name:string}> $items
     * @return array{0:string,1:string}
     */
    private function build_email_shape(
        string $dist_id,
        WC_Order $order,
        OrderPlacementJobRow $job,
        string $ffl_number,
        ?DistributorShipTo $ffl_ship_to,
        array $items
    ): array {
        $po = $job->merchant_po_or_empty();
        $order_number = (string) $order->get_order_number();

        if ($dist_id === 'sports_south') {
            $customer_number = trim((string) Options::get_distributor_option('sports_south', 'customer_number', ''));
            $subject_left = $customer_number !== '' ? $customer_number : 'Sports South';
            $subject = $subject_left . ' - ' . ($po !== '' ? $po : $order_number);

            $ffl_contact = $ffl_ship_to instanceof DistributorShipTo ? trim((string) $ffl_ship_to->name) : '';
            $ffl_phone = $ffl_ship_to instanceof DistributorShipTo ? trim((string) $ffl_ship_to->phone) : '';
            $body = ($ffl_contact !== '' ? $ffl_contact : 'FFL contact person')
                . ' - '
                . ($ffl_phone !== '' ? $ffl_phone : 'FFL phone number');

            return [$subject, $body];
        }

        $subject = 'FFL Document - PO ' . ($po !== '' ? $po : $order_number);
        $lines = [
            self::SUPPORTED_DISTRIBUTORS[$dist_id] . ' FFL dropship document attached.',
            '',
            'Merchant PO: ' . ($po !== '' ? $po : '-'),
            'Woo Order: #' . $order_number,
            'External Order ID(s): ' . $this->join_or_dash($job->external_order_ids()),
            'Receiving FFL Number: ' . ($ffl_number !== '' ? FFLRowMapper::normalize_ffl_number($ffl_number) : '-'),
            '',
            'Receiving FFL:',
        ];

        $lines = array_merge($lines, $this->format_ship_to_notice_lines($ffl_ship_to));
        $lines[] = '';
        $lines[] = 'Customer:';
        $lines = array_merge($lines, $this->format_order_customer_lines($order));
        $lines[] = '';
        $lines[] = 'Items:';

        foreach ($items as $item) {
            $lines[] = sprintf(
                '  - UPC: %s | Qty: %d | %s',
                (string) ($item['upc'] ?? '-'),
                (int) ($item['qty'] ?? 0),
                (string) (($item['name'] ?? '') !== '' ? $item['name'] : '-')
            );
        }

        return [$subject, implode("\n", $lines)];
    }

    private function render_job_detail(OrderPlacementJobRow $job): void
    {
        $dist_id = $job->dist_id_norm();
        if (!isset(self::SUPPORTED_DISTRIBUTORS[$dist_id])) {
            return;
        }

        $order = wc_get_order((int) $job->order_id);
        if (!($order instanceof WC_Order)) {
            return;
        }

        [$ffl_number, $ffl_ship_to] = CheckoutOrderRequestBuilder::build_ship_to_ffl_from_order_or_null($this->ffl_table, $order);
        $items = $this->build_item_rows($order, $job);
        [$subject, $body] = $this->build_email_shape($dist_id, $order, $job, (string) $ffl_number, $ffl_ship_to, $items);
        $recipients = $this->configured_recipients_for($dist_id);
        ?>
        <section class="fflhub-ffl-doc-card fflhub-ffl-doc-detail">
            <div class="fflhub-ffl-doc-detail-head">
                <div>
                    <h2>
                        <?php echo esc_html(sprintf('%s Order #%s', self::SUPPORTED_DISTRIBUTORS[$dist_id], (string) $order->get_order_number())); ?>
                    </h2>
                    <p>
                        <span class="fflhub-ffl-pill"><?php echo esc_html($job->status); ?></span>
                        <span><?php esc_html_e('Job', 'ffl-hub'); ?> <code><?php echo esc_html((string) $job->id); ?></code></span>
                        <span><?php esc_html_e('PO', 'ffl-hub'); ?> <code><?php echo esc_html($job->merchant_po_or_empty() !== '' ? $job->merchant_po_or_empty() : '-'); ?></code></span>
                    </p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>">
                    <?php esc_html_e('Close', 'ffl-hub'); ?>
                </a>
            </div>

            <div class="fflhub-ffl-doc-detail-grid">
                <div>
                    <h3><?php esc_html_e('Email', 'ffl-hub'); ?></h3>
                    <dl>
                        <dt><?php esc_html_e('To', 'ffl-hub'); ?></dt>
                        <dd><?php echo esc_html(!empty($recipients) ? implode(', ', $recipients) : 'Not configured'); ?></dd>
                        <dt><?php esc_html_e('Subject', 'ffl-hub'); ?></dt>
                        <dd><code><?php echo esc_html($subject); ?></code></dd>
                        <dt><?php esc_html_e('Body', 'ffl-hub'); ?></dt>
                        <dd><pre><?php echo esc_html($body); ?></pre></dd>
                    </dl>
                </div>

                <div>
                    <h3><?php esc_html_e('Receiving FFL', 'ffl-hub'); ?></h3>
                    <p><strong><?php echo esc_html($ffl_number !== null && $ffl_number !== '' ? FFLRowMapper::normalize_ffl_number((string) $ffl_number) : '-'); ?></strong></p>
                    <pre><?php echo esc_html(implode("\n", $this->format_ship_to_notice_lines($ffl_ship_to))); ?></pre>
                </div>
            </div>

            <div class="fflhub-ffl-doc-items">
                <h3><?php esc_html_e('Items', 'ffl-hub'); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Qty', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Product', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item) : ?>
                            <tr>
                                <td><code><?php echo esc_html((string) ($item['upc'] ?? '')); ?></code></td>
                                <td><?php echo esc_html((string) ((int) ($item['qty'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) (($item['name'] ?? '') !== '' ? $item['name'] : '-')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" enctype="multipart/form-data" class="fflhub-ffl-doc-upload-form">
                <?php wp_nonce_field(self::NONCE_ACTION . '_' . (string) ((int) $job->id), self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_ffl_document_action" value="<?php echo esc_attr(self::FORM_ACTION_SEND); ?>" />
                <input type="hidden" name="fflhub_ffl_document_job_id" value="<?php echo esc_attr((string) ((int) $job->id)); ?>" />
                <label for="fflhub-ffl-document-file">
                    <strong><?php esc_html_e('Upload FFL document', 'ffl-hub'); ?></strong>
                </label>
                <input id="fflhub-ffl-document-file" type="file" name="<?php echo esc_attr(self::FILE_FIELD); ?>" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required />
                <?php submit_button(__('Send FFL Document Email', 'ffl-hub'), 'primary', 'fflhub_send_ffl_document_submit', false); ?>
            </form>
        </section>
        <?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     */
    private function render_summary(array $jobs): void
    {
        $counts = ['sports_south' => 0, 'kinseys' => 0];
        foreach ($jobs as $job) {
            $dist_id = $job instanceof OrderPlacementJobRow ? $job->dist_id_norm() : '';
            if (isset($counts[$dist_id])) {
                $counts[$dist_id]++;
            }
        }
        ?>
        <div class="fflhub-ffl-doc-summary">
            <div><strong><?php echo esc_html((string) count($jobs)); ?></strong><span><?php esc_html_e('Open FFL drop-ship jobs', 'ffl-hub'); ?></span></div>
            <div><strong><?php echo esc_html((string) $counts['sports_south']); ?></strong><span><?php esc_html_e('Sports South', 'ffl-hub'); ?></span></div>
            <div><strong><?php echo esc_html((string) $counts['kinseys']); ?></strong><span><?php esc_html_e('Kinsey\'s', 'ffl-hub'); ?></span></div>
        </div>
        <?php
    }

    /**
     * @param OrderPlacementJobRow[] $jobs
     */
    private function render_jobs_table(array $jobs, int $selected_job_id): void
    {
        ?>
        <section class="fflhub-ffl-doc-card">
            <h2><?php esc_html_e('Open Direct-Ship FFL Jobs', 'ffl-hub'); ?></h2>
            <?php if (empty($jobs)) : ?>
                <p><?php esc_html_e('No Sports South or Kinsey\'s direct-ship FFL jobs were found on open WooCommerce orders.', 'ffl-hub'); ?></p>
            <?php else : ?>
                <table class="widefat striped fflhub-ffl-doc-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Order', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Distributor', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Job', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('PO / External', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Receiving FFL', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Items', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Last Sent', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Action', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $job) : ?>
                            <?php $this->render_job_table_row($job, $selected_job_id); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <?php
    }

    private function render_job_table_row(OrderPlacementJobRow $job, int $selected_job_id): void
    {
        $dist_id = $job->dist_id_norm();
        $order = wc_get_order((int) $job->order_id);
        $order_number = $order instanceof WC_Order ? (string) $order->get_order_number() : (string) $job->order_id;
        $ffl_number = $order instanceof WC_Order
            ? FFLRowMapper::normalize_ffl_number((string) $order->get_meta('fflhub_receiving_ffl_number', true))
            : '';
        $item_summary = $order instanceof WC_Order ? $this->compact_item_summary($order, $job) : '-';
        $last_sent = $order instanceof WC_Order ? $this->last_document_log_label($order, (int) $job->id) : '-';
        $url = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'job_id' => (int) $job->id,
            ],
            admin_url('admin.php')
        );
        ?>
        <tr class="<?php echo $selected_job_id === (int) $job->id ? 'is-selected' : ''; ?>">
            <td>
                <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $job->order_id . '&action=edit')); ?>">
                    #<?php echo esc_html($order_number); ?>
                </a>
                <?php if ($order instanceof WC_Order) : ?>
                    <span><?php echo esc_html($this->customer_name($order)); ?></span>
                <?php endif; ?>
            </td>
            <td><strong><?php echo esc_html(self::SUPPORTED_DISTRIBUTORS[$dist_id] ?? $dist_id); ?></strong></td>
            <td>
                <span class="fflhub-ffl-pill"><?php echo esc_html((string) $job->status); ?></span>
                <code><?php echo esc_html((string) $job->id); ?></code>
            </td>
            <td>
                <div><code><?php echo esc_html($job->merchant_po_or_empty() !== '' ? $job->merchant_po_or_empty() : '-'); ?></code></div>
                <small><?php echo esc_html($this->join_or_dash($job->external_order_ids())); ?></small>
            </td>
            <td><code><?php echo esc_html($ffl_number !== '' ? $ffl_number : '-'); ?></code></td>
            <td><?php echo esc_html($item_summary); ?></td>
            <td><?php echo esc_html($last_sent); ?></td>
            <td><a class="button button-secondary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Open', 'ffl-hub'); ?></a></td>
        </tr>
        <?php
    }

    /**
     * @return OrderPlacementJobRow[]
     */
    private function find_supported_direct_ship_ffl_jobs(): array
    {
        global $wpdb;

        $table = $this->jobs_table->get_table_name();

        $sql = $wpdb->prepare(
            "
            SELECT
                {$this->job_select_columns()}
            FROM {$table}
            WHERE lane = %s
              AND dist_id IN (%s, %s)
            ORDER BY updated_at DESC, id DESC
            ",
            OrderPlacementKeysUtil::LANE_DIRECT_SHIP_FFL,
            'sports_south',
            'kinseys'
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $jobs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $job = new OrderPlacementJobRow($row);
            $order = wc_get_order((int) $job->order_id);
            if (!$order instanceof WC_Order || $this->is_closed_order($order)) {
                continue;
            }

            $jobs[] = $job;
        }

        return $jobs;
    }

    private function find_job_by_id(int $job_id): ?OrderPlacementJobRow
    {
        global $wpdb;

        if ($job_id <= 0) {
            return null;
        }

        $table = $this->jobs_table->get_table_name();
        $sql = $wpdb->prepare(
            "
            SELECT
                {$this->job_select_columns()}
            FROM {$table}
            WHERE id = %d
            LIMIT 1
            ",
            $job_id
        );

        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? new OrderPlacementJobRow($row) : null;
    }

    private function job_select_columns(): string
    {
        return 'id, order_id, job_key, dist_id, lane, status,
            attempts, created_at, updated_at,
            action_id, next_run_at,
            last_step, last_error, last_codes_json,
            done_at,
            payload_json, validate_result_json, place_result_json,
            merchant_po, external_order_ids_json, external_order_id,
            shipped_at, tracking_numbers_json, invoice_numbers_json,
            last_shipping_poll_at, shipping_service, shipping_weight, shipment_raw_json';
    }

    private function is_closed_order(WC_Order $order): bool
    {
        return in_array(strtolower((string) $order->get_status()), ['completed', 'cancelled', 'refunded', 'failed', 'trash'], true);
    }

    /**
     * @return array<int,array{upc:string,qty:int,name:string}>
     */
    private function build_item_rows(WC_Order $order, OrderPlacementJobRow $job): array
    {
        $names_by_upc = $this->order_item_names_by_upc($order);
        $rows = [];

        foreach ($job->payload_lines() as $line) {
            if (!($line instanceof DistributorOrderLine)) {
                continue;
            }

            $upc = $this->normalize_upc((string) $line->upc);
            if ($upc === '') {
                continue;
            }

            $rows[] = [
                'upc' => $upc,
                'qty' => max(1, (int) $line->qty),
                'name' => (string) ($names_by_upc[$upc] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,string>
     */
    private function order_item_names_by_upc(WC_Order $order): array
    {
        $out = [];

        foreach ($order->get_items() as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            $upc = $product instanceof WC_Product && method_exists($product, 'get_global_unique_id')
                ? $this->normalize_upc((string) $product->get_global_unique_id('edit'))
                : '';
            if ($upc === '' && $product instanceof WC_Product) {
                $upc = $this->normalize_upc((string) get_post_meta((int) $product->get_id(), '_global_unique_id', true));
            }
            if ($upc === '') {
                continue;
            }

            $name = trim((string) $item->get_name());
            if ($name !== '') {
                $out[$upc] = $name;
            }
        }

        return $out;
    }

    private function compact_item_summary(WC_Order $order, OrderPlacementJobRow $job): string
    {
        $parts = [];
        foreach ($this->build_item_rows($order, $job) as $row) {
            $parts[] = (string) ($row['upc'] ?? '') . ' x' . (string) ((int) ($row['qty'] ?? 0));
        }

        return !empty($parts) ? implode(', ', $parts) : '-';
    }

    private function customer_name(WC_Order $order): string
    {
        $name = trim((string) $order->get_formatted_billing_full_name());
        if ($name === '') {
            $name = trim((string) $order->get_formatted_shipping_full_name());
        }

        return $name !== '' ? $name : __('Unknown customer', 'ffl-hub');
    }

    /**
     * @return array<int,string>
     */
    private function format_ship_to_notice_lines(?DistributorShipTo $ship): array
    {
        if (!($ship instanceof DistributorShipTo)) {
            return ['  -'];
        }

        $lines = [
            '  Name: ' . (trim($ship->name) !== '' ? trim($ship->name) : '-'),
            '  Company: ' . (trim($ship->company) !== '' ? trim($ship->company) : '-'),
            '  Address 1: ' . (trim($ship->address1) !== '' ? trim($ship->address1) : '-'),
        ];

        if (trim($ship->address2) !== '') {
            $lines[] = '  Address 2: ' . trim($ship->address2);
        }

        $lines[] = '  City/State/ZIP: ' . trim(sprintf(
            '%s, %s %s',
            trim($ship->city) !== '' ? trim($ship->city) : '-',
            trim($ship->state) !== '' ? trim($ship->state) : '-',
            trim($ship->zip) !== '' ? trim($ship->zip) : '-'
        ));
        $lines[] = '  Phone: ' . (trim($ship->phone) !== '' ? trim($ship->phone) : '-');
        $lines[] = '  Email: ' . (trim($ship->email) !== '' ? trim($ship->email) : '-');

        return $lines;
    }

    /**
     * @return array<int,string>
     */
    private function format_order_customer_lines(WC_Order $order): array
    {
        return [
            '  Name: ' . $this->customer_name($order),
            '  Phone: ' . (trim((string) $order->get_billing_phone()) !== '' ? trim((string) $order->get_billing_phone()) : '-'),
            '  Email: ' . (trim((string) $order->get_billing_email()) !== '' ? trim((string) $order->get_billing_email()) : '-'),
        ];
    }

    /**
     * @param string[] $recipients
     */
    private function record_document_email(
        WC_Order $order,
        OrderPlacementJobRow $job,
        array $recipients,
        string $subject,
        string $attachment_url,
        string $filename
    ): void {
        $log = $order->get_meta(self::ORDER_META_LOG, true);
        if (!is_array($log)) {
            $log = [];
        }

        $log[] = [
            'sent_at' => current_time('mysql'),
            'sent_at_utc' => gmdate('Y-m-d H:i:s'),
            'job_id' => (int) $job->id,
            'job_key' => (string) $job->job_key,
            'distributor_id' => $job->dist_id_norm(),
            'merchant_po' => $job->merchant_po_or_empty(),
            'recipients' => array_values($recipients),
            'subject' => $subject,
            'attachment_url' => $attachment_url,
            'filename' => $filename,
        ];

        $log = array_slice($log, -50);
        $order->update_meta_data(self::ORDER_META_LOG, $log);
        $order->add_order_note(sprintf(
            'FFL document emailed to %s for %s job #%d. Attachment: %s',
            implode(', ', $recipients),
            self::SUPPORTED_DISTRIBUTORS[$job->dist_id_norm()] ?? $job->dist_id_norm(),
            (int) $job->id,
            $filename !== '' ? $filename : '-'
        ));
        $order->save();
    }

    private function last_document_log_label(WC_Order $order, int $job_id): string
    {
        $log = $order->get_meta(self::ORDER_META_LOG, true);
        if (!is_array($log)) {
            return '-';
        }

        for ($i = count($log) - 1; $i >= 0; $i--) {
            $entry = is_array($log[$i] ?? null) ? $log[$i] : [];
            if ((int) ($entry['job_id'] ?? 0) !== $job_id) {
                continue;
            }

            $sent_at = trim((string) ($entry['sent_at'] ?? ''));
            $filename = trim((string) ($entry['filename'] ?? ''));
            return trim(($sent_at !== '' ? $sent_at : 'sent') . ($filename !== '' ? ' / ' . $filename : ''));
        }

        return '-';
    }

    /**
     * @param string[] $values
     */
    private function join_or_dash(array $values): string
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return !empty($out) ? implode(', ', array_values(array_unique($out))) : '-';
    }

    private function normalize_upc(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        return is_string($digits) ? $digits : '';
    }

    /**
     * @param array{type:string,message:string,job_id?:int}|null $notice
     */
    private function render_notice(?array $notice): void
    {
        if (!is_array($notice) || trim((string) ($notice['message'] ?? '')) === '') {
            return;
        }

        $type = (string) ($notice['type'] ?? 'info');
        $class = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        ?>
        <div class="notice notice-<?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html((string) $notice['message']); ?></p>
        </div>
        <?php
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-ffl-documents-page{max-width:1500px}
            .fflhub-ffl-doc-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:16px 0}
            .fflhub-ffl-doc-summary div{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px}
            .fflhub-ffl-doc-summary strong{display:block;font-size:26px;line-height:1.1;color:#135e96}
            .fflhub-ffl-doc-summary span{display:block;margin-top:4px;color:#50575e}
            .fflhub-ffl-doc-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:16px 0}
            .fflhub-ffl-doc-detail{border-left:4px solid #2271b1}
            .fflhub-ffl-doc-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;border-bottom:1px solid #dcdcde;padding-bottom:12px;margin-bottom:14px}
            .fflhub-ffl-doc-detail-head h2{margin:0 0 8px}
            .fflhub-ffl-doc-detail-head p{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0}
            .fflhub-ffl-doc-detail-grid{display:grid;grid-template-columns:minmax(320px,1fr) minmax(260px,420px);gap:18px}
            .fflhub-ffl-doc-detail dl{display:grid;grid-template-columns:110px 1fr;gap:8px 12px;margin:0}
            .fflhub-ffl-doc-detail dt{font-weight:700;color:#3c434a}
            .fflhub-ffl-doc-detail dd{margin:0}
            .fflhub-ffl-doc-detail pre{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px;margin:0}
            .fflhub-ffl-doc-items{margin-top:16px}
            .fflhub-ffl-doc-upload-form{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #dcdcde}
            .fflhub-ffl-doc-table td span{display:block;color:#646970;margin-top:3px}
            .fflhub-ffl-doc-table tr.is-selected td{background:#f0f6fc}
            .fflhub-ffl-pill{display:inline-block;border-radius:999px;background:#e0f2fe;color:#0c4a6e;font-size:11px;font-weight:700;text-transform:uppercase;padding:2px 8px}
            @media (max-width:900px){.fflhub-ffl-doc-detail-grid{grid-template-columns:1fr}.fflhub-ffl-doc-upload-form{align-items:stretch}.fflhub-ffl-doc-upload-form .button{width:100%}}
        </style>
        <?php
    }
}
