<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FFLHub_FFL_API
 *
 * Public REST API endpoints for FFL search.
 * Example: GET /wp-json/fflhub/v1/ffls?zip=70801
 */
class FFLHub_FFL_API
{

    /**
     * Wire up REST routes.
     */
    public static function init(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * Register the REST routes.
     */
    public static function register_routes(): void
    {
        register_rest_route(
            'fflhub/v1',
            '/ffls',
            array(
                'methods'             => 'GET',
                'callback'            => array(__CLASS__, 'handle_ffl_search'),
                'permission_callback' => '__return_true', // public; we're just returning store addresses
                'args'                => array(
                    'zip' => array(
                        'description' => 'ZIP code or prefix to search by.',
                        'type'        => 'string',
                        'required'    => true,
                    ),
                    'limit' => array(
                        'description' => 'Maximum number of FFLs to return.',
                        'type'        => 'integer',
                        'required'    => false,
                        'default'     => 50,
                    ),
                ),
            )
        );
    }

    /**
     * Handle GET /fflhub/v1/ffls
     *
     * Query our FFL table by ZIP / ZIP prefix.
     */
    public static function handle_ffl_search(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $zip   = $request->get_param('zip');
        $limit = (int) $request->get_param('limit');

        $zip   = sanitize_text_field((string) $zip);
        $limit = max(1, min($limit, 200)); // clamp limit between 1 and 200

        if ($zip === '') {
            return new WP_REST_Response(
                array(
                    'error'   => 'missing_zip',
                    'message' => 'zip parameter is required.',
                ),
                400
            );
        }

        // Allow prefix search: "708" or "70801".
        $like = $zip . '%';

        $table_name = FFLHub_FFL_Importer::get_table_name_public();

        $sql = "
            SELECT
                ffl_number,
                license_name,
                premise_street,
                premise_city,
                premise_state,
                premise_zip,
                mail_street,
                mail_city,
                mail_state,
                mail_zip,
                voice_phone
            FROM {$table_name}
            WHERE premise_zip LIKE %s
               OR mail_zip    LIKE %s
            ORDER BY premise_state, premise_city, license_name
            LIMIT %d
        ";

        // Prepare handles both %s and %d.
        $prepared = $wpdb->prepare($sql, $like, $like, $limit);
        $rows     = $wpdb->get_results($prepared, ARRAY_A);

        // Shape the response a bit so it's convenient on the frontend.
        $ffls = array();

        foreach ($rows as $row) {
            $ffls[] = array(
                'ffl_number'   => $row['ffl_number'],
                'name'         => $row['license_name'],
                'premise'      => array(
                    'street' => $row['premise_street'],
                    'city'   => $row['premise_city'],
                    'state'  => $row['premise_state'],
                    'zip'    => $row['premise_zip'],
                ),
                'mailing'      => array(
                    'street' => $row['mail_street'],
                    'city'   => $row['mail_city'],
                    'state'  => $row['mail_state'],
                    'zip'    => $row['mail_zip'],
                ),
                'phone'        => $row['voice_phone'],
            );
        }

        return new WP_REST_Response(
            array(
                'count' => count($ffls),
                'ffls'  => $ffls,
            ),
            200
        );
    }
}
