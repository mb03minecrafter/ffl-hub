<?php
declare(strict_types=1);

namespace FFLHub\FFL\API;

use FFLHub\FFL\Data\FFLRepository;
use FFLHub\FFL\Tables\FFLTable;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public REST API endpoints for FFL search/autocomplete.
 *
 * Route:
 *   GET /wp-json/fflhub/v1/ffls?zip=70801&limit=50
 *
 * Response:
 *   {
 *     "count": 12,
 *     "ffls": [ ...public-shaped FFL records... ]
 *   }
 *
 * Notes:
 * - This endpoint is intentionally public (no auth) because it returns basic
 *   business contact/address information used during checkout.
 * - All DB access is delegated to FFLRepository.
 */
final class FFLApi
{
    private FFLTable $table;

    public function __construct(FFLTable $table)
    {
        $this->table = $table;
    }

    /**
     * Register REST route wiring (instance-based so the table dependency is explicit).
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes for this API module.
     */
    public function register_routes(): void
    {
        register_rest_route(
            'fflhub/v1',
            '/ffls',
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle_ffl_search'],
                'permission_callback' => '__return_true', // public endpoint
                'args'                => [
                    'zip' => [
                        'description' => 'ZIP code or prefix to search by.',
                        'type'        => 'string',
                        'required'    => true,
                    ],
                    'limit' => [
                        'description' => 'Maximum number of FFLs to return (clamped to 1..200).',
                        'type'        => 'integer',
                        'required'    => false,
                        'default'     => 50,
                    ],
                ],
            ]
        );
    }

    /**
     * Handler: GET /fflhub/v1/ffls
     *
     * Performs a ZIP/prefix search across premise_zip and mail_zip.
     */
    public function handle_ffl_search(WP_REST_Request $request): WP_REST_Response
    {
        $zip = sanitize_text_field((string) $request->get_param('zip'));

        $limit = (int) $request->get_param('limit');
        $limit = max(1, min($limit, 200));

        if ($zip === '') {
            return new WP_REST_Response(
                [
                    'error'   => 'missing_zip',
                    'message' => 'zip parameter is required.',
                ],
                400
            );
        }

        $ffls = FFLRepository::search_by_zip_prefix($this->table, $zip, $limit);

        return new WP_REST_Response(
            [
                'count' => count($ffls),
                'ffls'  => $ffls,
            ],
            200
        );
    }
}
