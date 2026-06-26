<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Direkte Rückmeldung an den Melder (einseitig: Amt → Melder),
 * mit Nachrichten-Verlauf in der Meldung. Ersetzt die frühere
 * Zweckentfremdung der WordPress-Kommentare.
 */
final class VW_Melder_Communication {

    public const HISTORY_META = '_vw_meldung_messages';
    public const ACTION       = 'vw_melder_send_message';

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
        add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_send' ] );
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

    /** @return array<int,array{time:string,user:string,message:string}> */
    public static function get_history( int $post_id ): array {
        $h = get_post_meta( $post_id, self::HISTORY_META, true );
        return is_array( $h ) ? $h : [];
    }

    public static function render( WP_Post $post ): void {
        $name   = (string) get_post_meta( $post->ID, '_vw_meldung_reporter_name', true );
        $email  = (string) get_post_meta( $post->ID, '_vw_meldung_reporter_email', true );
        $notify = (bool) get_post_meta( $post->ID, '_vw_meldung_notify', true );
        $history = self::get_history( $post->ID );
        ?>
        <style>
            .vwc-meta { margin: 0 0 12px; }
            .vwc-meta code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
            .vwc-hist { margin: 12px 0; padding: 0; list-style: none; }
            .vwc-hist li { border-left: 3px solid #0a5f2b; background: #f6f7f7; padding: 8px 12px; margin: 0 0 8px; border-radius: 0 4px 4px 0; }
            .vwc-hist .vwc-when { color: #646970; font-size: 12px; }
            .vwc-empty { color: #646970; font-style: italic; }
            .vwc-warn { color: #b32d2e; }
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

        <h4 style="margin:14px 0 6px"><?php esc_html_e( 'Verlauf', 'vw-melder' ); ?></h4>
        <?php if ( $history === [] ) : ?>
            <p class="vwc-empty"><?php esc_html_e( 'Noch keine Nachrichten gesendet.', 'vw-melder' ); ?></p>
        <?php else : ?>
            <ul class="vwc-hist">
                <?php foreach ( array_reverse( $history ) as $entry ) : ?>
                    <li>
                        <div class="vwc-when">
                            <?php
                            $ts = strtotime( (string) ( $entry['time'] ?? '' ) );
                            echo esc_html( $ts ? wp_date( 'd.m.Y H:i', $ts ) : '' );
                            echo ' — ' . esc_html( (string) ( $entry['user'] ?? '' ) );
                            ?>
                        </div>
                        <div><?php echo nl2br( esc_html( (string) ( $entry['message'] ?? '' ) ) ); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( $email === '' ) : ?>
            <p class="vwc-warn"><?php esc_html_e( 'Ohne hinterlegte E-Mail kann keine Rückmeldung gesendet werden.', 'vw-melder' ); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>">
                <?php wp_nonce_field( self::ACTION . '_' . $post->ID, 'vwc_nonce' ); ?>
                <p>
                    <label for="vwc-message"><strong><?php esc_html_e( 'Neue Nachricht an den Melder:', 'vw-melder' ); ?></strong></label>
                </p>
                <textarea id="vwc-message" name="vwc_message" rows="4" class="large-text" required placeholder="<?php esc_attr_e( 'Ihre Nachricht …', 'vw-melder' ); ?>"></textarea>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Nachricht senden', 'vw-melder' ); ?></button>
                    <span class="description" style="margin-left:8px">
                        <?php esc_html_e( 'Geht direkt als E-Mail an den Melder. Antworten landen bei der Benachrichtigungs-Adresse.', 'vw-melder' ); ?>
                    </span>
                </p>
            </form>
        <?php endif; ?>
        <?php
    }

    public static function handle_send(): void {
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

        if ( ! $post_id
            || ! current_user_can( 'edit_post', $post_id )
            || ! isset( $_POST['vwc_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vwc_nonce'] ) ), self::ACTION . '_' . $post_id )
        ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'vw-melder' ) );
        }

        $message = trim( (string) wp_unslash( $_POST['vwc_message'] ?? '' ) );
        $email   = (string) get_post_meta( $post_id, '_vw_meldung_reporter_email', true );
        $result  = 'empty';

        if ( $message !== '' && $email !== '' && is_email( $email ) ) {
            $title = get_the_title( $post_id );
            $reply_to = VW_Melder_Settings::notify_recipients()[0] ?? get_option( 'admin_email' );

            $subject = sprintf( __( 'Ihre Mängelmeldung: %s', 'vw-melder' ), $title );
            $body    = $message . "\n\n"
                . "—\n"
                . sprintf( __( 'Diese Nachricht bezieht sich auf Ihre Meldung „%s".', 'vw-melder' ), $title ) . "\n"
                . __( 'Verwaltungsverband Wildenstein – Mängelmelder', 'vw-melder' );

            $headers = [ 'Reply-To: ' . $reply_to ];
            $sent    = wp_mail( $email, $subject, $body, $headers );

            if ( $sent ) {
                $current = wp_get_current_user();
                $history = self::get_history( $post_id );
                $history[] = [
                    'time'    => gmdate( 'c' ),
                    'user'    => $current ? $current->display_name : '—',
                    'message' => sanitize_textarea_field( $message ),
                ];
                update_post_meta( $post_id, self::HISTORY_META, $history );
                $result = 'sent';
            } else {
                $result = 'failed';
            }
        }

        wp_safe_redirect( add_query_arg(
            [ 'vwc_msg' => $result ],
            get_edit_post_link( $post_id, 'url' )
        ) );
        exit;
    }

    public static function notice(): void {
        if ( ! isset( $_GET['vwc_msg'] ) ) {
            return;
        }
        $map = [
            'sent'   => [ 'success', __( 'Nachricht an den Melder wurde gesendet.', 'vw-melder' ) ],
            'failed' => [ 'error', __( 'Nachricht konnte nicht gesendet werden (E-Mail-Versand fehlgeschlagen).', 'vw-melder' ) ],
            'empty'  => [ 'warning', __( 'Keine Nachricht gesendet (Text oder E-Mail fehlt).', 'vw-melder' ) ],
        ];
        $key = sanitize_key( (string) $_GET['vwc_msg'] );
        if ( ! isset( $map[ $key ] ) ) {
            return;
        }
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $map[ $key ][0] ),
            esc_html( $map[ $key ][1] )
        );
    }
}
