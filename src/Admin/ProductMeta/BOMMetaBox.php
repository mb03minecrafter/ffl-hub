<?php

namespace FFLHub\Admin\ProductMeta;

use FFLHub\BOM\Data\BOMRepository;
use FFLHub\BOM\Services\ProductLinkResolver;
use FFLHub\BOM\Tables\BOMSchema;
use FFLHub\BOM\Tables\BOMTable;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Product\ProductMeta;
use WC_Product;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product admin UI for BOM rows.
 */
final class BOMMetaBox
{
    private const NONCE_FIELD  = 'fflhub_bom_nonce';
    private const NONCE_ACTION = 'fflhub_save_product_bom';

    private static ?DistributorHandler $handler = null;

    public static function init(?DistributorHandler $handler = null): void
    {
        self::$handler = $handler;

        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_bom_meta'], 1001, 1);
    }

    public static function add_meta_box(): void
    {
        add_meta_box(
            'fflhub_bom_meta',
            __('FFLHub Build BOM', 'ffl-hub'),
            [__CLASS__, 'render_meta_box'],
            'product',
            'normal',
            'default'
        );
    }

    public static function render_meta_box(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
        if (!($product instanceof WC_Product)) {
            echo '<p>' . esc_html__('Unable to load product.', 'ffl-hub') . '</p>';
            return;
        }

        $enabled = ((int) $product->get_meta(ProductMeta::FFLHUB_BOM_ENABLED_META, true) === 1);
        $rows    = self::load_rows((int) $post->ID);

        if (empty($rows)) {
            $rows[] = [
                'name'               => '',
                'notes'              => '',
                'qty'                => 1,
                'source_type'        => BOMSchema::SOURCE_INTERNAL_STOCK,
                'source_ref'         => '',
                'manual_unit_price'  => null,
                'manual_qty_on_hand' => null,
            ];
        }

?>
        <div style="margin-bottom:10px;">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" id="fflhub_bom_enabled" name="fflhub_bom_enabled" value="1" <?php checked(true, $enabled); ?> />
                <strong><?php echo esc_html__('Enable BOM For This Product', 'ffl-hub'); ?></strong>
            </label>
            <p style="margin:6px 0 0;color:#6b7280;">
                <?php echo esc_html__('Define component rows used to build this product. Rows are stored in the BOM table.', 'ffl-hub'); ?>
            </p>
        </div>

        <div id="fflhub-bom-editor" style="border:1px solid #e5e7eb;padding:10px;">
            <table class="widefat striped" style="margin-bottom:10px;">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Name', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Notes', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Qty', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Source', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Source Ref', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Manual Price', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Manual Qty', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Resolved Price', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Stock Status', 'ffl-hub'); ?></th>
                        <th><?php echo esc_html__('Actions', 'ffl-hub'); ?></th>
                    </tr>
                </thead>
                <tbody id="fflhub-bom-rows">
                    <?php foreach ($rows as $row) : ?>
                        <?php self::render_bom_row($row, false); ?>
                    <?php endforeach; ?>
                    <?php self::render_bom_row([
                        'name'               => '',
                        'notes'              => '',
                        'qty'                => 1,
                        'source_type'        => BOMSchema::SOURCE_INTERNAL_STOCK,
                        'source_ref'         => '',
                        'manual_unit_price'  => null,
                        'manual_qty_on_hand' => null,
                    ], true); ?>
                </tbody>
            </table>

            <button type="button" class="button" id="fflhub-bom-add-row">
                <?php echo esc_html__('Add BOM Item', 'ffl-hub'); ?>
            </button>
        </div>

        <script>
            (function() {
                var editor = document.getElementById('fflhub-bom-editor');
                var rowsWrap = document.getElementById('fflhub-bom-rows');
                var addBtn = document.getElementById('fflhub-bom-add-row');
                var enabledBox = document.getElementById('fflhub_bom_enabled');
                if (!rowsWrap || !addBtn) {
                    return;
                }

                function toggleEditor() {
                    if (!editor || !enabledBox) {
                        return;
                    }
                    editor.style.display = enabledBox.checked ? '' : 'none';
                }

                function resetRow(row) {
                    var fields = row.querySelectorAll('input, textarea, select');
                    for (var i = 0; i < fields.length; i++) {
                        var field = fields[i];
                        if (field.tagName === 'SELECT') {
                            field.value = 'internal_stock';
                            continue;
                        }
                        if (field.type === 'button') {
                            continue;
                        }
                        field.value = '';
                    }

                    var qtyField = row.querySelector('input[name="fflhub_bom_qty[]"]');
                    if (qtyField) {
                        qtyField.value = '1';
                    }

                    var priceCell = row.querySelector('.fflhub-bom-derived-price');
                    var stockCell = row.querySelector('.fflhub-bom-derived-stock');
                    if (priceCell) {
                        priceCell.textContent = '-';
                    }
                    if (stockCell) {
                        stockCell.textContent = '-';
                    }
                }

                addBtn.addEventListener('click', function(event) {
                    event.preventDefault();

                    var template = rowsWrap.querySelector('tr.fflhub-bom-template');
                    if (!template) {
                        return;
                    }

                    var clone = template.cloneNode(true);
                    clone.classList.remove('fflhub-bom-template');
                    clone.style.display = '';
                    resetRow(clone);
                    rowsWrap.appendChild(clone);
                });

                rowsWrap.addEventListener('click', function(event) {
                    var target = event.target;
                    if (!target || !target.classList.contains('fflhub-bom-remove-row')) {
                        return;
                    }

                    event.preventDefault();
                    var row = target.closest('tr');
                    if (!row || row.classList.contains('fflhub-bom-template')) {
                        return;
                    }

                    row.parentNode.removeChild(row);
                });

                if (enabledBox) {
                    enabledBox.addEventListener('change', toggleEditor);
                }

                toggleEditor();
            })();
        </script>
<?php
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function render_bom_row(array $row, bool $is_template): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        $notes = (string) ($row['notes'] ?? '');
        $qty = (float) ($row['qty'] ?? 1.0);
        if (!is_finite($qty) || $qty <= 0.0) {
            $qty = 1.0;
        }

        $source_type = BOMRepository::normalize_source_type((string) ($row['source_type'] ?? ''));
        if ($source_type === '') {
            $source_type = BOMSchema::SOURCE_INTERNAL_STOCK;
        }

        $source_ref = trim((string) ($row['source_ref'] ?? ''));
        $manual_unit_price = self::to_float_or_null($row['manual_unit_price'] ?? null);
        $manual_qty_on_hand = self::to_int_or_null($row['manual_qty_on_hand'] ?? null);

        $preview = $is_template
            ? ['unit_price' => '-', 'stock' => '-']
            : self::resolve_source_preview([
                'source_type'        => $source_type,
                'source_ref'         => $source_ref,
                'manual_unit_price'  => $manual_unit_price,
                'manual_qty_on_hand' => $manual_qty_on_hand,
            ]);

        $row_class = $is_template ? 'fflhub-bom-template' : '';
        $row_style = $is_template ? 'display:none;' : '';
        $source_options = self::source_options();
?>
        <tr class="<?php echo esc_attr($row_class); ?>" style="<?php echo esc_attr($row_style); ?>">
            <td>
                <input
                    type="text"
                    name="fflhub_bom_name[]"
                    value="<?php echo esc_attr($name); ?>"
                    style="width:100%;"
                    placeholder="<?php echo esc_attr__('Component name', 'ffl-hub'); ?>"
                />
            </td>
            <td>
                <textarea
                    name="fflhub_bom_notes[]"
                    rows="2"
                    style="width:100%;"
                    placeholder="<?php echo esc_attr__('Optional notes', 'ffl-hub'); ?>"
                ><?php echo esc_textarea($notes); ?></textarea>
            </td>
            <td style="width:90px;">
                <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    name="fflhub_bom_qty[]"
                    value="<?php echo esc_attr(self::format_qty($qty)); ?>"
                    style="width:100%;"
                />
            </td>
            <td style="width:150px;">
                <select name="fflhub_bom_source_type[]" style="width:100%;">
                    <?php foreach ($source_options as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($source_type, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td style="width:130px;">
                <input
                    type="text"
                    name="fflhub_bom_source_ref[]"
                    value="<?php echo esc_attr($source_ref); ?>"
                    style="width:100%;"
                    placeholder="<?php echo esc_attr__('UPC, URL, or Product ID', 'ffl-hub'); ?>"
                />
            </td>
            <td style="width:110px;">
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="fflhub_bom_manual_price[]"
                    value="<?php echo esc_attr($manual_unit_price !== null ? (string) $manual_unit_price : ''); ?>"
                    style="width:100%;"
                    placeholder="0.00"
                />
            </td>
            <td style="width:100px;">
                <input
                    type="number"
                    step="1"
                    min="0"
                    name="fflhub_bom_manual_qty[]"
                    value="<?php echo esc_attr($manual_qty_on_hand !== null ? (string) $manual_qty_on_hand : ''); ?>"
                    style="width:100%;"
                    placeholder="0"
                />
            </td>
            <td class="fflhub-bom-derived-price" style="white-space:nowrap;">
                <?php echo esc_html((string) ($preview['unit_price'] ?? '-')); ?>
            </td>
            <td class="fflhub-bom-derived-stock" style="white-space:nowrap;">
                <?php echo esc_html((string) ($preview['stock'] ?? '-')); ?>
            </td>
            <td style="width:78px;">
                <button type="button" class="button-link-delete fflhub-bom-remove-row">
                    <?php echo esc_html__('Remove', 'ffl-hub'); ?>
                </button>
            </td>
        </tr>
<?php
    }

    public static function save_bom_meta(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])),
                self::NONCE_ACTION
            )
        ) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $enabled = isset($_POST['fflhub_bom_enabled']) ? 1 : 0;
        update_post_meta($post_id, ProductMeta::FFLHUB_BOM_ENABLED_META, $enabled);

        $rows = self::collect_rows_from_request();
        $table = self::get_bom_table();
        self::ensure_table_exists($table);

        BOMRepository::replace_rows_for_parent($table, $post_id, $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function collect_rows_from_request(): array
    {
        $names = isset($_POST['fflhub_bom_name']) ? (array) wp_unslash($_POST['fflhub_bom_name']) : [];
        $notes = isset($_POST['fflhub_bom_notes']) ? (array) wp_unslash($_POST['fflhub_bom_notes']) : [];
        $qtys = isset($_POST['fflhub_bom_qty']) ? (array) wp_unslash($_POST['fflhub_bom_qty']) : [];
        $source_types = isset($_POST['fflhub_bom_source_type']) ? (array) wp_unslash($_POST['fflhub_bom_source_type']) : [];
        $source_refs = isset($_POST['fflhub_bom_source_ref']) ? (array) wp_unslash($_POST['fflhub_bom_source_ref']) : [];
        $manual_prices = isset($_POST['fflhub_bom_manual_price']) ? (array) wp_unslash($_POST['fflhub_bom_manual_price']) : [];
        $manual_qtys = isset($_POST['fflhub_bom_manual_qty']) ? (array) wp_unslash($_POST['fflhub_bom_manual_qty']) : [];

        $max_rows = max(
            count($names),
            count($notes),
            count($qtys),
            count($source_types),
            count($source_refs),
            count($manual_prices),
            count($manual_qtys)
        );

        $rows = [];

        for ($i = 0; $i < $max_rows; $i++) {
            $rows[] = [
                'name'               => sanitize_text_field((string) ($names[$i] ?? '')),
                'notes'              => sanitize_textarea_field((string) ($notes[$i] ?? '')),
                'qty'                => sanitize_text_field((string) ($qtys[$i] ?? '1')),
                'source_type'        => sanitize_text_field((string) ($source_types[$i] ?? '')),
                'source_ref'         => sanitize_text_field((string) ($source_refs[$i] ?? '')),
                'manual_unit_price'  => sanitize_text_field((string) ($manual_prices[$i] ?? '')),
                'manual_qty_on_hand' => sanitize_text_field((string) ($manual_qtys[$i] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function load_rows(int $product_id): array
    {
        return BOMRepository::get_rows_for_parent(self::get_bom_table(), $product_id);
    }

    private static function get_bom_table(): BOMTable
    {
        return new BOMTable(new BOMSchema());
    }

    private static function ensure_table_exists(BOMTable $table): void
    {
        global $wpdb;

        $table_name = $table->get_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if (!is_string($exists) || $exists === '') {
            $table->createTables();
        }
    }

    /**
     * @return array<string,string>
     */
    private static function source_options(): array
    {
        return [
            BOMSchema::SOURCE_DISTRIBUTOR_UPC => __('Distributor UPC', 'ffl-hub'),
            BOMSchema::SOURCE_PRODUCT_LINK    => __('Product Link', 'ffl-hub'),
            BOMSchema::SOURCE_INTERNAL_STOCK  => __('Internal Stock', 'ffl-hub'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{unit_price:string,stock:string}
     */
    private static function resolve_source_preview(array $row): array
    {
        $source_type = BOMRepository::normalize_source_type((string) ($row['source_type'] ?? ''));
        $source_ref = trim((string) ($row['source_ref'] ?? ''));

        if ($source_type === BOMSchema::SOURCE_DISTRIBUTOR_UPC) {
            $upc = BOMRepository::normalize_upc($source_ref);
            if ($upc === '') {
                return ['unit_price' => '-', 'stock' => __('Missing UPC', 'ffl-hub')];
            }

            if (!(self::$handler instanceof DistributorHandler)) {
                return ['unit_price' => '-', 'stock' => __('Distributor handler unavailable', 'ffl-hub')];
            }

            $lookup = self::$handler->get_payloads_for_upc($upc, false);
            $offer = $lookup->best_default();
            if (!($offer instanceof DistributorOffer)) {
                return ['unit_price' => '-', 'stock' => __('No distributor offer found', 'ffl-hub')];
            }

            $price = $offer->get_true_cost();
            if ($price === null) {
                $fallback_price = (float) ($offer->product->price ?? 0.0);
                $price = $fallback_price > 0.0 ? $fallback_price : null;
            }

            $qty = $offer->get_quantity();
            $stock = ($qty !== null)
                ? (string) $qty
                : __('Unknown qty', 'ffl-hub');

            return [
                'unit_price' => self::format_price($price),
                'stock'      => $stock,
            ];
        }

        if ($source_type === BOMSchema::SOURCE_PRODUCT_LINK) {
            if ($source_ref === '') {
                return ['unit_price' => '-', 'stock' => __('Missing product link', 'ffl-hub')];
            }

            $resolved = ProductLinkResolver::resolve($source_ref);
            if (!empty($resolved['resolved'])) {
                $stock_state = (string) ($resolved['stock_state'] ?? 'unknown');
                $stock_label = ($stock_state === 'out_of_stock')
                    ? __('Out of stock', 'ffl-hub')
                    : __('In stock', 'ffl-hub');

                return [
                    'unit_price' => self::format_price(self::to_float_or_null($resolved['unit_price'] ?? null)),
                    'stock'      => $stock_label,
                ];
            }

            $error_code = (string) ($resolved['error_code'] ?? '');
            if ($error_code === 'local_product_missing') {
                return ['unit_price' => '-', 'stock' => __('Linked product not found', 'ffl-hub')];
            }

            return [
                'unit_price' => '-',
                'stock'      => __('Link not resolved to a product', 'ffl-hub'),
            ];
        }

        $manual_price = self::to_float_or_null($row['manual_unit_price'] ?? null);
        $manual_qty = self::to_int_or_null($row['manual_qty_on_hand'] ?? null);

        return [
            'unit_price' => self::format_price($manual_price),
            'stock'      => ($manual_qty !== null)
                ? (string) $manual_qty
                : __('Unknown qty', 'ffl-hub'),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function to_float_or_null($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $num = (float) $raw;
        if (!is_finite($num)) {
            return null;
        }

        return max(0.0, $num);
    }

    /**
     * @param mixed $value
     */
    private static function to_int_or_null($value): ?int
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return max(0, (int) $raw);
    }

    private static function format_price(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return '$' . number_format(max(0.0, $value), 2, '.', '');
    }

    private static function format_qty(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, '.');

        return ($formatted === '') ? '1' : $formatted;
    }
}
