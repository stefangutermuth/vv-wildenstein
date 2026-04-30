<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_Mailer {

    public static function init(): void {
        add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 10, 3 );
    }

    private static function send( $to, string $subject, string $template, array $vars ): void {
        if ( is_string( $to ) ) {
            $to = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $to ) ?: [] ) );
        }
        $to = array_values( array_filter( (array) $to, 'is_email' ) );
        if ( empty( $to ) ) { return; }

        $vars['site_name'] = get_bloginfo( 'name' );
        $vars['site_url']  = home_url();

        $file = VW_EVENTS_DIR . 'templates/email/' . $template . '.php';
        if ( ! file_exists( $file ) ) { return; }
        ob_start();
        extract( $vars, EXTR_SKIP );
        include $file;
        $body = ob_get_clean();

        wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    private static function event_summary( int $post_id ): array {
        $start   = (string) get_post_meta( $post_id, '_vw_event_start', true );
        $end     = (string) get_post_meta( $post_id, '_vw_event_end', true );
        $all_day = (bool)   get_post_meta( $post_id, '_vw_event_all_day', true );
        return [
            'title'         => get_the_title( $post_id ),
            'start'         => $start,
            'end'           => $end,
            'when'          => vw_events_format_date_range( $start, $end, $all_day, ', ' ),
            'location_name' => (string) get_post_meta( $post_id, '_vw_event_location_name', true ),
            'location_addr' => (string) get_post_meta( $post_id, '_vw_event_location_addr', true ),
            'organizer'     => (string) get_post_meta( $post_id, '_vw_event_organizer_name', true ),
            'edit_link'     => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'permalink'     => get_permalink( $post_id ),
        ];
    }

    public static function notify_admin_new_submission( int $post_id ): void {
        $settings = VW_Events_Admin_UI::get_settings();
        $to       = $settings['admin_email'];
        $vars     = self::event_summary( $post_id );
        self::send( $to, sprintf( __( 'Neues Event eingereicht: %s', 'vw-events' ), $vars['title'] ), 'admin-new-submission', $vars );
    }

    public static function notify_submitter_thanks( int $post_id ): void {
        $email = (string) get_post_meta( $post_id, '_vw_event_submitter_email', true );
        $vars  = self::event_summary( $post_id );
        self::send( $email, __( 'Vielen Dank — dein Event wird geprüft', 'vw-events' ), 'submitter-thanks', $vars );
    }

    public static function notify_submitter_published( int $post_id ): void {
        $email = (string) get_post_meta( $post_id, '_vw_event_submitter_email', true );
        if ( ! $email ) { return; }
        $vars = self::event_summary( $post_id );
        self::send( $email, sprintf( __( 'Dein Event ist online: %s', 'vw-events' ), $vars['title'] ), 'submitter-published', $vars );
    }

    public static function notify_submitter_rejected( int $post_id, string $reason = '' ): void {
        $email = (string) get_post_meta( $post_id, '_vw_event_submitter_email', true );
        if ( ! $email ) { return; }
        $vars = self::event_summary( $post_id );
        $vars['reason'] = $reason;
        self::send( $email, __( 'Hinweis zu deinem eingereichten Event', 'vw-events' ), 'submitter-rejected', $vars );
    }

    public static function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
        if ( $post->post_type !== 'vw_event' ) { return; }
        if ( $new_status === $old_status ) { return; }

        if ( $new_status === 'publish' && $old_status !== 'publish' ) {
            // only mail submitter if it was a frontend submission
            if ( get_post_meta( $post->ID, '_vw_event_source', true ) === 'frontend_form' ) {
                self::notify_submitter_published( $post->ID );
            }
        }
        if ( $new_status === 'trash' && $old_status === 'pending' ) {
            self::notify_submitter_rejected( $post->ID );
        }
    }
}
