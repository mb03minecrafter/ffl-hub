<?php
// src/Woo/Emails/FFLHubPartialShipment.php

namespace FFLHub\Woo\Emails;

if (!defined('ABSPATH')) {
    exit;
}

use WC_Email;
use WC_Order;
use FFLHub\Distributor\Models\PartialShipmentEmailContext;

/**
 * Customer email: Sent when new tracking numbers are detected for an order placement job.
 */
final class FFLHubPartialShipment extends WC_Email
{
    public function __construct()
    {
        $this->id             = 'fflhub_partial_shipment';
        $this->title          = 'FFL Hub — Partial Shipment';
        $this->description    = 'Sent when new tracking numbers are detected for an order placement job.';
        $this->customer_email = true;

        $this->heading = 'Shipment update';
        $this->subject = '[{site_title}] Shipment update for order #{order_number}';

        $this->template_base  = trailingslashit(FFLHUB_PLUGIN_PATH) . 'src/Woo/Emails/Templates/';
        $this->template_html  = 'FFLHubPartialShipmentTemplate.php';
        $this->template_plain = 'Plain/FFLHubPartialShipmentTemplate.php';

        // Fired from your poller: do_action('fflhub_trigger_partial_shipment_email', $order_id, $ctx)
        add_action('fflhub_trigger_partial_shipment_email', [$this, 'trigger'], 10, 2);

        parent::__construct();
    }

    /**
     * Trigger the email.
     *
     * @param int   $order_id
     * @param mixed $ctx
     */
    public function trigger($order_id, $ctx = null)
    {
        $order_id = (int) $order_id;

        if (!($ctx instanceof PartialShipmentEmailContext)) {
            error_log('[FFLHUB][Email] abort: ctx not PartialShipmentEmailContext');
            return;
        }

        if (method_exists($ctx, 'should_send') && !$ctx->should_send()) {
            error_log('[FFLHUB][Email] abort: ctx->should_send() = false');
            return;
        }

        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            error_log('[FFLHUB][Email] abort: order not found');
            return;
        }

        $this->object    = $order;
        $this->recipient = (string) $order->get_billing_email();

        if (!$this->is_enabled() || !$this->get_recipient()) {
            error_log('[FFLHUB][Email] abort: disabled or empty recipient');
            return;
        }

        /**
         * IMPORTANT:
         * Do NOT overwrite $this->placeholders or you'll clobber Woo defaults like {site_title}.
         * Only add/override the one(s) you need.
         */
        $this->placeholders['{order_number}'] = $order->get_order_number();

        $this->setup_locale();

        // Render HTML content using our DTO context
        $html = $this->get_content_html($ctx);

        $sent = $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $html,
            $this->get_headers(),
            $this->get_attachments()
        );

        $this->restore_locale();
    }

    /**
     * @param PartialShipmentEmailContext|null $ctx
     */
    public function get_content_html($ctx = null): string
    {
        if (!($ctx instanceof PartialShipmentEmailContext)) {
            return '';
        }

        ob_start();

        wc_get_template(
            $this->template_html,
            [
                'order'   => $this->object,
                'email'   => $this,
                'context' => $ctx,
            ],
            '',
            $this->template_base
        );

        return (string) ob_get_clean();
    }

    /**
     * @param PartialShipmentEmailContext|null $ctx
     */
    public function get_content_plain($ctx = null): string
    {
        if (!($ctx instanceof PartialShipmentEmailContext)) {
            return '';
        }

        ob_start();

        wc_get_template(
            $this->template_plain,
            [
                'order'   => $this->object,
                'email'   => $this,
                'context' => $ctx,
            ],
            '',
            $this->template_base
        );

        return (string) ob_get_clean();
    }
}
