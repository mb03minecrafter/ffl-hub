<?php

declare(strict_types=1);

namespace FFLHub\Admin\Pages;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Top-level admin home for distributor order operations.
 *
 * This menu is intentionally separate from the main FFL Hub settings menu so
 * order worklists, batch queues, CA relay queues, and manual-order tools live
 * in one operational area without changing their existing page slugs.
 */
final class DistributorOrderingAdminPage
{
    public const CAPABILITY = 'manage_options';
    public const MENU_SLUG = 'fflhub-distributor-ordering';

    /**
     * @return array<string,array<int,array{label:string,slug:string,description:string}>>
     */
    private static function page_groups(): array
    {
        return [
            __('Batch Operations', 'ffl-hub') => [
                [
                    'label' => __('Dealer Batch Optimizer', 'ffl-hub'),
                    'slug' => 'fflhub-dealer-batch-optimizer',
                    'description' => __('Central batch settings, optimizer controls, and latest optimizer makeup.', 'ffl-hub'),
                ],
                [
                    'label' => __('RSR Dealer Batch Queue', 'ffl-hub'),
                    'slug' => 'fflhub-rsr-dealer-batch-queue',
                    'description' => __('RSR dealer-fulfilled batch rows and force-run controls.', 'ffl-hub'),
                ],
                [
                    'label' => __('Bill Hicks Dealer Batch Queue', 'ffl-hub'),
                    'slug' => 'fflhub-bill-hicks-dealer-batch-queue',
                    'description' => __('Bill Hicks dealer-fulfilled batch rows and force-run controls.', 'ffl-hub'),
                ],
                [
                    'label' => __('CSSI Dealer Batch Queue', 'ffl-hub'),
                    'slug' => 'fflhub-cssi-dealer-batch-queue',
                    'description' => __('CSSI dealer-fulfilled batch rows and force-run controls.', 'ffl-hub'),
                ],
                [
                    'label' => __("Davidson's Dealer Batch Queue", 'ffl-hub'),
                    'slug' => 'fflhub-davidsons-dealer-batch-queue',
                    'description' => __("Davidson's dealer-batch rows before they become manual-order follow-up.", 'ffl-hub'),
                ],
                [
                    'label' => __("Lipsey's Dealer Batch Queue", 'ffl-hub'),
                    'slug' => 'fflhub-lipseys-dealer-batch-queue',
                    'description' => __("Lipsey's dealer-fulfilled batch rows and force-run controls.", 'ffl-hub'),
                ],
                [
                    'label' => __('Zanders Dealer Batch Queue', 'ffl-hub'),
                    'slug' => 'fflhub-zanders-dealer-batch-queue',
                    'description' => __('Zanders dealer-fulfilled batch rows and force-run controls.', 'ffl-hub'),
                ],
                [
                    'label' => __('Sports South Dealer Batch Queue', 'ffl-hub'),
                    'slug' => 'fflhub-sports-south-dealer-batch-queue',
                    'description' => __('Sports South dealer-fulfilled batch rows and force-run controls.', 'ffl-hub'),
                ],
            ],
            __('Manual / Follow-Up', 'ffl-hub') => [
                [
                    'label' => __('Dealer Shipment Tracker', 'ffl-hub'),
                    'slug' => 'fflhub-dealer-fulfilled-jobs',
                    'description' => __('Distributor shipment tracking rows after dealer-fulfilled orders are placed.', 'ffl-hub'),
                ],
                [
                    'label' => __('FFL Documents Required', 'ffl-hub'),
                    'slug' => 'fflhub-ffl-documents-required',
                    'description' => __('Upload and email receiving FFL copies for Sports South and Kinsey\'s drop-ship firearm orders.', 'ffl-hub'),
                ],
                [
                    'label' => __('Failed Place Order Jobs', 'ffl-hub'),
                    'slug' => 'fflhub-failed-place-order-jobs',
                    'description' => __('Read-only triage view for failed FFL Hub distributor placement rows.', 'ffl-hub'),
                ],
                [
                    'label' => __("Davidson's Manual Order Status", 'ffl-hub'),
                    'slug' => 'fflhub-davidsons-manual-order-status',
                    'description' => __('Manual Davidson\'s PO entry and completion workflow.', 'ffl-hub'),
                ],
                [
                    'label' => __('Bill Hicks EDI Tests', 'ffl-hub'),
                    'slug' => 'fflhub-bill-hicks-edi-test-orders',
                    'description' => __('Build and upload explicit Bill Hicks 850 test files.', 'ffl-hub'),
                ],
                [
                    'label' => __("Lipsey's Credit Limit", 'ffl-hub'),
                    'slug' => 'fflhub-lipseys-credit-limit',
                    'description' => __("Lipsey's processing-order credit usage view.", 'ffl-hub'),
                ],
                [
                    'label' => __('Zanders Credit Limit', 'ffl-hub'),
                    'slug' => 'fflhub-zanders-credit-limit',
                    'description' => __('Zanders processing-order credit usage view.', 'ffl-hub'),
                ],
            ],
            __('CA Relay Queues', 'ffl-hub') => [
                [
                    'label' => __("Lipsey's CA Relay Batch Queue", 'ffl-hub'),
                    'slug' => 'fflhub-lipseys-ca-relay-batch-queue',
                    'description' => __("Lipsey's non-FFL California relay rows.", 'ffl-hub'),
                ],
                [
                    'label' => __('Zanders CA Relay Batch Queue', 'ffl-hub'),
                    'slug' => 'fflhub-zanders-ca-relay-batch-queue',
                    'description' => __('Zanders non-FFL California relay rows.', 'ffl-hub'),
                ],
                [
                    'label' => __('Sports South CA Relay Batch Queue', 'ffl-hub'),
                    'slug' => 'fflhub-sports-south-ca-relay-batch-queue',
                    'description' => __('Sports South non-FFL California relay rows.', 'ffl-hub'),
                ],
            ],
        ];
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
    }

    public function register_menu_page(): void
    {
        add_menu_page(
            __('FFLHub Distributor Ordering', 'ffl-hub'),
            __('FFLHub Distributor Ordering', 'ffl-hub'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-cart',
            57
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Distributor Ordering Dashboard', 'ffl-hub'),
            __('Dashboard', 'ffl-hub'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public static function ensure_access(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }
    }

    public function render_page(): void
    {
        self::ensure_access();
        ?>
        <div class="wrap fflhub-distributor-ordering">
            <?php self::render_styles(); ?>
            <h1><?php esc_html_e('FFLHub Distributor Ordering', 'ffl-hub'); ?></h1>
            <p class="description">
                <?php esc_html_e('Batch queues, manual order follow-up, test-order tools, and California relay queues for distributor order operations.', 'ffl-hub'); ?>
            </p>

            <div class="fflhub-ordering-grid">
                <?php foreach (self::page_groups() as $group_label => $pages) : ?>
                    <section class="fflhub-ordering-card">
                        <h2><?php echo esc_html((string) $group_label); ?></h2>
                        <div class="fflhub-ordering-links">
                            <?php foreach ($pages as $page) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page['slug'])); ?>">
                                    <strong><?php echo esc_html($page['label']); ?></strong>
                                    <span><?php echo esc_html($page['description']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function render_styles(): void
    {
        ?>
        <style>
            .fflhub-distributor-ordering{max-width:1500px}
            .fflhub-ordering-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-top:18px}
            .fflhub-ordering-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px}
            .fflhub-ordering-card h2{margin:0 0 12px}
            .fflhub-ordering-links{display:flex;flex-direction:column;gap:10px}
            .fflhub-ordering-links a{display:block;text-decoration:none;border:1px solid #dcdcde;border-left:4px solid #2271b1;border-radius:4px;padding:10px 12px;background:#f6f7f7;color:#1d2327}
            .fflhub-ordering-links a:hover{border-color:#2271b1;background:#fff}
            .fflhub-ordering-links strong{display:block;color:#135e96}
            .fflhub-ordering-links span{display:block;margin-top:3px;color:#646970}
        </style>
        <?php
    }
}
