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
}
