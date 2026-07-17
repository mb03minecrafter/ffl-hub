<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Product\State\ProductStateStore;
use FFLHub\Receiving\ReceivingTestShipmentStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug-only print helper for the Receiving page.
 *
 * The real receiving workflow is scanner-driven, so this page creates a small
 * 4x6-friendly barcode sheet with dummy tracking, UPC, and serial-number
 * barcodes. It also stores a debug-only shipment fixture so the Receiving page
 * can find the dummy tracking number when debug mode is enabled.
 */
final class ReceivingTestLabelsPage
{
    private const PAGE_SLUG = 'fflhub-receiving-test-labels';
    private const CODE128_START_B = 104;
    private const CODE128_STOP = 106;

    /**
     * Code 128 symbol patterns, indexed by code value.
     *
     * Each digit is a module width. Bars and spaces alternate, starting with a
     * bar. The stop code has seven widths; normal symbols have six.
     *
     * @var string[]
     */
    private const CODE128_PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu_page(): void
    {
        add_submenu_page(
            AdminPage::get_page_slug(),
            __('Receiving Test Labels', 'ffl-hub'),
            __('Receiving Test Labels', 'ffl-hub'),
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

        wp_enqueue_style(
            'fflhub-receiving-test-labels',
            FFLHUB_PLUGIN_URL . 'assets/css/fflhub-receiving-test-labels.css',
            [],
            (string) filemtime(FFLHUB_PLUGIN_PATH . 'assets/css/fflhub-receiving-test-labels.css')
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-hub'));
        }

        $tracking = $this->request_text('tracking');
        $serial_prefix = $this->request_text('serial_prefix');
        $upc_text = $this->request_textarea('upcs');
        if ($tracking === '') {
            $tracking = $this->dummy_tracking();
        }
        if ($serial_prefix === '') {
            $serial_prefix = 'TEST';
        }

        $rows = $upc_text !== ''
            ? $this->rows_from_text($upc_text, $serial_prefix)
            : $this->sample_rows($serial_prefix);
        $stored = false;
        if (!empty($rows)) {
            $stored = (new ReceivingTestShipmentStore())->upsert($this->debug_shipment_payload($tracking, $rows));
        }

        ?>
        <div class="wrap fflhub-receiving-labels-page">
            <div class="fflhub-receiving-labels-toolbar">
                <div>
                    <h1><?php esc_html_e('Receiving Test Labels', 'ffl-hub'); ?></h1>
                    <p><?php esc_html_e('Print dummy scanner labels and register a debug-only receiving shipment fixture.', 'ffl-hub'); ?></p>
                </div>
                <button type="button" class="button button-primary" onclick="window.print()">
                    <?php esc_html_e('Print 4x6 Labels', 'ffl-hub'); ?>
                </button>
            </div>

            <form method="get" class="fflhub-receiving-labels-form">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <label>
                    <span><?php esc_html_e('Dummy Tracking Barcode', 'ffl-hub'); ?></span>
                    <input type="text" name="tracking" value="<?php echo esc_attr($tracking); ?>" autocomplete="off" />
                </label>
                <label>
                    <span><?php esc_html_e('Dummy Serial Prefix', 'ffl-hub'); ?></span>
                    <input type="text" name="serial_prefix" value="<?php echo esc_attr($serial_prefix); ?>" autocomplete="off" />
                </label>
                <label class="fflhub-receiving-labels-upcs">
                    <span><?php esc_html_e('Optional UPCs', 'ffl-hub'); ?></span>
                    <textarea name="upcs" rows="5" placeholder="<?php echo esc_attr("706397939540|serial\n764503072949|no-serial"); ?>"><?php echo esc_textarea($upc_text); ?></textarea>
                    <small><?php esc_html_e('One per line. Use "|serial" for a serial-required row, "|no-serial" for an accessory row. Leave blank to auto-pick sample non-dropship UPCs.', 'ffl-hub'); ?></small>
                </label>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Build Labels', 'ffl-hub'); ?>
                </button>
            </form>

            <?php $this->render_result($tracking, $rows, $stored); ?>
        </div>
        <?php
    }

    /**
     * @param array<int,array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}> $rows
     */
    private function render_result(string $tracking, array $rows, bool $stored): void
    {
        if (empty($rows)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('No UPC rows were found. Enter UPCs manually or make sure product_state has active non-dropship examples.', 'ffl-hub') . '</p></div>';
            return;
        }

        if ($stored) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Debug receiving fixture saved. On the Receiving page, enable debug mode, then scan this dummy tracking barcode.', 'ffl-hub') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Labels rendered, but the debug receiving fixture was not saved.', 'ffl-hub') . '</p></div>';
        }

        $first_rows = array_slice($rows, 0, 3);
        $remaining = array_slice($rows, 3);
        $sheets = [$first_rows];
        foreach (array_chunk($remaining, 4) as $chunk) {
            $sheets[] = $chunk;
        }

        echo '<div class="fflhub-receiving-labels-print-area">';
        foreach ($sheets as $index => $sheet_rows) {
            $this->render_sheet($tracking, $sheet_rows, $index === 0, $index + 1, count($sheets));
        }
        echo '</div>';
    }

    /**
     * @param array<int,array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}> $rows
     */
    private function render_sheet(string $tracking, array $rows, bool $include_tracking, int $sheet_number, int $sheet_count): void
    {
        ?>
        <section class="fflhub-receiving-label-sheet">
            <header>
                <strong><?php esc_html_e('RECEIVING TEST', 'ffl-hub'); ?></strong>
                <span>
                    <?php
                    echo esc_html('Dummy label | Sheet ' . $sheet_number . ' of ' . $sheet_count);
                    ?>
                </span>
            </header>

            <?php if ($include_tracking && $tracking !== '') : ?>
                <div class="fflhub-receiving-label-tracking">
                    <span><?php esc_html_e('1. Scan Tracking', 'ffl-hub'); ?></span>
                    <?php echo $this->barcode_svg($tracking, 110, 'fflhub-receiving-barcode is-tracking'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <strong><?php echo esc_html($tracking); ?></strong>
                </div>
            <?php endif; ?>

            <div class="fflhub-receiving-label-items">
                <?php foreach ($rows as $row) : ?>
                    <div class="fflhub-receiving-label-row">
                        <div>
                            <span><?php echo esc_html('2. UPC ' . $row['unit'] . '/' . $row['total']); ?></span>
                            <?php echo $this->barcode_svg($row['upc'], 74, 'fflhub-receiving-barcode'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <strong><?php echo esc_html($row['upc']); ?></strong>
                            <small><?php echo esc_html($row['name']); ?></small>
                        </div>
                        <div class="<?php echo $row['serial_required'] ? 'is-serial-required' : ''; ?>">
                            <span><?php echo esc_html($row['serial_required'] ? '3. Serial' : 'Serial Not Required'); ?></span>
                            <?php if ($row['serial_required']) : ?>
                                <?php echo $this->barcode_svg($row['serial'], 74, 'fflhub-receiving-barcode'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <strong><?php echo esc_html($row['serial']); ?></strong>
                            <?php else : ?>
                                <em><?php esc_html_e('Accessory / non-FFL item', 'ffl-hub'); ?></em>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * @return array<int,array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}>
     */
    private function rows_from_text(string $text, string $serial_prefix): array
    {
        $rows = [];
        foreach (preg_split('/\R+/', $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[|,]/', $line);
            $upc = preg_replace('/[^0-9A-Za-z]/', '', (string) ($parts[0] ?? ''));
            $upc = is_string($upc) ? $upc : '';
            if ($upc === '') {
                continue;
            }

            $lookup = $this->product_state_context($upc);
            $flag = strtolower(trim((string) ($parts[1] ?? '')));
            $serial_required = in_array($flag, ['serial', 'ffl', 'serialized', 'yes', '1'], true)
                || ($flag === '' && !empty($lookup['serial_required']));
            if (in_array($flag, ['no-serial', 'accessory', 'no', '0'], true)) {
                $serial_required = false;
            }

            $rows[] = $this->label_row(
                $upc,
                (string) ($lookup['name'] ?? ('UPC ' . $upc)),
                $serial_required,
                $serial_prefix,
                1,
                1
            );
        }

        return $rows;
    }

    /**
     * @return array<int,array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}>
     */
    private function sample_rows(string $serial_prefix): array
    {
        $rows = [];
        $serial = $this->sample_product_state_row(true);
        $accessory = $this->sample_product_state_row(false);

        if (!empty($serial['upc'])) {
            $rows[] = $this->label_row(
                (string) $serial['upc'],
                (string) ($serial['name'] ?? ('UPC ' . (string) $serial['upc'])),
                true,
                $serial_prefix,
                1,
                1
            );
        }

        if (!empty($accessory['upc'])) {
            $rows[] = $this->label_row(
                (string) $accessory['upc'],
                (string) ($accessory['name'] ?? ('UPC ' . (string) $accessory['upc'])),
                false,
                $serial_prefix,
                1,
                1
            );
        }

        if (!empty($rows)) {
            return $rows;
        }

        return [
            $this->label_row('706397939540', 'Dummy serialized product', true, $serial_prefix, 1, 1),
            $this->label_row('764503072949', 'Dummy accessory product', false, $serial_prefix, 1, 1),
        ];
    }

    /**
     * @return array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}
     */
    private function label_row(string $upc, string $name, bool $serial_required, string $serial_prefix, int $unit, int $total): array
    {
        return [
            'upc' => $upc,
            'name' => $name,
            'serial' => $this->dummy_serial($serial_prefix, $upc, $unit),
            'unit' => $unit,
            'total' => $total,
            'serial_required' => $serial_required,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sample_product_state_row(bool $serial_required): array
    {
        global $wpdb;

        $table = ProductStateStore::table_name();
        $ffl = $serial_required ? 1 : 0;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT ps.product_id, ps.upc, p.post_title AS name
                FROM {$table} ps
                LEFT JOIN {$wpdb->posts} p
                    ON p.ID = ps.product_id
                WHERE ps.status = 'active'
                  AND ps.upc <> ''
                  AND ps.dropship_enabled = 0
                  AND ps.ffl_required = %d
                ORDER BY RAND()
                LIMIT 1
                ",
                $ffl
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @return array{name:string,serial_required:bool}
     */
    private function product_state_context(string $upc): array
    {
        $row = ProductStateStore::get_row_for_upc($upc);
        $name = '';
        if (is_array($row)) {
            $product_id = (int) ($row['product_id'] ?? 0);
            $product = $product_id > 0 ? wc_get_product($product_id) : null;
            $name = $product ? (string) $product->get_name() : '';

            return [
                'name' => $name !== '' ? $name : ('UPC ' . $upc),
                'serial_required' => ((int) ($row['ffl_required'] ?? 0)) === 1,
            ];
        }

        return [
            'name' => 'UPC ' . $upc,
            'serial_required' => false,
        ];
    }

    private function dummy_serial(string $prefix, string $upc, int $unit): string
    {
        $prefix = preg_replace('/[^0-9A-Za-z]/', '', strtoupper($prefix));
        $prefix = is_string($prefix) && $prefix !== '' ? substr($prefix, 0, 10) : 'TEST';
        $tail = substr($upc, -6);

        return $prefix . $tail . str_pad((string) $unit, 2, '0', STR_PAD_LEFT);
    }

    private function dummy_tracking(): string
    {
        return 'TEST' . gmdate('ymdHis');
    }

    /**
     * @param array<int,array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}> $rows
     * @return array<string,mixed>
     */
    private function debug_shipment_payload(string $tracking, array $rows): array
    {
        $tracking = strtoupper(trim($tracking));
        $products = [];
        foreach ($rows as $row) {
            $upc = (string) ($row['upc'] ?? '');
            if ($upc === '') {
                continue;
            }

            if (!isset($products[$upc])) {
                $products[$upc] = [
                    'upc' => $upc,
                    'name' => (string) ($row['name'] ?? ('UPC ' . $upc)),
                    'expected_qty' => 0,
                    'ffl_required' => !empty($row['serial_required']) ? 1 : 0,
                    'serial_required' => !empty($row['serial_required']) ? 1 : 0,
                ];
            }

            $products[$upc]['expected_qty']++;
            if (!empty($row['serial_required'])) {
                $products[$upc]['ffl_required'] = 1;
                $products[$upc]['serial_required'] = 1;
            }
        }

        return [
            'shipment_key' => 'test_' . substr(hash('sha256', $tracking), 0, 43),
            'dist_id' => 'test',
            'merchant_po' => 'TEST',
            'tracking_numbers' => [$tracking],
            'primary_tracking' => $tracking,
            'external_order_ids' => [],
            'shipping_services' => ['Debug Label'],
            'shipping_service' => 'Debug Label',
            'invoice_numbers' => [],
            'updated_at' => current_time('mysql', true),
            'debug_fixture' => 1,
            'fixture_products' => array_values($products),
        ];
    }

    private function barcode_svg(string $value, int $height, string $class): string
    {
        $value = $this->code128b_value($value);
        $codes = [self::CODE128_START_B];
        $checksum = self::CODE128_START_B;

        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($value[$i]) - 32;
            $codes[] = $code;
            $checksum += $code * ($i + 1);
        }

        $codes[] = $checksum % 103;
        $codes[] = self::CODE128_STOP;

        $quiet_zone = 10;
        $x = $quiet_zone;
        $bars = '';
        foreach ($codes as $code) {
            $pattern = self::CODE128_PATTERNS[$code] ?? '';
            $parts = str_split($pattern);
            foreach ($parts as $index => $width_char) {
                $width = (int) $width_char;
                if ($index % 2 === 0 && $width > 0) {
                    $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '" />';
                }
                $x += $width;
            }
        }

        $width = $x + $quiet_zone;

        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 ' . esc_attr((string) $width) . ' ' . esc_attr((string) $height) . '" role="img" aria-label="' . esc_attr($value) . '" preserveAspectRatio="none">' . $bars . '</svg>';
    }

    private function code128b_value(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^\x20-\x7E]/', '', $value);
        $value = is_string($value) ? $value : '';

        return substr($value, 0, 80);
    }

    private function request_text(string $key): string
    {
        return isset($_GET[$key])
            ? sanitize_text_field(wp_unslash((string) $_GET[$key]))
            : '';
    }

    private function request_textarea(string $key): string
    {
        return isset($_GET[$key])
            ? sanitize_textarea_field(wp_unslash((string) $_GET[$key]))
            : '';
    }
}
