<?php

namespace FFLHub\Distributor\Integrations\Lipseys;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Core\DistributorBase;
use FFLHub\Distributor\Contracts\DistributorModuleInterface;

use FFLHub\Distributor\Services\Lipseys\LipseysServices;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysProductCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysInventoryCronService;
use FFLHub\Distributor\Services\Lipseys\Cron\LipseysShipmentsDailyCronService;

use FFLHub\Distributor\Services\Lipseys\Tables\LipseysProductTableSchema;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysShipmentSchema;
use FFLHub\Distributor\Services\Lipseys\Tables\LipseysShipmentTable;

use FFLHub\Distributor\Services\Tables\DoubleBufferedProductTable;

/**
 * Lipsey's distributor "module" definition.
 *
 * A module is the canonical definition for a distributor:
 * - Stable ID + metadata (label/name/icon)
 * - Settings schema (fields shown in WP settings UI)
 * - Factory method that builds the runtime distributor instance AND its services graph
 *
 * The services graph is built here (not in DistributorHandler) so each integration
 * controls its own dependencies and can evolve independently.
 */
final class LipseysModule implements DistributorModuleInterface
{
    /**
     * Machine-friendly unique distributor ID.
     * Must match what you persist in Options::distributor_state.
     */
    public function id(): string
    {
        return 'lipseys';
    }

    /**
     * Short label used in UI cards and logs.
     */
    public function label(): string
    {
        return "Lipsey's";
    }

    /**
     * Full name for display where you want the human-readable vendor name.
     */
    public function name(): string
    {
        return "Lipsey's";
    }

    /**
     * Short description shown on the distributor card/list.
     */
    public function description(): string
    {
        return "Lipsey's Distributor";
    }

    /**
     * Description used in the settings section header.
     */
    public function section_description(): string
    {
        return "Lipsey's Distributor";
    }

    /**
     * Icon asset URL for settings/distributor UI.
     *
     * NOTE: Depends on plugin bootstrap defining FFLHUB_PLUGIN_URL.
     */
    public function icon_url(): string
    {
        return FFLHUB_PLUGIN_URL . 'assets/icons/logo-lipseys.png';
    }

    /**
     * Settings schema for this distributor.
     *
     * The Settings UI layer reads this schema to:
     * - register WP settings fields
     * - render inputs
     * - provide labels/descriptions/defaults
     *
     * IMPORTANT:
     * - Keys here become option suffixes, used via DistributorBase::get_option_name().
     *   e.g. lipseys dealer_email => option name "fflhub_lipseys_dealer_email"
     *
     * @return array<string, array{
     *   label:string,
     *   type:string,
     *   placeholder:string,
     *   description:string,
     *   default:string
     * }>
     */
    public function settings_schema(): array
    {
        return [
            'main_account_email' => [
                'label'       => 'Main Account Email',
                'type'        => 'text',
                'placeholder' => '',
                'description' => "Your Lipsey's main account email used for catalog imports.",
                'default'     => '',
            ],
            'main_account_password' => [
                'label'       => 'Main Account Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => "Password for your Lipsey's main account used for catalog imports.",
                'default'     => '',
            ],
            'dealer_email' => [
                'label'       => 'Dealer Email',
                'type'        => 'text',
                'placeholder' => '',
                'description' => "Your Lipsey's dealer login email.",
                'default'     => '',
            ],
            'dealer_password' => [
                'label'       => 'Dealer Password',
                'type'        => 'password',
                'placeholder' => '',
                'description' => "Password for your Lipsey's dealer account.",
                'default'     => '',
            ],
        ];
    }

    /**
     * Build the runtime distributor instance INCLUDING its services graph.
     *
     * Dependency graph built here:
     * - fulfillment schema -> double-buffered fulfillment table (hot-swappable)
     * - shipment schema -> shipment table
     * - cron services operating on those tables
     * - services bundle that exposes tables + cron services
     * - DistributorLipseys instance constructed with module + services bundle
     */
    public function build_distributor(): DistributorBase
    {
        // Schema definitions (single source of truth for table columns/indexes).
        $schema         = new LipseysProductTableSchema();
        $shipmentSchema = new LipseysShipmentSchema();

        /**
         * Double buffered fulfillment table:
         * - maintains two physical tables (A/B or live/staging)
         * - importer writes into staging, then atomically swaps
         * - front-end reads always hit "live" table
         *
         * The swap key is stored as an option/transient-like mechanism
         * (implementation-dependent in DoubleBufferedProductTable).
         */
        $table = new DoubleBufferedProductTable(
            $schema,
            'fflhub_lipseys_fulfillment_last_swap'
        );

        // Shipment table (Lipsey's is currently your only distributor with shipment ingestion).
        $shipmentTable = new LipseysShipmentTable($shipmentSchema);

        /**
         * Cron services:
         * - fulfillment cron: refreshes catalog/fulfillment rows (typically bulk import)
         * - inventory cron: refreshes inventory quantities (may be lighter weight)
         * - shipments daily cron: ingests shipment/tracking data (daily cadence)
         */
        $fulfillmentCron = new LipseysProductCronService($table);
        $inventoryCron   = new LipseysInventoryCronService($table);

        $shipmentCron = new LipseysShipmentsDailyCronService($shipmentTable);

        /**
         * Services bundle:
         * Provides a single object that DistributorLipseys can use to access:
         * - fulfillment table
         * - shipment table
         * - cron services (for activation/deactivation/register hooks)
         */
        $services = new LipseysServices(
            $table,
            $fulfillmentCron,
            $inventoryCron,
            $shipmentTable,
            $shipmentCron
        );

        // Distributor runtime implementation.
        return new DistributorLipseys($this, $services);
    }
}
