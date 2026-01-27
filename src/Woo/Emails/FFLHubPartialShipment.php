<?php
// src/Woo/Emails/FFLHubPartialShipment.php

namespace FFLHub\Woo\Emails;

if (!defined('ABSPATH')) {
    exit;
}

use WC_Email;
use WC_Order;

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

        // Template base is the directory that contains FFLHubPartialShipmentTemplate.php
        $this->template_base  = trailingslashit(FFLHUB_PLUGIN_PATH) . 'src/Woo/Emails/Templates/';
        $this->template_html  = 'FFLHubPartialShipmentTemplate.php';
        $this->template_plain = 'Plain/FFLHubPartialShipmentTemplate.php';

        // Fired from your poller: do_action('fflhub_trigger_partial_shipment_email', $order_id, $context)
        add_action('fflhub_trigger_partial_shipment_email', [$this, 'trigger'], 10, 2);

        parent::__construct();
    }

    /**
     * @param int $order_id
     * @param array<string,mixed> $context
     */
    public function trigger($order_id, $context = [])
    {



        error_log("EMAIL COMMAND TRIGGER FUNCTION!"
        );
        $order_id = (int) $order_id;
        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) {
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        if (!$this->is_enabled() || !$this->get_recipient()) {
            return;
        }

        $this->placeholders = [
            '{order_number}' => $order->get_order_number(),
        ];

        $this->setup_locale();

        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content_html($context),
            $this->get_headers(),
            $this->get_attachments()
        );

        $this->restore_locale();
    }

    /** @param array<string,mixed> $context */
    public function get_content_html($context = [])
    {
        ob_start();

        wc_get_template(
            $this->template_html,
            [
                'order'   => $this->object,
                'email'   => $this,
                'context' => is_array($context) ? $context : [],
            ],
            '',
            $this->template_base
        );

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $context */
    public function get_content_plain($context = [])
    {
        ob_start();

        wc_get_template(
            $this->template_plain,
            [
                'order'   => $this->object,
                'email'   => $this,
                'context' => is_array($context) ? $context : [],
            ],
            '',
            $this->template_base
        );

        return (string) ob_get_clean();
    }
}
