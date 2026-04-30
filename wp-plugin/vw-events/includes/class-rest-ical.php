<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_REST_Ical {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( VW_Events_REST_Events::NS, '/ical', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'feed' ],
        ] );
    }

    public static function feed( WP_REST_Request $req ): void {
        VW_Events_Multisite::with_master( static function () use ( $req ) {
            self::render_feed( $req );
        } );
    }

    private static function render_feed( WP_REST_Request $req ): void {
        $args = [
            'post_type'      => 'vw_event',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'meta_key'       => '_vw_event_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'tax_query'      => [],
            'meta_query'     => [
                [
                    'key'     => '_vw_event_start',
                    'value'   => current_time( 'Y-m-d\TH:i:s' ),
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ],
            ],
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
            $args['tax_query'][] = [
                'taxonomy' => 'vw_event_category',
                'field'    => 'slug',
                'terms'    => array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $category ) ) ) ),
            ];
        }

        $q = new WP_Query( $args );

        nocache_headers();
        header( 'Content-Type: text/calendar; charset=utf-8' );
        header( 'Content-Disposition: inline; filename="vw-events.ics"' );

        $tz_name = wp_timezone_string();
        $home    = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'wordpress.local';

        $out  = "BEGIN:VCALENDAR\r\n";
        $out .= "VERSION:2.0\r\n";
        $out .= "PRODID:-//vw-events//" . self::esc( $home ) . "//DE\r\n";
        $out .= "CALSCALE:GREGORIAN\r\n";
        $out .= "METHOD:PUBLISH\r\n";

        $now_utc = gmdate( 'Ymd\THis\Z' );

        foreach ( $q->posts as $post ) {
            $start    = (string) get_post_meta( $post->ID, '_vw_event_start', true );
            if ( ! $start ) { continue; }
            $end      = (string) get_post_meta( $post->ID, '_vw_event_end', true );
            $all_day  = (bool) get_post_meta( $post->ID, '_vw_event_all_day', true );
            $repeat   = (string) get_post_meta( $post->ID, '_vw_event_repeat', true );
            $until    = (string) get_post_meta( $post->ID, '_vw_event_repeat_until', true );
            $loc      = trim( implode( ' — ', array_filter( [
                (string) get_post_meta( $post->ID, '_vw_event_location_name', true ),
                (string) get_post_meta( $post->ID, '_vw_event_location_addr', true ),
            ] ) ) );

            $uid = sprintf( 'vw-event-%d@%s', $post->ID, $home );

            $out .= "BEGIN:VEVENT\r\n";
            $out .= 'UID:' . self::esc( $uid ) . "\r\n";
            $out .= 'DTSTAMP:' . $now_utc . "\r\n";
            $out .= 'SUMMARY:' . self::esc( get_the_title( $post ) ) . "\r\n";
            $out .= 'URL:' . self::esc( get_permalink( $post ) ) . "\r\n";

            if ( $all_day ) {
                $s = self::date_only( $start );
                $e = $end ? self::date_only( $end ) : $s;
                $e = gmdate( 'Ymd', strtotime( $e . ' +1 day' ) );
                $out .= 'DTSTART;VALUE=DATE:' . $s . "\r\n";
                $out .= 'DTEND;VALUE=DATE:' . $e . "\r\n";
            } else {
                $out .= 'DTSTART;TZID=' . $tz_name . ':' . self::dt_local( $start ) . "\r\n";
                if ( $end ) {
                    $out .= 'DTEND;TZID=' . $tz_name . ':' . self::dt_local( $end ) . "\r\n";
                }
            }

            if ( in_array( $repeat, [ 'daily', 'weekly', 'monthly' ], true ) ) {
                $freq = strtoupper( $repeat );
                $rule = 'FREQ=' . $freq;
                if ( $until ) {
                    $rule .= ';UNTIL=' . gmdate( 'Ymd\THis\Z', strtotime( $until . ' 23:59:59' ) );
                }
                $out .= 'RRULE:' . $rule . "\r\n";
            }

            $desc = wp_strip_all_tags( $post->post_content );
            if ( $desc !== '' ) {
                $out .= 'DESCRIPTION:' . self::esc( $desc ) . "\r\n";
            }
            if ( $loc !== '' ) {
                $out .= 'LOCATION:' . self::esc( $loc ) . "\r\n";
            }
            $out .= "END:VEVENT\r\n";
        }

        $out .= "END:VCALENDAR\r\n";
        echo $out; // phpcs:ignore
        exit;
    }

    private static function esc( string $s ): string {
        $s = str_replace( [ '\\', "\r\n", "\n", ',', ';' ], [ '\\\\', '\\n', '\\n', '\\,', '\\;' ], $s );
        return $s;
    }

    private static function dt_local( string $iso ): string {
        $ts = strtotime( $iso );
        return $ts ? date( 'Ymd\THis', $ts ) : '';
    }

    private static function date_only( string $iso ): string {
        $ts = strtotime( $iso );
        return $ts ? date( 'Ymd', $ts ) : '';
    }
}
