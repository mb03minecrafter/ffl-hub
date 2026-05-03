<?php

namespace FFLHub\Checkout;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defaults the MailPoet WooCommerce checkout newsletter opt-in checkbox to on.
 *
 * MailPoet's checkout opt-in is rendered by Woo Blocks/React and does not expose
 * a reliable server-side field default. This script clicks the checkbox once
 * when it appears, then respects the customer if they manually uncheck it.
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
    const storageKey = "fflhub_mailpoet_checkout_opt_in_unchecked";
    let programmatic = false;
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
        return isMailPoetOptInLabel(label) ? checkbox : null;
    }

    function customerOptedOut() {
        try {
            return window.sessionStorage.getItem(storageKey) === "1";
        } catch (error) {
            return false;
        }
    }

    function setCustomerOptedOut(value) {
        try {
            if (value) {
                window.sessionStorage.setItem(storageKey, "1");
            } else {
                window.sessionStorage.removeItem(storageKey);
            }
        } catch (error) {
            // Some privacy modes block sessionStorage. The checkbox default still works.
        }
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
        if (customerOptedOut()) {
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
                if (!checkbox.checked && !customerOptedOut()) {
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
