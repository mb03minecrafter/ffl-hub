<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only cart/checkout activity view.
 *
 * WooCommerce 10.9 no longer creates checkout-draft orders as early as older
 * versions did, so this page gives us similar visibility by decoding active
 * WooCommerce session carts. It intentionally does not create, update, or
 * delete Woo orders, carts, or sessions.
 */
final class CheckoutActivityPage
{
    private const PAGE_SLUG = 'fflhub-checkout-activity';
    private const DEFAULT_HOURS = 6;
    private const MAX_HOURS = 168;
    private const MAX_SESSIONS = 500;
    private const DISPLAY_TIMEZONE = 'America/Chicago';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Checkout Activity', 'ffl-hub'),
            __('Checkout Activity', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_register_style('fflhub-checkout-activity', false, [], FFLHUB_PLUGIN_VERSION);
        wp_enqueue_style('fflhub-checkout-activity');
        wp_add_inline_style('fflhub-checkout-activity', $this->inline_css());
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $hours = $this->read_hours_filter();
        $rows = $this->get_recent_non_empty_carts($hours);
        $stats = $this->summarize_rows($rows);
        ?>
        <div class="wrap fflhub-checkout-activity">
            <h1><?php esc_html_e('Checkout Activity', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Read-only view of recent non-empty WooCommerce carts. This does not create draft orders or edit customer carts.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_filters($hours); ?>
            <?php $this->render_summary($stats, $hours); ?>
            <?php $this->render_table($rows); ?>
        </div>
        <?php
    }

    private function read_hours_filter(): int
    {
        $hours = isset($_GET['hours']) ? absint(wp_unslash((string) $_GET['hours'])) : self::DEFAULT_HOURS;
        if ($hours <= 0) {
            return self::DEFAULT_HOURS;
        }

        return min($hours, self::MAX_HOURS);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_recent_non_empty_carts(int $hours): array
    {
        global $wpdb;

        if (!$wpdb) {
            return [];
        }

        $table = $wpdb->prefix . 'woocommerce_sessions';
        if (!$this->table_exists($table)) {
            return [];
        }

        $session_lifetime = $this->session_lifetime_seconds();
        $cutoff = time() - ($hours * HOUR_IN_SECONDS);
        $minimum_expiry = $cutoff + $session_lifetime;

        $sessions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT session_id, session_key, session_value, session_expiry
                 FROM {$table}
                 WHERE session_expiry >= %d
                   AND session_value LIKE %s
                 ORDER BY session_expiry DESC
                 LIMIT %d",
                $minimum_expiry,
                '%' . $wpdb->esc_like('cart') . '%',
                self::MAX_SESSIONS
            ),
            ARRAY_A
        );

        if (!is_array($sessions)) {
            return [];
        }

        $rows = [];
        foreach ($sessions as $session) {
            $row = $this->decode_session_row($session, $session_lifetime, $cutoff);
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>|null
     */
    private function decode_session_row(array $session, int $session_lifetime, int $cutoff): ?array
    {
        $data = maybe_unserialize((string) ($session['session_value'] ?? ''));
        if (!is_array($data)) {
            return null;
        }

        $cart = isset($data['cart']) ? maybe_unserialize($data['cart']) : [];
        if (!is_array($cart) || empty($cart)) {
            return null;
        }

        $expiry = (int) ($session['session_expiry'] ?? 0);
        $last_activity = $expiry - $session_lifetime;
        if ($last_activity < $cutoff) {
            return null;
        }

        $items = $this->decode_cart_items($cart);
        if (empty($items)) {
            return null;
        }

        $customer = isset($data['customer']) ? maybe_unserialize($data['customer']) : [];
        $cart_totals = isset($data['cart_totals']) ? maybe_unserialize($data['cart_totals']) : [];

        return [
            'session_id' => (int) ($session['session_id'] ?? 0),
            'session_key' => (string) ($session['session_key'] ?? ''),
            'last_activity' => $last_activity,
            'expires_at' => $expiry,
            'customer' => is_array($customer) ? $customer : [],
            'customer_label' => $this->customer_label((string) ($session['session_key'] ?? ''), is_array($customer) ? $customer : []),
            'email' => $this->customer_field(is_array($customer) ? $customer : [], ['email', 'billing_email']),
            'phone' => $this->customer_field(is_array($customer) ? $customer : [], ['phone', 'billing_phone']),
            'items' => $items,
            'item_count' => array_sum(array_map(static fn(array $item): int => (int) $item['quantity'], $items)),
            'cart_total' => $this->cart_total($cart_totals, $items),
            'shipping_total' => $this->cart_number($cart_totals, ['shipping_total', 'shipping_total_tax']),
            'coupons' => $this->session_list($data, 'applied_coupons'),
            'chosen_shipping_methods' => $this->session_list($data, 'chosen_shipping_methods'),
            'ffl_number' => isset($data['fflhub_receiving_ffl_number']) ? (string) $data['fflhub_receiving_ffl_number'] : '',
            'notices' => $this->session_notices($data),
        ];
    }

    /**
     * @param array<string,mixed> $cart
     * @return array<int,array<string,mixed>>
     */
    private function decode_cart_items(array $cart): array
    {
        $items = [];
        foreach ($cart as $cart_item) {
            if (!is_array($cart_item)) {
                continue;
            }

            $product_id = (int) ($cart_item['product_id'] ?? 0);
            $variation_id = (int) ($cart_item['variation_id'] ?? 0);
            $quantity = max(0, (int) ($cart_item['quantity'] ?? 0));
            $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
            if (!$product || $quantity < 1) {
                continue;
            }

            $unit_price = (float) wc_get_price_to_display($product);
            $line_total = isset($cart_item['line_total']) ? (float) $cart_item['line_total'] : ($unit_price * $quantity);

            $items[] = [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'upc' => method_exists($product, 'get_global_unique_id') ? (string) $product->get_global_unique_id() : '',
                'unit_price' => $unit_price,
                'line_total' => $line_total,
                'edit_url' => get_edit_post_link($product_id, 'raw') ?: '',
                'product_url' => get_permalink($product_id) ?: '',
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    private function session_list(array $data, string $key): array
    {
        if (!isset($data[$key])) {
            return [];
        }

        $value = maybe_unserialize($data[$key]);
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }

        $value = trim((string) $value);
        return $value === '' ? [] : [$value];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array{type:string,message:string}>
     */
    private function session_notices(array $data): array
    {
        if (!isset($data['wc_notices'])) {
            return [];
        }

        $raw_notices = maybe_unserialize($data['wc_notices']);
        if (!is_array($raw_notices)) {
            return [];
        }

        $out = [];
        foreach ($raw_notices as $type => $notices) {
            $type = sanitize_key((string) $type);
            $type = $type !== '' ? $type : 'notice';
            $notices = is_array($notices) ? $notices : [$notices];

            foreach ($notices as $notice) {
                $message = '';
                if (is_array($notice)) {
                    $message = isset($notice['notice']) ? (string) $notice['notice'] : '';
                } elseif (is_scalar($notice)) {
                    $message = (string) $notice;
                }

                $message = trim(wp_strip_all_tags($message));
                if ($message === '') {
                    continue;
                }

                $out[] = [
                    'type' => $type,
                    'message' => $message,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $customer
     * @param array<int,string> $keys
     */
    private function customer_field(array $customer, array $keys): string
    {
        foreach ($keys as $key) {
            if (!empty($customer[$key])) {
                return (string) $customer[$key];
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $customer
     */
    private function customer_label(string $session_key, array $customer): string
    {
        if (ctype_digit($session_key)) {
            $user = get_user_by('id', (int) $session_key);
            if ($user) {
                return sprintf('%s (#%d)', $user->user_login, (int) $session_key);
            }
        }

        $name = trim($this->customer_field($customer, ['first_name', 'billing_first_name']) . ' ' . $this->customer_field($customer, ['last_name', 'billing_last_name']));
        if ($name !== '') {
            return $name;
        }

        $email = $this->customer_field($customer, ['email', 'billing_email']);
        if ($email !== '') {
            return $email;
        }

        return __('Anonymous shopper', 'ffl-hub');
    }

    /**
     * @param array<string,mixed> $cart_totals
     * @param array<int,array<string,mixed>> $items
     */
    private function cart_total(array $cart_totals, array $items): float
    {
        foreach (['total', 'total_price'] as $key) {
            if (isset($cart_totals[$key]) && is_numeric($cart_totals[$key])) {
                return (float) $cart_totals[$key];
            }
        }

        return array_sum(array_map(static fn(array $item): float => (float) $item['line_total'], $items));
    }

    /**
     * @param array<string,mixed> $cart_totals
     * @param array<int,string> $keys
     */
    private function cart_number(array $cart_totals, array $keys): float
    {
        $total = 0.0;
        foreach ($keys as $key) {
            if (isset($cart_totals[$key]) && is_numeric($cart_totals[$key])) {
                $total += (float) $cart_totals[$key];
            }
        }

        return $total;
    }

    private function session_lifetime_seconds(): int
    {
        return (int) apply_filters('wc_session_expiration', 48 * HOUR_IN_SECONDS);
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;

        return $wpdb && (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function summarize_rows(array $rows): array
    {
        $total_value = 0.0;
        $items = 0;
        $with_email = 0;
        $with_ffl = 0;
        $with_errors = 0;

        foreach ($rows as $row) {
            $total_value += (float) $row['cart_total'];
            $items += (int) $row['item_count'];
            $with_email += (string) $row['email'] !== '' ? 1 : 0;
            $with_ffl += (string) $row['ffl_number'] !== '' ? 1 : 0;
            $notices = isset($row['notices']) && is_array($row['notices']) ? $row['notices'] : [];
            foreach ($notices as $notice) {
                if (is_array($notice) && (string) ($notice['type'] ?? '') === 'error') {
                    $with_errors++;
                    break;
                }
            }
        }

        return [
            'carts' => count($rows),
            'items' => $items,
            'total_value' => $total_value,
            'with_email' => $with_email,
            'with_ffl' => $with_ffl,
            'with_errors' => $with_errors,
        ];
    }

    private function render_filters(int $hours): void
    {
        ?>
        <form method="get" class="fflhub-checkout-activity-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <label for="fflhub-checkout-activity-hours"><?php esc_html_e('Window', 'ffl-hub'); ?></label>
            <select id="fflhub-checkout-activity-hours" name="hours">
                <?php foreach ([1, 3, 6, 12, 24, 48, 72, 168] as $option) : ?>
                    <option value="<?php echo esc_attr((string) $option); ?>" <?php selected($hours, $option); ?>>
                        <?php echo esc_html(sprintf(_n('%d hour', '%d hours', $option, 'ffl-hub'), $option)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Refresh', 'ffl-hub'), 'secondary', '', false); ?>
        </form>
        <?php
    }

    /**
     * @param array<string,mixed> $stats
     */
    private function render_summary(array $stats, int $hours): void
    {
        ?>
        <div class="fflhub-checkout-activity-summary">
            <div><strong><?php echo esc_html((string) $stats['carts']); ?></strong><span><?php echo esc_html(sprintf(__('non-empty carts in %d hours', 'ffl-hub'), $hours)); ?></span></div>
            <div><strong><?php echo wp_kses_post(wc_price((float) $stats['total_value'])); ?></strong><span><?php esc_html_e('visible cart value', 'ffl-hub'); ?></span></div>
            <div><strong><?php echo esc_html((string) $stats['items']); ?></strong><span><?php esc_html_e('items in carts', 'ffl-hub'); ?></span></div>
            <div><strong><?php echo esc_html((string) $stats['with_email']); ?></strong><span><?php esc_html_e('with email', 'ffl-hub'); ?></span></div>
            <div><strong><?php echo esc_html((string) $stats['with_ffl']); ?></strong><span><?php esc_html_e('with selected FFL', 'ffl-hub'); ?></span></div>
            <div><strong><?php echo esc_html((string) $stats['with_errors']); ?></strong><span><?php esc_html_e('with checkout errors', 'ffl-hub'); ?></span></div>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function render_table(array $rows): void
    {
        ?>
        <table class="widefat fixed striped table-view-list fflhub-checkout-activity-table">
            <thead>
            <tr>
                <th><?php esc_html_e('Last Activity', 'ffl-hub'); ?></th>
                <th><?php esc_html_e('Customer', 'ffl-hub'); ?></th>
                <th><?php esc_html_e('Cart Items', 'ffl-hub'); ?></th>
                <th><?php esc_html_e('Total', 'ffl-hub'); ?></th>
                <th><?php esc_html_e('Checkout Signals', 'ffl-hub'); ?></th>
                <th><?php esc_html_e('Session', 'ffl-hub'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr>
                    <td colspan="6"><?php esc_html_e('No non-empty carts found in this window.', 'ffl-hub'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($this->format_local_time((int) $row['last_activity'])); ?></strong>
                            <br />
                            <span class="description"><?php echo esc_html(sprintf(__('expires %s', 'ffl-hub'), $this->format_local_time((int) $row['expires_at']))); ?></span>
                        </td>
                        <td>
                            <strong><?php echo esc_html((string) $row['customer_label']); ?></strong>
                            <?php if ((string) $row['email'] !== '') : ?>
                                <br /><a href="mailto:<?php echo esc_attr((string) $row['email']); ?>"><?php echo esc_html((string) $row['email']); ?></a>
                            <?php endif; ?>
                            <?php if ((string) $row['phone'] !== '') : ?>
                                <br /><span><?php echo esc_html((string) $row['phone']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php $this->render_items((array) $row['items']); ?></td>
                        <td>
                            <strong><?php echo wp_kses_post(wc_price((float) $row['cart_total'])); ?></strong>
                            <br />
                            <span class="description"><?php echo esc_html(sprintf(_n('%d item', '%d items', (int) $row['item_count'], 'ffl-hub'), (int) $row['item_count'])); ?></span>
                        </td>
                        <td><?php $this->render_signals($row); ?></td>
                        <td>
                            <code><?php echo esc_html($this->short_session_key((string) $row['session_key'])); ?></code>
                            <br />
                            <span class="description"><?php echo esc_html(sprintf(__('session #%d', 'ffl-hub'), (int) $row['session_id'])); ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function render_items(array $items): void
    {
        foreach ($items as $item) {
            ?>
            <div class="fflhub-cart-item">
                <strong><?php echo esc_html((string) $item['quantity']); ?>x</strong>
                <?php if ((string) $item['edit_url'] !== '') : ?>
                    <a href="<?php echo esc_url((string) $item['edit_url']); ?>"><?php echo esc_html((string) $item['name']); ?></a>
                <?php else : ?>
                    <?php echo esc_html((string) $item['name']); ?>
                <?php endif; ?>
                <div class="description">
                    <?php
                    $bits = array_filter([
                        'ID ' . (string) $item['product_id'],
                        (string) $item['upc'] !== '' ? 'UPC ' . (string) $item['upc'] : '',
                        (string) $item['sku'] !== '' ? 'SKU ' . (string) $item['sku'] : '',
                        wc_price((float) $item['line_total']),
                    ]);
                    echo wp_kses_post(implode(' - ', $bits));
                    ?>
                    <?php if ((string) $item['product_url'] !== '') : ?>
                        - <a href="<?php echo esc_url((string) $item['product_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View', 'ffl-hub'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function render_signals(array $row): void
    {
        $has_output = false;
        $notices = isset($row['notices']) && is_array($row['notices']) ? $row['notices'] : [];
        foreach ($notices as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $type = sanitize_html_class((string) ($notice['type'] ?? 'notice'));
            $message = trim((string) ($notice['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $label = $type === 'error' ? __('Error: ', 'ffl-hub') : __('Notice: ', 'ffl-hub');
            echo '<div class="fflhub-checkout-notice fflhub-checkout-notice-' . esc_attr($type) . '">' . esc_html($label . $message) . '</div>';
            $has_output = true;
        }

        $signals = [];
        if (!empty($row['coupons'])) {
            $signals[] = __('Coupons: ', 'ffl-hub') . implode(', ', (array) $row['coupons']);
        }
        if (!empty($row['chosen_shipping_methods'])) {
            $signals[] = __('Shipping: ', 'ffl-hub') . implode(', ', (array) $row['chosen_shipping_methods']);
        }
        if ((string) $row['ffl_number'] !== '') {
            $signals[] = __('FFL: ', 'ffl-hub') . (string) $row['ffl_number'];
        }

        if (empty($signals) && !$has_output) {
            echo '<span class="description">' . esc_html__('Cart only', 'ffl-hub') . '</span>';
            return;
        }

        foreach ($signals as $signal) {
            echo '<div>' . esc_html($signal) . '</div>';
        }
    }

    private function short_session_key(string $session_key): string
    {
        if ($session_key === '') {
            return '';
        }

        if (strlen($session_key) <= 14) {
            return $session_key;
        }

        return substr($session_key, 0, 8) . '...' . substr($session_key, -5);
    }

    private function format_local_time(int $timestamp): string
    {
        return wp_date('M j, g:i a T', $timestamp, $this->display_timezone());
    }

    private function display_timezone(): \DateTimeZone
    {
        static $timezone = null;

        if (!$timezone instanceof \DateTimeZone) {
            $timezone = new \DateTimeZone(self::DISPLAY_TIMEZONE);
        }

        return $timezone;
    }

    private function inline_css(): string
    {
        return '
            .fflhub-checkout-activity-filters {
                display: flex;
                align-items: center;
                gap: 8px;
                margin: 16px 0;
            }
            .fflhub-checkout-activity-summary {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 10px;
                max-width: 980px;
                margin: 14px 0 18px;
            }
            .fflhub-checkout-activity-summary > div {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 10px 12px;
            }
            .fflhub-checkout-activity-summary strong {
                display: block;
                font-size: 18px;
                line-height: 1.2;
            }
            .fflhub-checkout-activity-summary span {
                color: #646970;
            }
            .fflhub-checkout-activity-table th:nth-child(1) { width: 130px; }
            .fflhub-checkout-activity-table th:nth-child(2) { width: 190px; }
            .fflhub-checkout-activity-table th:nth-child(4) { width: 110px; }
            .fflhub-checkout-activity-table th:nth-child(5) { width: 180px; }
            .fflhub-checkout-activity-table th:nth-child(6) { width: 130px; }
            .fflhub-cart-item + .fflhub-cart-item {
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid #f0f0f1;
            }
            .fflhub-checkout-notice {
                border-left: 3px solid #72aee6;
                margin: 0 0 6px;
                padding-left: 7px;
            }
            .fflhub-checkout-notice-error {
                border-left-color: #d63638;
                color: #8a2424;
                font-weight: 600;
            }
        ';
    }
}
