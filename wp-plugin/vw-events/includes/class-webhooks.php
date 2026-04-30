<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_Webhooks {

    public const QUEUE_OPTION = 'vw_events_webhook_queue';
    public const CRON_HOOK    = 'vw_events_dispatch_webhooks';
    public const THROTTLE     = 60; // seconds

    public static function init(): void {
        add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 20, 3 );
        add_action( self::CRON_HOOK, [ __CLASS__, 'dispatch' ] );
    }

    public static function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
        if ( $post->post_type !== 'vw_event' ) { return; }
        if ( $new_status !== 'publish' ) { return; }

        $standorte = wp_get_post_terms( $post->ID, 'vw_standort', [ 'fields' => 'slugs' ] );
        if ( ! is_array( $standorte ) || empty( $standorte ) ) { return; }

        $settings = VW_Events_Admin_UI::get_settings();
        $map      = (array) ( $settings['webhook_map'] ?? [] );
        $urls     = [];

        if ( in_array( 'verband-weit', $standorte, true ) ) {
            $urls = array_values( array_filter( $map ) );
        } else {
            foreach ( $standorte as $slug ) {
                if ( ! empty( $map[ $slug ] ) ) {
                    $urls[] = $map[ $slug ];
                }
            }
        }

        if ( empty( $urls ) ) { return; }

        $queue = (array) get_option( self::QUEUE_OPTION, [] );
        foreach ( $urls as $url ) {
            $queue[ md5( $url ) ] = $url;
        }
        update_option( self::QUEUE_OPTION, $queue, false );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + self::THROTTLE, self::CRON_HOOK );
        }
    }

    public static function dispatch(): void {
        $queue = (array) get_option( self::QUEUE_OPTION, [] );
        if ( empty( $queue ) ) { return; }
        delete_option( self::QUEUE_OPTION );

        foreach ( $queue as $url ) {
            wp_remote_post( $url, [
                'timeout'  => 5,
                'blocking' => false,
                'body'     => wp_json_encode( [ 'source' => 'vw-events', 'time' => time() ] ),
                'headers'  => [ 'Content-Type' => 'application/json' ],
            ] );
        }
    }
}
