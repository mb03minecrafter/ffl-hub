<?php

namespace FFLHub\Checkout\Notice;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shows a checkout warning when the customer destination appears to be CA.
 */
final class CaliforniaRelayNotice
{
    private const STYLE_HANDLE = 'fflhub-ca-relay-checkout-notice';
    private const SCRIPT_HANDLE = 'fflhub-ca-relay-checkout-notice';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_footer', [__CLASS__, 'render_notice_shell']);
    }

    public static function enqueue_assets(): void
    {
        if (!self::is_checkout_context()) {
            return;
        }

        wp_register_style(self::STYLE_HANDLE, false, [], '1.0.0');
        wp_enqueue_style(self::STYLE_HANDLE);
        wp_add_inline_style(self::STYLE_HANDLE, self::css());

        wp_register_script(self::SCRIPT_HANDLE, false, [], '1.0.0', true);
        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(self::SCRIPT_HANDLE, self::js());
    }

    public static function render_notice_shell(): void
    {
        if (!self::is_checkout_context()) {
            return;
        }

        $visible = self::current_customer_state_is_ca();
        ?>
        <div
            id="fflhub-ca-relay-checkout-notice"
            class="fflhub-ca-relay-checkout-notice"
            <?php echo $visible ? '' : 'hidden'; ?>>
            <?php echo esc_html(self::message()); ?>
        </div>
        <?php
    }

    private static function is_checkout_context(): bool
    {
        return function_exists('is_checkout')
            && is_checkout()
            && (!function_exists('is_order_received_page') || !is_order_received_page());
    }

    private static function current_customer_state_is_ca(): bool
    {
        if (!function_exists('WC') || !WC() || !WC()->customer) {
            return false;
        }

        $shipping_state = strtoupper(trim((string) WC()->customer->get_shipping_state()));
        $billing_state = strtoupper(trim((string) WC()->customer->get_billing_state()));

        return $shipping_state === 'CA' || ($shipping_state === '' && $billing_state === 'CA');
    }

    private static function message(): string
    {
        return 'California orders may take longer to ship due to CA restrictions and the need to ship eligible items from a separate warehouse before final delivery.';
    }

    private static function css(): string
    {
        return <<<'CSS'
.fflhub-ca-relay-checkout-notice {
    margin: 16px 0;
    padding: 12px 14px;
    border: 1px solid #d63638;
    border-left-width: 5px;
    border-radius: 6px;
    background: #fff5f5;
    color: #b00020;
    font-weight: 700;
    line-height: 1.4;
}
CSS;
    }

    private static function js(): string
    {
        $message = wp_json_encode(self::message());
        if (!is_string($message) || $message === '') {
            $message = '""';
        }

        return <<<JS
(function () {
    const message = {$message};
    const noticeId = "fflhub-ca-relay-checkout-notice";

    function fieldValue(selectors) {
        for (const selector of selectors) {
            const fields = Array.prototype.slice.call(document.querySelectorAll(selector));
            for (const field of fields) {
                if (!field || field.disabled) {
                    continue;
                }
                const value = String(field.value || "").trim().toUpperCase();
                if (value) {
                    return value;
                }
            }
        }
        return "";
    }

    function destinationState() {
        const shipping = fieldValue([
            "#shipping_state",
            "[name='shipping_state']",
            "[name='shipping[state]']",
            "[name='shippingAddress[state]']"
        ]);
        if (shipping) {
            return shipping;
        }

        const billing = fieldValue([
            "#billing_state",
            "[name='billing_state']",
            "[name='billing[state]']",
            "[name='billingAddress[state]']"
        ]);
        if (billing) {
            return billing;
        }

        return fieldValue([
            "select[id$='-state']",
            "input[id$='-state']",
            "select[name='state']",
            "input[name='state']"
        ]);
    }

    function ensureNotice() {
        let notice = document.getElementById(noticeId);

        const target = document.querySelector(".woocommerce-checkout-review-order")
            || document.querySelector("form.checkout")
            || document.querySelector(".wc-block-checkout")
            || document.querySelector("main")
            || document.body;

        if (!notice) {
            notice = document.createElement("div");
            notice.id = noticeId;
            notice.className = "fflhub-ca-relay-checkout-notice";
            notice.textContent = message;
            notice.hidden = true;
        }

        if (notice.parentElement !== target || notice !== target.firstChild) {
            target.insertBefore(notice, target.firstChild);
        }

        return notice;
    }

    function updateNotice() {
        const notice = ensureNotice();
        notice.textContent = message;
        notice.hidden = destinationState() !== "CA";
    }

    document.addEventListener("change", updateNotice, true);
    document.addEventListener("input", updateNotice, true);
    document.addEventListener("DOMContentLoaded", updateNotice);

    if (window.jQuery) {
        window.jQuery(document.body).on("updated_checkout", updateNotice);
    }

    const observer = new MutationObserver(function () {
        window.clearTimeout(observer._fflhubTimer);
        observer._fflhubTimer = window.setTimeout(updateNotice, 100);
    });
    observer.observe(document.body, { childList: true, subtree: true });

    updateNotice();
})();
JS;
    }
}
