<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_CPT {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
        add_action( 'init', [ __CLASS__, 'register_taxonomies' ] );
        add_action( 'init', [ __CLASS__, 'ensure_default_terms' ], 20 );
    }

    public static function register_post_type(): void {
        register_post_type( 'vw_event', [
            'labels' => [
                'name'               => __( 'Veranstaltungen', 'vw-events' ),
                'singular_name'      => __( 'Veranstaltung', 'vw-events' ),
                'add_new'            => __( 'Erstellen', 'vw-events' ),
                'add_new_item'       => __( 'Neue Veranstaltung', 'vw-events' ),
                'edit_item'          => __( 'Veranstaltung bearbeiten', 'vw-events' ),
                'new_item'           => __( 'Neue Veranstaltung', 'vw-events' ),
                'view_item'          => __( 'Veranstaltung ansehen', 'vw-events' ),
                'search_items'       => __( 'Veranstaltungen suchen', 'vw-events' ),
                'menu_name'          => __( 'Veranstaltungen', 'vw-events' ),
            ],
            'public'          => true,
            'show_in_rest'    => true,
            'rest_base'       => 'events-internal',
            'supports'        => [ 'title', 'editor', 'thumbnail', 'author', 'revisions' ],
            'has_archive'     => true,
            'menu_icon'       => 'dashicons-calendar-alt',
            'menu_position'   => 22,
            'capability_type' => 'post',
            'rewrite'         => [ 'slug' => 'veranstaltungen' ],
        ] );
    }

    public static function register_taxonomies(): void {
        register_taxonomy( 'vw_standort', [ 'vw_event' ], [
            'labels' => [
                'name'          => __( 'Standorte', 'vw-events' ),
                'singular_name' => __( 'Standort', 'vw-events' ),
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'standort' ],
        ] );

        register_taxonomy( 'vw_event_category', [ 'vw_event' ], [
            'labels' => [
                'name'          => __( 'Event-Kategorien', 'vw-events' ),
                'singular_name' => __( 'Event-Kategorie', 'vw-events' ),
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'event-kategorie' ],
        ] );
    }

    public static function ensure_default_terms(): void {
        foreach ( VW_Events_Helpers::STANDORT_DEFAULTS as $slug => $name ) {
            if ( ! term_exists( $slug, 'vw_standort' ) ) {
                wp_insert_term( $name, 'vw_standort', [ 'slug' => $slug ] );
            }
        }
        foreach ( VW_Events_Helpers::CATEGORY_DEFAULTS as $slug => $name ) {
            if ( ! term_exists( $slug, 'vw_event_category' ) ) {
                wp_insert_term( $name, 'vw_event_category', [ 'slug' => $slug ] );
            }
        }
    }
}
