<?php

namespace FFLHub\Checkout;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defaults Klaviyo's WooCommerce checkout email consent checkbox to on.
 *
 * Klaviyo renders classic checkout consent as a normal Woo field named
 * kl_newsletter_checkbox. For Checkout Blocks, its React block submits consent
 * as Store API extension data under extensions.klaviyo.newsletter.
 */
final class KlaviyoDefaultEmailOptIn
{
    private const SCRIPT_HANDLE = 'fflhub-klaviyo-default-email-opt-in';

    public static function init(): void
    {
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'default_classic_checkout_field'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_script'], 25);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function default_classic_checkout_field(array $fields): array
    {
        if (!self::is_klaviyo_email_checkout_enabled()) {
            return $fields;
        }

        if (isset($fields['billing']['kl_newsletter_checkbox']) && is_array($fields['billing']['kl_newsletter_checkbox'])) {
            $fields['billing']['kl_newsletter_checkbox']['default'] = 1;
            $fields['billing']['kl_newsletter_checkbox']['value'] = true;
        }

        return $fields;
    }

    public static function enqueue_script(): void
    {
        if (!self::is_checkout_context() || !self::is_klaviyo_email_checkout_enabled()) {
            return;
        }

        wp_register_script(self::SCRIPT_HANDLE, false, [], FFLHUB_PLUGIN_VERSION, true);
        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(self::SCRIPT_HANDLE, self::script());
    }

    private static function is_checkout_context(): bool
    {
        return function_exists('is_checkout')
            && is_checkout()
            && (!function_exists('is_order_received_page') || !is_order_received_page());
    }

    private static function is_klaviyo_email_checkout_enabled(): bool
    {
        $settings = get_option('klaviyo_settings');

        if (!is_array($settings)) {
            return false;
        }

        return self::is_truthy_option($settings['klaviyo_subscribe_checkbox'] ?? false);
    }

    /**
     * @param mixed $value
     */
    private static function is_truthy_option($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return $normalized !== '' && $normalized !== '0' && $normalized !== 'false' && $normalized !== 'no';
        }

        return !empty($value);
    }

    private static function script(): string
    {
        $settings = get_option('klaviyo_settings');
        $newsletter_text = is_array($settings) ? (string) ($settings['klaviyo_newsletter_text'] ?? '') : '';
        $newsletter_text_json = wp_json_encode($newsletter_text);
        if (!is_string($newsletter_text_json)) {
            $newsletter_text_json = '""';
        }

        return <<<JS
(function () {
    let programmatic = false;
    let customerOptedOut = false;
    let klaviyoNewsletterDetected = false;
    let timer = 0;
    const configuredNewsletterText = normalize({$newsletter_text_json});

    function normalize(value) {
        return String(value || "").replace(/\\s+/g, " ").trim().toLowerCase();
    }

    function isTruthy(value) {
        if (value === true || value === 1) {
            return true;
        }

        if (typeof value === "string") {
            const normalized = normalize(value);
            return normalized !== "" && normalized !== "0" && normalized !== "false" && normalized !== "no";
        }

        return !!value;
    }

    function getLabelForCheckbox(checkbox) {
        if (!checkbox) {
            return null;
        }

        const wrappingLabel = checkbox.closest && checkbox.closest("label");
        if (wrappingLabel) {
            return wrappingLabel;
        }

        if (checkbox.id && document.querySelectorAll) {
            const labels = Array.prototype.slice.call(document.querySelectorAll("label[for]"));
            for (const label of labels) {
                if (label.getAttribute("for") === checkbox.id) {
                    return label;
                }
            }
        }

        return null;
    }

    function isKlaviyoNewsletterLabel(label) {
        const text = normalize(label ? label.textContent : "");

        if (!text) {
            return false;
        }

        if (
            text.indexOf("sms") !== -1
            || text.indexOf("text message") !== -1
            || text.indexOf("phone") !== -1
            || text.indexOf("terms") !== -1
            || text.indexOf("privacy") !== -1
            || text.indexOf("ship") !== -1
        ) {
            return false;
        }

        if (configuredNewsletterText && text.indexOf(configuredNewsletterText) !== -1) {
            return true;
        }

        return (
            text.indexOf("email") !== -1
            || text.indexOf("newsletter") !== -1
            || text.indexOf("email updates") !== -1
        ) && (
            text.indexOf("subscribe") !== -1
            || text.indexOf("sign me up") !== -1
            || text.indexOf("sign up") !== -1
            || text.indexOf("updates") !== -1
            || text.indexOf("news") !== -1
        );
    }

    function findNewsletterCheckbox(root) {
        const scope = root && root.querySelectorAll ? root : document;
        const classic = scope.querySelector("input[type='checkbox'][name='kl_newsletter_checkbox'], #kl_newsletter_checkbox");

        if (classic && !classic.disabled) {
            klaviyoNewsletterDetected = true;
            return classic;
        }

        const labels = Array.prototype.slice.call(scope.querySelectorAll(".kl_newsletter_checkbox_field label, .wc-block-components-checkbox label, label"));

        for (const label of labels) {
            if (!isKlaviyoNewsletterLabel(label)) {
                continue;
            }

            const checkbox = label.querySelector("input[type='checkbox']");
            if (checkbox && !checkbox.disabled) {
                klaviyoNewsletterDetected = true;
                return checkbox;
            }
        }

        const checkboxes = Array.prototype.slice.call(scope.querySelectorAll("input[type='checkbox']"));
        for (const checkbox of checkboxes) {
            const marker = normalize([checkbox.name, checkbox.id, checkbox.className].join(" "));
            const label = getLabelForCheckbox(checkbox);

            if (
                !checkbox.disabled
                && (
                    marker.indexOf("kl_newsletter_checkbox") !== -1
                    || (marker.indexOf("klaviyo") !== -1 && isKlaviyoNewsletterLabel(label))
                )
            ) {
                klaviyoNewsletterDetected = true;
                return checkbox;
            }
        }

        return null;
    }

    function eventCheckbox(event) {
        if (!event || !event.target) {
            return null;
        }

        const target = event.target;
        let checkbox = target.matches && target.matches("input[type='checkbox']")
            ? target
            : null;

        if (!checkbox && target.closest) {
            const label = target.closest("label");
            checkbox = label ? label.querySelector("input[type='checkbox']") : null;
        }

        if (!checkbox) {
            return null;
        }

        if (checkbox.name === "kl_newsletter_checkbox") {
            klaviyoNewsletterDetected = true;
            return checkbox;
        }

        const label = getLabelForCheckbox(checkbox);
        if (!isKlaviyoNewsletterLabel(label)) {
            return null;
        }

        klaviyoNewsletterDetected = true;
        return checkbox;
    }

    function setCustomerOptedOut(value) {
        customerOptedOut = !!value;
    }

    function setCheckedWithNativeEvents(checkbox) {
        const descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "checked");

        if (descriptor && typeof descriptor.set === "function") {
            descriptor.set.call(checkbox, true);
        } else {
            checkbox.checked = true;
        }

        checkbox.dispatchEvent(new Event("input", { bubbles: true }));
        checkbox.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function defaultOptIn() {
        if (customerOptedOut) {
            return;
        }

        const checkbox = findNewsletterCheckbox(document);
        if (!checkbox || checkbox.checked) {
            return;
        }

        programmatic = true;

        try {
            checkbox.click();

            window.setTimeout(function () {
                if (!checkbox.checked && !customerOptedOut) {
                    setCheckedWithNativeEvents(checkbox);
                }
            }, 0);
        } finally {
            window.setTimeout(function () {
                programmatic = false;
            }, 75);
        }
    }

    function scheduleDefaultOptIn() {
        window.clearTimeout(timer);
        timer = window.setTimeout(defaultOptIn, 100);
    }

    function shouldOptInForRequest() {
        return !customerOptedOut;
    }

    function isCheckoutEndpoint(url) {
        return typeof url === "string"
            && url.indexOf("/wc/store/v1/checkout") !== -1;
    }

    function isKlaviyoNewsletterAvailable() {
        if (klaviyoNewsletterDetected) {
            return true;
        }

        try {
            const settings = window.wc
                && window.wc.wcSettings
                && typeof window.wc.wcSettings.getSetting === "function"
                    ? window.wc.wcSettings.getSetting("klaviyo_checkout_block_data", {})
                    : null;

            return !!(settings && isTruthy(settings.newsletterEnabled));
        } catch (error) {
            return false;
        }
    }

    function forceKlaviyoNewsletterPayload(rawBody) {
        if (!isKlaviyoNewsletterAvailable() || typeof rawBody !== "string" || rawBody === "") {
            return rawBody;
        }

        try {
            const payload = JSON.parse(rawBody);
            payload.extensions = payload.extensions && typeof payload.extensions === "object"
                ? payload.extensions
                : {};
            payload.extensions.klaviyo = payload.extensions.klaviyo && typeof payload.extensions.klaviyo === "object"
                ? payload.extensions.klaviyo
                : {};
            payload.extensions.klaviyo.newsletter = shouldOptInForRequest();

            return JSON.stringify(payload);
        } catch (error) {
            return rawBody;
        }
    }

    function patchFetchCheckoutPayloads() {
        if (!window.fetch || window.fetch.fflhubKlaviyoEmailOptInPatched) {
            return;
        }

        const originalFetch = window.fetch;
        const patchedFetch = function (input, init) {
            const requestUrl = typeof input === "string"
                ? input
                : input && typeof input.url === "string"
                    ? input.url
                    : "";

            if (isCheckoutEndpoint(requestUrl) && init && typeof init.body === "string") {
                init = Object.assign({}, init, {
                    body: forceKlaviyoNewsletterPayload(init.body),
                });
            }

            return originalFetch.call(this, input, init);
        };

        patchedFetch.fflhubKlaviyoEmailOptInPatched = true;
        window.fetch = patchedFetch;
    }

    function patchXhrCheckoutPayloads() {
        if (!window.XMLHttpRequest || window.XMLHttpRequest.prototype.fflhubKlaviyoEmailOptInPatched) {
            return;
        }

        const proto = window.XMLHttpRequest.prototype;
        const originalOpen = proto.open;
        const originalSend = proto.send;

        proto.open = function (method, url) {
            this.fflhubKlaviyoCheckoutUrl = typeof url === "string" ? url : "";
            return originalOpen.apply(this, arguments);
        };

        proto.send = function (body) {
            if (isCheckoutEndpoint(this.fflhubKlaviyoCheckoutUrl)) {
                body = forceKlaviyoNewsletterPayload(body);
            }

            return originalSend.call(this, body);
        };

        proto.fflhubKlaviyoEmailOptInPatched = true;
    }

    document.addEventListener("click", function (event) {
        const checkbox = eventCheckbox(event);
        if (!checkbox || programmatic) {
            return;
        }

        window.setTimeout(function () {
            setCustomerOptedOut(!checkbox.checked);
        }, 0);
    }, true);

    document.addEventListener("change", function (event) {
        const checkbox = eventCheckbox(event);
        if (!checkbox || programmatic) {
            return;
        }

        setCustomerOptedOut(!checkbox.checked);
    }, true);

    patchFetchCheckoutPayloads();
    patchXhrCheckoutPayloads();

    document.addEventListener("DOMContentLoaded", scheduleDefaultOptIn);
    window.addEventListener("load", scheduleDefaultOptIn);

    if (window.jQuery) {
        window.jQuery(document.body).on("updated_checkout", scheduleDefaultOptIn);
    }

    const observer = new MutationObserver(scheduleDefaultOptIn);
    observer.observe(document.body, { childList: true, subtree: true });

    scheduleDefaultOptIn();
})();
JS;
    }
}
