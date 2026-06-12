<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Offers\DistributorOffersStore;
use FFLHub\Distributor\Services\CSSI\CSSIOfferNormalizationService;
use FFLHub\Distributor\Services\CSSI\Tables\CSSIProductTableSchema;
use FFLHub\Distributor\Services\Lipseys\LipseysOfferNormalizationService;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysProductTableSchema;
use FFLHub\Distributor\Services\RSR\RSROfferNormalizationService;
use FFLHub\Distributor\Services\RSR\Tables\RSRProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Distributor\Services\Tables\ProductSchemaInterface;
use FFLHub\Distributor\Services\Zanders\ZandersOfferNormalizationService;
use FFLHub\Distributor\Services\Zanders\Tables\ZandersProductTableSchema;
use FFLHub\Product\BestOffers\ProductBestOffersStore;
use FFLHub\Product\State\ProductStateStore;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductStatePage
{
    private const PAGE_SLUG = 'fflhub-product-state';
    private const NONCE_ACTION = 'fflhub_product_state_backfill';
    private const NONCE_FIELD = 'fflhub_product_state_nonce';
    private const ACTION_BACKFILL = 'backfill_product_state';
    private const ACTION_NORMALIZE_ZANDERS = 'normalize_zanders_offers';
    private const ACTION_NORMALIZE_RSR = 'normalize_rsr_offers';
    private const ACTION_NORMALIZE_LIPSEYS = 'normalize_lipseys_offers';
    private const ACTION_NORMALIZE_CSSI = 'normalize_cssi_offers';
    private const RESULT_TRANSIENT_PREFIX = 'fflhub_product_state_backfill_result_';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Product State', 'ffl-hub'),
            __('Product State', 'ffl-hub'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        ProductStateStore::ensure_schema();
        DistributorOffersStore::ensure_schema();
        ProductBestOffersStore::ensure_schema();
        $this->maybe_handle_post();

        $result = $this->read_result();
        ?>
        <div class="wrap fflhub-product-state">
            <h1><?php esc_html_e('FFLHub Product State', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Create or refresh the managed product state table from existing WooCommerce product meta. This does not change product prices, stock, status, or existing meta.', 'ffl-hub'); ?>
            </p>

            <?php $this->render_result($result); ?>
            <?php $this->render_backfill_card(); ?>
            <?php $this->render_zanders_normalize_card(); ?>
            <?php $this->render_rsr_normalize_card(); ?>
            <?php $this->render_lipseys_normalize_card(); ?>
            <?php $this->render_cssi_normalize_card(); ?>
        </div>
        <?php
    }

    private function maybe_handle_post(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'ffl-hub'));
        }

        $action = isset($_POST['fflhub_product_state_action'])
            ? sanitize_text_field(wp_unslash((string) $_POST['fflhub_product_state_action']))
            : '';
        if (!in_array($action, [self::ACTION_BACKFILL, self::ACTION_NORMALIZE_ZANDERS, self::ACTION_NORMALIZE_RSR, self::ACTION_NORMALIZE_LIPSEYS, self::ACTION_NORMALIZE_CSSI], true)) {
            return;
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD])), self::NONCE_ACTION)
        ) {
            wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-hub'));
        }

        if ($action === self::ACTION_BACKFILL) {
            $result = ProductStateStore::backfill_from_product_meta();
            $result['type'] = self::ACTION_BACKFILL;
        } elseif ($action === self::ACTION_NORMALIZE_ZANDERS) {
            $result = ZandersOfferNormalizationService::normalize_from_product_table(
                $this->resolve_live_product_table(new ZandersProductTableSchema(), 'fflhub_zanders_fulfillment_last_swap')
            );
            $result['type'] = self::ACTION_NORMALIZE_ZANDERS;
        } elseif ($action === self::ACTION_NORMALIZE_RSR) {
            $result = RSROfferNormalizationService::normalize_from_product_table(
                $this->resolve_live_product_table(new RSRProductTableSchema(), 'fflhub_rsr_fulfillment_last_swap')
            );
            $result['type'] = self::ACTION_NORMALIZE_RSR;
        } elseif ($action === self::ACTION_NORMALIZE_LIPSEYS) {
            $result = LipseysOfferNormalizationService::normalize_from_product_table(
                $this->resolve_live_product_table(new LipseysProductTableSchema(), 'fflhub_lipseys_fulfillment_last_swap')
            );
            $result['type'] = self::ACTION_NORMALIZE_LIPSEYS;
        } else {
            $result = CSSIOfferNormalizationService::normalize_from_product_table(
                $this->resolve_live_product_table(new CSSIProductTableSchema(), 'fflhub_cssi_fulfillment_last_swap')
            );
            $result['type'] = self::ACTION_NORMALIZE_CSSI;
        }

        set_transient($this->result_transient_key(), $result, 5 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'ran' => $action], admin_url('admin.php')));
        exit;
    }

    private function resolve_live_product_table(ProductSchemaInterface $schema, string $swap_timestamp_option): string
    {
        return (new DoubleBufferedProductTable($schema, $swap_timestamp_option))->get_live_table_name();
    }

    private function render_backfill_card(): void
    {
        ?>
        <div class="postbox" style="max-width: 760px; padding: 16px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Backfill FFLHub Product State', 'ffl-hub'); ?></h2>
            <p>
                <?php esc_html_e('Reads managed WooCommerce products from current FFLHub product meta and inserts or updates rows in the product state table.', 'ffl-hub'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_product_state_action" value="<?php echo esc_attr(self::ACTION_BACKFILL); ?>" />
                <?php submit_button(__('Backfill FFLHub Product State', 'ffl-hub'), 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function render_zanders_normalize_card(): void
    {
        ?>
        <div class="postbox" style="max-width: 760px; padding: 16px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Normalize Zanders Distributor Offers', 'ffl-hub'); ?></h2>
            <p>
                <?php esc_html_e('Runs the Zanders-owned normalizer against the current live Zanders product table and upserts distributor offers only for active UPCs already present in the product state table. This does not change WooCommerce prices, stock, product meta, or Zanders cron behavior.', 'ffl-hub'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_product_state_action" value="<?php echo esc_attr(self::ACTION_NORMALIZE_ZANDERS); ?>" />
                <?php submit_button(__('Normalize Zanders Distributor Offers', 'ffl-hub'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function render_rsr_normalize_card(): void
    {
        ?>
        <div class="postbox" style="max-width: 760px; padding: 16px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Normalize RSR Offers', 'ffl-hub'); ?></h2>
            <p>
                <?php esc_html_e('Runs the RSR-owned normalizer against the current live RSR product table and upserts distributor offers only for active UPCs already present in the product state table. This does not change WooCommerce prices, stock, product meta, or RSR cron behavior.', 'ffl-hub'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_product_state_action" value="<?php echo esc_attr(self::ACTION_NORMALIZE_RSR); ?>" />
                <?php submit_button(__('Normalize RSR Offers', 'ffl-hub'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function render_lipseys_normalize_card(): void
    {
        ?>
        <div class="postbox" style="max-width: 760px; padding: 16px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Normalize Lipsey\'s Offers', 'ffl-hub'); ?></h2>
            <p>
                <?php esc_html_e('Runs the Lipsey\'s-owned normalizer against the current live Lipsey\'s product table and upserts distributor offers only for active UPCs already present in the product state table. This does not change WooCommerce prices, stock, product meta, or Lipsey\'s cron behavior.', 'ffl-hub'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_product_state_action" value="<?php echo esc_attr(self::ACTION_NORMALIZE_LIPSEYS); ?>" />
                <?php submit_button(__('Normalize Lipsey\'s Offers', 'ffl-hub'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function render_cssi_normalize_card(): void
    {
        ?>
        <div class="postbox" style="max-width: 760px; padding: 16px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Normalize CSSI Offers', 'ffl-hub'); ?></h2>
            <p>
                <?php esc_html_e('Runs the CSSI-owned normalizer against the current live CSSI product table and upserts distributor offers only for active UPCs already present in the product state table. This does not change WooCommerce prices, stock, product meta, or CSSI cron behavior.', 'ffl-hub'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="fflhub_product_state_action" value="<?php echo esc_attr(self::ACTION_NORMALIZE_CSSI); ?>" />
                <?php submit_button(__('Normalize CSSI Offers', 'ffl-hub'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function render_result(?array $result): void
    {
        if ($result === null) {
            return;
        }

        $type = (string) ($result['type'] ?? self::ACTION_BACKFILL);
        $offer_normalizers = [
            self::ACTION_NORMALIZE_ZANDERS => [
                'label' => 'Zanders',
                'matched_key' => 'matched_active_zanders_upcs',
            ],
            self::ACTION_NORMALIZE_RSR => [
                'label' => 'RSR',
                'matched_key' => 'matched_active_rsr_upcs',
            ],
            self::ACTION_NORMALIZE_LIPSEYS => [
                'label' => 'Lipsey\'s',
                'matched_key' => 'matched_active_lipseys_upcs',
            ],
            self::ACTION_NORMALIZE_CSSI => [
                'label' => 'CSSI',
                'matched_key' => 'matched_active_cssi_upcs',
            ],
        ];
        $normalizer = $offer_normalizers[$type] ?? null;
        $has_errors = !empty($result['errors']) && is_array($result['errors']);
        $notice_class = $has_errors ? 'notice-error' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr($notice_class); ?>">
            <?php if ($normalizer !== null) : ?>
                <?php
                $label = (string) $normalizer['label'];
                $matched_key = (string) $normalizer['matched_key'];
                ?>
                <p><strong><?php echo esc_html(sprintf('%s offer normalization complete.', $label)); ?></strong></p>
                <ul style="list-style:disc;margin-left:20px;">
                    <li><?php echo esc_html(sprintf('Source live table: %s', (string) ($result['source_live_table'] ?? ''))); ?></li>
                    <li><?php echo esc_html(sprintf('Active product state total: %d', (int) ($result['active_product_state_total'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf('Matched active %s UPCs: %d', $label, (int) ($result[$matched_key] ?? 0))); ?></li>
                    <?php if (array_key_exists('inserted_missing_offers', $result) || array_key_exists('updated_changed_offers', $result)) : ?>
                        <li><?php echo esc_html(sprintf('Inserted missing offers: %d', (int) ($result['inserted_missing_offers'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf('Insert missing runtime: %s ms', (string) ($result['insert_missing_elapsed_ms'] ?? '0.00'))); ?></li>
                        <li><?php echo esc_html(sprintf('Updated changed offers: %d', (int) ($result['updated_changed_offers'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf('Update changed runtime: %s ms', (string) ($result['update_changed_elapsed_ms'] ?? '0.00'))); ?></li>
                    <?php else : ?>
                        <li><?php echo esc_html(sprintf('Upsert MySQL affected rows: %d', (int) ($result['upsert_mysql_affected_rows'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf('Upsert runtime: %s ms', (string) ($result['upsert_elapsed_ms'] ?? '0.00'))); ?></li>
                    <?php endif; ?>
                    <li><?php echo esc_html(sprintf('Stale rows disabled: %d', (int) ($result['stale_disabled_offers'] ?? $result['stale_disabled'] ?? 0))); ?></li>
                    <li><?php echo esc_html(sprintf('Stale cleanup runtime: %s ms', (string) ($result['stale_cleanup_elapsed_ms'] ?? '0.00'))); ?></li>
                    <li><?php echo esc_html(sprintf('Runtime: %s ms (%s sec)', (string) ($result['elapsed_ms'] ?? '0.00'), (string) ($result['elapsed_sec'] ?? '0.000'))); ?></li>
                </ul>
                <?php if ($has_errors) : ?>
                    <p><strong><?php esc_html_e('Errors:', 'ffl-hub'); ?></strong></p>
                    <ul style="list-style:disc;margin-left:20px;">
                        <?php foreach ($result['errors'] as $message) : ?>
                            <li><?php echo esc_html((string) $message); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php else : ?>
            <p><strong><?php esc_html_e('Product state backfill complete.', 'ffl-hub'); ?></strong></p>
            <ul style="list-style:disc;margin-left:20px;">
                <li><?php echo esc_html(sprintf('Scanned: %d', (int) ($result['scanned'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Inserted: %d', (int) ($result['inserted'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Updated: %d', (int) ($result['updated'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Skipped missing UPC: %d', (int) ($result['skipped_missing_upc'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Skipped not managed: %d', (int) ($result['skipped_not_managed'] ?? 0))); ?></li>
                <li><?php echo esc_html(sprintf('Errors: %d', (int) ($result['errors'] ?? 0))); ?></li>
            </ul>
            <?php if (!empty($result['messages']) && is_array($result['messages'])) : ?>
                <p><strong><?php esc_html_e('First error messages:', 'ffl-hub'); ?></strong></p>
                <ul style="list-style:disc;margin-left:20px;">
                    <?php foreach ($result['messages'] as $message) : ?>
                        <li><?php echo esc_html((string) $message); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read_result(): ?array
    {
        $result = get_transient($this->result_transient_key());
        if (!is_array($result)) {
            return null;
        }

        delete_transient($this->result_transient_key());

        return $result;
    }

    private function result_transient_key(): string
    {
        return self::RESULT_TRANSIENT_PREFIX . (string) get_current_user_id();
    }
}
