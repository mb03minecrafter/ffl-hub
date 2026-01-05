<?php

namespace FFLHub\Distributor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A module is the canonical definition for a distributor:
 * - metadata (name/label/icon/etc)
 * - settings schema
 * - factory method to build the runtime distributor instance (and its services graph)
 */
interface DistributorModuleInterface
{
    public function id(): string;

    public function label(): string;

    public function name(): string;

    public function description(): string;

    public function section_description(): string;

    public function icon_url(): string;

    /**
     * Settings schema for this distributor.
     *
     * [
     *   'field_key' => [
     *     'label' => 'Field Label',
     *     'type'  => 'text|password|checkbox',
     *     'placeholder' => '...',
     *     'description' => 'Help text',
     *     'default'     => '',
     *   ],
     * ]
     */
    public function settings_schema(): array;

    /**
     * Build the runtime distributor instance including its services/table/cron wiring.
     */
    public function build_distributor(): DistributorBase;
}
