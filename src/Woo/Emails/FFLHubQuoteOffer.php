<?php

namespace FFLHub\Woo\Emails;

use FFLHub\Settings\Options;
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

        if ($context->force_plain_text || !Options::get_pretty_random_email_quotes_enabled()) {
            return $this->send_code_only_plain_text($context);
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
        return 'FFLHubQuoteOfferTemplateVariant1.php';
    }

    private function variant_plain_template(int $variant_index): string
    {
        return 'Plain/FFLHubQuoteOfferTemplateVariant1.php';
    }

    private function send_code_only_plain_text(QuoteOfferEmailContext $context): bool
    {
        $code = trim($context->coupon_code);
        if ($code === '') {
            return false;
        }

        $product_name = trim($context->product_name);
        if ($product_name === '') {
            $product_name = (string) __('requested product', 'ffl-hub');
        }

        $body = sprintf(__('Product: %s', 'ffl-hub'), $product_name);

        $product_url = trim($context->product_url);
        if ($product_url !== '') {
            $body .= "\n" . $product_url;
        }

        $body .= "\n\n" . sprintf(__('Coupon code: %s', 'ffl-hub'), $code);

        $quote_cart_url = trim($context->quote_cart_url);
        if ($quote_cart_url !== '') {
            $body .= "\n\n" . sprintf(
                __('Add to cart with quoted price: %s', 'ffl-hub'),
                $quote_cart_url
            );
        }

        $this->setup_locale();
        $sent = wp_mail(
            $this->get_recipient(),
            $this->get_subject(),
            $body . "\n",
            $this->plain_text_headers(),
            $this->get_attachments()
        );
        $this->restore_locale();

        return (bool) $sent;
    }

    /**
     * Keep all existing configured headers (From/Reply-To/Bcc), but force plain text content type.
     *
     * @return string[]
     */
    private function plain_text_headers(): array
    {
        $raw = (string) $this->get_headers();
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines)) {
            $lines = [];
        }

        $headers = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'Content-Type:') === 0) {
                continue;
            }
            $headers[] = $line;
        }

        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        return $headers;
    }
}
