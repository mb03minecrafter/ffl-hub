<?php

namespace FFLHub\Order;

use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rescues manually-approved Authorize.net orders without letting the gateway
 * retry capture on the WooCommerce status transition.
 */
final class AuthorizeNetOrderRescueService
{
    private const AUTHNET_CAPTURED_META = '_authnet_charge_captured';
    private const AUTHNET_CHARGE_ID_META = '_authnet_charge_id';
    private const AUTHNET_CAPTURE_FAILED_META = '_authnet_capture_failed';
    private const AUTHNET_FDS_HOLD_META = '_authnet_fds_hold';
    private const AUTHNET_PAYMENT_ID_META = 'Authorize.net Payment ID';
    private const PIPELINE_LOCK_META = '_fflhub_order_place_pipeline_lock';
    private const PIPELINE_STARTED_META = 'fflhub_place_pipeline_started';

    /**
     * @param array{
     *   transaction_id?:string,
     *   target_status?:string,
     *   force?:bool,
     *   skip_status?:bool,
     *   skip_pipeline?:bool,
     *   clear_pipeline_lock?:bool,
     *   note?:string
     * } $args
     * @return array<string,mixed>
     */
    public function rescue(int $order_id, array $args = []): array
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            throw new \InvalidArgumentException('Missing order ID.');
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            throw new \RuntimeException('Order not found.');
        }

        $force = !empty($args['force']);
        $payment_method = (string) $order->get_payment_method();
        if ($payment_method !== 'authnet' && !$force) {
            throw new \RuntimeException("Order payment method is '{$payment_method}', not 'authnet'.");
        }

        $target_status = sanitize_key((string) ($args['target_status'] ?? 'processing'));
        if ($target_status === '') {
            $target_status = 'processing';
        }

        $skip_status = !empty($args['skip_status']);
        $skip_pipeline = !empty($args['skip_pipeline']);
        $clear_pipeline_lock = !empty($args['clear_pipeline_lock']) || $this->has_stale_pipeline_lock($order);

        $transaction_id = trim((string) ($args['transaction_id'] ?? ''));
        if ($transaction_id === '') {
            $transaction_id = $this->existing_transaction_id($order);
        }

        $before_status = (string) $order->get_status();

        $this->disable_authnet_capture_for_order($order_id);

        if ($clear_pipeline_lock) {
            delete_post_meta($order_id, self::PIPELINE_LOCK_META);
        }

        $order->update_meta_data(self::AUTHNET_CAPTURED_META, 'yes');
        $order->update_meta_data(self::AUTHNET_FDS_HOLD_META, 'no');
        $order->delete_meta_data(self::AUTHNET_CAPTURE_FAILED_META);

        if ($transaction_id !== '') {
            $order->set_transaction_id($transaction_id);
            $order->update_meta_data(self::AUTHNET_PAYMENT_ID_META, $transaction_id);
        }

        if (method_exists($order, 'get_date_paid') && !$order->get_date_paid()) {
            $order->set_date_paid(time());
        }

        $order->save();

        $status_changed = false;
        if (!$skip_status && $order->get_status() !== $target_status) {
            $order->update_status($target_status, $this->status_note($args), true);
            $status_changed = true;
        }

        if (!$skip_pipeline) {
            do_action('fflhub_ordering_force_start', $order_id);
        }

        $order = wc_get_order($order_id);
        $after_status = $order instanceof WC_Order ? (string) $order->get_status() : $target_status;

        return [
            'order_id' => $order_id,
            'payment_method' => $payment_method,
            'before_status' => $before_status,
            'after_status' => $after_status,
            'status_changed' => $status_changed,
            'transaction_id' => $transaction_id,
            'pipeline_started' => !$skip_pipeline,
            'pipeline_lock_cleared' => $clear_pipeline_lock,
        ];
    }

    public function would_show_for_order(WC_Order $order): bool
    {
        return (string) $order->get_payment_method() === 'authnet';
    }

    private function disable_authnet_capture_for_order(int $order_id): void
    {
        add_filter(
            'wc_authnet_capture_on_status_change',
            static function ($capture, $order = null, $status_transition = []) use ($order_id) {
                unset($status_transition);

                if ($order instanceof WC_Order && (int) $order->get_id() === $order_id) {
                    return false;
                }

                return $capture;
            },
            0,
            3
        );
    }

    private function existing_transaction_id(WC_Order $order): string
    {
        $transaction_id = trim((string) $order->get_transaction_id());
        if ($transaction_id !== '') {
            return $transaction_id;
        }

        return trim((string) $order->get_meta(self::AUTHNET_CHARGE_ID_META, true));
    }

    private function has_stale_pipeline_lock(WC_Order $order): bool
    {
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return false;
        }

        $lock = (string) get_post_meta($order_id, self::PIPELINE_LOCK_META, true);
        if ($lock === '') {
            return false;
        }

        return (string) $order->get_meta(self::PIPELINE_STARTED_META, true) !== '1';
    }

    /**
     * @param array<string,mixed> $args
     */
    private function status_note(array $args): string
    {
        $note = trim((string) ($args['note'] ?? ''));
        if ($note !== '') {
            return $note;
        }

        return 'FFLHub Auth.net rescue: transaction manually approved/captured in Authorize.net; gateway status-capture bypassed for this status change.';
    }
}
