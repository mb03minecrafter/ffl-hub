<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps distributor-specific category values (Lipsey item groups, RSR department numbers)
 * into FFLHub's unified category + subcategory structure.
 */


/**
 * 
 * Here is the unified category structure, it is still WIP:
 * 
 * Firearms
 *     Handguns
 *         Pistols    
 *         Revolvers
 *     Rifles
 *         Semi-Auto
 *         Bolt Action
 *         Lever Action
 *         Pump Action
 *         Single Shot / Break Action
 *     Shotguns
 *         Semi-Auto
 *         Bolt Action
 *         Lever Action
 *         Pump Action
 *         Single Shot / Break Action
 *     Other / Specialty
 * Optics / Optics Accessories 
 *     Scopes / Magnified Optics
 *     Red Dots / Non-Magnified Optics
 *     Optic Mounts & Rings
 *     Observation / Range Finding
 *     Optics Accessories
 * Lights and Lasers
 * Magazines
 *     Rifle
 *     Handgun
 *     Shotgun
 * Ammo
 * NFA
 *     Suppressor Accessories 
 *
 * Black Powder
 *     Guns
 *     Firearms
 *     Accessories 
 * Less Lethal
 *     Tasers
 *     Pistol
 *     Rifle
 *     Ammo
 *     Accessories 
 * 
 */


class FFLHub_Category_Mapper
{


    /**
     * Map Lipsey's item_group → normalized category/subcategory
     */
    public static function map_lipseys(string $item_group): ?array
    {
        $g = strtoupper(trim($item_group));

        $map = [

            // ---- Handguns ----
            'SPECIALTY PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'POLYMER CENTERFIRE PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'POLYMER CENTERFIRE CONCEAL CARRY PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'METAL FRAME CENTERFIRE PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'METAL FRAME CENTERFIRE CONCEAL PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'POLYMER RIMFIRE PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'METAL FRAME RIMFIRE PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'TACTICAL CENTERFIRE SEMI-AUTO PISTOLS' => ['Firearms', 'Handguns', 'Pistol'],
            'TACTICAL RIMFIRE SEMI-AUTO PISTOL' => ['Firearms', 'Handguns', 'Pistol'],
            'SINGLE SHOT HANDGUNS' => ['Firearms', 'Handguns', 'Pistol'],
            'DERRINGERS' => ['Firearms', 'Handguns', 'Pistol'],

            'SINGLE ACTION CENTERFIRE REVOLVERS' => ['Firearms', 'Handguns', 'Revolver'],
            'SINGLE ACTION RIMFIRE REVOLVERS' => ['Firearms', 'Handguns', 'Revolver'],
            'DOUBLE ACTION CENTERFIRE REVOLVERS' => ['Firearms', 'Handguns', 'Revolver'],
            'DOUBLE ACTION CENTRIFIRE CONCEAL REVOLVER' => ['Firearms', 'Handguns', 'Revolver'],
            'DOUBLE ACTION RIMFIRE REVOLVERS' => ['Firearms', 'Handguns', 'Revolver'],
            'DOUBLE ACTION RIMFIRE CONCEAL REVOLVERS' => ['Firearms', 'Handguns', 'Revolver'],

            // ---- Rifles ----
            'SPORTING BOLT ACTION CENTERFIRE RIFLES' => ['Firearms', 'Rifles', 'Bolt Action'],
            'SPORTING BOLT ACTION RIMFIRE RIFLES' => ['Firearms', 'Rifles', 'Bolt Action'],
            'TACTICAL BOLT ACTION RIFLES' => ['Firearms', 'Rifles', 'Bolt Action'],
            'SPORTING SEMI-AUTO CENTERFIRE RIFLES' => ['Firearms', 'Rifles', 'Semi-Auto'],
            'SPORTING SEMI-AUTO RIMFIRE RIFLES' => ['Firearms', 'Rifles', 'Semi-Auto'],
            'TACTICAL CENTERFIRE SEMI-AUTO RIFLES' => ['Firearms', 'Rifles', 'Semi-Auto'],
            'TACTICAL RIMFIRE SEMI-AUTO RIFLES' => ['Firearms', 'Rifles', 'Semi-Auto'],
            'AR STYLE CENTERFIRE RIFLES' => ['Firearms', 'Rifles', 'Semi-Auto'],
            'SPORTING LEVERACTION CENTERFIRE RIFLES' => ['Firearms', 'Rifles', 'Lever Action'],
            'SPORTING LEVERACTION RIMFIRE RIFLES' => ['Firearms', 'Rifles', 'Lever Action'],
            'PUMP CENTERFIRE RIFLES' => ['Firearms', 'Rifles', 'Pump Action'],
            'PUMP RIMFIRE RIFLES' => ['Firearms', 'Rifles', 'Pump Action'],
            'SINGLE SHOT CENTERFIRE RIFLES' => ['Firearms', 'Rifles', 'Single Shot / Break Action'],
            'SINGLE SHOT RIMFIRE RIFLES' => ['Firearms', 'Rifles', 'Single Shot / Break Action'],
            'RIFLE/SHOTGUN COMBOS' => ['Firearms', 'Rifles', 'Single Shot / Break Action'],



            // ---- Shotguns ----
            'SPORTING PUMP SHOTGUNS' => ['Firearms', 'Shotguns', 'Pump Action'],
            'TACTICAL PUMP SHOTGUNS' => ['Firearms', 'Shotguns', 'Pump Action'],
            'SPORTING SEMI-AUTO SHOTGUNS' => ['Firearms', 'Shotguns', 'Semi-Auto'],
            'TACTICAL SEMI-AUTO SHOTGUNS' => ['Firearms', 'Shotguns', 'Semi-Auto'],
            'OVER/UNDER SHOTGUNS' => ['Firearms', 'Shotguns', 'Single Shot / Break Action'],
            'SIDE BY SIDE SHOTGUNS' => ['Firearms', 'Shotguns', 'Single Shot / Break Action'],
            'SINGLE SHOT SHOTGUNS' => ['Firearms', 'Shotguns', 'Single Shot / Break Action'],
            'LEVERACTION SHOTGUNS' => ['Firearms', 'Shotguns', 'Lever Action'],
            'BOLT ACTION SHOTGUN' => ['Firearms', 'Shotguns', 'Bolt Action'],

            //other
            'OTHER FIREARMS' => ['Firearms', 'Other / Specialty'],
            'FIRE CONTROL UNIT' => ['Firearms', 'Other / Specialty'],
            'ACTIONS' => ['Firearms', 'Other / Specialty'],

            // ---- Optics ----
            'SCOPES' => ['Optics', 'Scopes / Magnified Optics'],
            'MAGNIFIED TACTICAL OPTICS' => ['Optics', 'Scopes / Magnified Optics'],
            'SPOTTING SCOPES' => ['Optics', 'Scopes / Magnified Optics'],
            'NON-MAGNIFIED OPTICS' => ['Optics', 'Red Dots / Non-Magnified Optics'],
            'THERMAL OPTICS' => ['Optics', 'Red Dots / Non-Magnified Optics'],
            'HANDGUN SIGHTS' => ['Optics', 'Red Dots / Non-Magnified Optics'],
            'LONG GUN SIGHTS' => ['Optics', 'Red Dots / Non-Magnified Optics'],
            'SCOPE RINGS' => ['Optics', 'Optic Mounts & Rings'],
            'SCOPE MOUNTS' => ['Optics', 'Optic Mounts & Rings'],
            'MAGNIFIED TACTICAL OPTIC MOUNTS' => ['Optics', 'Optic Mounts & Rings'],
            'NON-MAGNIFIED OPTIC MOUNTS' => ['Optics', 'Optic Mounts & Rings'],
            'BINOCULARS' => ['Optics', 'Observation / Range Finding'],
            'RANGE FINDERS' => ['Optics', 'Observation / Range Finding'],

            'NON-MAGNIFIED OPTIC ACCESSORIES' => ['Optics', 'Optic Mounts & Rings'],
            'SCOPE ACCESSORIES' => ['Optics', 'Optics Accessories'],

            //Lights and Lasers
            'LASERS AND LIGHTS' => ['Lights and Lasers'],

            //Magazines
            'Rifle Magazines' => ['Magazines', 'Rifle'],
            'Handgun Magazines' => ['Magazines', 'Handgun'],
            'SHOTGUN MAGAZINES' => ['Magazines', 'Shotgun'],

            //Ammo
            'CENTERFIRE AMMO' => ['Ammo'],

            //NFA
            'SILENCER ACCESSORIES' => ['NFA / Suppressors', 'Suppressor Accessories'],
            'SILENCER MOUNTS' => ['NFA / Suppressors', 'Suppressor Accessories'],
            'SILENCER PISTONS' => ['NFA / Suppressors', 'Suppressor Accessories'],


            //Black Powder
            'BLACK POWDER GUNS' => ['Black Powder', 'Guns'],
            'BLACK POWDER FIREARMS (ATF CONTROLLED)' => ['Black Powder', 'Firearms'],
            'BLACK POWDER ACCESSORIES' => ['Black Powder', 'Accessories'],

            //Less lethal
            'LESS LETHAL PISTOL' => ['Less Lethal', 'Pistol'],
            'LESS LETHAL RIFLE' => ['Less Lethal', 'Rifle'],
            'LESS LETHAL AMMO' => ['Less Lethal', 'Ammo'],
            'LESS LETHAL ACCESSORIES' => ['Less Lethal', 'Accessories'],

        ];

        return $map[$g] ?? null;
    }

    //NEEDS A LOT OF WORK
    /**
     * Map RSR department number → unified category / subcategory.
     *
     * Unified structure (WIP):
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
     * Magazines: Rifle, Handgun, Shotgun
     * Ammo
     * NFA: Suppressor Accessories
     * Black Powder: Guns, Firearms, Accessories
     * Less Lethal: Tasers, Pistol, Rifle, Ammo, Accessories
     */
    public static function map_rsr(int|string $dept): ?array
    {
        $dept = (int) $dept;

        // NOTE:
        // - Only departments that clearly fit your current tree are mapped.
        // - Others return null so you can treat them as "uncategorized" or add categories later.
        // - Subcategory can be null when the dept is too broad (e.g. generic "Magazines", "Ammo").

        $map = [

            // --- Firearms: Handguns ---
            1 => ['Firearms', 'Handguns'],  // Handguns
            2 => ['Firearms', 'Handguns'],  // Used Handguns (no way to split pistol vs revolver from dept alone)

            // --- Firearms: Long guns (ambiguous rifle/shotgun) ---
            3 => ['Firearms', 'Other / Specialty'], // Used Long Guns
            5 => ['Firearms', 'Other / Specialty'], // Long Guns (could be rifles or shotguns; refine elsewhere if needed)

            // --- Less Lethal ---
            4  => ['Less Lethal', 'Tasers'],       // Tasers
            //26 => ['Less Lethal', 'Accessories'],  // Safety & Protection (often pepper spray, etc.)
            27 => ['Less Lethal'],  // Non-Lethal Defense (mixed less-lethal SKUs)

            // --- NFA ---
            6 => ['NFA'], // NFA Products (mixed but closest bucket you defined)

            // --- Black Powder ---
            7  => ['Black Powder', 'Firearms'],     // Black Powder
            16 => ['Black Powder', 'Accessories'],  // Black Powder Accessories

            // --- Optics / Optics Accessories ---
            8  => ['Optics / Optics Accessories', 'Scopes / Magnified Optics'], // Optics
            28 => ['Optics / Optics Accessories', 'Observation / Range Finding'], // Binoculars
            29 => ['Optics / Optics Accessories', 'Observation / Range Finding'], // Spotting Scopes
            30 => ['Optics / Optics Accessories', 'Red Dots / Non-Magnified Optics'], // Sights
            9  => ['Optics / Optics Accessories', 'Optics Accessories'], // Optical Accessories
            31 => ['Optics / Optics Accessories', 'Optics Accessories'], // Optical Accessories

            // --- Lights and Lasers ---
            20 => ['Lights and Lasers'],  // Lights, Lasers & Batteries

            // --- Magazines ---
            10 => ['Magazines'], // Magazines (mixed rifle/handgun/shotgun)
            24 => ['Magazines'], // High Capacity Magazines (still mixed; usually rifle, but not guaranteed)

            // --- Ammo ---
            18 => ['Ammo'], // Ammunition (mixed calibers & types)

            // --- Firearms: Other / Specialty (airguns, parts that don't fit elsewhere yet) ---
            //22 => ['Firearms', 'Other / Specialty'], // Airguns (no dedicated category yet)
            //41 => ['Firearms', 'Other / Specialty'], // Upper Receivers & Conversion Kits
            //42 => ['NFA',      'Suppressor Accessories'], // SBR Barrels & Upper Receivers (NFA-ish bucket)
            //43 => ['Firearms', 'Other / Specialty'], // Upper Receivers & Conversion Kits - High Capacity
        ];

        return $map[$dept] ?? null;
    }
}
