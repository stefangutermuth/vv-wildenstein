<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared utilities for the vw-events plugin.
 */
final class VW_Events_Helpers {

    public const META_KEYS_PUBLIC = [
        '_vw_event_start',
        '_vw_event_end',
        '_vw_event_all_day',
        '_vw_event_repeat',
        '_vw_event_repeat_until',
        '_vw_event_location_name',
        '_vw_event_location_addr',
        '_vw_event_organizer_name',
        '_vw_event_url',
    ];

    public const META_KEYS_PRIVATE = [
        '_vw_event_organizer_email',
        '_vw_event_submitter_name',
        '_vw_event_submitter_email',
        '_vw_event_submission_ip',
        '_vw_event_source',
    ];

    public const STANDORT_DEFAULTS = [
        'gruenhainichen' => 'Grünhainichen',
        'borstendorf'    => 'Borstendorf',
        'waldkirchen'    => 'Waldkirchen',
        'boernichen'     => 'Börnichen',
        'verband-weit'   => 'Verband-weit',
    ];

    public const CATEGORY_DEFAULTS = [
        'kultur'   => 'Kultur',
        'sport'    => 'Sport',
        'kirche'   => 'Kirche',
        'verein'   => 'Verein',
        'markt'    => 'Markt',
        'bildung'  => 'Bildung',
        'sonstige' => 'Sonstige',
    ];

    public static function to_iso8601( ?string $local, bool $all_day = false ): ?string {
        if ( ! $local ) { return null; }
        try {
            $tz = wp_timezone();
            $dt = new DateTimeImmutable( $local, $tz );
            if ( $all_day ) {
                return $dt->format( 'Y-m-d' );
            }
            return $dt->format( 'c' );
        } catch ( Exception $e ) {
            return null;
        }
    }

    public static function format_event( WP_Post $post ): array {
        $all_day = (bool) get_post_meta( $post->ID, '_vw_event_all_day', true );
        $start   = (string) get_post_meta( $post->ID, '_vw_event_start', true );
        $end     = (string) get_post_meta( $post->ID, '_vw_event_end', true );

        $thumb_id = get_post_thumbnail_id( $post );
        $image    = null;
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_src( $thumb_id, 'large' );
            if ( $src ) {
                $image = [
                    'url' => $src[0],
                    'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
                ];
            }
        }

        $standort_terms = wp_get_post_terms( $post->ID, 'vw_standort', [ 'fields' => 'slugs' ] );
        $cat_terms      = wp_get_post_terms( $post->ID, 'vw_event_category', [ 'fields' => 'slugs' ] );

        return [
            'id'              => $post->ID,
            'slug'            => $post->post_name,
            'title'           => get_the_title( $post ),
            'description_html'=> wp_kses_post( apply_filters( 'the_content', $post->post_content ) ),
            'start'           => self::to_iso8601( $start, $all_day ),
            'end'             => $end ? self::to_iso8601( $end, $all_day ) : null,
            'all_day'         => $all_day,
            'repeat'          => (string) ( get_post_meta( $post->ID, '_vw_event_repeat', true ) ?: 'none' ),
            'repeat_until'    => ( get_post_meta( $post->ID, '_vw_event_repeat_until', true ) ?: null ),
            'location'        => [
                'name'    => (string) get_post_meta( $post->ID, '_vw_event_location_name', true ),
                'address' => (string) get_post_meta( $post->ID, '_vw_event_location_addr', true ),
            ],
            'organizer'       => [
                'name' => (string) get_post_meta( $post->ID, '_vw_event_organizer_name', true ),
            ],
            'url'             => (string) get_post_meta( $post->ID, '_vw_event_url', true ),
            'image'           => $image,
            'standort'        => is_array( $standort_terms ) ? $standort_terms : [],
            'category'        => is_array( $cat_terms ) ? $cat_terms : [],
            'permalink'       => get_permalink( $post ),
        ];
    }

    public static function client_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
        }
        return (string) $ip;
    }

    public static function hash_ip( string $ip ): string {
        $salt = wp_salt( 'auth' );
        return hash( 'sha256', $salt . '|' . $ip );
    }
}

/**
 * Format an event date range for display.
 * Output: "30. April 2026<sep>18:00 – 20:00" (single day)
 *         "30. April 2026" (all-day, single day)
 *         "30. April 2026 18:00<sep>– 1. Mai 2026 02:00" (multi-day)
 */
function vw_events_format_date_range( string $start, string $end = '', bool $all_day = false, string $sep = "\n" ): string {
    if ( $start === '' ) { return '—'; }
    $ts_start = strtotime( $start );
    $ts_end   = $end !== '' ? strtotime( $end ) : 0;
    if ( ! $ts_start ) { return esc_html( $start ); }

    $date_fmt = 'j. F Y';
    $time_fmt = 'H:i';

    $start_date = date_i18n( $date_fmt, $ts_start );
    $start_time = date_i18n( $time_fmt, $ts_start );

    if ( $all_day ) {
        if ( $ts_end && date( 'Y-m-d', $ts_end ) !== date( 'Y-m-d', $ts_start ) ) {
            return $start_date . ' – ' . date_i18n( $date_fmt, $ts_end );
        }
        return $start_date;
    }

    if ( ! $ts_end ) {
        return $start_date . $sep . $start_time;
    }

    if ( date( 'Y-m-d', $ts_end ) === date( 'Y-m-d', $ts_start ) ) {
        return $start_date . $sep . $start_time . ' – ' . date_i18n( $time_fmt, $ts_end );
    }

    return $start_date . ' ' . $start_time . $sep . '– ' . date_i18n( $date_fmt, $ts_end ) . ' ' . date_i18n( $time_fmt, $ts_end );
}
