<?php
namespace FFLHub\Admin;

if (! \defined('ABSPATH')) {
    exit;
}

/**
 * Shows an admin warning if WP-Cron is still enabled.
 *
 * We want:
 *   define( 'DISABLE_WP_CRON', true );
 * in wp-config.php, and a real system cron to run WP-Cron via CLI.
 */
class WPCronWarning
{
    /**
     * Hook into WordPress.
     */
    public static function init(): void
    {
        \add_action('admin_notices', array(__CLASS__, 'maybe_show_notice'));
    }

    /**
     * Show a warning notice to admins if WP-Cron is not disabled.
     */
    public static function maybe_show_notice(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        // If WP-Cron is explicitly disabled, no warning.
        if (\defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return;
        }

        // Only show in the admin (should already be true on admin_notices, but be safe).
        if (! \is_admin()) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong>FFL Hub notice:</strong>
                WP-Cron is currently <strong>enabled</strong>. For more reliable distributor
                sync (RSR fulfillment and inventory updates), we recommend disabling WP-Cron
                in <code>wp-config.php</code> and running cron from the server instead.
            </p>
            <p>
                Add this line to <code>wp-config.php</code>:
                <br>
                <code>define( 'DISABLE_WP_CRON', true );</code>
            </p>
            <p>
                Then create a server cron job to run:
                <br>
                <code>wp cron event run --due-now</code>
                (or call <code>wp-cron.php</code> via curl/wget).
            </p>
        </div>
        <?php
    }
}

