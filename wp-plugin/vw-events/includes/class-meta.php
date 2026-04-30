<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_Meta {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_meta' ] );
    }

    public static function register_meta(): void {
        $strings = [
            '_vw_event_start',
            '_vw_event_end',
            '_vw_event_repeat',
            '_vw_event_repeat_until',
            '_vw_event_location_name',
            '_vw_event_location_addr',
            '_vw_event_organizer_name',
            '_vw_event_organizer_email',
            '_vw_event_url',
            '_vw_event_submitter_name',
            '_vw_event_submitter_email',
            '_vw_event_submission_ip',
            '_vw_event_source',
        ];
        foreach ( $strings as $key ) {
            register_post_meta( 'vw_event', $key, [
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => false,
                'sanitize_callback' => self::sanitizer_for( $key ),
                'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
            ] );
        }
        register_post_meta( 'vw_event', '_vw_event_all_day', [
            'type'              => 'boolean',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => static fn( $v ) => (bool) $v,
            'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
        ] );
    }

    private static function sanitizer_for( string $key ): callable {
        return match ( $key ) {
            '_vw_event_url'              => 'esc_url_raw',
            '_vw_event_organizer_email',
            '_vw_event_submitter_email'  => 'sanitize_email',
            '_vw_event_location_addr'    => 'sanitize_textarea_field',
            '_vw_event_repeat'           => static function ( $v ) {
                $v = sanitize_key( (string) $v );
                return in_array( $v, [ 'none', 'daily', 'weekly', 'monthly' ], true ) ? $v : 'none';
            },
            '_vw_event_source'           => static function ( $v ) {
                $v = sanitize_key( (string) $v );
                return in_array( $v, [ 'admin', 'frontend_form' ], true ) ? $v : 'admin';
            },
            default                      => 'sanitize_text_field',
        };
    }
}
