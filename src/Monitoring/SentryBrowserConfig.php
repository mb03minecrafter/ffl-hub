<?php

namespace FFLHub\Monitoring;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps browser Sentry useful by filtering known client-side noise and adding
 * checkout dependency diagnostics to the real failures we still want to see.
 */
final class SentryBrowserConfig
{
    public static function init(): void
    {
        add_filter('wp_sentry_public_options', [__CLASS__, 'filter_public_options']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_browser_hook'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'dequeue_unneeded_checkout_address_validation'], 100);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function filter_public_options(array $options): array
    {
        $options['ignoreErrors'] = self::merge_unique_list(
            isset($options['ignoreErrors']) && is_array($options['ignoreErrors']) ? $options['ignoreErrors'] : [],
            [
                'regex:Invalid call to runtime\\.sendMessage\\(\\)\\. Tab not found\\.?',
                'regex:AbortError: Transition was skipped',
                'regex:ResizeObserver loop completed with undelivered notifications\\.?',
                'regex:ResizeObserver loop limit exceeded',
            ]
        );

        $options['denyUrls'] = self::merge_unique_list(
            isset($options['denyUrls']) && is_array($options['denyUrls']) ? $options['denyUrls'] : [],
            [
                'regex:^chrome-extension://',
                'regex:^moz-extension://',
                'regex:^safari-extension://',
                'regex:^safari-web-extension://',
                'regex://static\\.klaviyo\\.com/',
                'regex://analytics\\.ahrefs\\.com/',
                'regex:/beacon\\.min\\.js',
                'regex:/cdn-cgi/',
            ]
        );

        $context = isset($options['context']) && is_array($options['context']) ? $options['context'] : [];
        $tags = isset($context['tags']) && is_array($context['tags']) ? $context['tags'] : [];
        $tags['fflhub_sentry_config'] = 'browser-v1';

        if (self::is_checkout_context()) {
            $tags['fflhub_checkout'] = '1';
        }

        $context['tags'] = $tags;
        $options['context'] = $context;

        return $options;
    }

    public static function enqueue_browser_hook(): void
    {
        if (!wp_script_is('wp-sentry-browser', 'registered') && !wp_script_is('wp-sentry-browser', 'enqueued')) {
            return;
        }

        wp_add_inline_script('wp-sentry-browser', self::browser_hook_script(), 'before');
    }

    public static function dequeue_unneeded_checkout_address_validation(): void
    {
        if (!self::is_checkout_context()) {
            return;
        }

        wp_dequeue_script('woocommerce-shipping-checkout-address-validation');
        wp_dequeue_style('wcshipping-checkout');
    }

    /**
     * @param array<int,mixed> $base
     * @param array<int,string> $append
     * @return array<int,mixed>
     */
    private static function merge_unique_list(array $base, array $append): array
    {
        foreach ($append as $value) {
            if (!in_array($value, $base, true)) {
                $base[] = $value;
            }
        }

        return array_values($base);
    }

    private static function is_checkout_context(): bool
    {
        return function_exists('is_checkout')
            && is_checkout()
            && (!function_exists('is_order_received_page') || !is_order_received_page());
    }

    private static function browser_hook_script(): string
    {
        return <<<'JS'
window.wp_sentry_hook = function (options) {
    var previousBeforeSend = typeof options.beforeSend === "function" ? options.beforeSend : null;

    function firstException(event) {
        return event
            && event.exception
            && event.exception.values
            && event.exception.values.length
                ? event.exception.values[0]
                : null;
    }

    function eventMessage(event) {
        var exception = firstException(event);
        if (exception && exception.value) {
            return String(exception.value);
        }

        return event && event.message ? String(event.message) : "";
    }

    function stackFilenames(event) {
        var exception = firstException(event);
        var frames = exception && exception.stacktrace && exception.stacktrace.frames
            ? exception.stacktrace.frames
            : [];

        return frames.map(function (frame) {
            return frame && frame.filename ? String(frame.filename) : "";
        }).filter(Boolean);
    }

    function matchesAny(value, needles) {
        value = String(value || "").toLowerCase();
        for (var i = 0; i < needles.length; i++) {
            if (value.indexOf(needles[i]) !== -1) {
                return true;
            }
        }

        return false;
    }

    function isKnownNoise(event) {
        var message = eventMessage(event);
        var filenames = stackFilenames(event);
        var joinedFilenames = filenames.join(" ");

        if (matchesAny(message, [
            "invalid call to runtime.sendmessage(). tab not found",
            "aborterror: transition was skipped",
            "resizeobserver loop completed with undelivered notifications",
            "resizeobserver loop limit exceeded"
        ])) {
            return true;
        }

        if (matchesAny(joinedFilenames, [
            "chrome-extension://",
            "moz-extension://",
            "safari-extension://",
            "safari-web-extension://",
            "static.klaviyo.com",
            "analytics.ahrefs.com",
            "/cdn-cgi/",
            "beacon.min.js"
        ])) {
            return true;
        }

        return false;
    }

    function checkoutDiagnostics() {
        var path = window.location && window.location.pathname ? window.location.pathname : "";
        var isCheckout = path.indexOf("/checkout") === 0;
        if (!isCheckout) {
            return null;
        }

        var resources = [];
        if (window.performance && typeof window.performance.getEntriesByType === "function") {
            resources = window.performance.getEntriesByType("resource")
                .filter(function (entry) {
                    return entry
                        && entry.name
                        && (
                            entry.name.indexOf("/wp-includes/js/dist/") !== -1
                            || entry.name.indexOf("/woocommerce/assets/client/blocks/") !== -1
                            || entry.name.indexOf("/woocommerce-shipping/dist/") !== -1
                        );
                })
                .slice(-30)
                .map(function (entry) {
                    return {
                        name: entry.name.replace(window.location.origin, ""),
                        duration: Math.round(entry.duration || 0),
                        transferSize: entry.transferSize || 0,
                        encodedBodySize: entry.encodedBodySize || 0
                    };
                });
        }

        return {
            readyState: document.readyState,
            path: path,
            wp: !!window.wp,
            wpHooks: !!(window.wp && window.wp.hooks),
            wpData: !!(window.wp && window.wp.data),
            wpDate: !!(window.wp && window.wp.date),
            wc: !!window.wc,
            wcSettings: !!(window.wc && window.wc.wcSettings),
            moment: !!window.moment,
            jQuery: !!window.jQuery,
            react: !!window.React,
            resources: resources
        };
    }

    options.beforeSend = function (event, hint) {
        if (isKnownNoise(event)) {
            return null;
        }

        var diagnostics = checkoutDiagnostics();
        if (diagnostics) {
            event.tags = event.tags || {};
            event.tags.fflhub_checkout = "1";
            event.tags.fflhub_checkout_ready_state = diagnostics.readyState;

            event.extra = event.extra || {};
            event.extra.fflhubCheckoutDiagnostics = diagnostics;
        }

        if (previousBeforeSend) {
            return previousBeforeSend(event, hint);
        }

        return event;
    };

    return options;
};
JS;
    }
}
