<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Stößt den GitHub-Actions-Deploy des Astro-Frontends an, sobald sich im
 * Backend etwas Sichtbares ändert (Meldung veröffentlicht/geändert/depubliziert/
 * gelöscht, Status/Anliegen umgesetzt, Taxonomie-Begriffe bearbeitet).
 *
 * Ersetzt das häufige Zeitplan-Polling: Die Seite aktualisiert sich nahezu
 * sofort, und es laufen kaum noch geplante Builds — das GitHub-Infrastruktur-
 * Problem „kein Runner verfügbar" (die „cancelled"/„not acquired"-Mails) tritt
 * dadurch nur noch äußerst selten auf.
 *
 * Technik: POST an die GitHub-REST-Schnittstelle „repository_dispatch" mit einem
 * fein-granularen Personal Access Token (Berechtigung „Contents: Read and write")
 * für stefangutermuth/vv-wildenstein. Der Deploy-Workflow lauscht auf das
 * Ereignis „rebuild-melder".
 */
final class VW_Melder_Deploy_Hook {

    public const OWNER       = 'stefangutermuth';
    public const REPO        = 'vv-wildenstein';
    public const EVENT_TYPE  = 'rebuild-melder';
    public const ACTION_TEST = 'vw_melder_test_deploy';
    public const LAST_OPTION = 'vw_melder_last_dispatch';

    /** Höchstens einmal pro Request auslösen. */
    private static bool $fired = false;

    public static function init(): void {
        // Meldung veröffentlicht / geändert / depubliziert
        add_action( 'transition_post_status', [ __CLASS__, 'on_transition' ], 10, 3 );
        // Meldung endgültig gelöscht
        add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ], 10, 1 );
        // Status/Anliegen an einer Meldung umgesetzt (auch per Schnellauswahl)
        add_action( 'set_object_terms', [ __CLASS__, 'on_set_terms' ], 10, 6 );
        // Anliegen-/Status-Begriffe selbst bearbeitet (Name, Farbe, Icon …)
        foreach ( [ 'vw_anliegen', 'vw_meldung_status' ] as $tax ) {
            add_action( "created_{$tax}", [ __CLASS__, 'trigger' ] );
            add_action( "edited_{$tax}", [ __CLASS__, 'trigger' ] );
            add_action( "delete_{$tax}", [ __CLASS__, 'trigger' ] );
        }
        // „Verbindung testen"-Knopf auf der Einstellungen-Seite
        add_action( 'admin_post_' . self::ACTION_TEST, [ __CLASS__, 'handle_test' ] );
        add_action( 'admin_notices', [ __CLASS__, 'test_notice' ] );
    }

    public static function enabled(): bool {
        $s = VW_Melder_Settings::get();
        return ! empty( $s['deploy_enabled'] ) && self::token() !== '';
    }

    public static function token(): string {
        return trim( (string) ( VW_Melder_Settings::get()['github_token'] ?? '' ) );
    }

    /** Zeitpunkt des letzten automatischen Auslösens (ISO-8601, UTC) oder ''. */
    public static function last_dispatch(): string {
        return (string) get_option( self::LAST_OPTION, '' );
    }

    // --- Auslöser ---------------------------------------------------------

    public static function on_transition( string $new, string $old, WP_Post $post ): void {
        if ( $post->post_type !== 'vw_meldung' ) {
            return;
        }
        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        // Nur relevant, wenn die Meldung öffentlich ist oder gerade war
        // (Neu-Einreichungen sind „pending" und lösen so KEINEN Build aus).
        if ( $new !== 'publish' && $old !== 'publish' ) {
            return;
        }
        self::trigger();
    }

    public static function on_delete( int $post_id ): void {
        if ( get_post_type( $post_id ) !== 'vw_meldung' ) {
            return;
        }
        if ( get_post_status( $post_id ) === 'publish' ) {
            self::trigger();
        }
    }

    /**
     * @param int          $object_id
     * @param array        $terms
     * @param array        $tt_ids
     * @param string       $taxonomy
     * @param bool         $append
     * @param array        $old_tt_ids
     */
    public static function on_set_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
        if ( ! in_array( $taxonomy, [ 'vw_anliegen', 'vw_meldung_status' ], true ) ) {
            return;
        }
        if ( get_post_type( (int) $object_id ) !== 'vw_meldung' ) {
            return;
        }
        // Nur wenn sich die Zuordnung wirklich geändert hat
        $before = array_map( 'intval', (array) $old_tt_ids );
        $after  = array_map( 'intval', (array) $tt_ids );
        sort( $before );
        sort( $after );
        if ( $before === $after ) {
            return;
        }
        self::trigger();
    }

    /** Feuert höchstens einmal pro Request (fire-and-forget, ohne den Admin zu bremsen). */
    public static function trigger(): void {
        if ( self::$fired || ! self::enabled() ) {
            return;
        }
        self::$fired = true;
        self::send( false );
        update_option( self::LAST_OPTION, gmdate( 'c' ), false );
    }

    // --- Versand ----------------------------------------------------------

    /**
     * @return array{ok:bool,code:int,message:string}
     */
    public static function send( bool $blocking ): array {
        $token = self::token();
        if ( $token === '' ) {
            return [ 'ok' => false, 'code' => 0, 'message' => __( 'Kein Token hinterlegt.', 'vw-melder' ) ];
        }

        $url  = sprintf( 'https://api.github.com/repos/%s/%s/dispatches', self::OWNER, self::REPO );
        $resp = wp_remote_post( $url, [
            'method'   => 'POST',
            'blocking' => $blocking,
            'timeout'  => $blocking ? 15 : 5,
            'headers'  => [
                'Accept'               => 'application/vnd.github+json',
                'Authorization'        => 'Bearer ' . $token,
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'vw-melder/' . VW_MELDER_VERSION,
                'Content-Type'         => 'application/json',
            ],
            'body'     => wp_json_encode( [ 'event_type' => self::EVENT_TYPE ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'code' => 0, 'message' => $resp->get_error_message() ];
        }

        // Nicht-blockierend: keine Antwort abgewartet → als „gesendet" werten.
        if ( ! $blocking ) {
            return [ 'ok' => true, 'code' => 0, 'message' => __( 'gesendet', 'vw-melder' ) ];
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code === 204 ) {
            return [ 'ok' => true, 'code' => 204, 'message' => __( 'OK', 'vw-melder' ) ];
        }
        return [ 'ok' => false, 'code' => $code, 'message' => self::explain( $code, wp_remote_retrieve_body( $resp ) ) ];
    }

    private static function explain( int $code, string $body ): string {
        $data = json_decode( $body, true );
        $msg  = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : '';
        switch ( $code ) {
            case 401:
                return __( 'Token ungültig oder abgelaufen (401).', 'vw-melder' );
            case 403:
                return __( 'Zugriff verweigert (403) — dem Token fehlt die Berechtigung „Contents: Read and write".', 'vw-melder' );
            case 404:
                return __( 'Repository nicht gefunden (404) — der Token hat keinen Zugriff auf stefangutermuth/vv-wildenstein.', 'vw-melder' );
            default:
                return $msg !== ''
                    ? sprintf( __( 'Fehler %1$d: %2$s', 'vw-melder' ), $code, $msg )
                    : sprintf( __( 'Unerwartete Antwort (%d).', 'vw-melder' ), $code );
        }
    }

    // --- „Verbindung testen" ---------------------------------------------

    public static function handle_test(): void {
        if ( ! current_user_can( 'manage_options' )
            || ! isset( $_POST['_wpnonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::ACTION_TEST )
        ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'vw-melder' ) );
        }
        $result = self::send( true ); // blockierend → echte Antwort von GitHub
        set_transient( 'vw_melder_deploy_test_' . get_current_user_id(), $result, 45 );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=vw_meldung&page=vw-melder-settings' ) );
        exit;
    }

    public static function test_notice(): void {
        $uid = get_current_user_id();
        $r   = get_transient( 'vw_melder_deploy_test_' . $uid );
        if ( ! $r || ! is_array( $r ) ) {
            return;
        }
        delete_transient( 'vw_melder_deploy_test_' . $uid );
        if ( ! empty( $r['ok'] ) ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( '✓ Verbindung zu GitHub erfolgreich — ein Deploy wurde ausgelöst. Die Seite ist in 1–2 Minuten aktuell.', 'vw-melder' )
            );
        } else {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( __( 'GitHub-Verbindung fehlgeschlagen: %s', 'vw-melder' ), (string) ( $r['message'] ?? '' ) ) )
            );
        }
    }
}
