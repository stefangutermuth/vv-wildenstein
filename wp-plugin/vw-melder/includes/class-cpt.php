<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Melder_CPT {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'init', [ __CLASS__, 'register_taxonomies' ] );
        add_action( 'init', [ __CLASS__, 'ensure_default_terms' ], 20 );

        // Permalink + „Ansehen“/Vorschau auf das Astro-Frontend (Subdomain) lenken.
        add_filter( 'post_type_link', [ __CLASS__, 'frontend_permalink' ], 10, 2 );
        add_filter( 'preview_post_link', [ __CLASS__, 'frontend_preview' ], 10, 2 );
    }

    public static function frontend_permalink( string $link, $post ): string {
        if ( $post && $post->post_type === 'vw_meldung' ) {
            return VW_Melder_Settings::frontend_url() . '/meldung/' . $post->ID;
        }
        return $link;
    }

    public static function frontend_preview( string $link, $post ): string {
        if ( $post && $post->post_type === 'vw_meldung' ) {
            return VW_Melder_Settings::frontend_url() . '/meldung/' . $post->ID;
        }
        return $link;
    }

    public static function register_post_type(): void {
        register_post_type( 'vw_meldung', [
            'labels' => [
                'name'          => __( 'Meldungen', 'vw-melder' ),
                'singular_name' => __( 'Meldung', 'vw-melder' ),
                'add_new'       => __( 'Erstellen', 'vw-melder' ),
                'add_new_item'  => __( 'Neue Meldung', 'vw-melder' ),
                'edit_item'     => __( 'Meldung bearbeiten', 'vw-melder' ),
                'new_item'      => __( 'Neue Meldung', 'vw-melder' ),
                'view_item'     => __( 'Meldung ansehen', 'vw-melder' ),
                'search_items'  => __( 'Meldungen suchen', 'vw-melder' ),
                'menu_name'     => __( 'Mängelmelder', 'vw-melder' ),
            ],
            'public'          => true,
            'show_in_rest'    => true,
            'rest_base'       => 'meldungen-internal',
            'supports'        => [ 'title', 'editor', 'thumbnail', 'author', 'revisions' ],
            'has_archive'     => true,
            'menu_icon'       => 'dashicons-warning',
            'menu_position'   => 23,
            'capability_type' => 'post',
            'rewrite'         => [ 'slug' => 'meldungen' ],
        ] );
    }

    public static function register_taxonomies(): void {
        register_taxonomy( 'vw_anliegen', [ 'vw_meldung' ], [
            'labels' => [
                'name'          => __( 'Anliegen', 'vw-melder' ),
                'singular_name' => __( 'Anliegen', 'vw-melder' ),
                'menu_name'     => __( 'Anliegen', 'vw-melder' ),
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'anliegen' ],
        ] );

        register_taxonomy( 'vw_meldung_status', [ 'vw_meldung' ], [
            'labels' => [
                'name'          => __( 'Status', 'vw-melder' ),
                'singular_name' => __( 'Status', 'vw-melder' ),
                'menu_name'     => __( 'Status', 'vw-melder' ),
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_in_rest'      => true,
            // Eigene Spalte als farbige Pille (siehe VW_Melder_Admin_UI), daher keine Auto-Spalte.
            'show_admin_column' => false,
            'rewrite'           => [ 'slug' => 'meldung-status' ],
        ] );
    }

    public static function ensure_default_terms(): void {
        foreach ( VW_Melder_Helpers::ANLIEGEN_DEFAULTS as $slug => $name ) {
            if ( ! term_exists( $slug, 'vw_anliegen' ) ) {
                wp_insert_term( $name, 'vw_anliegen', [ 'slug' => $slug ] );
            }
        }
        foreach ( VW_Melder_Helpers::STATUS_DEFAULTS as $slug => $name ) {
            if ( ! term_exists( $slug, 'vw_meldung_status' ) ) {
                wp_insert_term( $name, 'vw_meldung_status', [ 'slug' => $slug ] );
            }
        }
    }
}
