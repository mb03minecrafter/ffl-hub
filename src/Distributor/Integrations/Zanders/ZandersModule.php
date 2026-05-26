<?php

namespace FFLHub\Distributor\Integrations\Zanders;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsSchema;
use FFLHub\Distributor\Services\Orders\Tables\OrderPlacementJobsTable;
use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;
use FFLHub\Distributor\Services\Zanders\Cron\ZandersProductCronService;
use FFLHub\Distributor\Services\Zanders\Cron\ZandersInventoryCronService;
use FFLHub\Distributor\Services\Zanders\Tables\ZandersProductTableSchema;
use FFLHub\Distributor\Services\Zanders\ZandersServices;
use FFLHub\FFL\Tables\FFLSchema;
use FFLHub\FFL\Tables\FFLTable;

/**
 * Zanders module definition.
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
final class ZandersModule implements DistributorModuleInterface
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
        return 'zanders';
    }

    /**
     * Short label shown in UI cards / dropdowns.
     */
    public function label(): string
    {
        return "Zander's";
    }

    /**
     * Human-friendly name.
     */
    public function name(): string
    {
        return "Zander's";
    }

    /**
     * Short description for UI cards and help text.
     */
    public function description(): string
    {
        return "Zander's Sporting Goods";
    }

    /**
     * Section header/description used in settings UI (group description).
     */
    public function section_description(): string
    {
        return "Zander's Sporting Goods Distributor";
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
        return FFLHUB_PLUGIN_URL . 'assets/icons/logo-zanders.png';
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
            // Main account (dealer-fulfilled ordering)
            // ---------------------------
            'main_username' => [
                'label'       => 'Main Account Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Main Zanders API username (typically your main account email) used for dealer-fulfilled orders.',
                'default'     => '',
            ],
            'main_password' => [
                'label'       => 'Main Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Main Zanders API password/passcode used for dealer-fulfilled orders.',
                'default'     => '',
            ],

            // ---------------------------
            // FTP feed (fulfillment table)
            // ---------------------------
            'ftp_host' => [
                'label'       => 'FTP Host',
                'type'        => 'text',
                'placeholder' => 'ftp2.gzanders.com',
                'description' => 'Hostname for the Zanders FTP server.',
                'default'     => '',
            ],
            'ftp_username' => [
                'label'       => 'FTP Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your Zanders FTP username.',
                'default'     => '',
            ],
            'ftp_password' => [
                'label'       => 'FTP Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your Zanders FTP password.',
                'default'     => '',
            ],

            //Account details for drop shipping accessories and then drop shipping firearms:
            'accessory_username' => [
                'label'       => 'Zanders Accessory Account Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your Zanders Accessory Account Username',
                'default'     => '',
            ],
            'accessory_password' => [
                'label'       => 'Zanders Accessory Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Zanders Accessory Account Password',
                'default'     => '',
            ],
            'gun_username' => [
                'label'       => 'Zanders Gun Account Username',
                'type'        => 'text',
                'placeholder' => '',
                'description' => 'Your Zanders Gun Account Username',
                'default'     => '',
            ],
            'gun_password' => [
                'label'       => 'Zanders Gun Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => 'Your Zanders Gun Account Password',
                'default'     => '',
            ],
            'dealer_fulfilled_mode' => [
                'label'       => 'Zanders Dealer-Fulfilled Ordering Mode',
                'type'        => 'select',
                'options'     => [
                    'manual' => 'Manual (Davidson\'s-style)',
                    'auto'   => 'Auto SOAP ordering',
                ],
                'placeholder' => '',
                'description' => 'Manual keeps Zanders dealer-fulfilled rows out of the SOAP order API until dealer-fulfilled credentials are ready.',
                'default'     => 'manual',
            ],
            'dealer_batch_enabled' => [
                'label'       => 'Enable Zanders Dealer Batch Queue',
                'type'        => 'checkbox',
                'placeholder' => '',
                'description' => 'When enabled, Zanders dealer-fulfilled rows are held in a batch queue instead of placing immediately.',
                'default'     => '1',
            ],
            'dealer_batch_dispatch_time' => [
                'label'       => 'Zanders Dealer Batch Dispatch Time',
                'type'        => 'text',
                'placeholder' => '17:00',
                'description' => 'Daily local dispatch time (HH:MM, America/Chicago) for non-priority dealer batch rows.',
                'default'     => '17:00',
            ],
            'dealer_batch_low_stock_threshold' => [
                'label'       => 'Zanders Dealer Batch Low Stock Threshold',
                'type'        => 'number',
                'placeholder' => '3',
                'description' => 'Rows containing UPCs at or below this stock level, unknown stock, or demand above stock are flushed immediately.',
                'default'     => '3',
            ],
            'dealer_batch_retry_delay_seconds' => [
                'label'       => 'Zanders Dealer Batch Retry Delay Seconds',
                'type'        => 'number',
                'placeholder' => '300',
                'description' => 'Retry delay for retryable aggregate batch failures.',
                'default'     => '300',
            ],
            'dealer_batch_max_rows_per_run' => [
                'label'       => 'Zanders Dealer Batch Max Rows Per Run',
                'type'        => 'number',
                'placeholder' => '200',
                'description' => 'Maximum due batch_pending rows examined per batch cron run.',
                'default'     => '200',
            ],
            'dealer_batch_force_flush' => [
                'label'       => 'Force Zanders Dealer Batch Flush',
                'type'        => 'checkbox',
                'placeholder' => '',
                'description' => 'One-shot flag consumed by the next batch cron run; sends non-priority queued rows immediately.',
                'default'     => '0',
            ],
            'ca_relay_batch_enabled' => [
                'label'       => 'Enable Zanders CA Relay Batch Queue',
                'type'        => 'checkbox',
                'placeholder' => '',
                'description' => 'When enabled, non-FFL Zanders direct-ship rows going to CA are batched and shipped to your configured relay ship-to address.',
                'default'     => '1',
            ],
            'ca_relay_batch_dispatch_time' => [
                'label'       => 'Zanders CA Relay Batch Dispatch Time',
                'type'        => 'text',
                'placeholder' => '17:00',
                'description' => 'Daily local dispatch time (HH:MM, America/Chicago) for non-priority CA relay batch rows.',
                'default'     => '17:00',
            ],
            'ca_relay_batch_low_stock_threshold' => [
                'label'       => 'Zanders CA Relay Batch Low Stock Threshold',
                'type'        => 'number',
                'placeholder' => '3',
                'description' => 'Relay rows containing UPCs at or below this stock level, unknown stock, or demand above stock are flushed immediately.',
                'default'     => '3',
            ],
            'ca_relay_batch_retry_delay_seconds' => [
                'label'       => 'Zanders CA Relay Batch Retry Delay Seconds',
                'type'        => 'number',
                'placeholder' => '300',
                'description' => 'Retry delay for retryable aggregate CA relay batch failures.',
                'default'     => '300',
            ],
            'ca_relay_batch_max_rows_per_run' => [
                'label'       => 'Zanders CA Relay Batch Max Rows Per Run',
                'type'        => 'number',
                'placeholder' => '200',
                'description' => 'Maximum due CA relay batch_pending rows examined per batch cron run.',
                'default'     => '200',
            ],
            'ca_relay_batch_force_flush' => [
                'label'       => 'Force Zanders CA Relay Batch Flush',
                'type'        => 'checkbox',
                'placeholder' => '',
                'description' => 'One-shot flag consumed by the next CA relay batch cron run; sends non-priority queued rows immediately.',
                'default'     => '0',
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
     */
    public function build_distributor(): DistributorBase
    {

        $schema = new ZandersProductTableSchema();

        // Double-buffered table allows "atomic" swaps so readers never see half-imported data.
        $table = new DoubleBufferedProductTable(
            $schema,
            // Swap marker key (must be stable; changing it will "reset" swap history).
            'fflhub_zanders_fulfillment_last_swap'
        );

        // Cron services use the table as their storage target.
        $fulfillmentCron = new ZandersProductCronService($table);
        $inventoryCron = new ZandersInventoryCronService($table);


        $ffl_schema = new FFLSchema();
        $ffl_table = new FFLTable($ffl_schema);

        $order_schema = new OrderPlacementJobsSchema();
        $order_table = new OrderPlacementJobsTable($order_schema);


        $services = new ZandersServices($table, $fulfillmentCron, $inventoryCron, $ffl_table, $order_table);

        return new DistributorZanders($this, $services);
    }
}
