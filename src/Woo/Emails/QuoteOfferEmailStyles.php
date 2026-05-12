<?php

namespace FFLHub\Woo\Emails;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Settings\Options;

final class QuoteOfferEmailStyles
{
    public static function button_style(): string
    {
        return sprintf(
            'display:inline-block;padding:10px 16px;background:%s;color:%s;text-decoration:none;border:0;border-radius:4px;font-weight:700;',
            Options::get_quote_email_button_background_color(),
            Options::get_quote_email_button_text_color()
        );
    }
}
