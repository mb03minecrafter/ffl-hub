<?php

namespace FFLHub\Distributor\Contracts;

use FFLHub\Distributor\Core\DistributorBase;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical definition for a distributor integration.
 *
 * A Distributor *module* is responsible for:
 * - Declaring immutable metadata (id, label, name, icon, descriptions).
 * - Declaring the settings schema consumed by the admin UI and SettingsRegistrar.
 * - Building the runtime distributor instance, including its services graph
 *   (tables, cron services, importers, etc).
 *
 * Conceptual split:
 * - DistributorModuleInterface:
 *     "What this distributor *is* and how it is *constructed*."
 * - DistributorInterface / DistributorBase:
 *     "What this distributor *does* at runtime."
 *
 * Modules are lightweight, mostly-static objects.
 * Runtime behavior lives in the DistributorBase instance they create.
 */
interface DistributorModuleInterface
{
    /**
     * Machine-friendly unique ID (e.g. "rsr", "lipseys").
     *
     * Used for:
     * - option keys
     * - settings groups
     * - internal routing
     */
    public function id(): string;

    /**
     * Short label used in UI cards and summaries (e.g. "RSR").
     */
    public function label(): string;

    /**
     * Full human-readable name (e.g. "RSR Group").
     */
    public function name(): string;

    /**
     * Short description shown on distributor cards.
     */
    public function description(): string;

    /**
     * Longer description used in the distributor settings section.
     */
    public function section_description(): string;

    /**
     * URL to an icon image representing this distributor.
     */
    public function icon_url(): string;

    /**
     * Settings schema for this distributor.
     *
     * This schema is consumed by SettingsRegistrar and admin UI code
     * to dynamically register and render settings fields.
     *
     * Expected shape:
     * [
     *   'field_key' => [
     *     'label'       => 'Field Label',
     *     'type'        => 'text|password|checkbox|select|number',
     *     'placeholder' => '...',
     *     'description' => 'Help text',
     *     'default'     => '',
     *   ],
     * ]
     *
     * @return array<string,mixed>
     */
    public function settings_schema(): array;

    /**
     * Build the runtime distributor instance.
     *
     * This method is responsible for:
     * - Instantiating the concrete DistributorBase subclass.
     * - Wiring its services (tables, cron services, importers, etc).
     *
     * The returned instance is what the rest of the system interacts with
     * via DistributorInterface.
     */
    public function build_distributor(): DistributorBase;
}
