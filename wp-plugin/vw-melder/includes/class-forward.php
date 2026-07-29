<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Meldung an die zuständige Fachkraft weiterleiten.
 *
 * Der/die Verwaltungs-Mitarbeiter:in öffnet eine Meldung, sieht die automatisch
 * anhand der Kategorie (vw_anliegen) bestimmte zuständige Person, kann eine kurze
 * Notiz ergänzen und per „Senden“ verschicken. Die E-Mail enthält denselben
 * Report wie die Druck-/PDF-Ansicht (VW_Melder_Export::render_document). Jeder
 * Versand wird an der Meldung protokolliert (Zeitpunkt, Empfänger, Notiz).
 *
 * Empfänger-Adresse = Term-Meta an der Kategorie (im Kategorie-Formular pflegbar).
 *
 * Test-Modus (Einstellungen): solange aktiv, gehen ALLE Weiterleitungen an eine
 * Test-Adresse statt an die echten Zuständigen — zum gefahrlosen Ausprobieren.
 */
final class VW_Melder_Forward {

    public const ACTION       = 'vw_melder_forward';   // AJAX-Action
    public const NONCE        = 'vw_melder_forward';
    public const HISTORY_META = '_vw_meldung_forwards';
    public const TERM_EMAIL   = '_vw_anliegen_email';
    public const TERM_PERSON  = '_vw_anliegen_person';
    public const CAP          = 'edit_others_posts';

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
        add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle_ajax' ] );

        // E-Mail des Zuständigen als Feld im Kategorie-Formular (Anliegen)
        add_action( 'vw_anliegen_add_form_fields', [ __CLASS__, 'term_add_fields' ] );
        add_action( 'vw_anliegen_edit_form_fields', [ __CLASS__, 'term_edit_fields' ], 10, 1 );
        add_action( 'created_vw_anliegen', [ __CLASS__, 'save_term_fields' ] );
        add_action( 'edited_vw_anliegen', [ __CLASS__, 'save_term_fields' ] );
        add_filter( 'manage_edit-vw_anliegen_columns', [ __CLASS__, 'term_column' ] );
        add_filter( 'manage_vw_anliegen_custom_column', [ __CLASS__, 'term_column_content' ], 10, 3 );
    }

    /* ================= Empfänger aus Kategorie ================= */

    /** @return array{email:string,person:string,category:string} */
    public static function recipient_for( int $post_id ): array {
        $terms = wp_get_post_terms( $post_id, 'vw_anliegen' );
        if ( is_wp_error( $terms ) || $terms === [] ) {
            return [ 'email' => '', 'person' => '', 'category' => '' ];
        }
        foreach ( $terms as $t ) {
            $email = (string) get_term_meta( $t->term_id, self::TERM_EMAIL, true );
            if ( $email !== '' ) {
                return [
                    'email'    => $email,
                    'person'   => (string) get_term_meta( $t->term_id, self::TERM_PERSON, true ),
                    'category' => $t->name,
                ];
            }
        }
        return [ 'email' => '', 'person' => '', 'category' => $terms[0]->name ];
    }

    /* ================= Metabox an der Meldung ================= */

    public static function add_box(): void {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }
        add_meta_box(
            'vw_meldung_forward',
            __( 'An Zuständige weiterleiten', 'vw-melder' ),
            [ __CLASS__, 'render_box' ],
            'vw_meldung',
            'side',
            'high'
        );
    }

    public static function render_box( WP_Post $post ): void {
        $rcpt      = self::recipient_for( $post->ID );
        $test_mode = VW_Melder_Settings::forward_test_mode();
        $test_to   = VW_Melder_Settings::forward_test_email();
        ?>
        <style>
            .vwfwd-test { background:#fcf3d9; border:1px solid #dba617; color:#8a6d00; border-radius:5px; padding:7px 10px; margin:0 0 10px; font-size:12px; font-weight:600; }
            .vwfwd-to { margin:0 0 10px; }
            .vwfwd-to code { background:#f0f0f1; padding:1px 5px; border-radius:3px; }
            .vwfwd-warn { color:#b32d2e; }
            .vwfwd-msg { padding:7px 10px; border-radius:5px; font-size:12.5px; margin:8px 0 0; }
            .vwfwd-msg.ok { background:#edfaef; border:1px solid #0a5f2b; color:#0a5f2b; }
            .vwfwd-msg.err { background:#fcf0f1; border:1px solid #d63638; color:#b32d2e; }
            .vwfwd-loglist { margin:8px 0 0; padding:0; list-style:none; }
            .vwfwd-loglist li { border-left:3px solid #0a5f2b; background:#f6f7f7; padding:6px 10px; margin:0 0 7px; border-radius:0 4px 4px 0; }
            .vwfwd-sent { color:#0a5f2b; font-weight:600; font-size:12px; }
            .vwfwd-sent .dashicons { font-size:15px; width:15px; height:15px; vertical-align:text-bottom; }
            .vwfwd-note { font-size:12.5px; margin:3px 0 0; }
            .vwfwd-by { color:#646970; font-size:11.5px; margin-top:2px; }
        </style>

        <?php if ( $test_mode ) : ?>
            <div class="vwfwd-test">⚠ <?php printf(
                /* translators: %s: Test-E-Mail */
                esc_html__( 'TEST-MODUS: geht an %s — nicht an die echten Zuständigen.', 'vw-melder' ),
                '<code>' . esc_html( $test_to ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput
            ); ?></div>
        <?php endif; ?>

        <?php if ( $rcpt['email'] === '' ) : ?>
            <p class="vwfwd-warn">
                <?php esc_html_e( 'Für die Kategorie dieser Meldung ist keine Zuständigen-E-Mail hinterlegt.', 'vw-melder' ); ?>
                <?php if ( $rcpt['category'] !== '' ) : ?>
                    <br><span class="description"><?php echo esc_html( sprintf( __( 'Kategorie: %s — bitte unter „Anliegen“ eine E-Mail eintragen.', 'vw-melder' ), $rcpt['category'] ) ); ?></span>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <p class="vwfwd-to">
                <?php esc_html_e( 'Zuständig:', 'vw-melder' ); ?>
                <strong><?php echo esc_html( $rcpt['person'] !== '' ? $rcpt['person'] : $rcpt['email'] ); ?></strong>
                <?php if ( $rcpt['person'] !== '' ) : ?><br><code><?php echo esc_html( $rcpt['email'] ); ?></code><?php endif; ?>
                <br><span class="description"><?php echo esc_html( sprintf( __( 'Kategorie: %s', 'vw-melder' ), $rcpt['category'] ) ); ?></span>
            </p>
            <p>
                <label for="vwfwd-note"><strong><?php esc_html_e( 'Kurze Notiz (optional):', 'vw-melder' ); ?></strong></label>
                <textarea id="vwfwd-note" rows="3" class="widefat" placeholder="<?php esc_attr_e( 'z. B. Bitte um zeitnahe Prüfung …', 'vw-melder' ); ?>"></textarea>
            </p>
            <p>
                <button type="button" class="button button-primary" id="vwfwd-send" style="width:100%">
                    <span class="dashicons dashicons-email-alt" style="vertical-align:text-bottom"></span>
                    <?php echo $test_mode ? esc_html__( 'Test senden', 'vw-melder' ) : esc_html__( 'Senden', 'vw-melder' ); ?>
                </button>
            </p>
            <p id="vwfwd-msg" class="vwfwd-msg" style="display:none"></p>
        <?php endif; ?>

        <div id="vwfwd-log"><?php self::render_history( self::get_history( $post->ID ) ); ?></div>

        <?php if ( $rcpt['email'] !== '' ) : ?>
        <script>
        ( function () {
            var btn = document.getElementById( 'vwfwd-send' );
            if ( ! btn ) { return; }
            var note = document.getElementById( 'vwfwd-note' );
            var msg  = document.getElementById( 'vwfwd-msg' );
            var log  = document.getElementById( 'vwfwd-log' );
            var busy = <?php echo wp_json_encode( __( 'Wird gesendet …', 'vw-melder' ) ); ?>;
            var label = btn.innerHTML;
            btn.addEventListener( 'click', function () {
                btn.disabled = true; btn.textContent = busy; msg.style.display = 'none';
                var body = new URLSearchParams();
                body.append( 'action', <?php echo wp_json_encode( self::ACTION ); ?> );
                body.append( 'post_id', <?php echo (int) $post->ID; ?> );
                body.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?> );
                body.append( 'note', note ? note.value : '' );
                fetch( ajaxurl, { method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
                    .then( function ( r ) { return r.json(); } )
                    .then( function ( res ) {
                        btn.disabled = false; btn.innerHTML = label; msg.style.display = 'block';
                        if ( res && res.success ) {
                            msg.className = 'vwfwd-msg ok';
                            msg.textContent = ( res.data && res.data.message ) || 'Gesendet.';
                            if ( note ) { note.value = ''; }
                            if ( res.data && res.data.log_html ) { log.innerHTML = res.data.log_html; }
                        } else {
                            msg.className = 'vwfwd-msg err';
                            msg.textContent = ( res && res.data && res.data.message ) || 'Fehler beim Senden.';
                        }
                    } )
                    .catch( function () {
                        btn.disabled = false; btn.innerHTML = label;
                        msg.style.display = 'block'; msg.className = 'vwfwd-msg err';
                        msg.textContent = 'Netzwerkfehler — bitte erneut versuchen.';
                    } );
            } );
        } )();
        </script>
        <?php endif; ?>
        <?php
    }

    /* ================= Versand (AJAX) ================= */

    public static function handle_ajax(): void {
        check_ajax_referer( self::NONCE );

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id || get_post_type( $post_id ) !== 'vw_meldung'
            || ! current_user_can( self::CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Keine Berechtigung.', 'vw-melder' ) ] );
        }

        $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
        $res  = self::send( $post_id, $note );

        if ( empty( $res['ok'] ) ) {
            wp_send_json_error( [ 'message' => (string) $res['message'] ] );
        }

        ob_start();
        self::render_history( self::get_history( $post_id ) );
        $log_html = ob_get_clean();

        wp_send_json_success( [ 'message' => (string) $res['message'], 'log_html' => $log_html ] );
    }

    /**
     * Verschickt die Weiterleitung als E-Mail und protokolliert sie an der Meldung.
     * Kapselt die eigentliche Logik (auch per WP-CLI/Test aufrufbar).
     *
     * @return array{ok:bool,to:string,test:bool,message:string}
     */
    public static function send( int $post_id, string $note = '', string $by = '' ): array {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'vw_meldung' ) {
            return [ 'ok' => false, 'to' => '', 'test' => false, 'message' => __( 'Meldung nicht gefunden.', 'vw-melder' ) ];
        }

        $rcpt      = self::recipient_for( $post_id );
        $test_mode = VW_Melder_Settings::forward_test_mode();
        $to        = $test_mode ? VW_Melder_Settings::forward_test_email() : $rcpt['email'];
        if ( $to === '' || ! is_email( $to ) ) {
            return [ 'ok' => false, 'to' => '', 'test' => $test_mode, 'message' => $test_mode
                ? __( 'Keine gültige Test-E-Mail in den Einstellungen hinterlegt.', 'vw-melder' )
                : __( 'Für die Kategorie dieser Meldung ist keine gültige E-Mail hinterlegt.', 'vw-melder' ) ];
        }

        $user    = wp_get_current_user();
        $u_email = ( $user && is_email( $user->user_email ) ) ? $user->user_email : '';
        if ( $by === '' ) {
            $by = ( $user && $user->exists() ) ? $user->display_name : __( 'System', 'vw-melder' );
        }

        $intro = self::intro_html( $note, $by, $rcpt, $test_mode, $to );
        $html  = VW_Melder_Export::render_document( [ $post ], true, $intro );

        $subject = sprintf( __( 'Mängelmeldung #%1$d: %2$s', 'vw-melder' ), $post_id, get_the_title( $post_id ) );
        if ( $test_mode ) {
            $subject = '[TEST] ' . $subject;
        }
        $reply_to = $u_email !== '' ? $u_email : ( VW_Melder_Settings::notify_recipients()[0] ?? get_option( 'admin_email' ) );
        $headers  = [ 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $reply_to ];

        if ( ! wp_mail( $to, $subject, $html, $headers ) ) {
            return [ 'ok' => false, 'to' => $to, 'test' => $test_mode, 'message' => __( 'E-Mail-Versand fehlgeschlagen (wp_mail).', 'vw-melder' ) ];
        }

        $history   = self::get_history( $post_id );
        $history[] = [
            'time'     => gmdate( 'c' ),
            'user'     => $by,
            'to'       => $rcpt['email'],
            'to_real'  => $to,
            'person'   => $rcpt['person'],
            'category' => $rcpt['category'],
            'note'     => $note,
            'test'     => $test_mode ? 1 : 0,
        ];
        update_post_meta( $post_id, self::HISTORY_META, $history );

        return [ 'ok' => true, 'to' => $to, 'test' => $test_mode, 'message' => $test_mode
            ? sprintf( __( '✓ Test-Mail an %s gesendet.', 'vw-melder' ), $to )
            : sprintf( __( '✓ Weitergeleitet an %s.', 'vw-melder' ), $rcpt['person'] !== '' ? $rcpt['person'] : $to ) ];
    }

    /** Kopfblock der E-Mail (Notiz + Zuständigkeit), im Report-Layout. */
    private static function intro_html( string $note, string $by, array $rcpt, bool $test, string $to ): string {
        $person = trim( (string) ( $rcpt['person'] ?? '' ) );
        $email  = (string) ( $rcpt['email'] ?? '' );
        $zust   = $person !== '' ? $person . ( $email !== '' ? ' <' . $email . '>' : '' ) : $email;

        $rows  = '<tr><th>Weitergeleitet von</th><td>' . esc_html( $by ) . '</td></tr>';
        $rows .= '<tr><th>Zuständig</th><td>' . esc_html( $zust !== '' ? $zust : '—' )
            . ( $rcpt['category'] ? ' — ' . esc_html( (string) $rcpt['category'] ) : '' ) . '</td></tr>';

        $note_html = $note !== '' ? nl2br( esc_html( $note ) ) : '<em>keine Notiz</em>';

        $banner = $test
            ? '<div style="background:#fcf3d9;border:1px solid #dba617;color:#8a6d00;border-radius:6px;padding:8px 12px;margin:0 0 14px;font-weight:600">'
                . '⚠ TEST — diese Mail wäre regulär an ' . esc_html( $zust !== '' ? $zust : '—' ) . ' gegangen, wurde aber an ' . esc_html( $to ) . ' gesendet.'
                . '</div>'
            : '';

        return '<div class="handoff" style="border:2px solid #0a5f2b;border-radius:8px;padding:14px 18px;margin:0 0 20px;background:#f6faf7">'
            . $banner
            . '<h2 style="margin:0 0 8px;color:#0a5f2b;font-size:15px">Weiterleitung an die zuständige Fachkraft</h2>'
            . '<table class="daten" style="margin:0 0 12px">' . $rows . '</table>'
            . '<div style="font-weight:600;color:#2a3196;margin:0 0 3px">Notiz der Verwaltung</div>'
            . '<div class="beschreibung">' . $note_html . '</div>'
            . '</div>';
    }

    /* ================= Protokoll ================= */

    /** @return array<int,array<string,mixed>> */
    public static function get_history( int $post_id ): array {
        $h = get_post_meta( $post_id, self::HISTORY_META, true );
        return is_array( $h ) ? $h : [];
    }

    public static function render_history( array $history ): void {
        echo '<h4 style="margin:14px 0 6px">' . esc_html__( 'Weiterleitungs-Protokoll', 'vw-melder' ) . '</h4>';
        if ( $history === [] ) {
            echo '<p class="description">' . esc_html__( 'Diese Meldung wurde noch nicht weitergeleitet.', 'vw-melder' ) . '</p>';
            return;
        }
        echo '<ul class="vwfwd-loglist">';
        foreach ( array_reverse( $history ) as $e ) {
            $ts      = strtotime( (string) ( $e['time'] ?? '' ) );
            $when    = $ts ? wp_date( 'd.m.Y', $ts ) . ' um ' . wp_date( 'H:i', $ts ) . ' Uhr' : '';
            $test    = ! empty( $e['test'] );
            $to_real = (string) ( $e['to_real'] ?? ( $e['to'] ?? '' ) );
            $person  = (string) ( $e['person'] ?? '' );
            $real_to = (string) ( $e['to'] ?? '' );
            $label   = $person !== '' ? $person . ( $real_to !== '' ? ' (' . $real_to . ')' : '' ) : $real_to;

            echo '<li><div class="vwfwd-sent"><span class="dashicons dashicons-yes-alt"></span> ';
            if ( $test ) {
                echo esc_html( sprintf( __( 'TEST an %1$s — %2$s', 'vw-melder' ), $to_real, $when ) );
            } else {
                echo esc_html( sprintf( __( 'Weitergeleitet an %1$s — %2$s', 'vw-melder' ), $label !== '' ? $label : $to_real, $when ) );
            }
            echo '</div>';
            if ( ! empty( $e['note'] ) ) {
                echo '<div class="vwfwd-note">„' . esc_html( (string) $e['note'] ) . '“</div>';
            }
            if ( ! empty( $e['user'] ) ) {
                echo '<div class="vwfwd-by">' . esc_html( sprintf( __( 'von %s', 'vw-melder' ), (string) $e['user'] ) ) . '</div>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /* ================= Kategorie-Formular (Term-Meta) ================= */

    public static function term_add_fields(): void {
        wp_nonce_field( 'vw_anliegen_email', 'vw_anliegen_email_nonce' );
        ?>
        <div class="form-field">
            <label for="vw_anliegen_email"><?php esc_html_e( 'E-Mail des Zuständigen', 'vw-melder' ); ?></label>
            <input type="email" name="vw_anliegen_email" id="vw_anliegen_email" value="">
            <p><?php esc_html_e( 'An diese Adresse werden Meldungen dieser Kategorie weitergeleitet.', 'vw-melder' ); ?></p>
        </div>
        <div class="form-field">
            <label for="vw_anliegen_person"><?php esc_html_e( 'Name des Zuständigen', 'vw-melder' ); ?></label>
            <input type="text" name="vw_anliegen_person" id="vw_anliegen_person" value="">
        </div>
        <?php
    }

    public static function term_edit_fields( WP_Term $term ): void {
        wp_nonce_field( 'vw_anliegen_email', 'vw_anliegen_email_nonce' );
        $email  = (string) get_term_meta( $term->term_id, self::TERM_EMAIL, true );
        $person = (string) get_term_meta( $term->term_id, self::TERM_PERSON, true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="vw_anliegen_email"><?php esc_html_e( 'E-Mail des Zuständigen', 'vw-melder' ); ?></label></th>
            <td>
                <input type="email" name="vw_anliegen_email" id="vw_anliegen_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text">
                <p class="description"><?php esc_html_e( 'An diese Adresse werden Meldungen dieser Kategorie weitergeleitet.', 'vw-melder' ); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="vw_anliegen_person"><?php esc_html_e( 'Name des Zuständigen', 'vw-melder' ); ?></label></th>
            <td><input type="text" name="vw_anliegen_person" id="vw_anliegen_person" value="<?php echo esc_attr( $person ); ?>" class="regular-text"></td>
        </tr>
        <?php
    }

    public static function save_term_fields( int $term_id ): void {
        if ( ! isset( $_POST['vw_anliegen_email_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vw_anliegen_email_nonce'] ) ), 'vw_anliegen_email' )
        ) {
            return;
        }
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }
        if ( isset( $_POST['vw_anliegen_email'] ) ) {
            $email = sanitize_email( wp_unslash( $_POST['vw_anliegen_email'] ) );
            update_term_meta( $term_id, self::TERM_EMAIL, $email );
        }
        if ( isset( $_POST['vw_anliegen_person'] ) ) {
            update_term_meta( $term_id, self::TERM_PERSON, sanitize_text_field( wp_unslash( $_POST['vw_anliegen_person'] ) ) );
        }
    }

    /** @param array<string,string> $cols @return array<string,string> */
    public static function term_column( array $cols ): array {
        $cols['vw_zustaendig'] = __( 'Zuständig (E-Mail)', 'vw-melder' );
        return $cols;
    }

    public static function term_column_content( string $content, string $column, int $term_id ): string {
        if ( $column !== 'vw_zustaendig' ) {
            return $content;
        }
        $email  = (string) get_term_meta( $term_id, self::TERM_EMAIL, true );
        $person = (string) get_term_meta( $term_id, self::TERM_PERSON, true );
        if ( $email === '' ) {
            return '<span style="color:#b32d2e">' . esc_html__( '— nicht gesetzt', 'vw-melder' ) . '</span>';
        }
        return ( $person !== '' ? esc_html( $person ) . '<br>' : '' ) . '<code>' . esc_html( $email ) . '</code>';
    }
}
