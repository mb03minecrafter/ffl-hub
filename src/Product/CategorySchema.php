<?php
namespace FFLHub\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical definition of the FFLHub category tree.
 *
 * Everyone else (installer, mappers, assignment helpers) should use this
 * so we never have mismatched strings.
 */
class CategorySchema {

    // Top-level categories.
    public const CAT_FIREARMS      = 'Firearms';
    public const CAT_OPTICS        = 'Optics / Optics Accessories';
    public const CAT_LIGHTS        = 'Lights and Lasers';
    public const CAT_MAGAZINES     = 'Magazines';
    public const CAT_AMMO          = 'Ammo';
    public const CAT_NFA           = 'NFA';
    public const CAT_BLACK_POWDER  = 'Black Powder';
    public const CAT_LESS_LETHAL   = 'Less Lethal';

    /**
     * 
     *
     * Firearms
     *   Handguns: Pistols, Revolvers
     *   Rifles:   Semi-Auto, Bolt Action, Lever Action, Pump Action, Single Shot / Break Action
     *   Shotguns: Semi-Auto, Bolt Action, Lever Action, Pump Action, Single Shot / Break Action
     *   Other / Specialty
     * Optics / Optics Accessories:
     *   Scopes / Magnified Optics
     *   Red Dots / Non-Magnified Optics
     *   Optic Mounts & Rings
     *   Observation / Range Finding
     *   Optics Accessories
     * Lights and Lasers
     * Magazines: Rifle, Pistol, Shotgun
     * Ammo: Rifle, Handgun, Shotgun
     * NFA: Suppressor Accessories
     * Black Powder: Guns, Firearms, Accessories
     * Less Lethal: Tasers, Pistol, Rifle, Ammo, Accessories
     */
    public static function tree(): array {
        return [
            self::CAT_FIREARMS => [
                'Handguns' => [
                    'Pistols',
                    'Revolvers',
                ],
                'Rifles' => [
                    'Semi-Auto',
                    'Bolt Action',
                    'Lever Action',
                    'Pump Action',
                    'Single Shot / Break Action',
                ],
                'Shotguns' => [
                    'Semi-Auto',
                    'Bolt Action',
                    'Lever Action',
                    'Pump Action',
                    'Single Shot / Break Action',
                ],
                'Other / Specialty' => [],
            ],

            self::CAT_OPTICS => [
                'Scopes / Magnified Optics'       => [],
                'Red Dots / Non-Magnified Optics' => [],
                'Optic Mounts & Rings'            => [],
                'Observation / Range Finding'     => [],
                'Optics Accessories'              => [],
            ],

            self::CAT_LIGHTS => [
                // No children for now.
            ],

            self::CAT_MAGAZINES => [
                'Rifle'   => [],
                'Pistol'  => [],
                'Shotgun' => [],
            ],

            self::CAT_AMMO => [
                'Rifle'   => [],
                'Handgun' => [],
                'Shotgun' => [],
            ],

            self::CAT_NFA => [
                'Suppressor Accessories' => [],
            ],

            self::CAT_BLACK_POWDER => [
                'Guns'        => [],
                'Firearms'    => [],
                'Accessories' => [],
            ],

            self::CAT_LESS_LETHAL => [
                'Tasers'      => [],
                'Pistol'      => [],
                'Rifle'       => [],
                'Ammo'        => [],
                'Accessories' => [],
            ],
        ];
    }
}
