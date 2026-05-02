<?php
/**
 * One-off Holosun clearance email sender.
 *
 * Run from the WordPress root on the VPS with WP-CLI:
 *
 *   wp eval-file wp-content/plugins/ffl-hub/scripts/holosun-clearance-email.php mode=test test-email=mattbick2003@gmail.com
 *
 * Batch mode is guarded:
 *
 *   wp eval-file wp-content/plugins/ffl-hub/scripts/holosun-clearance-email.php mode=batch send=1 sleep-ms=250 limit=100
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this through WP-CLI from the WordPress root: wp eval-file wp-content/plugins/ffl-hub/scripts/holosun-clearance-email.php mode=test\n");
    exit(1);
}

global $wpdb;

$raw_args = (isset($args) && is_array($args)) ? $args : array_slice((array) ($_SERVER['argv'] ?? []), 1);
$opts = holosun_parse_args($raw_args);

$mode = strtolower(trim((string) holosun_opt($opts, 'mode', 'test')));
if (!in_array($mode, ['test', 'dry-run', 'batch'], true)) {
    holosun_fail('Invalid --mode. Use test, dry-run, or batch.');
}

$test_email = sanitize_email((string) holosun_opt($opts, 'test-email', 'mattbick2003@gmail.com'));
$test_first_name = trim((string) holosun_opt($opts, 'test-first-name', 'Matthew'));
$subject = trim((string) holosun_opt($opts, 'subject', 'Removal from Quote Email System, Holosun Update'));
$source = strtolower(trim((string) holosun_opt($opts, 'source', 'all')));
$limit = max(0, (int) holosun_opt($opts, 'limit', 0));
$offset = max(0, (int) holosun_opt($opts, 'offset', 0));
$sleep_ms = max(0, (int) holosun_opt($opts, 'sleep-ms', 250));
$send_batch = holosun_truthy(holosun_opt($opts, 'send', '0'));
$from_email = sanitize_email((string) holosun_opt($opts, 'from-email', (string) get_option('admin_email', '')));
$from_name = trim((string) holosun_opt($opts, 'from-name', 'Bickham Firearms'));
$reply_to = sanitize_email((string) holosun_opt($opts, 'reply-to', $from_email));
$physical_address = trim((string) holosun_opt($opts, 'physical-address', ''));

if ($subject === '') {
    holosun_fail('Missing --subject.');
}
if ($mode === 'test' && ($test_email === '' || !is_email($test_email))) {
    holosun_fail('Invalid --test-email.');
}
if (!in_array($source, ['all', 'quote', 'woo'], true)) {
    holosun_fail('Invalid --source. Use all, quote, or woo.');
}
if ($from_email === '' || !is_email($from_email)) {
    holosun_fail('Invalid sender email. Pass --from-email=you@example.com.');
}
if ($reply_to !== '' && !is_email($reply_to)) {
    holosun_fail('Invalid --reply-to.');
}

$products = holosun_products();
foreach ($products as &$product) {
    $product = array_merge($product, holosun_resolve_product_card_data($product));
}
unset($product);

if ($mode === 'test') {
    $recipients = [
        [
            'email' => $test_email,
            'first_name' => $test_first_name !== '' ? $test_first_name : 'there',
            'sources' => ['test'],
        ],
    ];
} else {
    $recipients = holosun_load_recipients($source);
    $recipients = array_values($recipients);
    if ($offset > 0 || $limit > 0) {
        $recipients = array_slice($recipients, $offset, $limit > 0 ? $limit : null);
    }
}

if (empty($recipients)) {
    holosun_log('No recipients found.');
    return;
}

$headers = [
    'Content-Type: text/html; charset=UTF-8',
    sprintf('From: %s <%s>', holosun_header_name($from_name), $from_email),
];
if ($reply_to !== '') {
    $headers[] = sprintf('Reply-To: %s <%s>', holosun_header_name('Matthew Bickham'), $reply_to);
}

$dry_run = ($mode === 'dry-run') || ($mode === 'batch' && !$send_batch);

holosun_log('Mode: ' . $mode . ($dry_run ? ' (no email will be sent)' : ''));
holosun_log('Recipients: ' . count($recipients));
holosun_log('Subject: ' . $subject);
if ($mode === 'batch' && !$send_batch) {
    holosun_log('Batch safety: add --send=1 when you are ready to actually send.');
}
if ($mode === 'batch' && $physical_address === '') {
    holosun_log('Warning: pass --physical-address="Your mailing address" before sending a commercial batch.');
}

$sent = 0;
$failed = 0;
$preview_count = 0;

foreach ($recipients as $recipient) {
    $email = sanitize_email((string) ($recipient['email'] ?? ''));
    if ($email === '' || !is_email($email)) {
        continue;
    }

    $first_name = holosun_first_name((string) ($recipient['first_name'] ?? ''));
    $html = holosun_build_email_html($first_name, $products, $physical_address);

    if ($dry_run) {
        if ($preview_count < 25) {
            holosun_log(sprintf('[dry-run] %s (%s) sources=%s', $email, $first_name, implode(',', (array) ($recipient['sources'] ?? []))));
        }
        $preview_count++;
        continue;
    }

    $ok = wp_mail($email, $subject, $html, $headers);
    if ($ok) {
        $sent++;
        holosun_log('Sent: ' . $email);
    } else {
        $failed++;
        holosun_log('FAILED: ' . $email);
    }

    if ($sleep_ms > 0) {
        usleep($sleep_ms * 1000);
    }
}

if ($dry_run && count($recipients) > 25) {
    holosun_log('[dry-run] ...and ' . (count($recipients) - 25) . ' more');
}

if ($failed > 0) {
    holosun_fail("Completed with failures. sent={$sent} failed={$failed}", false);
}

holosun_success($dry_run ? 'Dry run complete. No emails were sent.' : "Complete. sent={$sent} failed=0");

/**
 * @return array<string,string>
 */
function holosun_parse_args(array $raw_args): array
{
    $opts = [];
    foreach ($raw_args as $arg) {
        $arg = trim((string) $arg);
        if ($arg === '') {
            continue;
        }

        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);
        }

        if (strpos($arg, '=') === false) {
            $opts[$arg] = '1';
            continue;
        }

        [$key, $value] = array_pad(explode('=', $arg, 2), 2, '');
        $key = trim($key);
        if ($key !== '') {
            $opts[$key] = trim($value);
        }
    }

    return $opts;
}

function holosun_opt(array $opts, string $key, $default = '')
{
    return array_key_exists($key, $opts) ? $opts[$key] : $default;
}

function holosun_truthy($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int) $value) === 1;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'send'], true);
}

function holosun_log(string $message): void
{
    if (class_exists('\WP_CLI')) {
        \WP_CLI::log($message);
        return;
    }

    echo $message . PHP_EOL;
}

function holosun_success(string $message): void
{
    if (class_exists('\WP_CLI')) {
        \WP_CLI::success($message);
        return;
    }

    echo $message . PHP_EOL;
}

function holosun_fail(string $message, bool $halt = true): void
{
    if (class_exists('\WP_CLI')) {
        if ($halt) {
            \WP_CLI::error($message);
            return;
        }
        \WP_CLI::warning($message);
        return;
    }

    fwrite(STDERR, $message . PHP_EOL);
    if ($halt) {
        exit(1);
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function holosun_products(): array
{
    return [
        [
            'model' => 'HSHS507COMP',
            'name' => 'Holosun HS507COMP 2 MOA Reflex Sight, Black',
            'price' => '$285',
            'terms' => ['HSHS507COMP', 'HS507COMP'],
        ],
        [
            'model' => 'HS510C',
            'name' => 'Holosun HS510C 2/65 MOA Reflex Sight, Black',
            'price' => '$240',
            'terms' => ['HS510C'],
        ],
        [
            'model' => 'HS407KX2',
            'name' => 'Holosun HS407K X2 6 MOA Reflex Sight, Black',
            'price' => '$170',
            'terms' => ['HS407KX2', 'HS407K', 'X2'],
        ],
        [
            'model' => 'HSHM3X',
            'name' => 'Holosun HM3X Magnifier with Flip and QD Mount, Black',
            'price' => '$165',
            'terms' => ['HSHM3X', 'HM3X'],
        ],
        [
            'model' => 'HS507C-X3-RD',
            'name' => 'Holosun HS507C X3 RD 2/32 MOA Reflex Sight, Black',
            'price' => '$235',
            'terms' => ['HS507C-X3-RD', 'HS507C', 'X3', 'RD'],
        ],
        [
            'model' => 'HS407C-X3-RD',
            'name' => 'Holosun HS407C X3 RD 2 MOA Red Dot Sight, Black',
            'price' => '$190',
            'terms' => ['HS407C-X3-RD', 'HS407C', 'X3', 'RD'],
        ],
    ];
}

/**
 * @param array<string,mixed> $product
 * @return array<string,mixed>
 */
function holosun_resolve_product_card_data(array $product): array
{
    $product_id = holosun_resolve_product_id($product);
    $url = holosun_url_for_product_id($product_id);
    if ($url === '') {
        $url = holosun_fallback_search_url($product);
    }

    $card_name = trim((string) ($product['name'] ?? ''));
    $image_url = '';
    $sku = trim((string) ($product['model'] ?? ''));
    $stock_label = '';
    $woo_price_html = '';

    if ($product_id > 0 && function_exists('wc_get_product')) {
        $woo_product = wc_get_product($product_id);
        if (is_object($woo_product)) {
            if (method_exists($woo_product, 'get_name')) {
                $woo_name = trim((string) $woo_product->get_name());
                if ($woo_name !== '') {
                    $card_name = $woo_name;
                }
            }

            if (method_exists($woo_product, 'get_sku')) {
                $woo_sku = trim((string) $woo_product->get_sku());
                if ($woo_sku !== '') {
                    $sku = $woo_sku;
                }
            }

            if (method_exists($woo_product, 'get_price_html')) {
                $woo_price_html = trim((string) $woo_product->get_price_html());
            }

            if (method_exists($woo_product, 'is_in_stock') && $woo_product->is_in_stock()) {
                $stock_label = 'In stock';
                if (method_exists($woo_product, 'get_stock_quantity')) {
                    $qty = $woo_product->get_stock_quantity();
                    if (is_numeric($qty) && (int) $qty > 0) {
                        $stock_label = 'In stock';
                    }
                }
            }

            $image_id = 0;
            if (method_exists($woo_product, 'get_image_id')) {
                $image_id = (int) $woo_product->get_image_id();
            }

            if ($image_id <= 0 && method_exists($woo_product, 'get_parent_id')) {
                $parent_id = (int) $woo_product->get_parent_id();
                if ($parent_id > 0 && function_exists('wc_get_product')) {
                    $parent = wc_get_product($parent_id);
                    if (is_object($parent) && method_exists($parent, 'get_image_id')) {
                        $image_id = (int) $parent->get_image_id();
                    }
                }
            }

            if ($image_id > 0) {
                $image = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');
                if (is_string($image) && $image !== '') {
                    $image_url = $image;
                }
            }
        }
    }

    if ($image_url === '' && function_exists('wc_placeholder_img_src')) {
        $placeholder = wc_placeholder_img_src('woocommerce_thumbnail');
        if (is_string($placeholder) && $placeholder !== '') {
            $image_url = $placeholder;
        }
    }

    return [
        'product_id' => $product_id,
        'url' => $url,
        'card_name' => $card_name !== '' ? $card_name : (string) ($product['name'] ?? 'Holosun product'),
        'image_url' => $image_url,
        'sku' => $sku,
        'stock_label' => $stock_label,
        'woo_price_html' => $woo_price_html,
    ];
}

/**
 * @param array<string,mixed> $product
 */
function holosun_resolve_product_url(array $product): string
{
    $product_id = holosun_resolve_product_id($product);
    $url = holosun_url_for_product_id($product_id);
    return $url !== '' ? $url : holosun_fallback_search_url($product);
}

/**
 * @param array<string,mixed> $product
 */
function holosun_resolve_product_id(array $product): int
{
    $terms = array_values(array_filter(array_map('strval', (array) ($product['terms'] ?? []))));
    $model = trim((string) ($product['model'] ?? ''));

    $candidates = array_values(array_unique(array_filter(array_merge([$model], $terms))));
    foreach ($candidates as $candidate) {
        if (function_exists('wc_get_product_id_by_sku')) {
            $product_id = (int) wc_get_product_id_by_sku($candidate);
            if ($product_id > 0) {
                return $product_id;
            }
        }

        $product_id = holosun_find_product_id_by_sku_like($candidate);
        if ($product_id > 0) {
            return $product_id;
        }
    }

    $product_id = holosun_find_product_id_by_title_terms($terms);
    if ($product_id > 0) {
        return $product_id;
    }

    return 0;
}

/**
 * @param array<string,mixed> $product
 */
function holosun_fallback_search_url(array $product): string
{
    $model = trim((string) ($product['model'] ?? ''));
    $search = $model !== '' ? $model : (string) ($product['name'] ?? 'Holosun');
    return add_query_arg(
        [
            's' => $search,
            'post_type' => 'product',
        ],
        home_url('/')
    );
}

function holosun_url_for_product_id(int $product_id): string
{
    if ($product_id <= 0) {
        return '';
    }

    $post = get_post($product_id);
    if ($post && $post->post_type === 'product_variation' && (int) $post->post_parent > 0) {
        $product_id = (int) $post->post_parent;
    }

    $url = get_permalink($product_id);
    return is_string($url) ? $url : '';
}

function holosun_find_product_id_by_sku_like(string $term): int
{
    global $wpdb;

    $term = trim($term);
    if ($term === '') {
        return 0;
    }

    $like = '%' . $wpdb->esc_like($term) . '%';
    $sql = $wpdb->prepare(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
         WHERE p.post_type IN ('product', 'product_variation')
           AND p.post_status IN ('publish', 'private')
           AND pm.meta_value LIKE %s
         ORDER BY CASE WHEN pm.meta_value = %s THEN 0 ELSE 1 END, p.ID DESC
         LIMIT 1",
        $like,
        $term
    );

    return (int) $wpdb->get_var($sql);
}

/**
 * @param string[] $terms
 */
function holosun_find_product_id_by_title_terms(array $terms): int
{
    global $wpdb;

    $terms = array_values(array_filter(array_map('trim', $terms)));
    if (empty($terms)) {
        return 0;
    }

    $where = [];
    $params = [];
    foreach ($terms as $term) {
        $where[] = 'p.post_title LIKE %s';
        $params[] = '%' . $wpdb->esc_like($term) . '%';
    }

    $sql = "SELECT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'product'
              AND p.post_status IN ('publish', 'private')
              AND " . implode(' AND ', $where) . "
            ORDER BY p.ID DESC
            LIMIT 1";

    $sql = $wpdb->prepare($sql, ...$params);
    return (int) $wpdb->get_var($sql);
}

/**
 * @return array<int,array{email:string,first_name:string,sources:string[]}>
 */
function holosun_load_recipients(string $source): array
{
    $recipients = [];

    if ($source === 'all' || $source === 'quote') {
        holosun_merge_quote_recipients($recipients);
    }

    if ($source === 'all' || $source === 'woo') {
        holosun_merge_woo_customer_recipients($recipients);
    }

    ksort($recipients, SORT_STRING);
    return array_values($recipients);
}

/**
 * @param array<string,array{email:string,first_name:string,sources:string[]}> $recipients
 */
function holosun_merge_quote_recipients(array &$recipients): void
{
    global $wpdb;

    $table = $wpdb->prefix . 'fflhub_customer_quote_email_jobs';
    if (!holosun_table_exists($table)) {
        holosun_log('Quote jobs table not found: ' . $table);
        return;
    }

    $rows = $wpdb->get_results(
        "SELECT request_email, request_first_name, submitted_at
         FROM {$table}
         WHERE request_email <> ''
         ORDER BY submitted_at DESC, id DESC",
        ARRAY_A
    );

    if (!is_array($rows)) {
        holosun_log('Quote recipient query failed: ' . (string) $wpdb->last_error);
        return;
    }

    foreach ($rows as $row) {
        holosun_merge_recipient(
            $recipients,
            (string) ($row['request_email'] ?? ''),
            (string) ($row['request_first_name'] ?? ''),
            'quote'
        );
    }
}

/**
 * @param array<string,array{email:string,first_name:string,sources:string[]}> $recipients
 */
function holosun_merge_woo_customer_recipients(array &$recipients): void
{
    global $wpdb;

    $lookup = $wpdb->prefix . 'wc_customer_lookup';
    if (holosun_table_exists($lookup)) {
        $rows = $wpdb->get_results(
            "SELECT email, first_name
             FROM {$lookup}
             WHERE email <> ''
             ORDER BY date_last_active DESC, customer_id DESC",
            ARRAY_A
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                holosun_merge_recipient(
                    $recipients,
                    (string) ($row['email'] ?? ''),
                    (string) ($row['first_name'] ?? ''),
                    'woo_customer_lookup'
                );
            }
        }
    }

    $cap_key = $wpdb->prefix . 'capabilities';
    $sql = $wpdb->prepare(
        "SELECT u.user_email AS email, fn.meta_value AS first_name
         FROM {$wpdb->users} u
         INNER JOIN {$wpdb->usermeta} caps ON caps.user_id = u.ID AND caps.meta_key = %s
         LEFT JOIN {$wpdb->usermeta} fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
         WHERE u.user_email <> ''
           AND caps.meta_value LIKE %s
         ORDER BY u.ID DESC",
        $cap_key,
        '%customer%'
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            holosun_merge_recipient(
                $recipients,
                (string) ($row['email'] ?? ''),
                (string) ($row['first_name'] ?? ''),
                'wp_customer_user'
            );
        }
    }
}

/**
 * @param array<string,array{email:string,first_name:string,sources:string[]}> $recipients
 */
function holosun_merge_recipient(array &$recipients, string $email_raw, string $first_name_raw, string $source): void
{
    $email = strtolower(sanitize_email($email_raw));
    if ($email === '' || !is_email($email)) {
        return;
    }

    $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    if (in_array($domain, ['holosun.com', 'holosunusa.com'], true)) {
        return;
    }

    $first_name = holosun_first_name($first_name_raw);

    if (!isset($recipients[$email])) {
        $recipients[$email] = [
            'email' => $email,
            'first_name' => $first_name,
            'sources' => [$source],
        ];
        return;
    }

    if (($recipients[$email]['first_name'] ?? '') === 'there' && $first_name !== 'there') {
        $recipients[$email]['first_name'] = $first_name;
    }

    if (!in_array($source, $recipients[$email]['sources'], true)) {
        $recipients[$email]['sources'][] = $source;
    }
}

function holosun_table_exists(string $table): bool
{
    global $wpdb;

    $like = $wpdb->esc_like($table);
    return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table;
}

function holosun_first_name(string $name): string
{
    $name = trim(wp_strip_all_tags($name));
    if ($name === '') {
        return 'there';
    }

    $parts = preg_split('/\s+/', $name);
    $first = is_array($parts) && !empty($parts) ? trim((string) $parts[0]) : $name;
    $first = preg_replace('/[^A-Za-z\'\-]/', '', $first);
    $first = is_string($first) ? trim($first) : '';

    return $first !== '' ? $first : 'there';
}

/**
 * @param array<int,array<string,mixed>> $products
 */
function holosun_build_email_html(string $first_name, array $products, string $physical_address): string
{
    $site_url = home_url('/');
    $cards = holosun_product_cards_html($products, $site_url);

    $footer_html = $physical_address !== ''
        ? '<tr><td style="background:#f7f7f7;color:#666666;padding:18px 26px;font-size:12px;line-height:1.5;border-top:1px solid #dddddd;">' . esc_html($physical_address) . '</td></tr>'
        : '';

    return '<!doctype html>'
        . '<html><body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#111111;">'
        . '<span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;">Holosun restock update and quote list notice from Bickham Firearms.</span>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;margin:0;padding:28px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border:1px solid #dddddd;border-radius:0;overflow:hidden;">'
        . '<tr><td style="background:#ffffff;color:#111111;padding:24px 26px 18px;border-bottom:1px solid #dddddd;">'
        . '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#666666;">Bickham Firearms</div>'
        . '<div style="font-size:24px;font-weight:800;margin-top:6px;line-height:1.2;color:#111111;">Holosun restock update</div>'
        . '</td></tr>'
        . '<tr><td style="padding:26px;">'
        . '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hi ' . esc_html($first_name) . ',</p>'
        . '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">This is a notification letting you know that your email has been scrubbed from our quote list.</p>'
        . '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">We have started an email list if you would like to get updates on restocks and deals. That email list can be found at the bottom of <a href="' . esc_url($site_url) . '" style="color:#111111;font-weight:700;text-decoration:underline;">our site</a>.</p>'
        . '<p style="margin:0 0 18px;font-size:16px;line-height:1.55;">In addition, given our current Holosun dispute, we have now restocked these Holosun products at below MAP prices:</p>'
        . $cards
        . '<p style="margin:24px 0 0;font-size:16px;line-height:1.55;">Best wishes,<br>Matthew Bickham<br>Bickham Firearms</p>'
        . '</td></tr>'
        . $footer_html
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '</body></html>';
}

/**
 * @param array<int,array<string,mixed>> $products
 */
function holosun_product_cards_html(array $products, string $site_url): string
{
    $html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">';
    $chunks = array_chunk($products, 2);

    foreach ($chunks as $pair) {
        $html .= '<tr>';
        foreach ($pair as $product) {
            $html .= '<td valign="top" width="50%" style="padding:8px;">'
                . holosun_product_card_html($product, $site_url)
                . '</td>';
        }

        if (count($pair) === 1) {
            $html .= '<td valign="top" width="50%" style="padding:8px;">&nbsp;</td>';
        }

        $html .= '</tr>';
    }

    return $html . '</table>';
}

/**
 * @param array<string,mixed> $product
 */
function holosun_product_card_html(array $product, string $site_url): string
{
    $name = trim((string) ($product['card_name'] ?? $product['name'] ?? 'Holosun product'));
    $promo_name = trim((string) ($product['name'] ?? $name));
    $price = trim((string) ($product['price'] ?? ''));
    $url = trim((string) ($product['url'] ?? $site_url));
    $image_url = trim((string) ($product['image_url'] ?? ''));
    $sku = trim((string) ($product['sku'] ?? $product['model'] ?? ''));
    $stock_label = trim((string) ($product['stock_label'] ?? ''));

    $subline_parts = [];
    if ($sku !== '') {
        $subline_parts[] = 'SKU: ' . $sku;
    }
    if ($stock_label !== '') {
        $subline_parts[] = $stock_label;
    }
    $subline = implode(' &bull; ', array_map('esc_html', $subline_parts));

    $image = $image_url !== ''
        ? '<a href="' . esc_url($url) . '" style="display:block;text-decoration:none;height:160px;line-height:160px;text-align:center;"><img src="' . esc_url($image_url) . '" alt="' . esc_attr($promo_name) . '" width="220" style="display:inline-block;width:auto;max-width:220px;max-height:145px;height:auto;border:0;background:#ffffff;vertical-align:middle;"></a>'
        : '<a href="' . esc_url($url) . '" style="display:block;text-decoration:none;background:#f5f5f5;color:#515151;text-align:center;height:160px;line-height:160px;font-size:13px;">View product</a>';

    return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;border:1px solid #dddddd;border-radius:0;overflow:hidden;background:#ffffff;">'
        . '<tr><td align="center" height="188" style="padding:14px;background:#ffffff;border-bottom:1px solid #eeeeee;height:188px;vertical-align:middle;">' . $image . '</td></tr>'
        . '<tr><td style="padding:14px;">'
        . ($subline !== '' ? '<div style="margin:0 0 7px;font-size:11px;line-height:1.3;color:#666666;text-transform:uppercase;letter-spacing:.04em;">' . $subline . '</div>' : '')
        . '<a href="' . esc_url($url) . '" style="display:block;min-height:42px;font-size:14px;line-height:1.35;color:#111111;font-weight:700;text-decoration:none;">' . esc_html($name) . '</a>'
        . '<div style="margin-top:12px;font-size:12px;line-height:1.2;color:#666666;">Below MAP price</div>'
        . '<div style="margin-top:2px;font-size:24px;line-height:1.1;color:#111111;font-weight:800;">' . esc_html($price) . '</div>'
        . '<a href="' . esc_url($url) . '" style="display:block;margin-top:14px;background:#c9a24d;color:#111111;border-radius:5px;padding:10px 12px;font-size:13px;font-weight:700;text-align:center;text-decoration:none;">View product</a>'
        . '</td></tr>'
        . '</table>';
}

function holosun_header_name(string $name): string
{
    $name = trim(wp_strip_all_tags($name));
    $name = str_replace(["\r", "\n"], '', $name);
    return $name !== '' ? $name : 'Bickham Firearms';
}
