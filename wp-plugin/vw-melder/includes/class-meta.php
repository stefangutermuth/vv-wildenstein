<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Melder_Meta {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_meta' ] );
    }

    public static function register_meta(): void {
        $strings = [
            '_vw_meldung_lat',
            '_vw_meldung_lng',
            '_vw_meldung_address',
            '_vw_meldung_city',
            '_vw_meldung_postcode',
            '_vw_meldung_reporter_name',
            '_vw_meldung_reporter_email',
            '_vw_meldung_internal_note',
            '_vw_meldung_submission_ip',
            '_vw_meldung_source',
        ];
        foreach ( $strings as $key ) {
            register_post_meta( 'vw_meldung', $key, [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => false,
                'sanitize_callback' => self::sanitizer_for( $key ),
                'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
            ] );
        }

        register_post_meta( 'vw_meldung', '_vw_meldung_notify', [
            'type'              => 'boolean',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => static fn( $v ) => (bool) $v,
            'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
        ] );
    }

    private static function sanitizer_for( string $key ): callable {
        return match ( $key ) {
            '_vw_meldung_lat',
            '_vw_meldung_lng'           => static function ( $v ) {
                $v = str_replace( ',', '.', trim( (string) $v ) );
                return is_numeric( $v ) ? (string) (float) $v : '';
            },
            '_vw_meldung_reporter_email' => 'sanitize_email',
            '_vw_meldung_address',
            '_vw_meldung_internal_note'  => 'sanitize_textarea_field',
            '_vw_meldung_source'         => static function ( $v ) {
                $v = sanitize_key( (string) $v );
                return in_array( $v, [ 'admin', 'frontend_form', 'import' ], true ) ? $v : 'admin';
            },
            default                      => 'sanitize_text_field',
        };
    }
}
