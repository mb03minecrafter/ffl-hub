<?php

if (! defined('ABSPATH')) {
    exit;
}

class FFLHub_Category_Installer {

    public static function install_default_categories(): void {
        // 1) Check WooCommerce loaded
        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log('[FFLHub] Category install aborted: WooCommerce not loaded.');
            return;
        }

        // 2) Check taxonomy exists
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            error_log('[FFLHub] Category install aborted: taxonomy "product_cat" does not exist.');
            return;
        }

        $tree = self::get_category_tree();

        foreach ( $tree as $top_name => $sublevels ) {
            $top_id = self::ensure_term( $top_name );

            foreach ( $sublevels as $mid_name => $leaf_names ) {
                if ( is_int( $mid_name ) ) {
                    $mid_name   = $leaf_names;
                    $leaf_names = [];
                }

                $mid_id = self::ensure_term( $mid_name, $top_id );

                foreach ( $leaf_names as $leaf_name ) {
                    self::ensure_term( $leaf_name, $mid_id );
                }
            }
        }
    }

    private static function get_category_tree(): array {
        return [
            'Firearms' => [
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

            'Optics / Optics Accessories' => [
                'Scopes / Magnified Optics'       => [],
                'Red Dots / Non-Magnified Optics' => [],
                'Optic Mounts & Rings'            => [],
                'Observation / Range Finding'     => [],
                'Optics Accessories'              => [],
            ],

            'Lights and Lasers' => [
                'Lights and Lasers',
            ],

            'Magazines' => [
                'Magazines' => [
                    'Rifle',
                    'Handgun',
                    'Shotgun',
                ],
            ],

            'Ammo' => [
                'Ammo',
            ],

            'NFA' => [
                'Suppressor Accessories' => [],
            ],

            'Black Powder' => [
                'Guns'        => [],
                'Firearms'    => [],
                'Accessories' => [],
            ],

            'Less Lethal' => [
                'Tasers'      => [],
                'Pistol'      => [],
                'Rifle'       => [],
                'Ammo'        => [],
                'Accessories' => [],
            ],
        ];
    }

    private static function ensure_term( string $name, ?int $parent_id = null ): int {
        $taxonomy = 'product_cat';
        $parent   = $parent_id ?: 0;

        if ( ! taxonomy_exists( $taxonomy ) ) {
            error_log("[FFLHub] ensure_term called but taxonomy '{$taxonomy}' does not exist.");
            return 0;
        }

        $existing = term_exists( $name, $taxonomy, $parent );

        if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
            error_log("[FFLHub] Term already exists: {$name}");
            return (int) $existing['term_id'];
        }

        $args   = [
            'slug'   => sanitize_title( $name ),
            'parent' => $parent,
        ];
        $result = wp_insert_term( $name, $taxonomy, $args );

        if ( is_wp_error( $result ) ) {
            error_log("[FFLHub] Failed to insert term '{$name}': " . $result->get_error_message());
            return 0;
        }

        error_log("[FFLHub] Created term: {$name} (parent: {$parent})");

        return (int) $result['term_id'];
    }
}

