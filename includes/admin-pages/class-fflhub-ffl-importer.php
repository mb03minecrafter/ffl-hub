<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Pure parser for the ATF TXT file.
 * Turns the raw text into an array of associative arrays ready to insert.
 */
class FFLHub_FFL_Parser {

    /**
     * Parse the ATF TXT file contents.
     *
     * @param string $txt
     * @return array<int, array<string,string>> Rows keyed to match DB columns.
     */
    public function parse( string $txt ): array {
        $lines = preg_split( "/\r\n|\n|\r/", $txt );
        $rows  = array();

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            $columns = explode( "\t", $line );

            // Skip header row that starts with "LIC_REGN".
            if ( isset( $columns[0] ) && strtoupper( trim( $columns[0] ) ) === 'LIC_REGN' ) {
                continue;
            }

            // Expect at least 17 columns based on the sample.
            if ( count( $columns ) < 17 ) {
                continue;
            }

            // Raw columns (include LIC_REGN again).
            $lic_regn     = trim( $columns[0] ?? '' );
            $lic_dist     = trim( $columns[1] ?? '' );
            $lic_cnty     = trim( $columns[2] ?? '' );
            $lic_type     = trim( $columns[3] ?? '' );
            $lic_xprdte   = trim( $columns[4] ?? '' );
            $lic_seqn     = trim( $columns[5] ?? '' );
            $license_name = trim( $columns[6] ?? '' );

            // Column 7 is blank spacer.
            $premise_street = trim( $columns[8]  ?? '' );
            $premise_city   = trim( $columns[9]  ?? '' );
            $premise_state  = strtoupper( trim( $columns[10] ?? '' ) );
            $premise_zip    = trim( $columns[11] ?? '' );

            $mail_street = trim( $columns[12] ?? '' );
            $mail_city   = trim( $columns[13] ?? '' );
            $mail_state  = strtoupper( trim( $columns[14] ?? '' ) );
            $mail_zip    = trim( $columns[15] ?? '' );

            $voice_phone = trim( $columns[16] ?? '' );

            // Basic sanity check: must have name + premises address.
            if ( $license_name === '' || $premise_street === '' || $premise_city === '' || $premise_state === '' ) {
                continue;
            }

            // Normalize ZIPs (remove spaces).
            $premise_zip = preg_replace( '/\s+/', '', $premise_zip );
            $mail_zip    = preg_replace( '/\s+/', '', $mail_zip );

            // Build FFL number INCLUDING LIC_REGN:
            // Format: REGN-DIST-CNTY-TYPE-XPRDTE-SEQ
            $ffl_number_parts = array( $lic_regn, $lic_dist, $lic_cnty, $lic_type, $lic_xprdte, $lic_seqn );
            $ffl_number_parts = array_map( 'trim', $ffl_number_parts );
            $ffl_number_parts = array_filter(
                $ffl_number_parts,
                static function ( $v ) {
                    return $v !== '';
                }
            );

            // Must have all 6 parts.
            if ( count( $ffl_number_parts ) < 6 ) {
                continue;
            }

            $ffl_number = implode( '-', $ffl_number_parts );

            $rows[] = array(
                'ffl_number'     => $ffl_number,
                'license_name'   => $license_name,
                'premise_street' => $premise_street,
                'premise_city'   => $premise_city,
                'premise_state'  => $premise_state,
                'premise_zip'    => $premise_zip,
                'mail_street'    => $mail_street,
                'mail_city'      => $mail_city,
                'mail_state'     => $mail_state,
                'mail_zip'       => $mail_zip,
                'voice_phone'    => $voice_phone,
            );
        }

        return $rows;
    }
}

/**
 * Coordinator class:
 * - Wires up the admin page and POST handler.
 * - Calls FFLHub_FFL_Table for schema.
 * - Uses FFLHub_FFL_Parser to parse and then bulk upsert.
 */
class FFLHub_FFL_Importer {

    /**
     * Wire up hooks.
     *
     * Called from FFLHub_Plugin::register_services().
     */
    public static function init(): void {
        // Add the "FFL Import" page in the admin.
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );

        // Handle the form POST (file upload).
        add_action( 'admin_post_fflhub_import_ffls', array( __CLASS__, 'handle_import_submission' ) );
    }

    

    /**
     * Public wrapper so other classes can use the FFL table name.
     * (FFLHub_FFL_API still calls this and doesn't need to change.)
     */
    public static function get_table_name_public(): string {
        return FFLHub_FFL_Table::get_table_name();
    }

    /**
     * Register the "FFL Import" admin page under Tools.
     */
    public static function register_admin_page(): void {
        add_submenu_page(
            'tools.php',                        // parent menu slug (Tools menu)
            'FFL Hub – Import FFL List',        // page title
            'FFL Import',                       // menu title
            'manage_options',                   // capability required
            'fflhub-ffl-import',                // menu slug
            array( __CLASS__, 'render_admin_page' ) // callback
        );
    }

    /**
     * Render the FFL Import admin page (upload form + search).
     */
    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        $message       = isset( $_GET['fflhub_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['fflhub_msg'] ) ) : '';
        $imported_rows = isset( $_GET['fflhub_count'] ) ? (int) $_GET['fflhub_count'] : 0;

        // Handle search query (GET).
        $search_zip = isset( $_GET['fflhub_search_zip'] )
            ? sanitize_text_field( wp_unslash( $_GET['fflhub_search_zip'] ) )
            : '';

        $search_results = array();

        if ( $search_zip !== '' ) {
            global $wpdb;

            $table_name = FFLHub_FFL_Table::get_table_name();
            // Allow prefix search: e.g. "708" or full "70801".
            $like = $search_zip . '%';

            $sql = "
            SELECT
                ffl_number,
                license_name,
                premise_street,
                premise_city,
                premise_state,
                premise_zip,
                voice_phone
            FROM {$table_name}
            WHERE premise_zip LIKE %s
               OR mail_zip    LIKE %s
            ORDER BY premise_state, premise_city, license_name
            LIMIT 100
        ";

            $search_results = $wpdb->get_results(
                $wpdb->prepare( $sql, $like, $like ),
                ARRAY_A
            );
        }
        ?>
        <div class="wrap">
            <h1>FFL Hub – Import ATF FFL List</h1>

            <p>
                Step 1: Download the latest <strong>Complete Federal Firearms Listings</strong> TXT file
                from the ATF website.<br>
                Step 2: Upload that TXT file here to populate/update the FFL database.
            </p>

            <?php if ( $message === 'success' ) : ?>
                <div class="notice notice-success">
                    <p>
                        Imported/updated <strong><?php echo esc_html( $imported_rows ); ?></strong> FFL records.
                    </p>
                </div>
            <?php elseif ( $message === 'error' ) : ?>
                <div class="notice notice-error">
                    <p>There was an error importing the FFL file. Please try again.</p>
                </div>
            <?php endif; ?>

            <hr>

            <!-- Upload form -->
            <h2>Upload ATF TXT File</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'fflhub_import_ffls', 'fflhub_import_ffls_nonce' ); ?>

                <input type="hidden" name="action" value="fflhub_import_ffls">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="fflhub_txt_file">ATF TXT file</label></th>
                        <td>
                            <input type="file" name="fflhub_txt_file" id="fflhub_txt_file" accept=".txt" required>
                            <p class="description">
                                Upload the TXT file you downloaded from the ATF website.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Import FFL List' ); ?>
            </form>

            <hr>

            <!-- Search section -->
            <h2>Search FFLs by ZIP</h2>
            <p>
                Enter a full ZIP (e.g. <code>70801</code>) or a ZIP prefix (e.g. <code>708</code>) to see matching FFLs.
            </p>

            <form method="get" action="">
                <input type="hidden" name="page" value="fflhub-ffl-import" />
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
                                value="<?php echo esc_attr( $search_zip ); ?>"
                                class="regular-text"
                                placeholder="e.g. 708 or 70801" />
                            <?php submit_button( 'Search', 'secondary', '', false ); ?>
                        </td>
                    </tr>
                </table>
            </form>

            <?php if ( $search_zip !== '' ) : ?>
                <h3>
                    Search results for ZIP:
                    <code><?php echo esc_html( $search_zip ); ?></code>
                </h3>

                <?php if ( empty( $search_results ) ) : ?>
                    <p>No FFLs found for that ZIP / prefix.</p>
                <?php else : ?>
                    <p><?php echo esc_html( count( $search_results ) ); ?> FFL(s) found (showing up to 100).</p>

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
                            <?php foreach ( $search_results as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['ffl_number'] ); ?></td>
                                    <td><?php echo esc_html( $row['license_name'] ); ?></td>
                                    <td><?php echo esc_html( $row['premise_street'] ); ?></td>
                                    <td>
                                        <?php
                                        echo esc_html( $row['premise_city'] . ', ' . $row['premise_state'] . ' ' . $row['premise_zip'] );
                                        ?>
                                    </td>
                                    <td><?php echo esc_html( $row['voice_phone'] ); ?></td>
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
    public static function handle_import_submission(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 'fflhub_import_ffls', 'fflhub_import_ffls_nonce' );

        if ( ! isset( $_FILES['fflhub_txt_file'] ) || ! is_array( $_FILES['fflhub_txt_file'] ) ) {
            wp_redirect(
                add_query_arg(
                    array(
                        'fflhub_msg'   => 'error',
                        'fflhub_count' => 0,
                    ),
                    admin_url( 'tools.php?page=fflhub-ffl-import' )
                )
            );
            exit;
        }

        $file = $_FILES['fflhub_txt_file'];

        if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
            wp_redirect(
                add_query_arg(
                    array(
                        'fflhub_msg'   => 'error',
                        'fflhub_count' => 0,
                    ),
                    admin_url( 'tools.php?page=fflhub-ffl-import' )
                )
            );
            exit;
        }

        $contents = file_get_contents( $file['tmp_name'] );

        if ( $contents === false ) {
            wp_redirect(
                add_query_arg(
                    array(
                        'fflhub_msg'   => 'error',
                        'fflhub_count' => 0,
                    ),
                    admin_url( 'tools.php?page=fflhub-ffl-import' )
                )
            );
            exit;
        }

        // Import the contents.
        $imported_count = self::import_atf_txt( $contents );

        if ( $imported_count <= 0 ) {
            wp_redirect(
                add_query_arg(
                    array(
                        'fflhub_msg'   => 'error',
                        'fflhub_count' => 0,
                    ),
                    admin_url( 'tools.php?page=fflhub-ffl-import' )
                )
            );
            exit;
        }

        // Success.
        wp_redirect(
            add_query_arg(
                array(
                    'fflhub_msg'   => 'success',
                    'fflhub_count' => $imported_count,
                ),
                admin_url( 'tools.php?page=fflhub-ffl-import' )
            )
        );
        exit;
    }

    /**
     * Import the ATF TXT contents into the DB.
     *
     * Uses FFLHub_FFL_Parser to get rows and then bulk upserts them.
     *
     * @param string $txt
     * @return int Number of rows inserted/updated (count of processed lines).
     */
    protected static function import_atf_txt( string $txt ): int {
        global $wpdb;

        $table_name = FFLHub_FFL_Table::get_table_name();

        // Allow long-running import if needed.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $parser = new FFLHub_FFL_Parser();
        $rows   = $parser->parse( $txt );

        $batch_size   = 500;   // rows per multi-insert
        $batch_rows   = array();
        $total_import = 0;

        /**
         * Flush current batch into DB as a multi-row
         * INSERT ... VALUES (...) ON DUPLICATE KEY UPDATE ...
         */
        $flush_batch = function () use ( &$batch_rows, &$total_import, $table_name, $wpdb ) {
            if ( empty( $batch_rows ) ) {
                return;
            }

            $placeholders = array();
            $values       = array();

            foreach ( $batch_rows as $row ) {
                $placeholders[] = '( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s )';

                $values[] = $row['ffl_number'];
                $values[] = $row['license_name'];
                $values[] = $row['premise_street'];
                $values[] = $row['premise_city'];
                $values[] = $row['premise_state'];
                $values[] = $row['premise_zip'];
                $values[] = $row['mail_street'];
                $values[] = $row['mail_city'];
                $values[] = $row['mail_state'];
                $values[] = $row['mail_zip'];
                $values[] = $row['voice_phone'];
            }

            $sql = "
            INSERT INTO {$table_name}
                ( ffl_number, license_name, premise_street, premise_city, premise_state,
                  premise_zip, mail_street, mail_city, mail_state, mail_zip, voice_phone )
            VALUES " . implode( ', ', $placeholders ) . "
            ON DUPLICATE KEY UPDATE
                license_name   = VALUES(license_name),
                premise_street = VALUES(premise_street),
                premise_city   = VALUES(premise_city),
                premise_state  = VALUES(premise_state),
                premise_zip    = VALUES(premise_zip),
                mail_street    = VALUES(mail_street),
                mail_city      = VALUES(mail_city),
                mail_state     = VALUES(mail_state),
                mail_zip       = VALUES(mail_zip),
                voice_phone    = VALUES(voice_phone)
        ";

            $prepared = $wpdb->prepare( $sql, $values );
            $result   = $wpdb->query( $prepared );

            if ( $result !== false ) {
                $total_import += count( $batch_rows );
            }

            // Reset batch.
            $batch_rows = array();
        };

        foreach ( $rows as $row ) {
            $batch_rows[] = $row;

            if ( count( $batch_rows ) >= $batch_size ) {
                $flush_batch();
            }
        }

        // Flush any remaining rows.
        $flush_batch();

        return $total_import;
    }
}
