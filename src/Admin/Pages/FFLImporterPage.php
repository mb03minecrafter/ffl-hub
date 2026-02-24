<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Parsing\FFLParser;
use FFLHub\FFL\Tables\FFLTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinator for the "FFL Import" admin page and import logic.
 */
final class FFLImporterPage
{
    private const MENU_SLUG     = 'fflhub-ffl-import';
    private const FORM_ACTION   = 'fflhub_import_ffls';
    private const NONCE_ACTION  = 'fflhub_import_ffls';
    private const NONCE_NAME    = 'fflhub_import_ffls_nonce';

    // -----------------------------
    // DEBUG LOGGING (no behavior changes)
    // -----------------------------
    private const LOG_PREFIX = '[FFLHUB][FFLImport]';

    private static function log(string $msg, array $ctx = []): void
    {
        return; // silence the logger
    }

    private FFLTable $table;

    public function __construct(FFLTable $table)
    {
        $this->table = $table;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_' . self::FORM_ACTION, [$this, 'handle_import_submission']);
        self::log('register() wired hooks', [
            'menu_slug' => self::MENU_SLUG,
            'action'    => self::FORM_ACTION,
        ]);
    }

    public function get_table_name_public(): string
    {
        return $this->table->get_table_name();
    }

    public function register_admin_page(): void
    {
        self::log('register_admin_page()');
        add_submenu_page(
            'tools.php',
            'FFL Hub - Import FFL List',
            'FFL Import',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $message       = isset($_GET['fflhub_msg']) ? sanitize_text_field(wp_unslash($_GET['fflhub_msg'])) : '';
        $imported_rows = isset($_GET['fflhub_count']) ? (int) $_GET['fflhub_count'] : 0;

        $search_zip = isset($_GET['fflhub_search_zip'])
            ? sanitize_text_field(wp_unslash($_GET['fflhub_search_zip']))
            : '';

        $search_results = [];
        if ($search_zip !== '') {
            self::log('search request', ['zip' => $search_zip]);
            $search_results = FFLRepository::search_by_zip_prefix($this->table, $search_zip, 100);
            self::log('search results', ['count' => is_array($search_results) ? count($search_results) : -1]);
        }
        ?>
        <div class="wrap">
            <h1>FFL Hub - Import ATF FFL List</h1>

            <p>
                Step 1: Download the latest <strong>Complete Federal Firearms Listings</strong> CSV file
                from the ATF website: https://www.atf.gov/firearms/tools-and-services-firearms-industry/federal-firearms-listings<br>
                Step 2: Upload that CSV file here to populate/update the FFL database.
            </p>

            <?php if ($message === 'success') : ?>
                <div class="notice notice-success">
                    <p>
                        Imported/updated <strong><?php echo esc_html($imported_rows); ?></strong> FFL records.
                    </p>
                </div>
            <?php elseif ($message === 'error') : ?>
                <div class="notice notice-error">
                    <p>There was an error importing the FFL file. Please try again.</p>
                </div>
            <?php endif; ?>

            <hr>

            <h2>Upload ATF CSV File</h2>
            <form
                method="post"
                enctype="multipart/form-data"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fflhub_csv_file">ATF CSV file</label></th>
                        <td>
                            <input
                                type="file"
                                name="fflhub_csv_file"
                                id="fflhub_csv_file"
                                accept=".csv,.txt"
                                required>
                            <p class="description">
                                Upload the CSV file you downloaded from the ATF website.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Import FFL List'); ?>
            </form>

            <hr>

            <h2>Search FFLs by ZIP</h2>
            <p>
                Enter a full ZIP (e.g. <code>70801</code>) or a ZIP prefix (e.g. <code>708</code>) to see matching FFLs.
            </p>

            <form method="get" action="">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="fflhub_search_zip">ZIP / ZIP prefix</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                name="fflhub_search_zip"
                                id="fflhub_search_zip"
                                value="<?php echo esc_attr($search_zip); ?>"
                                class="regular-text"
                                placeholder="e.g. 708 or 70801" />
                            <?php submit_button('Search', 'secondary', '', false); ?>
                        </td>
                    </tr>
                </table>
            </form>

            <?php if ($search_zip !== '') : ?>
                <h3>
                    Search results for ZIP:
                    <code><?php echo esc_html($search_zip); ?></code>
                </h3>

                <?php if (empty($search_results)) : ?>
                    <p>No FFLs found for that ZIP / prefix.</p>
                <?php else : ?>
                    <p><?php echo esc_html(count($search_results)); ?> FFL(s) found (showing up to 100).</p>

                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>FFL #</th>
                                <th>License Name</th>
                                <th>Premise Address</th>
                                <th>City / State / ZIP</th>
                                <th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_results as $ffl) : ?>
                                <?php $premise = is_array($ffl['premise'] ?? null) ? $ffl['premise'] : []; ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($ffl['ffl_number'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($ffl['name'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($premise['street'] ?? '')); ?></td>
                                    <td>
                                        <?php
                                        echo esc_html(
                                            (string) ($premise['city'] ?? '') . ', ' .
                                            (string) ($premise['state'] ?? '') . ' ' .
                                            (string) ($premise['zip'] ?? '')
                                        );
                                        ?>
                                    </td>
                                    <td><?php echo esc_html((string) ($ffl['phone'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_import_submission(): void
    {
        self::log('handle_import_submission() START', [
            'user_id' => get_current_user_id(),
            'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
        ]);

        if (!current_user_can('manage_options')) {
            self::log('permission denied');
            wp_die('You do not have permission to perform this action.');
        }

        // Will wp_die on nonce failure; still log the attempt.
        self::log('checking nonce', [
            'nonce_field_present' => isset($_POST[self::NONCE_NAME]),
        ]);
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (!isset($_FILES['fflhub_csv_file']) || !is_array($_FILES['fflhub_csv_file'])) {
            self::log('missing $_FILES[fflhub_csv_file]', ['files_keys' => array_keys($_FILES ?? [])]);
            $this->redirect_with_result('error', 0);
        }

        $file = $_FILES['fflhub_csv_file'];
        $tmp  = (string) ($file['tmp_name'] ?? '');
        $name = sanitize_file_name((string) ($file['name'] ?? ''));

        self::log('upload received', [
            'name'     => $name,
            'type'     => $file['type'] ?? '',
            'size'     => $file['size'] ?? 0,
            'error'    => $file['error'] ?? null,
            'tmp_name' => $tmp,
        ]);

        if (!empty($file['error']) || $tmp === '') {
            self::log('upload invalid', [
                'error'    => $file['error'] ?? null,
                'tmp_name' => $tmp,
            ]);
            $this->redirect_with_result('error', 0);
        }

        if (!$this->is_allowed_upload_extension($name)) {
            self::log('upload extension rejected', ['name' => $name]);
            $this->redirect_with_result('error', 0);
        }

        self::log('reading tmp file', [
            'is_readable' => is_readable($tmp),
            'filesize'    => @filesize($tmp),
        ]);

        $contents = file_get_contents($tmp);
        if ($contents === false) {
            self::log('file_get_contents failed', ['tmp' => $tmp]);
            $this->redirect_with_result('error', 0);
        }

        self::log('file read OK', [
            'bytes' => strlen($contents),
            'head'  => substr($contents, 0, 80),
        ]);

        $imported_count = $this->import_atf_txt((string) $contents);

        self::log('import complete', ['imported_count' => $imported_count]);

        if ($imported_count <= 0) {
            $this->redirect_with_result('error', 0);
        }

        $this->redirect_with_result('success', $imported_count);
    }

    protected function import_atf_txt(string $txt): int
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        self::log('import_atf_txt() START', [
            'txt_bytes' => strlen($txt),
        ]);

        try {
            $parser = new FFLParser();
            $rows   = $parser->parse($txt);

            self::log('parse finished', [
                'rows_count' => is_array($rows) ? count($rows) : -1,
            ]);

            if (empty($rows)) {
                self::log('parse returned 0 rows');
                return 0;
            }

            // Useful sanity peek at shape (avoid logging whole row)
            $first = $rows[0] ?? null;
            if (is_array($first)) {
                self::log('first row keys', ['keys' => array_slice(array_keys($first), 0, 30)]);
            }

            $table_name = method_exists($this->table, 'get_table_name') ? $this->table->get_table_name() : '';
            self::log('bulk_upsert begin', [
                'table' => $table_name,
                'batch' => 500,
            ]);

            $result = FFLRepository::bulk_upsert($this->table, $rows, 500);

            self::log('bulk_upsert done', ['result' => $result]);

            return (int) $result;
        } catch (\Throwable $e) {
            self::log('EXCEPTION during import', [
                'type'    => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return 0;
        }
    }

    private function is_allowed_upload_extension(string $file_name): bool
    {
        $ext = strtolower((string) pathinfo($file_name, PATHINFO_EXTENSION));
        return in_array($ext, ['csv', 'txt'], true);
    }

    private function redirect_with_result(string $msg, int $count): void
    {
        $msg = in_array($msg, ['success', 'error'], true) ? $msg : 'error';

        self::log('redirect_with_result()', [
            'msg'   => $msg,
            'count' => $count,
        ]);

        wp_safe_redirect(
            add_query_arg(
                [
                    'fflhub_msg'   => $msg,
                    'fflhub_count' => $count,
                ],
                admin_url('tools.php?page=' . self::MENU_SLUG)
            )
        );
        exit;
    }
}
