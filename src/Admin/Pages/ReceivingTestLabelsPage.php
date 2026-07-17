<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Receiving\ReceivingShipmentService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug-only print helper for the Receiving page.
 *
 * The real receiving workflow is scanner-driven, so this page creates a small
 * 4x6-friendly barcode sheet from an existing tracked dealer shipment. It does
 * not write receiving events or alter orders; it only renders Code 128 test
 * labels that can be scanned back into the Receiving wizard.
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

    private OrderPlacementJobsTable $jobs_table;

    public function __construct(OrderPlacementJobsTable $jobs_table)
    {
        $this->jobs_table = $jobs_table;
    }

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
        $po = $this->request_text('po');
        $serial_prefix = $this->request_text('serial_prefix');
        $include_old = $this->request_bool('include_old');
        if ($serial_prefix === '') {
            $serial_prefix = 'TEST';
        }

        $result = null;
        if ($tracking !== '' || $po !== '') {
            $service = new ReceivingShipmentService($this->jobs_table, null, $include_old);
            $result = $tracking !== ''
                ? $service->lookup_by_tracking($tracking)
                : $service->lookup_by_po($po);
        }

        ?>
        <div class="wrap fflhub-receiving-labels-page">
            <div class="fflhub-receiving-labels-toolbar">
                <div>
                    <h1><?php esc_html_e('Receiving Test Labels', 'ffl-hub'); ?></h1>
                    <p><?php esc_html_e('Print scanner test labels from an existing tracked dealer shipment. This page does not receive inventory or change orders.', 'ffl-hub'); ?></p>
                </div>
                <button type="button" class="button button-primary" onclick="window.print()">
                    <?php esc_html_e('Print 4x6 Labels', 'ffl-hub'); ?>
                </button>
            </div>

            <form method="get" class="fflhub-receiving-labels-form">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <label>
                    <span><?php esc_html_e('Tracking Scan / Number', 'ffl-hub'); ?></span>
                    <input type="text" name="tracking" value="<?php echo esc_attr($tracking); ?>" autocomplete="off" />
                </label>
                <label>
                    <span><?php esc_html_e('PO / Distributor Order', 'ffl-hub'); ?></span>
                    <input type="text" name="po" value="<?php echo esc_attr($po); ?>" autocomplete="off" />
                </label>
                <label>
                    <span><?php esc_html_e('Dummy Serial Prefix', 'ffl-hub'); ?></span>
                    <input type="text" name="serial_prefix" value="<?php echo esc_attr($serial_prefix); ?>" autocomplete="off" />
                </label>
                <label class="fflhub-receiving-labels-check">
                    <input type="checkbox" name="include_old" value="1" <?php checked($include_old); ?> />
                    <span><?php esc_html_e('Include old/completed shipments', 'ffl-hub'); ?></span>
                </label>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Build Labels', 'ffl-hub'); ?>
                </button>
            </form>

            <?php $this->render_result($result, $serial_prefix); ?>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function render_result(?array $result, string $serial_prefix): void
    {
        if ($result === null) {
            echo '<p class="fflhub-receiving-labels-empty">' . esc_html__('Look up a shipment to generate printable test labels.', 'ffl-hub') . '</p>';
            return;
        }

        if (empty($result['ok']) || empty($result['shipment']) || !is_array($result['shipment'])) {
            $message = (string) ($result['message'] ?? __('Shipment was not found.', 'ffl-hub'));
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        $shipment = $result['shipment'];
        $rows = $this->label_rows($shipment, $serial_prefix);
        if (empty($rows)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Shipment has no open expected UPC rows to print.', 'ffl-hub') . '</p></div>';
            return;
        }

        $tracking = (string) ($shipment['primary_tracking'] ?? '');
        if ($tracking === '') {
            $tracking_numbers = (array) ($shipment['tracking_numbers'] ?? []);
            $tracking = (string) ($tracking_numbers[0] ?? '');
        }

        $first_rows = array_slice($rows, 0, 5);
        $remaining = array_slice($rows, 5);
        $sheets = [$first_rows];
        foreach (array_chunk($remaining, 6) as $chunk) {
            $sheets[] = $chunk;
        }

        echo '<div class="fflhub-receiving-labels-print-area">';
        foreach ($sheets as $index => $sheet_rows) {
            $this->render_sheet($shipment, $tracking, $sheet_rows, $index === 0, $index + 1, count($sheets));
        }
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $shipment
     * @param array<int,array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}> $rows
     */
    private function render_sheet(array $shipment, string $tracking, array $rows, bool $include_tracking, int $sheet_number, int $sheet_count): void
    {
        ?>
        <section class="fflhub-receiving-label-sheet">
            <header>
                <strong><?php echo esc_html(strtoupper((string) ($shipment['dist_id'] ?? 'Distributor'))); ?></strong>
                <span>
                    <?php
                    echo esc_html(trim(
                        'PO ' . (string) ($shipment['merchant_po'] ?? '') . ' | Sheet ' . $sheet_number . ' of ' . $sheet_count
                    ));
                    ?>
                </span>
            </header>

            <?php if ($include_tracking && $tracking !== '') : ?>
                <div class="fflhub-receiving-label-tracking">
                    <span><?php esc_html_e('1. Scan Tracking', 'ffl-hub'); ?></span>
                    <?php echo $this->barcode_svg($tracking, 74, 'fflhub-receiving-barcode is-tracking'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <strong><?php echo esc_html($tracking); ?></strong>
                </div>
            <?php endif; ?>

            <div class="fflhub-receiving-label-items">
                <?php foreach ($rows as $row) : ?>
                    <div class="fflhub-receiving-label-row">
                        <div>
                            <span><?php echo esc_html('2. UPC ' . $row['unit'] . '/' . $row['total']); ?></span>
                            <?php echo $this->barcode_svg($row['upc'], 42, 'fflhub-receiving-barcode'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <strong><?php echo esc_html($row['upc']); ?></strong>
                            <small><?php echo esc_html($row['name']); ?></small>
                        </div>
                        <div class="<?php echo $row['serial_required'] ? 'is-serial-required' : ''; ?>">
                            <span><?php echo esc_html($row['serial_required'] ? '3. Serial' : 'Serial Not Required'); ?></span>
                            <?php if ($row['serial_required']) : ?>
                                <?php echo $this->barcode_svg($row['serial'], 42, 'fflhub-receiving-barcode'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
     * @param array<string,mixed> $shipment
     * @return array<int,array{upc:string,name:string,serial:string,unit:int,total:int,serial_required:bool}>
     */
    private function label_rows(array $shipment, string $serial_prefix): array
    {
        $rows = [];
        foreach ((array) ($shipment['products'] ?? []) as $product) {
            if (!is_array($product)) {
                continue;
            }

            $upc = preg_replace('/[^0-9A-Za-z]/', '', (string) ($product['upc'] ?? ''));
            $upc = is_string($upc) ? $upc : '';
            if ($upc === '') {
                continue;
            }

            $total = max(0, (int) ($product['remaining_qty'] ?? 0));
            if ($total <= 0) {
                continue;
            }

            $name = (string) ($product['name'] ?? ('UPC ' . $upc));
            $serial_required = ((int) ($product['serial_required'] ?? $product['ffl_required'] ?? 0)) === 1;
            for ($unit = 1; $unit <= $total; $unit++) {
                $rows[] = [
                    'upc' => $upc,
                    'name' => $name,
                    'serial' => $this->dummy_serial($serial_prefix, $upc, $unit),
                    'unit' => $unit,
                    'total' => $total,
                    'serial_required' => $serial_required,
                ];
            }
        }

        return $rows;
    }

    private function dummy_serial(string $prefix, string $upc, int $unit): string
    {
        $prefix = preg_replace('/[^0-9A-Za-z]/', '', strtoupper($prefix));
        $prefix = is_string($prefix) && $prefix !== '' ? substr($prefix, 0, 10) : 'TEST';
        $tail = substr($upc, -6);

        return $prefix . $tail . str_pad((string) $unit, 2, '0', STR_PAD_LEFT);
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

    private function request_bool(string $key): bool
    {
        $value = isset($_GET[$key])
            ? strtolower(trim(sanitize_text_field(wp_unslash((string) $_GET[$key]))))
            : '';

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
