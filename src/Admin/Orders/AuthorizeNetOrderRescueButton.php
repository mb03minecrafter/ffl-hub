<?php

namespace FFLHub\Admin\Orders;

use FFLHub\Order\AuthorizeNetOrderRescueService;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthorizeNetOrderRescueButton
{
    private const ACTION = 'fflhub_rescue_authnet_order';
    private const NONCE_ACTION = 'fflhub_rescue_authnet_order';
    private const NOTICE_TRANSIENT_PREFIX = 'fflhub_authnet_rescue_notice_';

    private AuthorizeNetOrderRescueService $service;

    public function __construct(?AuthorizeNetOrderRescueService $service = null)
    {
        $this->service = $service ?? new AuthorizeNetOrderRescueService();
    }

    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_button'], 20, 1);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_post']);
        add_action('admin_notices', [$this, 'render_notice']);
    }

    /**
     * @param mixed $order
     */
    public function render_button($order): void
    {
        if (!($order instanceof WC_Order)) {
            return;
        }

        if (!$this->can_manage_order((int) $order->get_id())) {
            return;
        }

        if (!$this->service->would_show_for_order($order)) {
            return;
        }

        $order_id = (int) $order->get_id();
        $action_url = wp_nonce_url(
            add_query_arg(
                [
                    'action' => self::ACTION,
                    'order_id' => $order_id,
                ],
                admin_url('admin-post.php')
            ),
            self::NONCE_ACTION . '_' . $order_id
        );
        $transaction_id = trim((string) $order->get_transaction_id());
        if ($transaction_id === '') {
            $transaction_id = trim((string) $order->get_meta('_authnet_charge_id', true));
        }

        ?>
        <div class="fflhub-authnet-rescue" style="clear:both;margin:12px 0;padding:10px;border:1px solid #dcdcde;background:#fff;">
            <p style="margin:0 0 8px;">
                <strong><?php esc_html_e('FFLHub Authorize.net Rescue', 'ffl-hub'); ?></strong><br />
                <span><?php esc_html_e('Use after manually approving/capturing an Authorize.net hold. This bypasses the gateway capture hook, sets the order to Processing, and schedules FFLHub order-placement rows only.', 'ffl-hub'); ?></span>
            </p>
            <?php if ($transaction_id !== '') : ?>
                <p style="margin:0 0 8px;">
                    <?php esc_html_e('Transaction ID:', 'ffl-hub'); ?>
                    <code><?php echo esc_html($transaction_id); ?></code>
                </p>
            <?php endif; ?>
            <a
                href="<?php echo esc_url($action_url); ?>"
                class="button button-primary"
                onclick="return confirm('Rescue this Authorize.net order and schedule FFLHub job rows? This will not flush dealer batches.');"
            >
                <?php esc_html_e('Rescue Auth.net Order + Schedule FFLHub Jobs', 'ffl-hub'); ?>
            </a>
        </div>
        <?php
    }

    public function handle_post(): void
    {
        $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
        if ($order_id <= 0) {
            $this->redirect_to_orders('error', __('Missing order ID.', 'ffl-hub'));
        }

        if (!$this->can_manage_order($order_id)) {
            wp_die(esc_html__('You are not allowed to rescue this order.', 'ffl-hub'));
        }

        check_admin_referer(self::NONCE_ACTION . '_' . $order_id);

        $transaction_id = isset($_REQUEST['transaction_id'])
            ? sanitize_text_field(wp_unslash((string) $_REQUEST['transaction_id']))
            : '';

        try {
            $result = $this->service->rescue($order_id, [
                'transaction_id' => $transaction_id,
                'target_status' => 'processing',
            ]);

            $message = sprintf(
                __('Auth.net rescue complete. Order moved %1$s -> %2$s and FFLHub job rows were scheduled/queued. No dealer batch flush was run.', 'ffl-hub'),
                (string) ($result['before_status'] ?? ''),
                (string) ($result['after_status'] ?? '')
            );
            $this->redirect_to_order($order_id, 'success', $message);
        } catch (\Throwable $e) {
            $this->redirect_to_order($order_id, 'error', $e->getMessage());
        }
    }

    public function render_notice(): void
    {
        $notice = get_transient($this->notice_transient_key());
        if (!is_array($notice)) {
            return;
        }

        delete_transient($this->notice_transient_key());

        $type = strtolower(trim((string) ($notice['type'] ?? 'success')));
        $class = $type === 'error' ? 'notice-error' : 'notice-success';
        $message = trim((string) ($notice['message'] ?? ''));
        if ($message === '') {
            return;
        }

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function can_manage_order(int $order_id): bool
    {
        return current_user_can('manage_woocommerce')
            || current_user_can('edit_shop_orders')
            || ($order_id > 0 && current_user_can('edit_post', $order_id));
    }

    private function redirect_to_order(int $order_id, string $type, string $message): void
    {
        $this->store_notice($type, $message);

        $order = wc_get_order($order_id);
        $url = ($order instanceof WC_Order)
            ? $order->get_edit_order_url()
            : admin_url('edit.php?post_type=shop_order');

        wp_safe_redirect($url);
        exit;
    }

    private function redirect_to_orders(string $type, string $message): void
    {
        $this->store_notice($type, $message);
        wp_safe_redirect(admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    private function store_notice(string $type, string $message): void
    {
        set_transient(
            $this->notice_transient_key(),
            [
                'type' => $type === 'error' ? 'error' : 'success',
                'message' => $message,
            ],
            60
        );
    }

    private function notice_transient_key(): string
    {
        return self::NOTICE_TRANSIENT_PREFIX . (int) get_current_user_id();
    }
}
