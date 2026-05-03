<?php

namespace FFLHub\Checkout;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defaults the MailPoet WooCommerce checkout newsletter opt-in checkbox to on.
 *
 * MailPoet's checkout opt-in is rendered by Woo Blocks/React and starts with
 * local component state set to false. This script defaults it on for the current
 * checkout page, then respects the customer if they manually uncheck it.
 */
final class MailPoetDefaultOptIn
{
    private const SCRIPT_HANDLE = 'fflhub-mailpoet-default-opt-in';

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_script']);
    }

    public static function enqueue_script(): void
    {
        if (!self::is_checkout_context()) {
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

    private static function script(): string
    {
        return <<<'JS'
(function () {
    let programmatic = false;
    let customerOptedOut = false;
    let mailPoetOptInDetected = false;
    let timer = 0;

    function normalize(value) {
        return String(value || "").replace(/\s+/g, " ").trim().toLowerCase();
    }

    function isMailPoetOptInLabel(label) {
        const text = normalize(label ? label.textContent : "");

        if (!text) {
            return false;
        }

        if (text.indexOf("terms") !== -1 || text.indexOf("privacy policy") !== -1) {
            return false;
        }

        return text.indexOf("email me") !== -1
            && (
                text.indexOf("deals") !== -1
                || text.indexOf("new arrivals") !== -1
                || text.indexOf("restock") !== -1
                || text.indexOf("newsletter") !== -1
                || text.indexOf("bickham firearms") !== -1
            );
    }

    function findOptInCheckbox(root) {
        const scope = root && root.querySelectorAll ? root : document;
        const labels = Array.prototype.slice.call(scope.querySelectorAll(".wc-block-components-checkbox label, label"));

        for (const label of labels) {
            if (!isMailPoetOptInLabel(label)) {
                continue;
            }

            const checkbox = label.querySelector("input[type='checkbox']");
            if (checkbox && !checkbox.disabled) {
                mailPoetOptInDetected = true;
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
        const checkbox = target.matches && target.matches("input[type='checkbox']")
            ? target
            : target.closest && target.closest("label")
                ? target.closest("label").querySelector("input[type='checkbox']")
                : null;

        if (!checkbox) {
            return null;
        }

        const label = checkbox.closest("label");
        if (!isMailPoetOptInLabel(label)) {
            return null;
        }

        mailPoetOptInDetected = true;
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

        const checkbox = findOptInCheckbox(document);
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

    function isMailPoetOptInAvailable() {
        if (mailPoetOptInDetected) {
            return true;
        }

        try {
            const settings = window.wc
                && window.wc.wcSettings
                && typeof window.wc.wcSettings.getSetting === "function"
                    ? window.wc.wcSettings.getSetting("mailpoet_data", {})
                    : null;

            return !!(settings && settings.optinEnabled);
        } catch (error) {
            return false;
        }
    }

    function forceMailPoetOptInPayload(rawBody) {
        if (!isMailPoetOptInAvailable() || typeof rawBody !== "string" || rawBody === "") {
            return rawBody;
        }

        try {
            const payload = JSON.parse(rawBody);
            payload.extensions = payload.extensions && typeof payload.extensions === "object"
                ? payload.extensions
                : {};
            payload.extensions.mailpoet = payload.extensions.mailpoet && typeof payload.extensions.mailpoet === "object"
                ? payload.extensions.mailpoet
                : {};
            payload.extensions.mailpoet.optin = shouldOptInForRequest();

            return JSON.stringify(payload);
        } catch (error) {
            return rawBody;
        }
    }

    function patchFetchCheckoutPayloads() {
        if (!window.fetch || window.fetch.fflhubMailPoetOptInPatched) {
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
                    body: forceMailPoetOptInPayload(init.body),
                });
            }

            return originalFetch.call(this, input, init);
        };

        patchedFetch.fflhubMailPoetOptInPatched = true;
        window.fetch = patchedFetch;
    }

    function patchXhrCheckoutPayloads() {
        if (!window.XMLHttpRequest || window.XMLHttpRequest.prototype.fflhubMailPoetOptInPatched) {
            return;
        }

        const proto = window.XMLHttpRequest.prototype;
        const originalOpen = proto.open;
        const originalSend = proto.send;

        proto.open = function (method, url) {
            this.fflhubMailPoetCheckoutUrl = typeof url === "string" ? url : "";
            return originalOpen.apply(this, arguments);
        };

        proto.send = function (body) {
            if (isCheckoutEndpoint(this.fflhubMailPoetCheckoutUrl)) {
                body = forceMailPoetOptInPayload(body);
            }

            return originalSend.call(this, body);
        };

        proto.fflhubMailPoetOptInPatched = true;
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
