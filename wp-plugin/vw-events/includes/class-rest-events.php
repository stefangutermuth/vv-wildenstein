<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_REST_Events {

    public const NS = 'vw-events/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/events', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'list_events' ],
            'args'                => [
                'standort' => [ 'type' => 'string' ],
                'category' => [ 'type' => 'string' ],
                'from'     => [ 'type' => 'string' ],
                'to'       => [ 'type' => 'string' ],
                'per_page' => [ 'type' => 'integer', 'default' => 20 ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
            ],
        ] );

        register_rest_route( self::NS, '/events/(?P<id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'single_event' ],
        ] );
    }

    public static function list_events( WP_REST_Request $req ): WP_REST_Response {
        $per_page = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );
        $page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );

        $args = [
            'post_type'      => 'vw_event',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'meta_key'       => '_vw_event_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'tax_query'      => [],
            'meta_query'     => [],
        ];

        $standort = (string) $req->get_param( 'standort' );
        if ( $standort !== '' ) {
            $slugs   = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $standort ) ) ) );
            $slugs[] = 'verband-weit';
            $args['tax_query'][] = [
                'taxonomy' => 'vw_standort',
                'field'    => 'slug',
                'terms'    => array_values( array_unique( $slugs ) ),
            ];
        }

        $category = (string) $req->get_param( 'category' );
        if ( $category !== '' ) {
            $cats = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $category ) ) ) );
            $args['tax_query'][] = [
                'taxonomy' => 'vw_event_category',
                'field'    => 'slug',
                'terms'    => $cats,
            ];
        }

        $from = (string) $req->get_param( 'from' );
        $to   = (string) $req->get_param( 'to' );
        if ( $from !== '' ) {
            $args['meta_query'][] = [
                'key'     => '_vw_event_start',
                'value'   => $from,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ];
        }
        if ( $to !== '' ) {
            $args['meta_query'][] = [
                'key'     => '_vw_event_start',
                'value'   => $to,
                'compare' => '<=',
                'type'    => 'DATETIME',
            ];
        }

        return VW_Events_Multisite::with_master( static function () use ( $args ) {
            $q = new WP_Query( $args );
            $items = array_map( [ VW_Events_Helpers::class, 'format_event' ], $q->posts );

            $resp = new WP_REST_Response( $items, 200 );
            $resp->header( 'X-WP-Total', (string) (int) $q->found_posts );
            $resp->header( 'X-WP-TotalPages', (string) (int) $q->max_num_pages );
            return $resp;
        } );
    }

    public static function single_event( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req['id'];
        return VW_Events_Multisite::with_master( static function () use ( $id ) {
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== 'vw_event' || $post->post_status !== 'publish' ) {
                return new WP_REST_Response( [ 'message' => 'Not found' ], 404 );
            }
            return new WP_REST_Response( VW_Events_Helpers::format_event( $post ), 200 );
        } );
    }
}
