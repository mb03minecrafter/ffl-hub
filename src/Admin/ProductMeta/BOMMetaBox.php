<?php

namespace FFLHub\Admin\ProductMeta;

use FFLHub\BOM\Data\BOMRepository;
use FFLHub\BOM\Services\BOMRowSyncService;
use FFLHub\BOM\Services\ProductLinkResolver;
use FFLHub\BOM\Tables\BOMSchema;
use FFLHub\BOM\Tables\BOMTable;
use FFLHub\Distributor\Core\DistributorHandler;
use FFLHub\Distributor\Models\DistributorOffer;
use FFLHub\Product\ProductMeta;
use FFLHub\Util\DebugLogUtil;
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
    private const DEBUG_CONST  = 'FFLHUB_ADMIN_DEBUG';
    private const LOG_PREFIX   = '[FFLHub][BOM][Admin]';

    private static ?DistributorHandler $handler = null;
    /** @var array<int,bool> */
    private static array $save_guard = [];

    public static function init(?DistributorHandler $handler = null): void
    {
        self::$handler = $handler;

        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_bom_meta'], 1001, 1);
        add_action('save_post_product', [__CLASS__, 'save_bom_meta_fallback'], 1001, 3);
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
                '_row_mode'          => 'seed',
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
                        <?php
                        if (!isset($row['_row_mode'])) {
                            $row['_row_mode'] = 'row';
                        }
                        self::render_bom_row($row, false);
                        ?>
                    <?php endforeach; ?>
                    <?php self::render_bom_row([
                        'name'               => '',
                        'notes'              => '',
                        'qty'                => 1,
                        'source_type'        => BOMSchema::SOURCE_INTERNAL_STOCK,
                        'source_ref'         => '',
                        'manual_unit_price'  => null,
                        'manual_qty_on_hand' => null,
                        '_row_mode'          => 'template',
                    ], true); ?>
                </tbody>
            </table>

            <button type="button" class="button" id="fflhub-bom-add-row">
                <?php echo esc_html__('Add BOM Item', 'ffl-hub'); ?>
            </button>
            <button type="submit" class="button button-secondary" name="fflhub_bom_sync_now" value="1">
                <?php echo esc_html__('Update Source Data', 'ffl-hub'); ?>
            </button>
            <input type="hidden" id="fflhub-bom-payload" name="fflhub_bom_payload" value="" />
            <input type="hidden" name="fflhub_bom_form_present" value="1" />
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

                    var modeField = row.querySelector('.fflhub-bom-row-mode');
                    if (modeField) {
                        modeField.value = 'row';
                    }
                }

                function syncManualQtyState(row) {
                    if (!row) {
                        return;
                    }

                    var sourceTypeField = row.querySelector('select[name="fflhub_bom_source_type[]"]');
                    var manualQtyField = row.querySelector('input[name="fflhub_bom_manual_qty[]"]');
                    if (!sourceTypeField || !manualQtyField) {
                        return;
                    }

                    var isInternal = String(sourceTypeField.value || '') === 'internal_stock';
                    if (!isInternal) {
                        manualQtyField.value = '';
                        manualQtyField.disabled = true;
                        manualQtyField.setAttribute('disabled', 'disabled');
                    } else {
                        manualQtyField.disabled = false;
                        manualQtyField.removeAttribute('disabled');
                    }
                }

                function enableRow(row) {
                    var fields = row.querySelectorAll('input, textarea, select, button');
                    for (var i = 0; i < fields.length; i++) {
                        fields[i].disabled = false;
                        fields[i].removeAttribute('disabled');
                    }
                }

                function readValue(row, selector, fallback) {
                    var field = row.querySelector(selector);
                    if (!field) {
                        return (typeof fallback === 'string') ? fallback : '';
                    }

                    return String(field.value || '');
                }

                function refreshPayload() {
                    var payloadField = document.getElementById('fflhub-bom-payload');
                    if (!payloadField) {
                        return;
                    }

                    var rows = rowsWrap.querySelectorAll('tr');
                    var payload = [];

                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        if (row.classList.contains('fflhub-bom-template')) {
                            continue;
                        }

                        var sourceType = readValue(row, 'select[name="fflhub_bom_source_type[]"]', 'internal_stock');
                        var manualQty = '';
                        if (sourceType === 'internal_stock') {
                            manualQty = readValue(row, 'input[name="fflhub_bom_manual_qty[]"]', '');
                        }

                        payload.push({
                            name: readValue(row, 'input[name="fflhub_bom_name[]"]', ''),
                            notes: readValue(row, 'textarea[name="fflhub_bom_notes[]"]', ''),
                            qty: readValue(row, 'input[name="fflhub_bom_qty[]"]', '1'),
                            source_type: sourceType,
                            source_ref: readValue(row, 'input[name="fflhub_bom_source_ref[]"]', ''),
                            manual_unit_price: readValue(row, 'input[name="fflhub_bom_manual_price[]"]', ''),
                            manual_qty_on_hand: manualQty,
                            row_mode: 'row'
                        });
                    }

                    payloadField.value = JSON.stringify(payload);
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
                    enableRow(clone);
                    resetRow(clone);
                    syncManualQtyState(clone);
                    rowsWrap.appendChild(clone);
                    refreshPayload();
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
                    refreshPayload();
                });

                rowsWrap.addEventListener('input', refreshPayload);
                rowsWrap.addEventListener('change', refreshPayload);
                rowsWrap.addEventListener('change', function(event) {
                    var target = event.target;
                    if (!target || target.name !== 'fflhub_bom_source_type[]') {
                        return;
                    }

                    var row = target.closest('tr');
                    if (!row) {
                        return;
                    }

                    syncManualQtyState(row);
                    refreshPayload();
                });

                if (enabledBox) {
                    enabledBox.addEventListener('change', toggleEditor);
                }

                var postForm = document.getElementById('post');
                if (postForm) {
                    postForm.addEventListener('submit', refreshPayload);
                }

                var initRows = rowsWrap.querySelectorAll('tr');
                for (var i = 0; i < initRows.length; i++) {
                    var initRow = initRows[i];
                    if (initRow.classList.contains('fflhub-bom-template')) {
                        continue;
                    }
                    syncManualQtyState(initRow);
                }

                toggleEditor();
                refreshPayload();
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
        $row_mode = trim((string) ($row['_row_mode'] ?? ($is_template ? 'template' : 'row')));

        $preview = $is_template
            ? ['unit_price' => '-', 'stock' => '-']
            : self::resolve_preview_for_row([
                'source_type'         => $source_type,
                'source_ref'          => $source_ref,
                'manual_unit_price'   => $manual_unit_price,
                'manual_qty_on_hand'  => $manual_qty_on_hand,
                'resolved_unit_price' => self::to_float_or_null($row['resolved_unit_price'] ?? null),
                'resolved_stock_state' => trim((string) ($row['resolved_stock_state'] ?? '')),
                'resolved_stock_qty'   => self::to_int_or_null($row['resolved_stock_qty'] ?? null),
                'resolved_error_code'  => trim((string) ($row['resolved_error_code'] ?? '')),
            ]);

        $row_class = $is_template ? 'fflhub-bom-template' : '';
        $row_style = $is_template ? 'display:none;' : '';
        $disabled = $is_template ? 'disabled="disabled"' : '';
        $manual_qty_disabled = ($is_template || $source_type !== BOMSchema::SOURCE_INTERNAL_STOCK)
            ? 'disabled="disabled"'
            : '';
        $manual_qty_value = ($source_type === BOMSchema::SOURCE_INTERNAL_STOCK && $manual_qty_on_hand !== null)
            ? (string) $manual_qty_on_hand
            : '';
        $source_options = self::source_options();
?>
        <tr class="<?php echo esc_attr($row_class); ?>" style="<?php echo esc_attr($row_style); ?>">
            <td>
                <input type="hidden" class="fflhub-bom-row-mode" name="fflhub_bom_row_mode[]" value="<?php echo esc_attr($row_mode); ?>" <?php echo $disabled; ?> />
                <input
                    type="text"
                    name="fflhub_bom_name[]"
                    value="<?php echo esc_attr($name); ?>"
                    style="width:100%;"
                    placeholder="<?php echo esc_attr__('Component name', 'ffl-hub'); ?>"
                    <?php echo $disabled; ?>
                />
            </td>
            <td>
                <textarea
                    name="fflhub_bom_notes[]"
                    rows="2"
                    style="width:100%;"
                    placeholder="<?php echo esc_attr__('Optional notes', 'ffl-hub'); ?>"
                    <?php echo $disabled; ?>
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
                    <?php echo $disabled; ?>
                />
            </td>
            <td style="width:150px;">
                <select name="fflhub_bom_source_type[]" style="width:100%;" <?php echo $disabled; ?>>
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
                    <?php echo $disabled; ?>
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
                    <?php echo $disabled; ?>
                />
            </td>
            <td style="width:100px;">
                <input
                    type="number"
                    step="1"
                    min="0"
                    name="fflhub_bom_manual_qty[]"
                    value="<?php echo esc_attr($manual_qty_value); ?>"
                    style="width:100%;"
                    placeholder="<?php echo esc_attr__('Internal only', 'ffl-hub'); ?>"
                    <?php echo $manual_qty_disabled; ?>
                />
            </td>
            <td class="fflhub-bom-derived-price" style="white-space:nowrap;">
                <?php echo esc_html((string) ($preview['unit_price'] ?? '-')); ?>
            </td>
            <td class="fflhub-bom-derived-stock" style="white-space:nowrap;">
                <?php echo esc_html((string) ($preview['stock'] ?? '-')); ?>
            </td>
            <td style="width:78px;">
                <button type="button" class="button-link-delete fflhub-bom-remove-row" <?php echo $disabled; ?>>
                    <?php echo esc_html__('Remove', 'ffl-hub'); ?>
                </button>
            </td>
        </tr>
<?php
    }

    public static function save_bom_meta_fallback(int $post_id, WP_Post $post, bool $update): void
    {
        self::debug_ctx('save_bom_meta_fallback called', [
            'post_id' => $post_id,
            'update' => $update ? 1 : 0,
            'has_nonce' => isset($_POST[self::NONCE_FIELD]) ? 1 : 0,
        ]);

        if (!isset($_POST[self::NONCE_FIELD])) {
            self::debug('save_bom_meta_fallback skipped: nonce missing');
            return;
        }

        self::save_bom_meta($post_id);
    }

    public static function save_bom_meta(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            self::debug_ctx('save_bom_meta skipped: autosave', ['post_id' => $post_id]);
            return;
        }

        $has_nonce = isset($_POST[self::NONCE_FIELD]);
        $nonce_ok = $has_nonce
            && wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])),
                self::NONCE_ACTION
            );

        self::debug_ctx('save_bom_meta entry', [
            'post_id' => $post_id,
            'has_nonce' => $has_nonce ? 1 : 0,
            'nonce_ok' => $nonce_ok ? 1 : 0,
            'has_form_present' => isset($_POST['fflhub_bom_form_present']) ? 1 : 0,
            'enabled_posted' => isset($_POST['fflhub_bom_enabled']) ? 1 : 0,
            'has_payload' => isset($_POST['fflhub_bom_payload']) ? 1 : 0,
            'payload_len' => isset($_POST['fflhub_bom_payload']) ? strlen((string) wp_unslash($_POST['fflhub_bom_payload'])) : 0,
            'name_count' => isset($_POST['fflhub_bom_name']) && is_array($_POST['fflhub_bom_name']) ? count($_POST['fflhub_bom_name']) : 0,
        ]);

        if (
            !$has_nonce ||
            !$nonce_ok
        ) {
            self::debug_ctx('save_bom_meta skipped: nonce validation failed', ['post_id' => $post_id]);
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            self::debug_ctx('save_bom_meta skipped: capability failed', ['post_id' => $post_id]);
            return;
        }

        if (!isset($_POST['fflhub_bom_form_present'])) {
            self::debug_ctx('save_bom_meta skipped: form marker missing', ['post_id' => $post_id]);
            return;
        }

        if (isset(self::$save_guard[$post_id])) {
            self::debug_ctx('save_bom_meta skipped: guard hit', ['post_id' => $post_id]);
            return;
        }
        self::$save_guard[$post_id] = true;

        $enabled = isset($_POST['fflhub_bom_enabled']) ? 1 : 0;
        update_post_meta($post_id, ProductMeta::FFLHUB_BOM_ENABLED_META, $enabled);

        $table = self::get_bom_table();
        self::ensure_table_ready($table);

        if ($enabled !== 1) {
            BOMRepository::replace_rows_for_parent($table, $post_id, []);
            self::debug_ctx('save_bom_meta disabled: rows cleared', ['post_id' => $post_id]);
            return;
        }

        $rows = self::collect_rows_from_request();
        self::debug_ctx('save_bom_meta collected rows', [
            'post_id' => $post_id,
            'row_count' => count($rows),
            'sample' => self::row_sample($rows),
        ]);
        BOMRepository::replace_rows_for_parent($table, $post_id, $rows);
        $stored_rows = BOMRepository::get_rows_for_parent($table, $post_id);
        self::debug_ctx('save_bom_meta rows persisted', [
            'post_id' => $post_id,
            'stored_count' => count($stored_rows),
            'stored_sample' => self::row_sample($stored_rows),
        ]);

        // Always refresh cached source data on save (including draft saves).
        // Manual price override is respected by BOMRowSyncService.
        $sync_result = BOMRowSyncService::sync_parent_rows($table, self::$handler, $post_id);
        self::debug_ctx('save_bom_meta sync completed', array_merge(
            ['post_id' => $post_id],
            $sync_result
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function collect_rows_from_request(): array
    {
        $payload = isset($_POST['fflhub_bom_payload'])
            ? wp_unslash($_POST['fflhub_bom_payload'])
            : '';

        self::debug_ctx('collect_rows_from_request start', [
            'has_payload' => is_string($payload) && trim($payload) !== '' ? 1 : 0,
            'payload_len' => is_string($payload) ? strlen($payload) : 0,
        ]);

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $rows = [];
                foreach ($decoded as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $rows[] = [
                        'name'               => sanitize_text_field((string) ($row['name'] ?? '')),
                        'notes'              => sanitize_textarea_field((string) ($row['notes'] ?? '')),
                        'qty'                => sanitize_text_field((string) ($row['qty'] ?? '1')),
                        'source_type'        => sanitize_text_field((string) ($row['source_type'] ?? '')),
                        'source_ref'         => sanitize_text_field((string) ($row['source_ref'] ?? '')),
                        'manual_unit_price'  => sanitize_text_field((string) ($row['manual_unit_price'] ?? '')),
                        'manual_qty_on_hand' => sanitize_text_field((string) ($row['manual_qty_on_hand'] ?? '')),
                        'row_mode'           => 'row',
                    ];
                }

                self::debug_ctx('collect_rows_from_request used payload', [
                    'rows' => count($rows),
                    'sample' => self::row_sample($rows),
                ]);
                return $rows;
            }

            self::debug('collect_rows_from_request payload decode failed, falling back to array inputs');
        }

        $names = isset($_POST['fflhub_bom_name']) ? (array) wp_unslash($_POST['fflhub_bom_name']) : [];
        $notes = isset($_POST['fflhub_bom_notes']) ? (array) wp_unslash($_POST['fflhub_bom_notes']) : [];
        $qtys = isset($_POST['fflhub_bom_qty']) ? (array) wp_unslash($_POST['fflhub_bom_qty']) : [];
        $source_types = isset($_POST['fflhub_bom_source_type']) ? (array) wp_unslash($_POST['fflhub_bom_source_type']) : [];
        $source_refs = isset($_POST['fflhub_bom_source_ref']) ? (array) wp_unslash($_POST['fflhub_bom_source_ref']) : [];
        $manual_prices = isset($_POST['fflhub_bom_manual_price']) ? (array) wp_unslash($_POST['fflhub_bom_manual_price']) : [];
        $manual_qtys = isset($_POST['fflhub_bom_manual_qty']) ? (array) wp_unslash($_POST['fflhub_bom_manual_qty']) : [];
        $row_modes = isset($_POST['fflhub_bom_row_mode']) ? (array) wp_unslash($_POST['fflhub_bom_row_mode']) : [];

        $max_rows = max(
            count($names),
            count($notes),
            count($qtys),
            count($source_types),
            count($source_refs),
            count($manual_prices),
            count($manual_qtys),
            count($row_modes)
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
                'row_mode'           => sanitize_text_field((string) ($row_modes[$i] ?? 'row')),
            ];
        }

        self::debug_ctx('collect_rows_from_request used array inputs', [
            'max_rows' => $max_rows,
            'name_count' => count($names),
            'notes_count' => count($notes),
            'qty_count' => count($qtys),
            'source_type_count' => count($source_types),
            'source_ref_count' => count($source_refs),
            'manual_price_count' => count($manual_prices),
            'manual_qty_count' => count($manual_qtys),
            'row_mode_count' => count($row_modes),
            'sample' => self::row_sample($rows),
        ]);

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function load_rows(int $product_id): array
    {
        $table = self::get_bom_table();
        self::ensure_table_ready($table);
        return BOMRepository::get_rows_for_parent($table, $product_id);
    }

    private static function get_bom_table(): BOMTable
    {
        return new BOMTable(new BOMSchema());
    }

    private static function ensure_table_ready(BOMTable $table): void
    {
        // dbDelta is idempotent and also applies schema additions (new columns/indexes).
        $table->createTables();
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

            $manual_unit_price = self::to_float_or_null($row['manual_unit_price'] ?? null);
            $resolved = ProductLinkResolver::resolve($source_ref);
            if (!empty($resolved['resolved'])) {
                $stock_state = (string) ($resolved['stock_state'] ?? 'unknown');
                $resolved_error_code = strtolower(trim((string) ($resolved['error_code'] ?? '')));
                if ($stock_state === 'out_of_stock') {
                    $stock_label = __('Out of stock', 'ffl-hub');
                } elseif ($stock_state === 'in_stock') {
                    $stock_label = __('In stock', 'ffl-hub');
                } elseif (self::is_blocked_stock_error($resolved_error_code)) {
                    $stock_label = __('Manual Verification', 'ffl-hub');
                } else {
                    $stock_label = __('Unknown stock', 'ffl-hub');
                }

                $resolved_price = self::to_float_or_null($resolved['unit_price'] ?? null);
                if ($manual_unit_price !== null) {
                    $resolved_price = $manual_unit_price;
                }

                return [
                    'unit_price' => self::format_price($resolved_price),
                    'stock'      => $stock_label,
                ];
            }

            $error_code = (string) ($resolved['error_code'] ?? '');
            if ($error_code === 'local_product_missing') {
                return ['unit_price' => '-', 'stock' => __('Linked product not found', 'ffl-hub')];
            }
            if (self::is_blocked_stock_error($error_code)) {
                return ['unit_price' => self::format_price($manual_unit_price), 'stock' => __('Manual Verification', 'ffl-hub')];
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
     * @param array<string,mixed> $row
     * @return array{unit_price:string,stock:string}
     */
    private static function resolve_preview_for_row(array $row): array
    {
        $source_type = BOMRepository::normalize_source_type((string) ($row['source_type'] ?? ''));
        $resolved_price = self::to_float_or_null($row['resolved_unit_price'] ?? null);
        $resolved_state = trim((string) ($row['resolved_stock_state'] ?? ''));
        $resolved_qty = self::to_int_or_null($row['resolved_stock_qty'] ?? null);
        $resolved_error_code = trim((string) ($row['resolved_error_code'] ?? ''));
        $manual_price = self::to_float_or_null($row['manual_unit_price'] ?? null);
        $manual_qty = ($source_type === BOMSchema::SOURCE_INTERNAL_STOCK)
            ? self::to_int_or_null($row['manual_qty_on_hand'] ?? null)
            : null;
        $has_resolved_stock = ($resolved_qty !== null)
            || in_array(strtolower($resolved_state), ['in_stock', 'out_of_stock', 'manual_verification'], true);

        if ($manual_price !== null) {
            $resolved_price = $manual_price;
        }

        if ($manual_qty !== null) {
            $resolved_qty = max(0, $manual_qty);
            $resolved_state = $resolved_qty > 0 ? 'in_stock' : 'out_of_stock';
            $has_resolved_stock = true;
        }

        if (!$has_resolved_stock && self::is_blocked_stock_error($resolved_error_code)) {
            return [
                'unit_price' => self::format_price($resolved_price),
                'stock'      => __('Manual Verification', 'ffl-hub'),
            ];
        }

        if ($resolved_price !== null && !$has_resolved_stock) {
            $source_preview = self::resolve_source_preview($row);
            return [
                'unit_price' => self::format_price($resolved_price),
                'stock'      => (string) ($source_preview['stock'] ?? __('Unknown', 'ffl-hub')),
            ];
        }

        if ($resolved_price !== null || $has_resolved_stock) {
            return [
                'unit_price' => self::format_price($resolved_price),
                'stock'      => self::format_stock_label($resolved_state, $resolved_qty),
            ];
        }

        return self::resolve_source_preview($row);
    }

    private static function format_stock_label(string $state, ?int $qty): string
    {
        $state = strtolower(trim($state));

        if ($qty !== null) {
            return (string) max(0, $qty);
        }

        if ($state === 'in_stock') {
            return __('In stock', 'ffl-hub');
        }
        if ($state === 'out_of_stock') {
            return __('Out of stock', 'ffl-hub');
        }
        if ($state === 'manual_verification') {
            return __('Manual Verification', 'ffl-hub');
        }

        return __('Unknown', 'ffl-hub');
    }

    private static function is_blocked_stock_error(string $error_code): bool
    {
        $error_code = strtolower(trim($error_code));
        if ($error_code === '') {
            return false;
        }

        return in_array($error_code, ['external_http_403', 'external_http_429'], true);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function row_sample(array $rows, int $max = 10): array
    {
        $out = [];
        $limit = max(1, $max);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'qty' => trim((string) ($row['qty'] ?? '')),
                'source_type' => trim((string) ($row['source_type'] ?? '')),
                'source_ref' => trim((string) ($row['source_ref'] ?? '')),
                'manual_unit_price' => trim((string) ($row['manual_unit_price'] ?? '')),
                'manual_qty_on_hand' => trim((string) ($row['manual_qty_on_hand'] ?? '')),
                'row_mode' => trim((string) ($row['row_mode'] ?? ($row['_row_mode'] ?? ''))),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private static function debug(string $msg): void
    {
        DebugLogUtil::log(self::DEBUG_CONST, self::LOG_PREFIX, $msg);
    }

    /**
     * @param array<string,mixed> $ctx
     */
    private static function debug_ctx(string $msg, array $ctx): void
    {
        DebugLogUtil::log_ctx(self::DEBUG_CONST, self::LOG_PREFIX, $msg, $ctx);
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
