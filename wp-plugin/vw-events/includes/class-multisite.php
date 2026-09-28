<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Multisite-Bridge: Subsites lesen Events vom konfigurierten Master-Blog.
 * Aktiviert sich nur, wenn `master_blog_id` gesetzt UND ungleich der aktuellen Blog-ID ist.
 */
final class VW_Events_Multisite {

    public static function master_blog_id(): int {
        if ( ! is_multisite() ) { return 0; }
        $s  = VW_Events_Admin_UI::get_settings();
        $id = (int) ( $s['master_blog_id'] ?? 0 );
        return $id > 0 ? $id : 0;
    }

    public static function is_subsite(): bool {
        $master = self::master_blog_id();
        return $master > 0 && $master !== get_current_blog_id();
    }

    /**
     * Run a callable in the master-blog context. Restores after.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function with_master( callable $fn ) {
        if ( ! self::is_subsite() ) {
            return $fn();
        }
        switch_to_blog( self::master_blog_id() );
        try {
            return $fn();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * URL einer externen Master-WP (für getrennte Installationen ohne Multisite).
     * Leer = aktuelle Site IST der Master / nicht konfiguriert.
     */
    public static function master_url(): string {
        $s = VW_Events_Admin_UI::get_settings();
        return (string) ( $s['master_url'] ?? '' );
    }

    public static function is_remote(): bool {
        $url  = self::master_url();
        if ( $url === '' ) { return false; }
        // Wenn die URL auf diese Site selbst zeigt, kein Remote-Fetch nötig.
        $self = home_url();
        return rtrim( $url, '/' ) !== rtrim( $self, '/' );
    }

    /**
     * Holt Events live per REST von der Master-WP.
     * Gibt eine Liste von Event-Arrays (Schema wie VW_Events_Helpers::format_event) zurück.
     */
    public static function fetch_remote_events( array $params = [] ): array {
        $base = self::master_url();
        if ( $base === '' ) { return []; }
        $url = $base . '/wp-json/vw-events/v1/events';
        $url = add_query_arg( array_filter( $params, static fn( $v ) => $v !== '' && $v !== null ), $url );
        $resp = wp_remote_get( $url, [
            'timeout'   => 5,
            'sslverify' => true,
            'headers'   => [ 'Accept' => 'application/json' ],
        ] );
        if ( is_wp_error( $resp ) ) { return []; }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) { return []; }
        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );
        return is_array( $data ) ? $data : [];
    }
}
