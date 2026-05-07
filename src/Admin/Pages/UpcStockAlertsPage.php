<?php

namespace FFLHub\Admin\Pages;

use FFLHub\Product\StockAlerts\UpcStockAlertCronService;
use FFLHub\Product\StockAlerts\UpcStockAlertStore;

if (!defined('ABSPATH')) {
    exit;
}

final class UpcStockAlertsPage
{
    private const PAGE_SLUG = 'fflhub-upc-stock-alerts';
    private const NONCE_ACTION = 'fflhub_upc_stock_alerts_action';
    private const NONCE_FIELD = 'fflhub_upc_stock_alerts_nonce';

    private const ACTION_SAVE_SETTINGS = 'save_settings';
    private const ACTION_ADD_UPCS = 'add_upcs';
    private const ACTION_REMOVE_UPC = 'remove_upc';
    private const ACTION_RESET_UPC = 'reset_upc';
    private const ACTION_RUN_NOW = 'run_now';

    private UpcStockAlertCronService $cron_service;

    public function __construct(UpcStockAlertCronService $cron_service)
    {
        $this->cron_service = $cron_service;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('UPC Stock Alerts', 'ffl-hub'),
            __('UPC Stock Alerts', 'ffl-hub'),
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

        $this->maybe_handle_post();
        $notice = $this->read_notice();
        $watchlist = UpcStockAlertStore::get_watchlist();
        $recipients = UpcStockAlertStore::get_recipients_raw();
        $enabled = UpcStockAlertStore::is_enabled();
        ?>
        <div class="wrap fflhub-upc-stock-alerts">
            <?php $this->render_styles(); ?>
            <h1><?php esc_html_e('UPC Stock Alerts', 'ffl-hub'); ?></h1>
            <p><?php esc_html_e('Track UPCs across enabled distributors and email when they transition from out of stock or unknown to in stock.', 'ffl-hub'); ?></p>
            <?php $this->render_notice($notice); ?>
            <?php $this->render_settings_card($enabled, $recipients); ?>
            <?php $this->render_add_card(); ?>
            <?php $this->render_status_card($watchlist); ?>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'ffl-hub'));
        }

        $action = isset($_POST['fflhub_stock_alert_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_stock_alert_action']))
            : '';
        if (!in_array($action, [self::ACTION_SAVE_SETTINGS, self::ACTION_ADD_UPCS, self::ACTION_REMOVE_UPC, self::ACTION_RESET_UPC, self::ACTION_RUN_NOW], true)) {
            return;
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])), self::NONCE_ACTION)
        ) {
            $this->redirect_with_notice('error', __('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        if ($action === self::ACTION_SAVE_SETTINGS) {
            $enabled = !empty($_POST['fflhub_stock_alert_enabled']);
            $recipients = isset($_POST['fflhub_stock_alert_recipients'])
                ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_stock_alert_recipients']))
                : '';
            UpcStockAlertStore::set_enabled($enabled);
            UpcStockAlertStore::set_recipients_raw($recipients);
            $this->redirect_with_notice('success', __('UPC stock alert settings saved.', 'ffl-hub'));
        }

        if ($action === self::ACTION_ADD_UPCS) {
            $text = isset($_POST['fflhub_stock_alert_upcs'])
                ? sanitize_textarea_field(wp_unslash((string) $_POST['fflhub_stock_alert_upcs']))
                : '';
            $result = UpcStockAlertStore::add_upcs_from_text($text);
            $this->redirect_with_notice(
                'success',
                sprintf(
                    __('Added %d UPCs. Re-enabled/found %d existing UPCs.', 'ffl-hub'),
                    (int) $result['added'],
                    (int) $result['existing']
                )
            );
        }

        if ($action === self::ACTION_REMOVE_UPC) {
            $upc = isset($_POST['fflhub_stock_alert_upc'])
                ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_stock_alert_upc']))
                : '';
            $ok = UpcStockAlertStore::remove_upc($upc);
            $this->redirect_with_notice($ok ? 'success' : 'error', $ok ? __('UPC removed from watchlist.', 'ffl-hub') : __('Could not remove that UPC.', 'ffl-hub'));
        }

        if ($action === self::ACTION_RESET_UPC) {
            $upc = isset($_POST['fflhub_stock_alert_upc'])
                ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_stock_alert_upc']))
                : '';
            $ok = UpcStockAlertStore::reset_upc($upc);
            $this->redirect_with_notice($ok ? 'success' : 'error', $ok ? __('UPC alert state reset. It can alert again on the next in-stock check.', 'ffl-hub') : __('Could not reset that UPC.', 'ffl-hub'));
        }

        if ($action === self::ACTION_RUN_NOW) {
            $this->cron_service->run();
            $this->redirect_with_notice('success', __('UPC stock alert check ran.', 'ffl-hub'));
        }
    }

    private function render_settings_card(bool $enabled, string $recipients): void
    {
        ?>
        <section class="fflhub-stock-alert-card">
            <h2><?php esc_html_e('Alert Settings', 'ffl-hub'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_stock_alert_action" value="<?php echo esc_attr(self::ACTION_SAVE_SETTINGS); ?>" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable alerts', 'ffl-hub'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="fflhub_stock_alert_enabled" value="1" <?php checked($enabled); ?> />
                                    <?php esc_html_e('Run the UPC stock alert checker every 30 minutes.', 'ffl-hub'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fflhub_stock_alert_recipients"><?php esc_html_e('Email recipients', 'ffl-hub'); ?></label></th>
                            <td>
                                <input
                                    id="fflhub_stock_alert_recipients"
                                    name="fflhub_stock_alert_recipients"
                                    type="text"
                                    class="large-text"
                                    value="<?php echo esc_attr($recipients); ?>"
                                    placeholder="matthew@bickhamfirearms.com" />
                                <p class="description"><?php esc_html_e('Comma, space, or newline separated email addresses.', 'ffl-hub'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Save Alert Settings', 'ffl-hub')); ?>
            </form>
            <form method="post" action="" class="fflhub-stock-alert-inline-form">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_stock_alert_action" value="<?php echo esc_attr(self::ACTION_RUN_NOW); ?>" />
                <button type="submit" class="button button-secondary"><?php esc_html_e('Run Check Now', 'ffl-hub'); ?></button>
                <span class="description"><?php echo esc_html($this->next_scheduled_label()); ?></span>
            </form>
        </section>
        <?php
    }

    private function render_add_card(): void
    {
        ?>
        <section class="fflhub-stock-alert-card">
            <h2><?php esc_html_e('Add UPCs', 'ffl-hub'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_stock_alert_action" value="<?php echo esc_attr(self::ACTION_ADD_UPCS); ?>" />
                <textarea
                    name="fflhub_stock_alert_upcs"
                    rows="5"
                    class="large-text code"
                    placeholder="<?php esc_attr_e('One UPC per line, or paste a comma-separated list.', 'ffl-hub'); ?>"></textarea>
                <p class="description"><?php esc_html_e('The first successful in-stock check for a watched UPC sends an email, then it stays quiet until the UPC goes out of stock and comes back again.', 'ffl-hub'); ?></p>
                <?php submit_button(__('Add UPCs to Watchlist', 'ffl-hub')); ?>
            </form>
        </section>
        <?php
    }

    /**
     * @param array<string,array<string,mixed>> $watchlist
     */
    private function render_status_card(array $watchlist): void
    {
        ?>
        <section class="fflhub-stock-alert-card">
            <h2><?php esc_html_e('Watched UPCs', 'ffl-hub'); ?></h2>
            <?php if (empty($watchlist)) : ?>
                <p><?php esc_html_e('No UPCs are being watched yet.', 'ffl-hub'); ?></p>
            <?php else : ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('UPC', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Status', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Qty', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Distributors', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Product', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Best Price', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Last Checked', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Last Alert', 'ffl-hub'); ?></th>
                            <th><?php esc_html_e('Actions', 'ffl-hub'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($watchlist as $row) : ?>
                            <?php
                            $upc = (string) ($row['upc'] ?? '');
                            $status = $this->status_label($row['last_in_stock'] ?? null);
                            $status_class = $this->status_class($row['last_in_stock'] ?? null, (string) ($row['last_error'] ?? ''));
                            $qty = $row['last_quantity'];
                            $price = $row['last_price'];
                            $error = trim((string) ($row['last_error'] ?? ''));
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($upc); ?></code></td>
                                <td>
                                    <span class="fflhub-stock-alert-pill <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status); ?></span>
                                    <?php if ($error !== '') : ?>
                                        <p class="fflhub-stock-alert-error"><?php echo esc_html($error); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($qty === null ? '-' : (string) ((int) $qty)); ?></td>
                                <td><?php echo esc_html((string) (($row['last_distributors'] ?? '') !== '' ? $row['last_distributors'] : '-')); ?></td>
                                <td><?php echo esc_html((string) (($row['last_product_name'] ?? '') !== '' ? $row['last_product_name'] : '-')); ?></td>
                                <td><?php echo esc_html($price === null ? '-' : '$' . number_format((float) $price, 2)); ?></td>
                                <td><?php echo esc_html((string) (($row['last_checked_at'] ?? '') !== '' ? $row['last_checked_at'] . ' UTC' : '-')); ?></td>
                                <td><?php echo esc_html((string) (($row['last_notified_at'] ?? '') !== '' ? $row['last_notified_at'] . ' UTC' : '-')); ?></td>
                                <td>
                                    <form method="post" action="" class="fflhub-stock-alert-row-action">
                                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                                        <input type="hidden" name="fflhub_stock_alert_action" value="<?php echo esc_attr(self::ACTION_RESET_UPC); ?>" />
                                        <input type="hidden" name="fflhub_stock_alert_upc" value="<?php echo esc_attr($upc); ?>" />
                                        <button type="submit" class="button button-small"><?php esc_html_e('Reset', 'ffl-hub'); ?></button>
                                    </form>
                                    <form method="post" action="" class="fflhub-stock-alert-row-action">
                                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                                        <input type="hidden" name="fflhub_stock_alert_action" value="<?php echo esc_attr(self::ACTION_REMOVE_UPC); ?>" />
                                        <input type="hidden" name="fflhub_stock_alert_upc" value="<?php echo esc_attr($upc); ?>" />
                                        <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Remove', 'ffl-hub'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
        <?php
    }

    private function status_label($last_in_stock): string
    {
        if ($last_in_stock === null) {
            return __('Unknown', 'ffl-hub');
        }

        return !empty($last_in_stock) ? __('In stock', 'ffl-hub') : __('Out of stock', 'ffl-hub');
    }

    private function status_class($last_in_stock, string $error): string
    {
        if ($error !== '') {
            return 'is-error';
        }
        if ($last_in_stock === null) {
            return 'is-unknown';
        }

        return !empty($last_in_stock) ? 'is-in-stock' : 'is-out-of-stock';
    }

    private function next_scheduled_label(): string
    {
        if (!function_exists('as_next_scheduled_action')) {
            return __('Action Scheduler is not available yet.', 'ffl-hub');
        }

        $next = as_next_scheduled_action(
            UpcStockAlertCronService::CRON_HOOK,
            [],
            'fflhub_stock_alerts'
        );
        if ($next === false) {
            return __('No next Action Scheduler run is currently scheduled.', 'ffl-hub');
        }

        return sprintf(__('Next scheduled check: %s', 'ffl-hub'), date_i18n('M j, Y g:i A T', (int) $next));
    }

    private function read_notice(): ?array
    {
        $type = isset($_GET['fflhub_stock_alert_notice'])
            ? sanitize_text_field(wp_unslash((string) $_GET['fflhub_stock_alert_notice']))
            : '';
        $message = isset($_GET['fflhub_stock_alert_message'])
            ? sanitize_text_field(wp_unslash((string) $_GET['fflhub_stock_alert_message']))
            : '';
        if ($type === '' || $message === '') {
            return null;
        }

        return [
            'type' => $type === 'error' ? 'error' : 'success',
            'message' => $message,
        ];
    }

    private function render_notice(?array $notice): void
    {
        if (empty($notice)) {
            return;
        }

        $class = ((string) ($notice['type'] ?? 'success') === 'error') ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p></div>';
    }

    private function redirect_with_notice(string $type, string $message): void
    {
        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE_SLUG,
            'fflhub_stock_alert_notice' => $type === 'error' ? 'error' : 'success',
            'fflhub_stock_alert_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    private function render_styles(): void
    {
        ?>
        <style>
            .fflhub-stock-alert-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin:14px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .fflhub-stock-alert-card h2{margin-top:0}
            .fflhub-stock-alert-inline-form{display:flex;align-items:center;gap:10px;margin-top:10px}
            .fflhub-stock-alert-row-action{display:inline-block;margin:0 6px 4px 0}
            .fflhub-stock-alert-pill{display:inline-block;border-radius:999px;padding:2px 8px;font-size:12px;font-weight:600;line-height:1.6;background:#f6f7f7;color:#1d2327}
            .fflhub-stock-alert-pill.is-in-stock{background:#dcfce7;color:#166534}
            .fflhub-stock-alert-pill.is-out-of-stock{background:#fee2e2;color:#991b1b}
            .fflhub-stock-alert-pill.is-unknown{background:#e0f2fe;color:#075985}
            .fflhub-stock-alert-pill.is-error{background:#fef3c7;color:#92400e}
            .fflhub-stock-alert-error{margin:6px 0 0;color:#92400e;font-size:12px}
        </style>
        <?php
    }
}
