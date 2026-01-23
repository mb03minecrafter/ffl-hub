<?php

namespace FFLHub\Distributor\Core;

if (! defined('ABSPATH')) {
    exit;
}

use FFLHub\Distributor\Contracts\DistributorModuleInterface;
use FFLHub\Distributor\Integrations\RSR\RSRModule;
use FFLHub\Distributor\Integrations\Lipseys\LipseysModule;

final class DistributorRegistry
{
    /**
     * @return DistributorModuleInterface[]
     */
    public static function get_modules(): array
    {
        return [
            new RSRModule(),
            new LipseysModule(),
        ];
    }

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
