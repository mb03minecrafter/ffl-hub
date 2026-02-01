<?php
declare(strict_types=1);

namespace FFLHub\Admin\Pages;

use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Data\FFLRowMapper;
use FFLHub\FFL\Parsing\FFLParser;
use FFLHub\FFL\Tables\FFLTable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinator for the "FFL Import" admin page and import logic.
 *
 * Instance-based:
 * - Constructor takes the FFLTable (explicit dependency).
 * - Uses FFLRepository static helpers for DB access.
 * - Uses FFLParser for TXT parsing.
 */
final class FFLImporterPage
{
    private const MENU_SLUG     = 'fflhub-ffl-import';
    private const FORM_ACTION   = 'fflhub_import_ffls';
    private const NONCE_ACTION  = 'fflhub_import_ffls';
    private const NONCE_NAME    = 'fflhub_import_ffls_nonce';

    private FFLTable $table;

    public function __construct(FFLTable $table)
    {
        $this->table = $table;
    }

    /**
     * Wire up hooks (call from plugin bootstrap).
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_' . self::FORM_ACTION, [$this, 'handle_import_submission']);
    }

    /**
     * Public wrapper so other classes can use the FFL table name (instance-based).
     */
    public function get_table_name_public(): string
    {
        return $this->table->get_table_name();
    }

    /**
     * Register the "FFL Import" admin page under Tools.
     */
    public function register_admin_page(): void
    {
        add_submenu_page(
            'tools.php',
            'FFL Hub – Import FFL List',
            'FFL Import',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    /**
     * Render the FFL Import admin page (upload form + search).
     */
    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $message       = isset($_GET['fflhub_msg']) ? sanitize_text_field(wp_unslash($_GET['fflhub_msg'])) : '';
        $imported_rows = isset($_GET['fflhub_count']) ? (int) $_GET['fflhub_count'] : 0;

        // Handle search query (GET).
        $search_zip = isset($_GET['fflhub_search_zip'])
            ? sanitize_text_field(wp_unslash($_GET['fflhub_search_zip']))
            : '';

        $search_results = [];
        if ($search_zip !== '') {
            // Repo returns canonical "public" shape (premise/mailing/phone).
            // For this admin table, we can render from that shape directly.
            $search_results = FFLRepository::search_by_zip_prefix($this->table, $search_zip, 100);
        }
        ?>
        <div class="wrap">
            <h1>FFL Hub – Import ATF FFL List</h1>

            <p>
                Step 1: Download the latest <strong>Complete Federal Firearms Listings</strong> TXT file
                from the ATF website.<br>
                Step 2: Upload that TXT file here to populate/update the FFL database.
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

            <!-- Upload form -->
            <h2>Upload ATF TXT File</h2>
            <form
                method="post"
                enctype="multipart/form-data"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                <input type="hidden" name="action" value="<?php echo esc_attr(self::FORM_ACTION); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fflhub_txt_file">ATF TXT file</label></th>
                        <td>
                            <input
                                type="file"
                                name="fflhub_txt_file"
                                id="fflhub_txt_file"
                                accept=".txt"
                                required>
                            <p class="description">
                                Upload the TXT file you downloaded from the ATF website.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Import FFL List'); ?>
            </form>

            <hr>

            <!-- Search section -->
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
                                <?php
                                $premise = is_array($ffl['premise'] ?? null) ? $ffl['premise'] : [];
                                ?>
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

    /**
     * Handle the form submission when the TXT file is uploaded.
     */
    public function handle_import_submission(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        if (!isset($_FILES['fflhub_txt_file']) || !is_array($_FILES['fflhub_txt_file'])) {
            $this->redirect_with_result('error', 0);
        }

        $file = $_FILES['fflhub_txt_file'];

        if (!empty($file['error']) || empty($file['tmp_name'])) {
            $this->redirect_with_result('error', 0);
        }

        $contents = file_get_contents((string) $file['tmp_name']);
        if ($contents === false) {
            $this->redirect_with_result('error', 0);
        }

        $imported_count = $this->import_atf_txt((string) $contents);

        if ($imported_count <= 0) {
            $this->redirect_with_result('error', 0);
        }

        $this->redirect_with_result('success', $imported_count);
    }

    /**
     * Import the ATF TXT contents into the DB.
     *
     * Uses FFLParser to get rows and then bulk upserts them via FFLRepository.
     */
    protected function import_atf_txt(string $txt): int
    {
        // Allow long-running import if needed.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $parser = new FFLParser();
        $rows   = $parser->parse($txt);

        if (empty($rows)) {
            return 0;
        }

        // Delegate DB work to repository (single SQL choke-point).
        return FFLRepository::bulk_upsert($this->table, $rows, 500);
    }

    private function redirect_with_result(string $msg, int $count): void
    {
        wp_redirect(
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
