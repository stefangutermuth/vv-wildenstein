<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Öffentlicher Submission-Endpoint für das Astro-Frontend.
 * Legt Bürger-Meldungen als `pending` (Moderation) an — sie erscheinen
 * erst nach Freigabe im Admin auf der Karte.
 */
final class VW_Melder_REST_Submit {

    public const NS = 'vw-melder/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/submit', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [ __CLASS__, 'handle' ],
        ] );
    }

    public static function handle( WP_REST_Request $req ): WP_REST_Response {
        // Honeypot: echte Nutzer lassen das Feld leer.
        if ( (string) $req->get_param( 'website' ) !== '' ) {
            // Bot: vorgeben, dass alles ok ist, aber nichts anlegen.
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }

        $title    = sanitize_text_field( (string) $req->get_param( 'title' ) );
        $anliegen = sanitize_key( (string) $req->get_param( 'anliegen' ) );
        $desc     = wp_kses_post( (string) $req->get_param( 'description' ) );
        $r_name   = sanitize_text_field( (string) $req->get_param( 'reporter_name' ) );
        $r_email  = sanitize_email( (string) $req->get_param( 'reporter_email' ) );
        $notify   = (string) $req->get_param( 'notify' ) !== '' ? 1 : 0;
        $address  = sanitize_text_field( (string) $req->get_param( 'address' ) );
        $lat      = (string) $req->get_param( 'lat' );
        $lng      = (string) $req->get_param( 'lng' );

        // Pflichtfelder
        if ( $title === '' ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => 'Titel fehlt.' ], 400 );
        }
        if ( ! array_key_exists( $anliegen, VW_Melder_Helpers::ANLIEGEN_DEFAULTS ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => 'Unbekannte Kategorie.' ], 400 );
        }
        if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => 'Standort fehlt.' ], 400 );
        }

        $post_id = wp_insert_post( [
            'post_type'    => 'vw_meldung',
            'post_status'  => 'pending', // Moderation
            'post_title'   => $title,
            'post_content' => $desc,
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'message' => 'Speichern fehlgeschlagen.' ], 500 );
        }

        update_post_meta( $post_id, '_vw_meldung_lat', (string) (float) str_replace( ',', '.', $lat ) );
        update_post_meta( $post_id, '_vw_meldung_lng', (string) (float) str_replace( ',', '.', $lng ) );
        update_post_meta( $post_id, '_vw_meldung_address', $address );
        update_post_meta( $post_id, '_vw_meldung_reporter_name', $r_name );
        update_post_meta( $post_id, '_vw_meldung_reporter_email', $r_email );
        update_post_meta( $post_id, '_vw_meldung_notify', $notify );
        update_post_meta( $post_id, '_vw_meldung_source', 'frontend_form' );
        update_post_meta( $post_id, '_vw_meldung_submission_ip', VW_Melder_Helpers::hash_ip( VW_Melder_Helpers::client_ip() ) );

        wp_set_post_terms( $post_id, [ $anliegen ], 'vw_anliegen', false );
        wp_set_post_terms( $post_id, [ 'neu' ], 'vw_meldung_status', false );

        // Foto
        if ( ! empty( $_FILES['photo'] ) && ! empty( $_FILES['photo']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $att_id = media_handle_upload( 'photo', $post_id );
            if ( ! is_wp_error( $att_id ) ) {
                set_post_thumbnail( $post_id, (int) $att_id );
            }
        }

        self::notify_admin( $post_id, $title, $r_name, $r_email );

        return new WP_REST_Response( [ 'ok' => true, 'id' => $post_id ], 201 );
    }

    private static function notify_admin( int $post_id, string $title, string $name, string $email ): void {
        $to      = VW_Melder_Settings::notify_recipients();
        $edit    = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
        $subject = '[Mängelmelder] Neue Meldung zur Freigabe: ' . $title;
        $body    = "Eine neue Meldung wurde eingereicht und wartet auf Freigabe.\n\n"
            . "Titel: {$title}\n"
            . 'Melder: ' . ( $name !== '' ? $name : '—' ) . "\n"
            . 'E-Mail: ' . ( $email !== '' ? $email : '—' ) . "\n\n"
            . "Prüfen und freigeben:\n{$edit}\n";
        wp_mail( $to, $subject, $body );
    }
}
