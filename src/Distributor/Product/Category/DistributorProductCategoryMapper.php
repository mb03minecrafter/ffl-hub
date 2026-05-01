<?php

namespace FFLHub\Distributor\Product\Category;

use FFLHub\Product\CategorySchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DistributorProductCategoryMapper
 *
 * Translates distributor-specific category identifiers into FFLHub's unified
 * category path format.
 *
 * Output format:
 * - A category path is an ordered list of strings:
 *     [ topLevel, midLevel?, leafLevel? ]
 *   Example:
 *     [ CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols' ]
 *
 * Design goals:
 * - Pure mapping (no DB/IO, no side effects).
 * - Tolerant of messy inputs (extra whitespace, case differences, numeric strings).
 * - Returns null when a mapping is unknown (callers can fall back to defaults).
 *
 * Notes:
 * - Lipsey's uses an "item_group" string (often uppercase with varying punctuation).
 * - RSR uses a numeric department code ("dept_number").
 */
class DistributorProductCategoryMapper
{
    /**
     * Map Lipsey's item_group -> unified category path.
     *
     * Normalization rules:
     * - Trims whitespace
     * - Uppercases
     *
     * @param string $item_group Lipsey's item group label (as received from API/feed)
     * @return array<int,string>|null Category path, or null if unknown
     */
    public static function map_lipseys(string $item_group): ?array
    {
        $g = strtoupper(trim($item_group));
        if ($g === '') {
            return null;
        }

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

        // Return a copy (avoid callers accidentally mutating our map entries).
        return isset($map[$g]) ? array_values($map[$g]) : null;
    }

    /**
     * Map RSR department number -> unified category path.
     *
     * Accepts:
     * - int
     * - numeric string
     * - strings with whitespace (e.g. " 10 ")
     *
     * @param int|string $dept RSR dept_number (as received from feed/table)
     * @return array<int,string>|null Category path, or null if unknown/invalid
     */
    public static function map_rsr(int|string $dept): ?array
    {
        $dept_i = self::to_int_or_zero($dept);
        if ($dept_i <= 0) {
            return null;
        }

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

        return isset($map[$dept_i]) ? array_values($map[$dept_i]) : null;
    }


    /**
     * Map Zanders category string -> unified category path.
     *
     * Zanders feed uses a relatively clean top-level Category column.
     * We map the obvious ones precisely, then use a few conservative
     * contains-based fallbacks.
     *
     * @param string $category Zanders "category" column
     * @return array<int,string>|null
     */
    public static function map_zanders(string $category): ?array
    {
        $c = strtoupper(trim($category));
        if ($c === '') {
            return null;
        }

        // --- High confidence exact mappings ---
        $map = [

            // Firearms
            'PISTOL'                => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'REVOLVER'              => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'RIFLE'                 => [CategorySchema::CAT_FIREARMS, 'Rifles'],
            'SHOTGUN'               => [CategorySchema::CAT_FIREARMS, 'Shotguns'],
            'RECEIVER'              => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'PISTOL FRAMES'         => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'OTHER FIREARMS'        => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'STARTER PISTOLS'       => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],

            // NFA / Suppressors
            'DS SUPPRESSORS'        => [CategorySchema::CAT_NFA, 'Suppressors'],
            'SUPPRESSOR ACCESSORIES' => [CategorySchema::CAT_NFA, 'Suppressor Accessories'],

            // Black powder / muzzleloading
            'BLACK POWDER REVOLVERS' => [CategorySchema::CAT_BLACK_POWDER, 'Guns'],
            'MUZZLELOADING FIREARMS' => [CategorySchema::CAT_BLACK_POWDER, 'Firearms'],
            'MUZZLELOADING ACCESSORIES' => [CategorySchema::CAT_BLACK_POWDER, 'Accessories'],
            'PERCUSSION CAPS'       => [CategorySchema::CAT_BLACK_POWDER, 'Accessories'],

            // Optics
            'OPTICS'                => [CategorySchema::CAT_OPTICS],
            'BINOCULARS'            => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],
            'RANGEFINDERS'          => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],
            'SPOTTING SCOPES'       => [CategorySchema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'SCOPE MOUNTS AND RINGS' => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'SCOPE COVERS'          => [CategorySchema::CAT_OPTICS, 'Optics Accessories'],
            'BORE SIGHTERS'         => [CategorySchema::CAT_OPTICS, 'Optics Accessories'],
            'NIGHT VISION'          => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'], // best-fit LANE for now

            // Lights / lasers
            'LASERS'                => [CategorySchema::CAT_LIGHTS],
            'LIGHTS AND ACCESSORIES' => [CategorySchema::CAT_LIGHTS],

            // Magazines
            'MAGAZINES (REPLACEMENT)' => [CategorySchema::CAT_MAGAZINES],
            'MAGAZINE ACCESSORIES'    => [CategorySchema::CAT_MAGAZINES],

            // Ammo
            'AMMO'                 => [CategorySchema::CAT_AMMO],
            'BLANKS'               => [CategorySchema::CAT_AMMO],
            'SNAP CAPS'            => [CategorySchema::CAT_AMMO],

            // Less lethal
            'PEPPER SPRAY'         => [CategorySchema::CAT_LESS_LETHAL],
            'STUN GUNS'            => [CategorySchema::CAT_LESS_LETHAL],
            'PERSONAL SAFETY(NON FIREARMS)' => [CategorySchema::CAT_LESS_LETHAL],

            // Airguns (you may want its own CAT later; for now keep it under firearms-ish)
            'AIRGUNS AND ACCESSORIES' => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],

            // Reloading (no dedicated constant shown in your snippet; returning null lets caller default)
            // 'RELOADING TOOLS' ...
        ];

        if (isset($map[$c])) {
            return array_values($map[$c]);
        }

        // --- Conservative heuristic fallbacks (keeps mapping coverage high) ---

        // Anything with these keywords is almost certainly a firearm PART, not a complete gun.
        foreach (['BARREL', 'TRIGGER', 'STOCK', 'FOREARM', 'GRIP', 'MUZZLE BRAKE', 'AR15 UPPER', 'CONVERSION KIT', 'CHOKE TUBE'] as $kw) {
            if (strpos($c, $kw) !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Parts'];
            }
        }

        // Sights belong under optics in your taxonomy.
        if (strpos($c, 'SIGHT') !== false) {
            return [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'];
        }

        // Suppressor-ish keywords
        if (strpos($c, 'SUPPRESS') !== false || strpos($c, 'SILENC') !== false) {
            return [CategorySchema::CAT_NFA];
        }

        // Muzzleloading-ish keywords
        if (strpos($c, 'MUZZLE') !== false || strpos($c, 'BLACK POWDER') !== false) {
            return [CategorySchema::CAT_BLACK_POWDER];
        }

        // Magazine keyword
        if (strpos($c, 'MAGAZ') !== false) {
            return [CategorySchema::CAT_MAGAZINES];
        }

        // Ammo keyword
        if (strpos($c, 'AMMO') !== false) {
            return [CategorySchema::CAT_AMMO];
        }

        // Lights / lasers keyword
        if (strpos($c, 'LIGHT') !== false || strpos($c, 'LASER') !== false) {
            return [CategorySchema::CAT_LIGHTS];
        }

        // If we can't confidently map it (outdoors/archery/apparel/etc.), return null.
        return null;
    }

    /**
     * Map Davidson's item_type string -> unified category path.
     *
     * Known values are largely firearm lane strings such as:
     * - "Pistol: Semi-Auto"
     * - "Revolver: Double Action"
     * - "Rifle: Bolt Action"
     * - "Shotgun: Pump Action"
     * - "AR Lower: Receiver Only"
     *
     * Unknown values return null so caller/default logic can place the product
     * into an uncategorized fallback.
     *
     * @param string $item_type Davidson's item_type value
     * @return array<int,string>|null
     */
    public static function map_davidsons(string $item_type): ?array
    {
        $raw = trim($item_type);
        if ($raw === '') {
            return null;
        }

        $t = strtoupper($raw);
        $t = (string) preg_replace('/\s+/', ' ', $t);
        $t = trim($t);
        if ($t === '') {
            return null;
        }

        $exact = [
            'AR LOWER: RECEIVER ONLY'      => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'MINI CANNON: MUZZLELOADER'    => [CategorySchema::CAT_BLACK_POWDER, 'Guns'],

            'PISTOL:'                      => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL: BOLT ACTION'          => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL: DERRINGER'            => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL: LEVER ACTION'         => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL: SEMI-AUTO'            => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL: SEMI-AUTOMATIC'       => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL: SINGLE ACTION'        => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL: SINGLE SHOT'          => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],

            'REVOLVER: DOUBLE ACTION'      => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'REVOLVER: DOUBLE ACTION ONLY' => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'REVOLVER: SINGLE ACTION'      => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],

            'RIFLE: BOLT ACTION'           => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Bolt Action'],
            'RIFLE: BREAK ACTION'          => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],
            'RIFLE: LEVER ACTION'          => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Lever Action'],
            'RIFLE: PUMP ACTION'           => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Pump Action'],
            'RIFLE: SEMI AUTO'             => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'RIFLE: SEMI-AUTO'             => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'RIFLE: SINGLE SHOT'           => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'],

            'SHOTGUN: BOLT ACTION'         => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Bolt Action'],
            'SHOTGUN: BREAK ACTION'        => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'SHOTGUN: LEVER ACTION'        => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Lever Action'],
            'SHOTGUN: OVER AND UNDER'      => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'SHOTGUN: PUMP ACTION'         => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Pump Action'],
            'SHOTGUN: SEMI-AUTO'           => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Semi-Auto'],
            'SHOTGUN: SIDE BY SIDE'        => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],
            'SHOTGUN: SINGLE SHOT'         => [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'],

            'RIFLE|SHOTGUN COMBO: ALL'     => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'RIFLE|SHOTGUN: ALL'           => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
        ];

        if (isset($exact[$t])) {
            return array_values($exact[$t]);
        }

        // Common "non-standard but present" values from Davidson's feed.
        if (strpos($t, 'MUZZLELOADER') !== false) {
            return [CategorySchema::CAT_BLACK_POWDER, 'Guns'];
        }

        if (strpos($t, 'AR LOWER') !== false || strpos($t, 'RECEIVER') !== false || strpos($t, ': UPPER') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
        }

        if (strpos($t, 'RIFLE|SHOTGUN') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
        }

        // Pistol/Revolver families.
        if (strpos($t, 'REVOLVER:') === 0) {
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'];
        }
        if (strpos($t, 'PISTOL:') === 0) {
            if (strpos($t, 'AIR GUN') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
            }
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'];
        }

        // Rifle family.
        if (strpos($t, 'RIFLE:') === 0) {
            if (strpos($t, 'BOLT') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Rifles', 'Bolt Action'];
            }
            if (strpos($t, 'LEVER') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Rifles', 'Lever Action'];
            }
            if (strpos($t, 'PUMP') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Rifles', 'Pump Action'];
            }
            if (strpos($t, 'SEMI AUTO') !== false || strpos($t, 'SEMI-AUTO') !== false || strpos($t, 'SEMI-AUTOMATIC') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'];
            }
            if (strpos($t, 'BREAK') !== false || strpos($t, 'SINGLE SHOT') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Rifles', 'Single Shot / Break Action'];
            }
            return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
        }

        // Shotgun family.
        if (strpos($t, 'SHOTGUN:') === 0) {
            if (strpos($t, 'BOLT') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Bolt Action'];
            }
            if (strpos($t, 'LEVER') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Lever Action'];
            }
            if (strpos($t, 'PUMP') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Pump Action'];
            }
            if (strpos($t, 'SEMI AUTO') !== false || strpos($t, 'SEMI-AUTO') !== false || strpos($t, 'SEMI-AUTOMATIC') !== false) {
                return [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Semi-Auto'];
            }
            if (
                strpos($t, 'BREAK') !== false ||
                strpos($t, 'SINGLE SHOT') !== false ||
                strpos($t, 'OVER AND UNDER') !== false ||
                strpos($t, 'SIDE BY SIDE') !== false
            ) {
                return [CategorySchema::CAT_FIREARMS, 'Shotguns', 'Single Shot / Break Action'];
            }
            return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
        }

        // Let caller/default rules put unknown values into uncategorized fallback.
        return null;
    }

    /**
     * Map Orion product category strings to the unified category path.
     *
     * Orion can return one or more comma-delimited category codes such as
     * PISTOLS, RIFLES, TACTICAL_RIFLES, MAGAZINES, OPTICS, etc. We map exact
     * firearm/accessory categories first, then fall back to conservative
     * keyword checks for the broader catalog.
     */
    public static function map_orion(string $category): ?array
    {
        $raw = strtoupper(trim($category));
        if ($raw === '') {
            return null;
        }

        $tokens = preg_split('/[,|;]+/', $raw);
        $tokens = array_values(
            array_filter(
                array_map(
                    static fn($token): string => trim((string) preg_replace('/\s+/', '_', (string) $token)),
                    (array) $tokens
                ),
                static fn(string $token): bool => $token !== ''
            )
        );

        $exact = [
            'PISTOLS' => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'PISTOL' => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'],
            'REVOLVERS' => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'REVOLVER' => [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'],
            'RIFLES' => [CategorySchema::CAT_FIREARMS, 'Rifles'],
            'TACTICAL_RIFLES' => [CategorySchema::CAT_FIREARMS, 'Rifles', 'Semi-Auto'],
            'SHOTGUNS' => [CategorySchema::CAT_FIREARMS, 'Shotguns'],
            'COMPLETE_LOWERS' => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'RECEIVERS' => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'FRAMES' => [CategorySchema::CAT_FIREARMS, 'Other / Specialty'],
            'COMPLETE_UPPERS' => [CategorySchema::CAT_FIREARMS, 'Parts'],
            'BARRELS' => [CategorySchema::CAT_FIREARMS, 'Parts'],
            'TRIGGERS' => [CategorySchema::CAT_FIREARMS, 'Parts'],
            'CHOKE_TUBES' => [CategorySchema::CAT_FIREARMS, 'Parts'],

            'SCOPES' => [CategorySchema::CAT_OPTICS, 'Scopes / Magnified Optics'],
            'OPTICS' => [CategorySchema::CAT_OPTICS],
            'RED_DOTS' => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'SIGHTS' => [CategorySchema::CAT_OPTICS, 'Red Dots / Non-Magnified Optics'],
            'BINOCULARS' => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],
            'RANGE_FINDERS' => [CategorySchema::CAT_OPTICS, 'Observation / Range Finding'],
            'BORE_SIGHTERS' => [CategorySchema::CAT_OPTICS, 'Optics Accessories'],
            'BASES' => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],
            'RINGS' => [CategorySchema::CAT_OPTICS, 'Optic Mounts & Rings'],

            'LIGHTS' => [CategorySchema::CAT_LIGHTS],
            'LASERS' => [CategorySchema::CAT_LIGHTS],

            'MAGAZINES' => [CategorySchema::CAT_MAGAZINES],
            'AIR_GUN_MAGAZINES' => [CategorySchema::CAT_MAGAZINES],

            'AMMUNITION' => [CategorySchema::CAT_AMMO],
            'AMMO' => [CategorySchema::CAT_AMMO],

            'SUPPRESSORS' => [CategorySchema::CAT_NFA],
            'SILENCERS' => [CategorySchema::CAT_NFA],
            'SUPPRESSOR_ACCESSORIES' => [CategorySchema::CAT_NFA, 'Suppressor Accessories'],

            'BLACK_POWDER_GUNS' => [CategorySchema::CAT_BLACK_POWDER, 'Guns'],
            'BLACK_POWDER_TOOLS' => [CategorySchema::CAT_BLACK_POWDER, 'Accessories'],

            'BATONS' => [CategorySchema::CAT_LESS_LETHAL],
            'PEPPER_SPRAY' => [CategorySchema::CAT_LESS_LETHAL],
        ];

        foreach ($tokens as $token) {
            if (isset($exact[$token])) {
                return array_values($exact[$token]);
            }
        }

        $haystack = implode(' ', $tokens);

        if (strpos($haystack, 'SUPPRESS') !== false || strpos($haystack, 'SILENC') !== false) {
            return [CategorySchema::CAT_NFA];
        }
        if (strpos($haystack, 'BLACK_POWDER') !== false || strpos($haystack, 'MUZZLE') !== false) {
            return [CategorySchema::CAT_BLACK_POWDER];
        }
        if (strpos($haystack, 'MAGAZ') !== false) {
            return [CategorySchema::CAT_MAGAZINES];
        }
        if (strpos($haystack, 'AMMO') !== false || strpos($haystack, 'AMMUNITION') !== false) {
            return [CategorySchema::CAT_AMMO];
        }
        if (strpos($haystack, 'OPTIC') !== false || strpos($haystack, 'SCOPE') !== false || strpos($haystack, 'SIGHT') !== false) {
            return [CategorySchema::CAT_OPTICS];
        }
        if (strpos($haystack, 'LIGHT') !== false || strpos($haystack, 'LASER') !== false) {
            return [CategorySchema::CAT_LIGHTS];
        }
        if (strpos($haystack, 'SHOTGUN') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Shotguns'];
        }
        if (strpos($haystack, 'RIFLE') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Rifles'];
        }
        if (strpos($haystack, 'PISTOL') !== false || strpos($haystack, 'HANDGUN') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Pistols'];
        }
        if (strpos($haystack, 'REVOLVER') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Handguns', 'Revolvers'];
        }
        if (strpos($haystack, 'RECEIVER') !== false || strpos($haystack, 'LOWER') !== false || strpos($haystack, 'FRAME') !== false) {
            return [CategorySchema::CAT_FIREARMS, 'Other / Specialty'];
        }

        return null;
    }





    /**
     * Convert an int-ish value to int, returning 0 if not usable.
     *
     * @param mixed $v
     */
    private static function to_int_or_zero($v): int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v)) {
            $s = trim($v);
            if ($s === '') {
                return 0;
            }
            // tolerate things like "0010" or "10\n"
            if (ctype_digit($s)) {
                return (int) $s;
            }
            // fallback: extract digits (handles "Dept 10" etc.)
            $digits = preg_replace('/\D+/', '', $s);
            return is_string($digits) && $digits !== '' ? (int) $digits : 0;
        }

        if (is_float($v)) {
            return (int) $v;
        }

        return 0;
    }
}

