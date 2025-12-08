<?php

namespace FFLHub\Distributor\Product\Category;

use FFLHub\Product\CategorySchema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps distributor-specific category values (Lipsey item groups, RSR department numbers)
 * into FFLHub's unified category structure.
 */
class DistributorProductCategoryMapper
{
    /**
     * Map Lipsey's item_group → unified category path.
     *
     * Returns an array like:
     *   [ top-level, mid-level, leaf ]
     *   e.g. [ 'Firearms', 'Handguns', 'Pistols' ]
     *
     * Some entries may be only 1–2 levels deep depending on what we know.
     */
    public static function map_lipseys(string $item_group): ?array
    {
        $g = strtoupper(trim($item_group));

        $map = [

            // ---- Handguns ----
            'SPECIALTY PISTOLS'                         => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'POLYMER CENTERFIRE PISTOLS'                => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'POLYMER CENTERFIRE CONCEAL CARRY PISTOLS'  => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'METAL FRAME CENTERFIRE PISTOLS'            => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'METAL FRAME CENTERFIRE CONCEAL PISTOLS'    => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'POLYMER RIMFIRE PISTOLS'                   => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'METAL FRAME RIMFIRE PISTOLS'               => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'TACTICAL CENTERFIRE SEMI-AUTO PISTOLS'     => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'TACTICAL RIMFIRE SEMI-AUTO PISTOL'         => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'SINGLE SHOT HANDGUNS'                      => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'DERRINGERS'                                => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],

            'SINGLE ACTION CENTERFIRE REVOLVERS'        => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'SINGLE ACTION RIMFIRE REVOLVERS'           => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION CENTERFIRE REVOLVERS'        => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION CENTRIFIRE CONCEAL REVOLVER' => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION RIMFIRE REVOLVERS'           => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION RIMFIRE CONCEAL REVOLVERS'   => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],

            // ---- Rifles ----
            'SPORTING BOLT ACTION CENTERFIRE RIFLES'    => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Bolt Action'],
            'SPORTING BOLT ACTION RIMFIRE RIFLES'       => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Bolt Action'],
            'TACTICAL BOLT ACTION RIFLES'               => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Bolt Action'],
            'SPORTING SEMI-AUTO CENTERFIRE RIFLES'      => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'SPORTING SEMI-AUTO RIMFIRE RIFLES'         => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'TACTICAL CENTERFIRE SEMI-AUTO RIFLES'      => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'TACTICAL RIMFIRE SEMI-AUTO RIFLES'         => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'AR STYLE CENTERFIRE RIFLES'                => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'SPORTING LEVERACTION CENTERFIRE RIFLES'    => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Lever Action'],
            'SPORTING LEVERACTION RIMFIRE RIFLES'       => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Lever Action'],
            'PUMP CENTERFIRE RIFLES'                    => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Pump Action'],
            'PUMP RIMFIRE RIFLES'                       => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Pump Action'],
            'SINGLE SHOT CENTERFIRE RIFLES'             => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],
            'SINGLE SHOT RIMFIRE RIFLES'                => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],
            'RIFLE/SHOTGUN COMBOS'                      => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],

            // ---- Shotguns ----
            'SPORTING PUMP SHOTGUNS'                    => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Pump Action'],
            'TACTICAL PUMP SHOTGUNS'                    => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Pump Action'],
            'SPORTING SEMI-AUTO SHOTGUNS'               => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Semi-Auto'],
            'TACTICAL SEMI-AUTO SHOTGUNS'               => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Semi-Auto'],
            'OVER/UNDER SHOTGUNS'                       => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'SIDE BY SIDE SHOTGUNS'                     => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'SINGLE SHOT SHOTGUNS'                      => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'LEVERACTION SHOTGUNS'                      => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Lever Action'],
            'BOLT ACTION SHOTGUN'                       => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Bolt Action'],

            // ---- Firearms: Other / Specialty ----
            'OTHER FIREARMS'                            => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'FIRE CONTROL UNIT'                         => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'ACTIONS'                                   => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],

            // ---- Optics ----
            'SCOPES'                                    => [CategorySchema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'MAGNIFIED TACTICAL OPTICS'                 => [CategorySchema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'SPOTTING SCOPES'                           => [CategorySchema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'NON-MAGNIFIED OPTICS'                      => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'THERMAL OPTICS'                            => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'HANDGUN SIGHTS'                            => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'LONG GUN SIGHTS'                           => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'SCOPE RINGS'                               => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'SCOPE MOUNTS'                              => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'MAGNIFIED TACTICAL OPTIC MOUNTS'           => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'NON-MAGNIFIED OPTIC MOUNTS'                => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'NON-MAGNIFIED OPTIC ACCESSORIES'           => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'SCOPE ACCESSORIES'                         => [CategorySchema::CAT_OPTICS, 'Optics Accessories'],
            'BINOCULARS'                                => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],
            'RANGE FINDERS'                             => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],

            // ---- Lights and Lasers ----
            'LASERS AND LIGHTS'                         => [CategorySchema::CAT_LIGHTS],

            // ---- Magazines ----
            'RIFLE MAGAZINES'                           => [CategorySchema::CAT_MAGAZINES, 'Rifle'],
            'HANDGUN MAGAZINES'                         => [CategorySchema::CAT_MAGAZINES, 'Pistol'],
            'SHOTGUN MAGAZINES'                         => [CategorySchema::CAT_MAGAZINES, 'Shotgun'],

            // ---- Ammo ----
            'CENTERFIRE AMMO'                           => [CategorySchema::CAT_AMMO],

            // ---- NFA ----
            'SILENCER ACCESSORIES'                      => [CategorySchema::CAT_NFA, 'Suppressor Accessories'],
            'SILENCER MOUNTS'                           => [CategorySchema::CAT_NFA, 'Suppressor Accessories'],
            'SILENCER PISTONS'                          => [CategorySchema::CAT_NFA, 'Suppressor Accessories'],

            // ---- Black Powder ----
            'BLACK POWDER GUNS'                         => [CategorySchema::CAT_BLACK_POWDER, 'Guns'],
            'BLACK POWDER FIREARMS (ATF CONTROLLED)'    => [CategorySchema::CAT_BLACK_POWDER, 'Firearms'],
            'BLACK POWDER ACCESSORIES'                  => [CategorySchema::CAT_BLACK_POWDER, 'Accessories'],

            // ---- Less Lethal ----
            'LESS LETHAL PISTOL'                        => [CategorySchema::CAT_LESS_LETHAL, 'Pistol'],
            'LESS LETHAL RIFLE'                         => [CategorySchema::CAT_LESS_LETHAL, 'Rifle'],
            'LESS LETHAL AMMO'                          => [CategorySchema::CAT_LESS_LETHAL, 'Ammo'],
            'LESS LETHAL ACCESSORIES'                   => [CategorySchema::CAT_LESS_LETHAL, 'Accessories'],
        ];

        return $map[$g] ?? null;
    }

    /**
     * Map RSR department number → unified category path.
     *
     * Returns arrays like:
     *   [ top-level, mid-level? ]
     * Examples:
     *   [ Firearms, Handguns ]
     *   [ Optics / Optics Accessories, Scopes / Magnified Optics ]
     *   [ Magazines ]
     */
    public static function map_rsr(int|string $dept): ?array
    {
        $dept = (int) $dept;

        $map = [

            // --- Firearms: Handguns ---
            1 => [CategorySchema::CAT_FIREARMS, 'Handguns'], // Handguns
            2 => [CategorySchema::CAT_FIREARMS, 'Handguns'], // Used Handguns

            // --- Firearms: Long guns (ambiguous rifle/shotgun) ---
            3 => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'], // Used Long Guns
            5 => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'], // Long Guns (mixed)

            // --- Less Lethal ---
            4  => [CategorySchema::CAT_LESS_LETHAL, 'Tasers'], // Tasers
            27 => [CategorySchema::CAT_LESS_LETHAL],           // Non-Lethal Defense (mixed)

            // --- NFA ---
            6  => [CategorySchema::CAT_NFA], // NFA Products (mixed; not only suppressors)

            // --- Black Powder ---
            7  => [CategorySchema::CAT_BLACK_POWDER, 'Firearms'],
            16 => [CategorySchema::CAT_BLACK_POWDER, 'Accessories'],

            // --- Optics / Optics Accessories ---
            8  => [CategorySchema::CAT_OPTICS],
            28 => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],
            29 => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],
            30 => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            9  => [CategorySchema::CAT_OPTICS, 'Optics Accessories'],
            31 => [CategorySchema::CAT_OPTICS, 'Optics Accessories'],

            // --- Lights and Lasers ---
            20 => [CategorySchema::CAT_LIGHTS],

            // --- Magazines ---
            10 => [CategorySchema::CAT_MAGAZINES],
            24 => [CategorySchema::CAT_MAGAZINES],

            // --- Ammo ---
            18 => [CategorySchema::CAT_AMMO],

            // (You can later decide where to place 22, 41, 42, 43 etc.)
        ];

        return $map[$dept] ?? null;
    }
}
