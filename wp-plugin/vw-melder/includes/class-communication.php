<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Direkte Rückmeldung an den Melder (einseitig: Amt → Melder), mit Protokoll.
 * Die Nachricht wird beim Speichern (Aktualisieren) der Meldung versendet —
 * kein verschachteltes Formular, kein Pflichtfeld.
 */
final class VW_Melder_Communication {

    public const HISTORY_META = '_vw_meldung_messages';
    public const NONCE        = 'vwc_nonce';

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
        add_action( 'save_post_vw_meldung', [ __CLASS__, 'save' ], 20, 1 );
        add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
    }

    public static function add_box(): void {
        add_meta_box(
            'vw_meldung_communication',
            __( 'Kommunikation mit dem Melder', 'vw-melder' ),
            [ __CLASS__, 'render' ],
            'vw_meldung',
            'normal',
            'default'
        );
    }

    /** @return array<int,array{time:string,user:string,message:string,to?:string}> */
    public static function get_history( int $post_id ): array {
        $h = get_post_meta( $post_id, self::HISTORY_META, true );
        return is_array( $h ) ? $h : [];
    }

    public static function render( WP_Post $post ): void {
        $name    = (string) get_post_meta( $post->ID, '_vw_meldung_reporter_name', true );
        $email   = (string) get_post_meta( $post->ID, '_vw_meldung_reporter_email', true );
        $notify  = (bool) get_post_meta( $post->ID, '_vw_meldung_notify', true );
        $history = self::get_history( $post->ID );
        wp_nonce_field( 'vwc_save_' . $post->ID, self::NONCE );
        ?>
        <style>
            .vwc-meta { margin: 0 0 12px; }
            .vwc-meta code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
            .vwc-empty { color: #646970; font-style: italic; }
            .vwc-warn { color: #b32d2e; }
            .vwc-log { margin: 10px 0 16px; padding: 0; list-style: none; }
            .vwc-log li { border-left: 3px solid #0a5f2b; background: #f6f7f7; padding: 8px 12px; margin: 0 0 8px; border-radius: 0 4px 4px 0; }
            .vwc-sent { color: #0a5f2b; font-weight: 600; font-size: 12px; display: flex; align-items: center; gap: 5px; }
            .vwc-sent .dashicons { font-size: 15px; width: 15px; height: 15px; }
            .vwc-log .vwc-msg { margin: 4px 0 2px; }
            .vwc-log .vwc-by { color: #646970; font-size: 12px; }
        </style>

        <p class="vwc-meta">
            <strong><?php esc_html_e( 'Melder:', 'vw-melder' ); ?></strong>
            <?php echo $name !== '' ? esc_html( $name ) : '<span class="vwc-empty">' . esc_html__( 'kein Name', 'vw-melder' ) . '</span>'; ?>
            &nbsp;·&nbsp;
            <strong><?php esc_html_e( 'E-Mail:', 'vw-melder' ); ?></strong>
            <?php echo $email !== '' ? '<code>' . esc_html( $email ) . '</code>' : '<span class="vwc-warn">' . esc_html__( 'keine E-Mail hinterlegt', 'vw-melder' ) . '</span>'; ?>
            &nbsp;·&nbsp;
            <?php echo $notify
                ? '<span style="color:#0a5f2b">' . esc_html__( 'möchte Updates', 'vw-melder' ) . '</span>'
                : '<span class="vwc-empty">' . esc_html__( 'keine Updates gewünscht', 'vw-melder' ) . '</span>'; ?>
        </p>

        <h4 style="margin:14px 0 6px"><?php esc_html_e( 'Protokoll der Nachrichten', 'vw-melder' ); ?></h4>
        <?php if ( $history === [] ) : ?>
            <p class="vwc-empty"><?php esc_html_e( 'Noch keine Nachrichten an den Melder gesendet.', 'vw-melder' ); ?></p>
        <?php else : ?>
            <ul class="vwc-log">
                <?php foreach ( array_reverse( $history ) as $entry ) :
                    $ts   = strtotime( (string) ( $entry['time'] ?? '' ) );
                    $when = $ts ? wp_date( 'd.m.Y', $ts ) . ' um ' . wp_date( 'H:i', $ts ) . ' Uhr' : '';
                    $to   = (string) ( $entry['to'] ?? '' );
                ?>
                    <li>
                        <div class="vwc-sent">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php
                            printf(
                                /* translators: 1: E-Mail, 2: Datum/Uhrzeit */
                                esc_html__( 'E-Mail gesendet%1$s — %2$s', 'vw-melder' ),
                                $to !== '' ? ' ' . esc_html__( 'an', 'vw-melder' ) . ' ' . esc_html( $to ) : '',
                                esc_html( $when )
                            );
                            ?>
                        </div>
                        <div class="vwc-msg"><?php echo nl2br( esc_html( (string) ( $entry['message'] ?? '' ) ) ); ?></div>
                        <?php if ( ! empty( $entry['user'] ) ) : ?>
                            <div class="vwc-by"><?php echo esc_html( sprintf( __( 'von %s', 'vw-melder' ), (string) $entry['user'] ) ); ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( $email === '' ) : ?>
            <p class="vwc-warn"><?php esc_html_e( 'Ohne hinterlegte E-Mail kann keine Rückmeldung gesendet werden.', 'vw-melder' ); ?></p>
        <?php else : ?>
            <p><label for="vwc-message"><strong><?php esc_html_e( 'Neue Nachricht an den Melder:', 'vw-melder' ); ?></strong></label></p>
            <textarea id="vwc-message" name="vwc_message" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Ihre Nachricht … (leer lassen = nichts senden)', 'vw-melder' ); ?>"></textarea>
            <p class="description">
                <?php esc_html_e( 'Wird beim „Aktualisieren" direkt als E-Mail an den Melder gesendet und hier protokolliert. Antworten landen bei der Benachrichtigungs-Adresse. Leer lassen ändert nichts.', 'vw-melder' ); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    public static function save( int $post_id ): void {
        if ( ! isset( $_POST[ self::NONCE ] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'vwc_save_' . $post_id )
        ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $message = trim( (string) wp_unslash( $_POST['vwc_message'] ?? '' ) );
        if ( $message === '' ) {
            return; // nichts eingegeben → nichts senden (Status etc. wird normal gespeichert)
        }

        $email = (string) get_post_meta( $post_id, '_vw_meldung_reporter_email', true );
        if ( $email === '' || ! is_email( $email ) ) {
            self::set_notice( 'noemail' );
            return;
        }

        $title    = get_the_title( $post_id );
        $reply_to = VW_Melder_Settings::notify_recipients()[0] ?? get_option( 'admin_email' );
        $subject  = sprintf( __( 'Ihre Mängelmeldung: %s', 'vw-melder' ), $title );
        $body     = $message . "\n\n—\n"
            . sprintf( __( 'Diese Nachricht bezieht sich auf Ihre Meldung „%s".', 'vw-melder' ), $title ) . "\n"
            . __( 'Verwaltungsverband Wildenstein – Mängelmelder', 'vw-melder' );

        $sent = wp_mail( $email, $subject, $body, [ 'Reply-To: ' . $reply_to ] );

        if ( $sent ) {
            $current   = wp_get_current_user();
            $history   = self::get_history( $post_id );
            $history[] = [
                'time'    => gmdate( 'c' ),
                'user'    => $current ? $current->display_name : '—',
                'to'      => $email,
                'message' => sanitize_textarea_field( $message ),
            ];
            update_post_meta( $post_id, self::HISTORY_META, $history );
            self::set_notice( 'sent' );
        } else {
            self::set_notice( 'failed' );
        }
    }

    private static function set_notice( string $result ): void {
        set_transient( 'vwc_notice_' . get_current_user_id(), $result, 45 );
    }

    public static function notice(): void {
        $uid = get_current_user_id();
        $result = get_transient( 'vwc_notice_' . $uid );
        if ( ! $result ) {
            return;
        }
        delete_transient( 'vwc_notice_' . $uid );
        $map = [
            'sent'    => [ 'success', __( 'Nachricht an den Melder wurde gesendet.', 'vw-melder' ) ],
            'failed'  => [ 'error', __( 'Nachricht konnte nicht gesendet werden (E-Mail-Versand fehlgeschlagen).', 'vw-melder' ) ],
            'noemail' => [ 'warning', __( 'Keine Nachricht gesendet: beim Melder ist keine gültige E-Mail hinterlegt.', 'vw-melder' ) ],
        ];
        if ( ! isset( $map[ $result ] ) ) {
            return;
        }
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $map[ $result ][0] ),
            esc_html( $map[ $result ][1] )
        );
    }
}
