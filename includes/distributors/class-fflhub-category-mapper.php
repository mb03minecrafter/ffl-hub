<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps distributor-specific category values (Lipsey item groups, RSR department numbers)
 * into FFLHub's unified category structure.
 */
class FFLHub_Category_Mapper
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
            'SPECIALTY PISTOLS'                         => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'POLYMER CENTERFIRE PISTOLS'                => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'POLYMER CENTERFIRE CONCEAL CARRY PISTOLS'  => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'METAL FRAME CENTERFIRE PISTOLS'            => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'METAL FRAME CENTERFIRE CONCEAL PISTOLS'    => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'POLYMER RIMFIRE PISTOLS'                   => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'METAL FRAME RIMFIRE PISTOLS'               => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'TACTICAL CENTERFIRE SEMI-AUTO PISTOLS'     => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'TACTICAL RIMFIRE SEMI-AUTO PISTOL'         => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'SINGLE SHOT HANDGUNS'                      => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'DERRINGERS'                                => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Pistols'],

            'SINGLE ACTION CENTERFIRE REVOLVERS'        => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'SINGLE ACTION RIMFIRE REVOLVERS'           => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION CENTERFIRE REVOLVERS'        => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION CENTRIFIRE CONCEAL REVOLVER' => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION RIMFIRE REVOLVERS'           => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'DOUBLE ACTION RIMFIRE CONCEAL REVOLVERS'   => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns', 'Revolvers'],

            // ---- Rifles ----
            'SPORTING BOLT ACTION CENTERFIRE RIFLES'    => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Bolt Action'],
            'SPORTING BOLT ACTION RIMFIRE RIFLES'       => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Bolt Action'],
            'TACTICAL BOLT ACTION RIFLES'               => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Bolt Action'],
            'SPORTING SEMI-AUTO CENTERFIRE RIFLES'      => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'SPORTING SEMI-AUTO RIMFIRE RIFLES'         => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'TACTICAL CENTERFIRE SEMI-AUTO RIFLES'      => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'TACTICAL RIMFIRE SEMI-AUTO RIFLES'         => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'AR STYLE CENTERFIRE RIFLES'                => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'SPORTING LEVERACTION CENTERFIRE RIFLES'    => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Lever Action'],
            'SPORTING LEVERACTION RIMFIRE RIFLES'       => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Lever Action'],
            'PUMP CENTERFIRE RIFLES'                    => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Pump Action'],
            'PUMP RIMFIRE RIFLES'                       => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Pump Action'],
            'SINGLE SHOT CENTERFIRE RIFLES'             => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],
            'SINGLE SHOT RIMFIRE RIFLES'                => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],
            'RIFLE/SHOTGUN COMBOS'                      => [FFLHub_Category_Schema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],

            // ---- Shotguns ----
            'SPORTING PUMP SHOTGUNS'                    => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Pump Action'],
            'TACTICAL PUMP SHOTGUNS'                    => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Pump Action'],
            'SPORTING SEMI-AUTO SHOTGUNS'               => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Semi-Auto'],
            'TACTICAL SEMI-AUTO SHOTGUNS'               => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Semi-Auto'],
            'OVER/UNDER SHOTGUNS'                       => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'SIDE BY SIDE SHOTGUNS'                     => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'SINGLE SHOT SHOTGUNS'                      => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'LEVERACTION SHOTGUNS'                      => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Lever Action'],
            'BOLT ACTION SHOTGUN'                       => [FFLHub_Category_Schema::CAT_FIREARMS, 'Shotguns', 'Bolt Action'],

            // ---- Firearms: Other / Specialty ----
            'OTHER FIREARMS'                            => [FFLHub_Category_Schema::CAT_FIREARMS, 'Other / Specialty'],
            'FIRE CONTROL UNIT'                         => [FFLHub_Category_Schema::CAT_FIREARMS, 'Other / Specialty'],
            'ACTIONS'                                   => [FFLHub_Category_Schema::CAT_FIREARMS, 'Other / Specialty'],

            // ---- Optics ----
            'SCOPES'                                    => [FFLHub_Category_Schema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'MAGNIFIED TACTICAL OPTICS'                 => [FFLHub_Category_Schema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'SPOTTING SCOPES'                           => [FFLHub_Category_Schema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'NON-MAGNIFIED OPTICS'                      => [FFLHub_Category_Schema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'THERMAL OPTICS'                            => [FFLHub_Category_Schema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'HANDGUN SIGHTS'                            => [FFLHub_Category_Schema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'LONG GUN SIGHTS'                           => [FFLHub_Category_Schema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'SCOPE RINGS'                               => [FFLHub_Category_Schema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'SCOPE MOUNTS'                              => [FFLHub_Category_Schema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'MAGNIFIED TACTICAL OPTIC MOUNTS'           => [FFLHub_Category_Schema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'NON-MAGNIFIED OPTIC MOUNTS'                => [FFLHub_Category_Schema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'NON-MAGNIFIED OPTIC ACCESSORIES'           => [FFLHub_Category_Schema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'SCOPE ACCESSORIES'                         => [FFLHub_Category_Schema::CAT_OPTICS, 'Optics Accessories'],
            'BINOCULARS'                                => [FFLHub_Category_Schema::CAT_OPTICS, 'Observation / Range Finding'],
            'RANGE FINDERS'                             => [FFLHub_Category_Schema::CAT_OPTICS, 'Observation / Range Finding'],

            // ---- Lights and Lasers ----
            'LASERS AND LIGHTS'                         => [FFLHub_Category_Schema::CAT_LIGHTS],

            // ---- Magazines ----
            'RIFLE MAGAZINES'                           => [FFLHub_Category_Schema::CAT_MAGAZINES, 'Rifle'],
            'HANDGUN MAGAZINES'                         => [FFLHub_Category_Schema::CAT_MAGAZINES, 'Pistol'], // matches schema
            'SHOTGUN MAGAZINES'                         => [FFLHub_Category_Schema::CAT_MAGAZINES, 'Shotgun'],

            // ---- Ammo ----
            'CENTERFIRE AMMO'                           => [FFLHub_Category_Schema::CAT_AMMO],

            // ---- NFA ----
            'SILENCER ACCESSORIES'                      => [FFLHub_Category_Schema::CAT_NFA, 'Suppressor Accessories'],
            'SILENCER MOUNTS'                           => [FFLHub_Category_Schema::CAT_NFA, 'Suppressor Accessories'],
            'SILENCER PISTONS'                          => [FFLHub_Category_Schema::CAT_NFA, 'Suppressor Accessories'],

            // ---- Black Powder ----
            'BLACK POWDER GUNS'                         => [FFLHub_Category_Schema::CAT_BLACK_POWDER, 'Guns'],
            'BLACK POWDER FIREARMS (ATF CONTROLLED)'    => [FFLHub_Category_Schema::CAT_BLACK_POWDER, 'Firearms'],
            'BLACK POWDER ACCESSORIES'                  => [FFLHub_Category_Schema::CAT_BLACK_POWDER, 'Accessories'],

            // ---- Less Lethal ----
            'LESS LETHAL PISTOL'                        => [FFLHub_Category_Schema::CAT_LESS_LETHAL, 'Pistol'],
            'LESS LETHAL RIFLE'                         => [FFLHub_Category_Schema::CAT_LESS_LETHAL, 'Rifle'],
            'LESS LETHAL AMMO'                          => [FFLHub_Category_Schema::CAT_LESS_LETHAL, 'Ammo'],
            'LESS LETHAL ACCESSORIES'                   => [FFLHub_Category_Schema::CAT_LESS_LETHAL, 'Accessories'],
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
            1 => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns'], // Handguns
            2 => [FFLHub_Category_Schema::CAT_FIREARMS, 'Handguns'], // Used Handguns

            // --- Firearms: Long guns (ambiguous rifle/shotgun) ---
            3 => [FFLHub_Category_Schema::CAT_FIREARMS, 'Other / Specialty'], // Used Long Guns
            5 => [FFLHub_Category_Schema::CAT_FIREARMS, 'Other / Specialty'], // Long Guns (mixed)

            // --- Less Lethal ---
            4  => [FFLHub_Category_Schema::CAT_LESS_LETHAL, 'Tasers'], // Tasers
            27 => [FFLHub_Category_Schema::CAT_LESS_LETHAL],           // Non-Lethal Defense (mixed)

            // --- NFA ---
            6  => [FFLHub_Category_Schema::CAT_NFA], // NFA Products (mixed; not only suppressors)

            // --- Black Powder ---
            7  => [FFLHub_Category_Schema::CAT_BLACK_POWDER, 'Firearms'],
            16 => [FFLHub_Category_Schema::CAT_BLACK_POWDER, 'Accessories'],

            // --- Optics / Optics Accessories ---
            8  => [FFLHub_Category_Schema::CAT_OPTICS],
            28 => [FFLHub_Category_Schema::CAT_OPTICS, 'Observation / Range Finding'],
            29 => [FFLHub_Category_Schema::CAT_OPTICS, 'Observation / Range Finding'],
            30 => [FFLHub_Category_Schema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            9  => [FFLHub_Category_Schema::CAT_OPTICS, 'Optics Accessories'],
            31 => [FFLHub_Category_Schema::CAT_OPTICS, 'Optics Accessories'],

            // --- Lights and Lasers ---
            20 => [FFLHub_Category_Schema::CAT_LIGHTS],

            // --- Magazines ---
            10 => [FFLHub_Category_Schema::CAT_MAGAZINES],
            24 => [FFLHub_Category_Schema::CAT_MAGAZINES],

            // --- Ammo ---
            18 => [FFLHub_Category_Schema::CAT_AMMO],

            // (You can later decide where to place 22, 41, 42, 43 etc.)
        ];

        return $map[$dept] ?? null;
    }
}
