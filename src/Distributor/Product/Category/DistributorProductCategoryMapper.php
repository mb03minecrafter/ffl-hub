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
     * Map Lipsey's item_group → unified category path.
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
     * Map RSR department number → unified category path.
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
