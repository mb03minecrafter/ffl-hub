<?php

namespace FFLHub\Woo\Emails;

use FFLHub\Woo\Emails\Models\QuoteOfferEmailContext;
use WC_Email;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer email sent for scheduled quote offer jobs.
 */
final class FFLHubQuoteOffer extends WC_Email
{
    private ?QuoteOfferEmailContext $context = null;

    public function __construct()
    {
        $this->id             = 'fflhub_quote_offer';
        $this->title          = 'FFL Hub - Quote Offer';
        $this->description    = 'Sent when a queued custom quote offer becomes due.';
        $this->customer_email = true;

        $this->heading = 'Your custom quote is ready';
        $this->subject = '[{site_title}] Your custom quote is ready';

        $this->template_base  = trailingslashit(FFLHUB_PLUGIN_PATH) . 'src/Woo/Emails/Templates/';
        $this->template_html  = 'FFLHubQuoteOfferTemplateVariant1.php';
        $this->template_plain = 'Plain/FFLHubQuoteOfferTemplateVariant1.php';

        parent::__construct();
    }

    public function trigger(QuoteOfferEmailContext $context): bool
    {
        $this->context = $context;
        $this->recipient = $context->recipient_email;
        $this->subject = $context->subject;

        if (!$this->is_enabled() || !$this->get_recipient()) {
            return false;
        }

        $this->setup_locale();
        $sent = $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
        $this->restore_locale();

        return (bool) $sent;
    }

    public function get_content_html(): string
    {
        if (!($this->context instanceof QuoteOfferEmailContext)) {
            return '';
        }

        ob_start();
        wc_get_template(
            $this->variant_html_template($this->context->variant_index),
            [
                'email'   => $this,
                'context' => $this->context,
            ],
            '',
            $this->template_base
        );

        return (string) ob_get_clean();
    }

    public function get_content_plain(): string
    {
        if (!($this->context instanceof QuoteOfferEmailContext)) {
            return '';
        }

        ob_start();
        wc_get_template(
            $this->variant_plain_template($this->context->variant_index),
            [
                'email'   => $this,
                'context' => $this->context,
            ],
            '',
            $this->template_base
        );

        return (string) ob_get_clean();
    }

    private function variant_html_template(int $variant_index): string
    {
        if ($variant_index === 1) {
            return 'FFLHubQuoteOfferTemplateVariant2.php';
        }
        if ($variant_index === 2) {
            return 'FFLHubQuoteOfferTemplateVariant3.php';
        }

        return 'FFLHubQuoteOfferTemplateVariant1.php';
    }

    private function variant_plain_template(int $variant_index): string
    {
        if ($variant_index === 1) {
            return 'Plain/FFLHubQuoteOfferTemplateVariant2.php';
        }
        if ($variant_index === 2) {
            return 'Plain/FFLHubQuoteOfferTemplateVariant3.php';
        }

        return 'Plain/FFLHubQuoteOfferTemplateVariant1.php';
    }
}

