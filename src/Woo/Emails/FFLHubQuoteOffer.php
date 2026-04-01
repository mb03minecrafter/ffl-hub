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

        add_filter('woocommerce_email_footer_text', [$this, 'filter_footer_text_for_quote_email'], 10, 2);
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

    /**
     * Remove WooCommerce default footer text for this custom quote email only.
     *
     * @param mixed $email
     */
    public function filter_footer_text_for_quote_email(string $footer_text, $email = null): string
    {
        if ($email instanceof self) {
            return '';
        }

        return $footer_text;
    }

    private function variant_html_template(int $variant_index): string
    {
        $templates = [
            'FFLHubQuoteOfferTemplateVariant1.php',
            'FFLHubQuoteOfferTemplateVariant2.php',
            'FFLHubQuoteOfferTemplateVariant3.php',
            'FFLHubQuoteOfferTemplateVariant4.php',
            'FFLHubQuoteOfferTemplateVariant5.php',
            'FFLHubQuoteOfferTemplateVariant6.php',
        ];

        return $templates[$variant_index] ?? $templates[0];
    }

    private function variant_plain_template(int $variant_index): string
    {
        $templates = [
            'Plain/FFLHubQuoteOfferTemplateVariant1.php',
            'Plain/FFLHubQuoteOfferTemplateVariant2.php',
            'Plain/FFLHubQuoteOfferTemplateVariant3.php',
            'Plain/FFLHubQuoteOfferTemplateVariant4.php',
            'Plain/FFLHubQuoteOfferTemplateVariant5.php',
            'Plain/FFLHubQuoteOfferTemplateVariant6.php',
        ];

        return $templates[$variant_index] ?? $templates[0];
    }
}
