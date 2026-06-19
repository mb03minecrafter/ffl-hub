<?php

namespace FFLHub\Feeds\GunMade;

if (!defined('ABSPATH')) {
    exit;
}

final class GunMadeFeedEndpoint
{
    public static function init(): void
    {
        add_action('parse_request', [self::class, 'maybe_serve_feed'], 0);
    }

    /**
     * Serve /gunmade-feed.xml directly from the generated uploads feed file.
     *
     * This avoids requiring a rewrite-rule flush or custom nginx alias. If the
     * cron has not generated the file yet, we generate it once on demand.
     *
     * @param mixed $wp
     */
    public static function maybe_serve_feed($wp): void
    {
        unset($wp);

        $path = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)
            : '';

        if ($path !== '/gunmade-feed.xml') {
            return;
        }

        $generator = new GunMadeFeedGenerator();
        $paths = $generator->resolve_paths();

        if (!is_file($paths['xml'])) {
            $generator->generate();
        }

        if (!is_file($paths['xml'])) {
            status_header(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Gunmade feed has not been generated.';
            exit;
        }

        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Length: ' . (string) filesize($paths['xml']));
        readfile($paths['xml']);
        exit;
    }
}
