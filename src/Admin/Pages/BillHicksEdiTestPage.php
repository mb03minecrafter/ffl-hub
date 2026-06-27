<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOrderLine;
use FFLHub\Distributor\Models\DistributorOrderRequest;
use FFLHub\Distributor\Models\DistributorOrderResult;
use FFLHub\Distributor\Models\DistributorOrderValidationResult;
use FFLHub\Distributor\Models\DistributorShipTo;
use FFLHub\Distributor\Services\BillHicks\BillHicksFtpCredentials;
use FFLHub\Distributor\Services\Orders\Jobs\Util\OrderPlacementKeysUtil;
use FFLHub\Settings\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-only Bill Hicks EDI 850 test uploader.
 *
 * This is intentionally separate from Woo order jobs. It lets us send explicit
 * test 850 files to BHC's FTP folder without changing any real order state.
 */
final class BillHicksEdiTestPage
{
    private const PAGE_SLUG = 'fflhub-bill-hicks-edi-test-orders';
    private const NONCE_ACTION = 'fflhub_bill_hicks_edi_test_upload';
    private const ACTION_UPLOAD = 'fflhub_bill_hicks_edi_test_upload';

    private DistributorHandler $handler;

    public function __construct(DistributorHandler $handler)
    {
        $this->handler = $handler;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Bill Hicks EDI Test Orders', 'ffl-hub'),
            __('Bill Hicks EDI Tests', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $result = $this->maybe_handle_upload();
        $configs = self::test_case_configs();
        $posted_cases = $this->posted_cases();
        ?>
        <div class="wrap fflhub-bhc-edi-test">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('Bill Hicks EDI Test Orders', 'ffl-hub'); ?></h1>
            <p>
                <?php esc_html_e(
                    'Build explicit Bill Hicks test requests, run the real Bill Hicks validation/place_order path, and upload 850 test files. This page does not create Woo orders or order-job rows.',
                    'ffl-hub'
                ); ?>
            </p>

            <div class="notice notice-warning inline">
                <p>
                    <strong><?php esc_html_e('Real FTP upload:', 'ffl-hub'); ?></strong>
                    <?php esc_html_e(
                        'Submitting this form writes local 850 files and uploads them to the configured Bill Hicks /DeerfordDefense/To BHC folder.',
                        'ffl-hub'
                    ); ?>
                </p>
            </div>

            <?php $this->render_credential_summary(); ?>
            <?php $this->render_result($result); ?>

            <form method="post" action="">
                <input type="hidden" name="fflhub_bhc_edi_test_action" value="<?php echo esc_attr(self::ACTION_UPLOAD); ?>" />
                <?php wp_nonce_field(self::NONCE_ACTION, 'fflhub_bhc_edi_test_nonce'); ?>

                <?php foreach ($configs as $key => $config) : ?>
                    <?php $this->render_test_case_card($key, $config, is_array($posted_cases[$key] ?? null) ? $posted_cases[$key] : []); ?>
                <?php endforeach; ?>

                <div class="fflhub-bhc-confirm">
                    <label>
                        <input type="checkbox" name="fflhub_bhc_confirm_upload" value="1" />
                        <?php esc_html_e('I understand this will upload the enabled Bill Hicks 850 test files to FTP.', 'ffl-hub'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Each enabled test must use a PO value containing TEST.', 'ffl-hub'); ?>
                    </p>
                    <?php submit_button(__('Upload Enabled BHC Test 850 Files', 'ffl-hub'), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function test_case_configs(): array
    {
        return [
            'pistol_direct_ffl' => [
                'label' => 'Pistol FFL Drop Ship',
                'description' => 'Single firearm line. Ships to the receiving FFL and should resolve to UPSH from the BHC catalog row.',
                'lane' => 'direct_ship_ffl',
                'po_split_index' => 1,
                'line_count' => 1,
                'line_ffl_fixed' => true,
                'ship_to_label' => 'Customer / buyer contact',
                'ffl_label' => 'Receiving FFL ship-to',
                'requires_ffl_ship_to' => true,
            ],
            'long_gun_direct_ffl' => [
                'label' => 'Long Gun FFL Drop Ship',
                'description' => 'Single firearm line. Ships to the receiving FFL and should resolve to UPS from the BHC catalog row.',
                'lane' => 'direct_ship_ffl',
                'po_split_index' => 2,
                'line_count' => 1,
                'line_ffl_fixed' => true,
                'ship_to_label' => 'Customer / buyer contact',
                'ffl_label' => 'Receiving FFL ship-to',
                'requires_ffl_ship_to' => true,
            ],
            'accessory_direct_non_ffl' => [
                'label' => 'Accessory Drop Ship',
                'description' => 'Single non-FFL line. Ships to the customer and should resolve to UPSR from the BHC catalog row.',
                'lane' => 'direct_ship_non_ffl',
                'po_split_index' => 1,
                'line_count' => 1,
                'line_ffl_fixed' => false,
                'ship_to_label' => 'Customer ship-to',
                'ffl_label' => '',
                'requires_ffl_ship_to' => false,
            ],
            'dealer_fulfilled_batch' => [
                'label' => 'Dealer-Fulfilled Batch',
                'description' => 'Batch-shaped file using dealer_fulfilled lane. No receiving FFL is sent; the file ships to the dealer address entered below.',
                'lane' => 'dealer_fulfilled',
                'po_split_index' => 1,
                'line_count' => 3,
                'line_ffl_fixed' => null,
                'ship_to_label' => 'Dealer ship-to',
                'ffl_label' => '',
                'requires_ffl_ship_to' => false,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $posted
     */
    private function render_test_case_card(string $key, array $config, array $posted): void
    {
        $field = static fn(string $name): string => 'cases[' . $key . '][' . $name . ']';
        $po = $this->posted_value($posted, 'po', $this->default_test_po($config));
        $notes = $this->posted_value($posted, 'notes', 'FFLHub Bill Hicks EDI test: ' . (string) ($config['label'] ?? $key));
        $line_count = max(1, (int) ($config['line_count'] ?? 1));
        $posted_lines = is_array($posted['lines'] ?? null) ? $posted['lines'] : [];
        ?>
        <section class="fflhub-bhc-card">
            <header>
                <label class="fflhub-bhc-enable">
                    <input type="checkbox" name="<?php echo esc_attr($field('enabled')); ?>" value="1" <?php checked(!empty($posted['enabled'])); ?> />
                    <span><?php echo esc_html((string) ($config['label'] ?? $key)); ?></span>
                </label>
                <p><?php echo esc_html((string) ($config['description'] ?? '')); ?></p>
            </header>

            <div class="fflhub-bhc-grid two">
                <label>
                    <?php esc_html_e('Test PO / order id', 'ffl-hub'); ?>
                    <input type="text" name="<?php echo esc_attr($field('po')); ?>" value="<?php echo esc_attr($po); ?>" />
                </label>
                <label>
                    <?php esc_html_e('Notes', 'ffl-hub'); ?>
                    <input type="text" name="<?php echo esc_attr($field('notes')); ?>" value="<?php echo esc_attr($notes); ?>" />
                </label>
            </div>

            <h3><?php esc_html_e('Lines', 'ffl-hub'); ?></h3>
            <div class="fflhub-bhc-lines">
                <div class="fflhub-bhc-line header">
                    <span><?php esc_html_e('UPC', 'ffl-hub'); ?></span>
                    <span><?php esc_html_e('Qty', 'ffl-hub'); ?></span>
                    <?php if (($config['line_ffl_fixed'] ?? null) === null) : ?>
                        <span><?php esc_html_e('FFL item', 'ffl-hub'); ?></span>
                    <?php else : ?>
                        <span><?php esc_html_e('Line type', 'ffl-hub'); ?></span>
                    <?php endif; ?>
                </div>
                <?php for ($i = 0; $i < $line_count; $i++) : ?>
                    <?php $line_posted = is_array($posted_lines[$i] ?? null) ? $posted_lines[$i] : []; ?>
                    <div class="fflhub-bhc-line">
                        <input type="text" name="<?php echo esc_attr('cases[' . $key . '][lines][' . $i . '][upc]'); ?>" value="<?php echo esc_attr($this->posted_value($line_posted, 'upc', '')); ?>" placeholder="UPC" />
                        <input type="number" min="1" step="1" name="<?php echo esc_attr('cases[' . $key . '][lines][' . $i . '][qty]'); ?>" value="<?php echo esc_attr($this->posted_value($line_posted, 'qty', '1')); ?>" />
                        <?php if (($config['line_ffl_fixed'] ?? null) === null) : ?>
                            <label class="fflhub-bhc-inline-check">
                                <input type="checkbox" name="<?php echo esc_attr('cases[' . $key . '][lines][' . $i . '][ffl_required]'); ?>" value="1" <?php checked(!empty($line_posted['ffl_required'])); ?> />
                                <?php esc_html_e('FFL required', 'ffl-hub'); ?>
                            </label>
                        <?php else : ?>
                            <span class="fflhub-bhc-pill">
                                <?php echo !empty($config['line_ffl_fixed']) ? esc_html__('FFL required', 'ffl-hub') : esc_html__('Non-FFL', 'ffl-hub'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <?php $this->render_ship_to_fields($key, 'ship_to', (string) ($config['ship_to_label'] ?? 'Ship-to'), is_array($posted['ship_to'] ?? null) ? $posted['ship_to'] : []); ?>

            <?php if (!empty($config['requires_ffl_ship_to'])) : ?>
                <div class="fflhub-bhc-ffl-panel">
                    <label class="fflhub-bhc-ffl-number">
                        <?php esc_html_e('Receiving FFL number', 'ffl-hub'); ?>
                        <input type="text" name="<?php echo esc_attr($field('ffl_number')); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'ffl_number', '')); ?>" placeholder="5-76-000-00-0X-00000" />
                    </label>
                    <?php $this->render_ship_to_fields($key, 'ffl_ship_to', (string) ($config['ffl_label'] ?? 'Receiving FFL'), is_array($posted['ffl_ship_to'] ?? null) ? $posted['ffl_ship_to'] : []); ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * @param array<string,mixed> $posted
     */
    private function render_ship_to_fields(string $case_key, string $group, string $label, array $posted): void
    {
        $base = 'cases[' . $case_key . '][' . $group . ']';
        ?>
        <h3><?php echo esc_html($label); ?></h3>
        <div class="fflhub-bhc-grid three">
            <label>
                <?php esc_html_e('Name', 'ffl-hub'); ?>
                <input type="text" name="<?php echo esc_attr($base . '[name]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'name', '')); ?>" />
            </label>
            <label>
                <?php esc_html_e('Company', 'ffl-hub'); ?>
                <input type="text" name="<?php echo esc_attr($base . '[company]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'company', '')); ?>" />
            </label>
            <label>
                <?php esc_html_e('Phone', 'ffl-hub'); ?>
                <input type="text" name="<?php echo esc_attr($base . '[phone]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'phone', '')); ?>" />
            </label>
            <label class="wide">
                <?php esc_html_e('Address 1', 'ffl-hub'); ?>
                <input type="text" name="<?php echo esc_attr($base . '[address1]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'address1', '')); ?>" />
            </label>
            <label>
                <?php esc_html_e('Address 2', 'ffl-hub'); ?>
                <input type="text" name="<?php echo esc_attr($base . '[address2]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'address2', '')); ?>" />
            </label>
            <label>
                <?php esc_html_e('City', 'ffl-hub'); ?>
                <input type="text" name="<?php echo esc_attr($base . '[city]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'city', '')); ?>" />
            </label>
            <label>
                <?php esc_html_e('State', 'ffl-hub'); ?>
                <input type="text" maxlength="2" name="<?php echo esc_attr($base . '[state]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'state', '')); ?>" />
            </label>
            <label>
                <?php esc_html_e('ZIP', 'ffl-hub'); ?>
                <input type="text" name="<?php echo esc_attr($base . '[zip]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'zip', '')); ?>" />
            </label>
            <label class="wide">
                <?php esc_html_e('Email', 'ffl-hub'); ?>
                <input type="email" name="<?php echo esc_attr($base . '[email]'); ?>" value="<?php echo esc_attr($this->posted_value($posted, 'email', '')); ?>" />
            </label>
        </div>
        <?php
    }

    /**
     * @return array<string,mixed>|null
     */
    private function maybe_handle_upload(): ?array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        $action = isset($_POST['fflhub_bhc_edi_test_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_bhc_edi_test_action']))
            : '';
        if ($action !== self::ACTION_UPLOAD) {
            return null;
        }

        if (
            !isset($_POST['fflhub_bhc_edi_test_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['fflhub_bhc_edi_test_nonce'])), self::NONCE_ACTION)
        ) {
            return $this->error_result(['Security check failed. Refresh the page and try again.']);
        }

        if (empty($_POST['fflhub_bhc_confirm_upload'])) {
            return $this->error_result(['Upload confirmation is required before sending Bill Hicks test files.']);
        }

        if (!Options::is_distributor_enabled('bill_hicks')) {
            return $this->error_result(['Bill Hicks is disabled. Enable the distributor before sending Bill Hicks test files.']);
        }

        $distributor = $this->bill_hicks_distributor();
        if (!$distributor instanceof DistributorBase) {
            return $this->error_result(['Bill Hicks distributor is not available. Make sure the Bill Hicks module is installed and enabled.']);
        }

        $cases = isset($_POST['cases']) && is_array($_POST['cases'])
            ? wp_unslash($_POST['cases'])
            : [];
        $configs = self::test_case_configs();
        $enabled = [];
        foreach ($configs as $key => $config) {
            if (!empty($cases[$key]['enabled'])) {
                $enabled[$key] = $config;
            }
        }

        if (empty($enabled)) {
            return $this->error_result(['Enable at least one Bill Hicks EDI test case before uploading.']);
        }

        $rows = [];
        $all_ok = true;
        foreach ($enabled as $key => $config) {
            $prepared = $this->build_request_from_case($key, $config, is_array($cases[$key] ?? null) ? $cases[$key] : []);
            if (empty($prepared['ok'])) {
                $all_ok = false;
                $rows[] = $this->case_result_row($config, '', '', '', '', '', 0, false, (array) ($prepared['errors'] ?? []));
                continue;
            }

            /** @var DistributorOrderRequest $request */
            $request = $prepared['request'];

            $validation = $distributor->validate_order_request($request, true);
            if (!$validation instanceof DistributorOrderValidationResult) {
                $all_ok = false;
                $rows[] = $this->case_result_row(
                    $config,
                    $request->merchant_order_id,
                    '',
                    '',
                    '',
                    '',
                    count($request->valid_lines()),
                    false,
                    ['Bill Hicks validation returned an invalid result.'],
                    '',
                    '',
                    '',
                    ''
                );
                continue;
            }

            if (empty($validation->ok)) {
                $all_ok = false;
                $rows[] = $this->case_result_row(
                    $config,
                    $request->merchant_order_id,
                    '',
                    '',
                    '',
                    '',
                    count($request->valid_lines()),
                    false,
                    [(string) $validation->message],
                    (string) $validation->code,
                    (string) $validation->message,
                    '',
                    ''
                );
                continue;
            }

            $order_result = $distributor->place_order($request);
            if (!$order_result instanceof DistributorOrderResult) {
                $all_ok = false;
                $rows[] = $this->case_result_row(
                    $config,
                    $request->merchant_order_id,
                    '',
                    '',
                    '',
                    '',
                    count($request->valid_lines()),
                    false,
                    ['Bill Hicks place_order returned an invalid result.'],
                    (string) $validation->code,
                    (string) $validation->message,
                    '',
                    ''
                );
                continue;
            }

            $ok = !empty($order_result->ok);
            $all_ok = $all_ok && $ok;
            $details = is_array($order_result->details) ? $order_result->details : [];
            $rows[] = $this->case_result_row(
                $config,
                (string) ($details['po'] ?? $request->merchant_order_id),
                (string) ($details['filename'] ?? ''),
                (string) ($details['local_path'] ?? ''),
                (string) ($details['remote_path'] ?? ''),
                (string) ($details['ship_method'] ?? ''),
                (int) ($details['line_count'] ?? count($request->valid_lines())),
                $ok,
                $ok ? [] : [(string) $order_result->message],
                (string) $validation->code,
                (string) $validation->message,
                (string) $order_result->code,
                (string) $order_result->message
            );
        }

        return [
            'ok' => $all_ok,
            'rows' => $rows,
        ];
    }

    private function bill_hicks_distributor(): ?DistributorBase
    {
        $distributor = $this->handler->get_distributor_by_id('bill_hicks');
        return $distributor instanceof DistributorBase ? $distributor : null;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $posted
     * @return array{ok:bool,errors:string[],request?:DistributorOrderRequest,lines?:DistributorOrderLine[]}
     */
    private function build_request_from_case(string $key, array $config, array $posted): array
    {
        $errors = [];
        $po = $this->clean_text((string) ($posted['po'] ?? ''));
        if ($po === '') {
            $errors[] = 'Missing test PO for ' . (string) ($config['label'] ?? $key) . '.';
        } elseif (strpos(strtoupper($po), 'TEST') === false) {
            $errors[] = 'PO must contain TEST for ' . (string) ($config['label'] ?? $key) . '.';
        }

        $lines = $this->read_lines($posted, $config, $errors);
        $ship_to = $this->read_ship_to(is_array($posted['ship_to'] ?? null) ? $posted['ship_to'] : [], (string) ($config['ship_to_label'] ?? 'Ship-to'), $errors);
        $ffl_ship_to = null;
        $ffl_number = '';

        if (!empty($config['requires_ffl_ship_to'])) {
            $ffl_number = $this->clean_text((string) ($posted['ffl_number'] ?? ''));
            if ($ffl_number === '') {
                $errors[] = 'Missing receiving FFL number for ' . (string) ($config['label'] ?? $key) . '.';
            }
            $ffl_ship_to = $this->read_ship_to(is_array($posted['ffl_ship_to'] ?? null) ? $posted['ffl_ship_to'] : [], (string) ($config['ffl_label'] ?? 'Receiving FFL'), $errors);
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $dest_state = $ffl_ship_to instanceof DistributorShipTo ? $ffl_ship_to->state : $ship_to->state;
        $notes = $this->clean_text((string) ($posted['notes'] ?? ''));

        return [
            'ok' => true,
            'errors' => [],
            'request' => new DistributorOrderRequest(
                $lines,
                $ship_to,
                $ffl_ship_to,
                $po,
                $dest_state,
                $ffl_number,
                $notes,
                (string) ($config['lane'] ?? '')
            ),
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string,mixed> $posted
     * @param array<string,mixed> $config
     * @param string[] $errors
     * @return DistributorOrderLine[]
     */
    private function read_lines(array $posted, array $config, array &$errors): array
    {
        $raw_lines = is_array($posted['lines'] ?? null) ? $posted['lines'] : [];
        $fixed_ffl = $config['line_ffl_fixed'] ?? null;
        $lines = [];

        foreach ($raw_lines as $raw_line) {
            if (!is_array($raw_line)) {
                continue;
            }
            $upc = preg_replace('/[^0-9A-Za-z]/', '', $this->clean_text((string) ($raw_line['upc'] ?? '')));
            $upc = is_string($upc) ? $upc : '';
            if ($upc === '') {
                continue;
            }

            $qty = max(1, (int) ($raw_line['qty'] ?? 1));
            $ffl_required = ($fixed_ffl === null)
                ? !empty($raw_line['ffl_required'])
                : (bool) $fixed_ffl;
            $lines[] = new DistributorOrderLine($upc, $qty, $ffl_required);
        }

        if (empty($lines)) {
            $errors[] = 'Add at least one UPC line for ' . (string) ($config['label'] ?? 'this test') . '.';
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $errors
     */
    private function read_ship_to(array $data, string $label, array &$errors): DistributorShipTo
    {
        $name = $this->clean_text((string) ($data['name'] ?? ''));
        $company = $this->clean_text((string) ($data['company'] ?? ''));
        $address1 = $this->clean_text((string) ($data['address1'] ?? ''));
        $address2 = $this->clean_text((string) ($data['address2'] ?? ''));
        $city = $this->clean_text((string) ($data['city'] ?? ''));
        $state = strtoupper($this->clean_text((string) ($data['state'] ?? '')));
        $zip = $this->clean_text((string) ($data['zip'] ?? ''));
        $phone = $this->clean_text((string) ($data['phone'] ?? ''));
        $email = sanitize_email((string) ($data['email'] ?? ''));

        if ($name === '' && $company === '') {
            $errors[] = $label . ': enter a name or company.';
        }
        if ($address1 === '' || $city === '' || $state === '' || $zip === '') {
            $errors[] = $label . ': address 1, city, state, and ZIP are required.';
        }
        if ($state !== '' && strlen($state) !== 2) {
            $errors[] = $label . ': state should be a 2-letter abbreviation.';
        }

        return new DistributorShipTo($name, $company, $address1, $address2, $city, $state, $zip, $phone, $email);
    }

    private function clean_text(string $value): string
    {
        return trim(sanitize_text_field($value));
    }

    /**
     * Keep default test POs close to production POs:
     * FH-{DIST}-{order_id}-{lane_code}{split}
     *
     * Since there is no real Woo order id on this page, TEST occupies the
     * order-id slot while still preserving the production prefix, distributor,
     * lane code, and split suffix shape.
     *
     * @param array<string,mixed> $config
     */
    private function default_test_po(array $config): string
    {
        $lane = (string) ($config['lane'] ?? '');
        $lane_code = OrderPlacementKeysUtil::lane_code($lane);
        $split_index = max(1, (int) ($config['po_split_index'] ?? 1));

        return sprintf('FH-BILL_HICKS-TEST-%s%d', $lane_code, $split_index);
    }

    /**
     * @return array<string,mixed>
     */
    private function posted_cases(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return [];
        }

        return isset($_POST['cases']) && is_array($_POST['cases'])
            ? (array) wp_unslash($_POST['cases'])
            : [];
    }

    /**
     * @param array<string,mixed> $posted
     */
    private function posted_value(array $posted, string $key, string $default): string
    {
        if (!array_key_exists($key, $posted)) {
            return $default;
        }

        return $this->clean_text((string) $posted[$key]);
    }

    /**
     * @param array<string,mixed> $config
     * @param string[] $errors
     * @return array<string,mixed>
     */
    private function case_result_row(
        array $config,
        string $po,
        string $filename,
        string $local_path,
        string $remote_path,
        string $ship_method,
        int $line_count,
        bool $ok,
        array $errors,
        string $validation_code = '',
        string $validation_message = '',
        string $place_code = '',
        string $place_message = ''
    ): array {
        return [
            'label' => (string) ($config['label'] ?? ''),
            'po' => $po,
            'filename' => $filename,
            'local_path' => $local_path,
            'remote_path' => $remote_path,
            'ship_method' => $ship_method,
            'line_count' => $line_count,
            'ok' => $ok,
            'errors' => $errors,
            'validation_code' => $validation_code,
            'validation_message' => $validation_message,
            'place_code' => $place_code,
            'place_message' => $place_message,
        ];
    }

    /**
     * @param string[] $errors
     * @return array<string,mixed>
     */
    private function error_result(array $errors): array
    {
        return [
            'ok' => false,
            'rows' => [
                [
                    'label' => 'Request',
                    'po' => '',
                    'filename' => '',
                    'local_path' => '',
                    'remote_path' => '',
                    'ship_method' => '',
                    'line_count' => 0,
                    'ok' => false,
                    'errors' => $errors,
                    'validation_code' => '',
                    'validation_message' => '',
                    'place_code' => '',
                    'place_message' => '',
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function render_result(?array $result): void
    {
        if ($result === null) {
            return;
        }

        $ok = !empty($result['ok']);
        ?>
        <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> inline">
            <p>
                <strong>
                    <?php echo $ok ? esc_html__('Bill Hicks EDI test upload complete.', 'ffl-hub') : esc_html__('Bill Hicks EDI test upload had errors.', 'ffl-hub'); ?>
                </strong>
            </p>
        </div>
        <table class="widefat striped fflhub-bhc-results">
            <thead>
                <tr>
                    <th><?php esc_html_e('Test', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Status', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('PO', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('File', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Ship Method', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Lines', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Validation', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Place Result', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Local / Remote', 'ffl-hub'); ?></th>
                    <th><?php esc_html_e('Errors', 'ffl-hub'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ((array) ($result['rows'] ?? []) as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($row['label'] ?? '')); ?></td>
                        <td><?php echo !empty($row['ok']) ? esc_html__('OK', 'ffl-hub') : esc_html__('Failed', 'ffl-hub'); ?></td>
                        <td><?php echo esc_html((string) ($row['po'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['filename'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['ship_method'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) (int) ($row['line_count'] ?? 0)); ?></td>
                        <td>
                            <strong><?php echo esc_html((string) ($row['validation_code'] ?? '')); ?></strong><br />
                            <?php echo esc_html((string) ($row['validation_message'] ?? '')); ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html((string) ($row['place_code'] ?? '')); ?></strong><br />
                            <?php echo esc_html((string) ($row['place_message'] ?? '')); ?>
                        </td>
                        <td>
                            <code><?php echo esc_html((string) ($row['local_path'] ?? '')); ?></code><br />
                            <code><?php echo esc_html((string) ($row['remote_path'] ?? '')); ?></code>
                        </td>
                        <td><?php echo esc_html(implode('; ', (array) ($row['errors'] ?? []))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_credential_summary(): void
    {
        $loaded = BillHicksFtpCredentials::load();
        $customer_number = BillHicksFtpCredentials::edi_customer_number();
        ?>
        <div class="fflhub-bhc-status">
            <span><?php echo !empty($loaded['credentials']) ? esc_html__('FTP credentials present', 'ffl-hub') : esc_html__('FTP credentials missing', 'ffl-hub'); ?></span>
            <span><?php echo $customer_number !== '' ? esc_html__('EDI customer number present', 'ffl-hub') : esc_html__('EDI customer number missing', 'ffl-hub'); ?></span>
            <span><?php echo esc_html__('Outbound: ', 'ffl-hub') . esc_html(BillHicksFtpCredentials::edi_order_outbound_remote_dir()); ?></span>
        </div>
        <?php
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-bhc-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                margin: 18px 0;
                padding: 18px;
            }
            .fflhub-bhc-card header {
                border-bottom: 1px solid #f0f0f1;
                margin-bottom: 16px;
                padding-bottom: 10px;
            }
            .fflhub-bhc-enable {
                align-items: center;
                display: flex;
                gap: 8px;
                font-size: 17px;
                font-weight: 700;
            }
            .fflhub-bhc-grid {
                display: grid;
                gap: 12px;
                margin: 10px 0 16px;
            }
            .fflhub-bhc-grid.two {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .fflhub-bhc-grid.three {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .fflhub-bhc-grid label,
            .fflhub-bhc-line {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .fflhub-bhc-grid input,
            .fflhub-bhc-line input {
                width: 100%;
            }
            .fflhub-bhc-grid .wide {
                grid-column: span 2;
            }
            .fflhub-bhc-lines {
                border: 1px solid #e2e4e7;
                border-radius: 6px;
                margin-bottom: 16px;
                overflow: hidden;
            }
            .fflhub-bhc-line {
                align-items: center;
                display: grid;
                grid-template-columns: 1fr 90px 160px;
                gap: 10px;
                padding: 8px 10px;
            }
            .fflhub-bhc-line.header {
                background: #f6f7f7;
                color: #50575e;
                font-weight: 700;
            }
            .fflhub-bhc-inline-check {
                align-items: center;
                display: flex;
                flex-direction: row;
                gap: 6px;
            }
            .fflhub-bhc-pill,
            .fflhub-bhc-status span {
                background: #f0f6fc;
                border: 1px solid #c5d9ed;
                border-radius: 999px;
                color: #0a4b78;
                display: inline-block;
                font-size: 12px;
                padding: 3px 8px;
            }
            .fflhub-bhc-status {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin: 12px 0;
            }
            .fflhub-bhc-ffl-panel {
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                padding: 14px;
            }
            .fflhub-bhc-ffl-number {
                display: block;
                font-weight: 700;
                margin-bottom: 12px;
                max-width: 360px;
            }
            .fflhub-bhc-confirm {
                background: #fff;
                border: 1px solid #dcdcde;
                border-left: 4px solid #d63638;
                margin: 20px 0;
                padding: 16px;
            }
            .fflhub-bhc-results code {
                white-space: normal;
                word-break: break-all;
            }
            @media (max-width: 960px) {
                .fflhub-bhc-grid.two,
                .fflhub-bhc-grid.three,
                .fflhub-bhc-line {
                    grid-template-columns: 1fr;
                }
                .fflhub-bhc-grid .wide {
                    grid-column: auto;
                }
            }
        </style>
        <?php
    }
}
