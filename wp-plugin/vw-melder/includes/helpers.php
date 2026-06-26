<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared utilities for the vw-melder plugin.
 */
final class VW_Melder_Helpers {

    /** Anliegen (Art des Mangels). slug => Anzeigename. */
    public const ANLIEGEN_DEFAULTS = [
        'strassen-gehwege-plaetze' => 'Straßen, Gehwege und Plätze',
        'strassenbeleuchtung'      => 'Straßenbeleuchtung',
        'muell-verschmutzung'      => 'Müllablagerung und Verschmutzung',
        'gruenflaechen-baeume'     => 'Grünflächen und Bäume',
        'wander-radwege'           => 'Wander- und Radwege',
    ];

    /** Bearbeitungs-Status. slug => Anzeigename. */
    public const STATUS_DEFAULTS = [
        'neu'             => 'Neue Meldung',
        'in-bearbeitung'  => 'In Bearbeitung',
        'erledigt'        => 'Erledigt',
    ];

    /** Migrations-Mapping: alter Slug (melder.vv-wildenstein.com) => neuer Slug. */
    public const ANLIEGEN_SLUG_MAP = [
        'strassen-u-gehwege-oeffentl-plaetze' => 'strassen-gehwege-plaetze',
        'strassenbeleuchtung'                 => 'strassenbeleuchtung',
        'muellablagerungen-verschmutzung'     => 'muell-verschmutzung',
        'gruenflaechen-baeume'                => 'gruenflaechen-baeume',
        'wander-u-radwege'                    => 'wander-radwege',
    ];

    public const STATUS_SLUG_MAP = [
        'neue-meldung'   => 'neu',
        'in-bearbeitung' => 'in-bearbeitung',
        'erledigt'       => 'erledigt',
    ];

    /** Öffentliche Meta-Felder (gehen an die REST-Ausgabe). */
    public const META_KEYS_PUBLIC = [
        '_vw_meldung_lat',
        '_vw_meldung_lng',
        '_vw_meldung_address',
        '_vw_meldung_city',
        '_vw_meldung_postcode',
    ];

    /** Interne Meta-Felder (NIE öffentlich ausliefern). */
    public const META_KEYS_PRIVATE = [
        '_vw_meldung_reporter_name',
        '_vw_meldung_reporter_email',
        '_vw_meldung_notify',
        '_vw_meldung_internal_note',
        '_vw_meldung_submission_ip',
        '_vw_meldung_source',
    ];

    /**
     * Öffentliche REST-Repräsentation einer Meldung.
     * Enthält bewusst KEINE Melder-Daten (Name/E-Mail/IP) und keinen internen Hinweis.
     */
    public static function format_meldung( WP_Post $post ): array {
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

        $lat = (string) get_post_meta( $post->ID, '_vw_meldung_lat', true );
        $lng = (string) get_post_meta( $post->ID, '_vw_meldung_lng', true );

        $anliegen = wp_get_post_terms( $post->ID, 'vw_anliegen', [ 'fields' => 'slugs' ] );
        $status   = wp_get_post_terms( $post->ID, 'vw_meldung_status' );
        $status_term = ( is_array( $status ) && $status ) ? $status[0] : null;

        return [
            'id'               => $post->ID,
            'slug'             => $post->post_name,
            'title'            => get_the_title( $post ),
            'description_html' => wp_kses_post( apply_filters( 'the_content', $post->post_content ) ),
            'created'          => get_post_time( 'c', true, $post ),
            'anliegen'         => is_array( $anliegen ) ? $anliegen : [],
            'status'           => $status_term ? [
                'slug'  => $status_term->slug,
                'label' => $status_term->name,
            ] : null,
            'location'         => [
                'lat'      => $lat !== '' ? (float) $lat : null,
                'lng'      => $lng !== '' ? (float) $lng : null,
                'address'  => (string) get_post_meta( $post->ID, '_vw_meldung_address', true ),
                'city'     => (string) get_post_meta( $post->ID, '_vw_meldung_city', true ),
                'postcode' => (string) get_post_meta( $post->ID, '_vw_meldung_postcode', true ),
            ],
            'image'            => $image,
            'public_notes'     => self::public_notes( $post->ID ),
            'permalink'        => get_permalink( $post ),
        ];
    }

    /** Öffentliche Antworten der Verwaltung (Datum + Text), neueste zuerst. */
    public static function public_notes( int $post_id ): array {
        $raw = get_post_meta( $post_id, '_vw_meldung_public_notes', true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( array_reverse( array_values( $raw ) ) as $note ) {
            $text = (string) ( $note['text'] ?? '' );
            if ( $text === '' ) {
                continue;
            }
            $out[] = [
                'date' => isset( $note['time'] ) ? (string) $note['time'] : '',
                'text' => $text,
            ];
        }
        return $out;
    }

    /** Ein GeoJSON-Feature für die Karte. */
    public static function format_geojson_feature( WP_Post $post ): ?array {
        $lat = (string) get_post_meta( $post->ID, '_vw_meldung_lat', true );
        $lng = (string) get_post_meta( $post->ID, '_vw_meldung_lng', true );
        if ( $lat === '' || $lng === '' ) {
            return null;
        }

        $status      = wp_get_post_terms( $post->ID, 'vw_meldung_status' );
        $status_term = ( is_array( $status ) && $status ) ? $status[0] : null;
        $anliegen    = wp_get_post_terms( $post->ID, 'vw_anliegen', [ 'fields' => 'slugs' ] );

        return [
            'type'       => 'Feature',
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [ (float) $lng, (float) $lat ], // GeoJSON: [lng, lat]
            ],
            'properties' => [
                'id'        => $post->ID,
                'title'     => get_the_title( $post ),
                'status'    => $status_term ? $status_term->slug : 'neu',
                'anliegen'  => is_array( $anliegen ) ? $anliegen : [],
                'permalink' => get_permalink( $post ),
            ],
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
