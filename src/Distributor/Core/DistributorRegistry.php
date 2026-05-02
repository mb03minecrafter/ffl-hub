<?php

namespace FFLHub\Distributor\Core;

if (!defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Integrations\CSSI\CSSIModule;
use FFLHub\Distributor\Integrations\Davidsons\DavidsonsModule;
use FFLHub\Distributor\Integrations\Lipseys\LipseysModule;
use FFLHub\Distributor\Integrations\MGE\MGEModule;
use FFLHub\Distributor\Integrations\Orion\OrionModule;
use FFLHub\Distributor\Integrations\RSR\RSRModule;
use FFLHub\Distributor\Integrations\SportsSouth\SportsSouthModule;
use FFLHub\Distributor\Integrations\Zanders\ZandersModule;

/**
 * Static registry for distributor modules.
 *
 * This is the single source of truth for which distributors exist in the plugin.
 *
 * Notes:
 * - We return module *instances* (not class names) because modules may encapsulate
 *   schema defaults, labels, and build logic.
 * - Current implementation constructs new module instances on each call.
 *   That is acceptable because modules are lightweight; if that changes later,
 *   this can be memoized without changing external call sites.
 */
final class DistributorRegistry
{
    /**
     * Get all distributor modules supported by this plugin.
     *
     * @return DistributorModuleInterface[]
     */
    public static function get_modules(): array
    {
        return [
            new RSRModule(),
            new LipseysModule(),
            new ZandersModule(),
            new OrionModule(),
            new SportsSouthModule(),
            new MGEModule(),
            new CSSIModule(),
            new DavidsonsModule()
        ];
    }

    /**
     * Find a module by id.
     *
     * @return DistributorModuleInterface|null
     */
    public static function get_module_by_id(string $id): ?DistributorModuleInterface
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        foreach (self::get_modules() as $module) {
            if ($module->id() === $id) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Get all distributor ids.
     *
     * @return string[]
     */
    public static function get_distributor_ids(): array
    {
        $ids = [];

        foreach (self::get_modules() as $module) {
            $ids[] = $module->id();
        }

        return $ids;
    }
}
