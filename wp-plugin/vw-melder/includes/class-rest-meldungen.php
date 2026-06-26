<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Melder_REST_Meldungen {

    public const NS = 'vw-melder/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/meldungen', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'list_meldungen' ],
            'args'                => [
                'anliegen' => [ 'type' => 'string' ],
                'status'   => [ 'type' => 'string' ],
                'per_page' => [ 'type' => 'integer', 'default' => 50 ],
                'page'     => [ 'type' => 'integer', 'default' => 1 ],
            ],
        ] );

        register_rest_route( self::NS, '/meldungen/(?P<id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'single_meldung' ],
        ] );

        // GeoJSON-FeatureCollection für die Karte.
        register_rest_route( self::NS, '/geojson', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'geojson' ],
            'args'                => [
                'status' => [ 'type' => 'string' ],
            ],
        ] );
    }

    private static function base_query_args(): array {
        return [
            'post_type'      => 'vw_meldung',
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [],
        ];
    }

    private static function apply_tax_filters( array $args, WP_REST_Request $req ): array {
        $anliegen = (string) $req->get_param( 'anliegen' );
        if ( $anliegen !== '' ) {
            $slugs = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $anliegen ) ) ) );
            $args['tax_query'][] = [
                'taxonomy' => 'vw_anliegen',
                'field'    => 'slug',
                'terms'    => $slugs,
            ];
        }

        $status = (string) $req->get_param( 'status' );
        if ( $status !== '' ) {
            $slugs = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $status ) ) ) );
            $args['tax_query'][] = [
                'taxonomy' => 'vw_meldung_status',
                'field'    => 'slug',
                'terms'    => $slugs,
            ];
        }

        return $args;
    }

    public static function list_meldungen( WP_REST_Request $req ): WP_REST_Response {
        $per_page = min( 200, max( 1, (int) $req->get_param( 'per_page' ) ?: 50 ) );
        $page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );

        $args = self::apply_tax_filters( self::base_query_args(), $req );
        $args['posts_per_page'] = $per_page;
        $args['paged']          = $page;

        $q     = new WP_Query( $args );
        $items = array_map( [ VW_Melder_Helpers::class, 'format_meldung' ], $q->posts );

        $resp = new WP_REST_Response( $items, 200 );
        $resp->header( 'X-WP-Total', (string) (int) $q->found_posts );
        $resp->header( 'X-WP-TotalPages', (string) (int) $q->max_num_pages );
        return $resp;
    }

    public static function single_meldung( WP_REST_Request $req ): WP_REST_Response {
        $id   = (int) $req['id'];
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'vw_meldung' || $post->post_status !== 'publish' ) {
            return new WP_REST_Response( [ 'message' => 'Not found' ], 404 );
        }
        return new WP_REST_Response( VW_Melder_Helpers::format_meldung( $post ), 200 );
    }

    public static function geojson( WP_REST_Request $req ): WP_REST_Response {
        $args = self::base_query_args();
        $args['posts_per_page'] = -1;
        $args = self::apply_tax_filters( $args, $req );

        $q        = new WP_Query( $args );
        $features = [];
        foreach ( $q->posts as $post ) {
            $feature = VW_Melder_Helpers::format_geojson_feature( $post );
            if ( $feature !== null ) {
                $features[] = $feature;
            }
        }

        return new WP_REST_Response( [
            'type'     => 'FeatureCollection',
            'features' => $features,
        ], 200 );
    }
}
