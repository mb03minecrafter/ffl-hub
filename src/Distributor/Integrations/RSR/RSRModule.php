<?php

namespace FFLHub\Distributor\Integrations\RSR;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

use FFLHub\Distributor\Services\RSR\RSRServices;
use FFLHub\Distributor\Services\RSR\Cron\RSRProductCronService;
use FFLHub\Distributor\Services\RSR\Cron\RSRInventoryCronService;
use FFLHub\Distributor\Services\RSR\Tables\RSRProductTableSchema;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * RSR module definition.
 *
 * A "module" is the canonical distributor descriptor + factory:
 * - Exposes static metadata used across the plugin (settings UI, labels, icons)
 * - Defines the settings schema for this integration (option keys + types)
 * - Builds the runtime "Distributor" instance + its services graph (tables + cron services)
 *
 * NOTE:
 * - The settings keys returned by settings_schema() must match what DistributorRSR
 *   expects via get_option($this->get_option_name(...)) lookups.
 * - This module does not itself talk to the network; it wires the services that will.
 */
final class RSRModule implements DistributorModuleInterface
{
    /**
     * Machine-friendly stable id/slug for this distributor.
     *
     * IMPORTANT:
     * - Used as the canonical identifier in registries, routing, and option name prefixes.
     * - Must remain stable or you will "lose" settings (because option keys change).
     */
    public function id(): string
    {
        return 'rsr';
    }

    /**
     * Short label shown in UI cards / dropdowns.
     */
    public function label(): string
    {
        return 'RSR';
    }

    /**
     * Human-friendly name.
     */
    public function name(): string
    {
        return 'RSR Group';
    }

    /**
     * Short description for UI cards and help text.
     */
    public function description(): string
    {
        return 'RSR Group Distributor';
    }

    /**
     * Section header/description used in settings UI (group description).
     */
    public function section_description(): string
    {
        return 'RSR Group Distributor';
    }

    /**
     * Icon shown in UI.
     *
     * Depends on:
     * - FFLHUB_PLUGIN_URL being defined globally
     * - asset existing at assets/icons/logo-rsr.png
     */
    public function icon_url(): string
    {
        return FFLHUB_PLUGIN_URL . 'assets/icons/logo-rsr.png';
    }

    /**
     * Settings schema for this distributor.
     *
     * Keys here become option names via your DistributorBase option-name helper:
     *   get_option($this->get_option_name('some_key'), default)
     *
     * So the *exact* keys here must match what the runtime distributor and services read.
     *
     * RSR has multiple credential sets:
     * - Main account (often used for non-dropship operations / general API access)
     * - Drop-ship account (used for DirectConnect order submission in your DistributorRSR)
     * - POS indicator (required by DirectConnect payloads)
     *
     * It also has FTP credentials for pulling the fulfillment feed.
     */
    public function settings_schema(): array
    {
        return [
            // ---------------------------
            // Main account (if you use it)
            // ---------------------------
            'main_account_number' => [
                'label'       => 'Main Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your main RSR account number (this is your RSR username).',
                'default'     => '',
            ],
            'main_account_password' => [
                'label'       => 'Main Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR main account password.',
                'default'     => '',
            ],

            // ---------------------------
            // DirectConnect / ordering
            // ---------------------------
            'pos_indicator' => [
                'label'       => 'POS Indicator',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR POS indicator for API requests.',
                'default'     => '',
            ],
            'order_email' => [
                'label'       => 'Order Email',
                'type'        => 'email',
                'placeholder' => '',
                'description' => 'Email sent in RSR API order payloads (Email field).',
                'default'     => '',
            ],
            'dropship_account_number' => [
                'label'       => 'Drop-Ship Account Number',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR drop-ship account number (different from main).',
                'default'     => '',
            ],
            'dropship_account_password' => [
                'label'       => 'Drop-Ship Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Password for the drop-ship account.',
                'default'     => '',
            ],

            // ---------------------------
            // FTP feed (fulfillment table)
            // ---------------------------
            'ftp_host' => [
                'label'       => 'FTP Host',
                'type'        => 'text',
                'placeholder' => 'ftps.rsrgroup.com',
                'description' => 'Hostname for the RSR FTP server.',
                'default'     => '',
            ],
            'ftp_username' => [
                'label'       => 'FTP Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your RSR FTP username.',
                'default'     => '',
            ],
            'ftp_password' => [
                'label'       => 'FTP Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your RSR FTP password.',
                'default'     => '',
            ],

            /**
             * FTPS toggle.
             *
             * Default '1' means enabled by default.
             *
             * NOTE/WATCH:
             * - Some schema consumers treat checkbox defaults as 'yes'/'no' or bool.
             * - You're using strings ('1'), which is fine as long as the reader casts properly.
             */
            'ftp_use_ssl' => [
                'label'       => 'Use FTPS (SSL)',
                'type'        => 'checkbox',
                'description' => 'Connect using FTPS/SSL (recommended).',
                'default'     => '1',
            ],

            // ---------------------------
            // Import behavior
            // ---------------------------
            'accessories_only' => [
                'label'       => 'Accessories Only',
                'type'        => 'checkbox',
                'description' => 'When enabled, exclude firearm/NFA departments (1, 2, 3, 5, 6) during RSR product import.',
                'default'     => '1',
            ],
        ];
    }

    /**
     * Build the runtime distributor instance including its services/table/cron wiring.
     *
     * What gets created here:
     * 1) Schema object that defines the fulfillment table column mapping.
     * 2) DoubleBufferedProductTable:
     *    - Maintains "live" and "staging" tables behind the scenes
     *    - Cron jobs import into staging, then swap pointers to make updates atomic
     *    - The swap timestamp/marker is stored under a WP option key (or similar)
     * 3) Fulfillment + Inventory cron services:
     *    - Fulfillment cron: responsible for pulling/refreshing the entire feed
     *    - Inventory cron: typically lighter weight (stock deltas), if supported
     * 4) RSRServices bundle that exposes:
     *    - get_fulfillment_table()
     *    - cron service instances for scheduling/dispatch
     * 5) DistributorRSR runtime implementation bound to this module + services
     */
    public function build_distributor(): DistributorBase
    {
        // Defines the canonical mapping from the RSR feed rows -> your normalized field names.
        $schema = new RSRProductTableSchema();

        // Double-buffered table allows "atomic" swaps so readers never see half-imported data.
        $table = new DoubleBufferedProductTable(
            $schema,
            // Swap marker key (must be stable; changing it will "reset" swap history).
            'fflhub_rsr_fulfillment_last_swap'
        );

        // Cron services use the table as their storage target.
        $fulfillmentCron = new RSRProductCronService($table);
        $inventoryCron   = new RSRInventoryCronService($table);

        // Services bundle is injected into the runtime distributor for lookups/cron access.
        $services = new RSRServices(
            $table,
            $fulfillmentCron,
            $inventoryCron
        );

        // Runtime distributor implementation (ordering/validation/etc).
        return new DistributorRSR($this, $services);
    }
}
