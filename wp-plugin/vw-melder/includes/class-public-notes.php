<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Öffentliche Antworten/Notizen der Verwaltung an einer Meldung.
 * Im Frontend sichtbar. Hinzufügen beim Speichern (Aktualisieren) — kein
 * verschachteltes Formular, kein Pflichtfeld. Löschen per Link.
 */
final class VW_Melder_Public_Notes {

    public const META        = '_vw_meldung_public_notes';
    public const ACTION_DEL  = 'vw_melder_del_note';
    public const NONCE       = 'vwn_save_nonce';

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
        add_action( 'save_post_vw_meldung', [ __CLASS__, 'save' ], 20, 1 );
        add_action( 'admin_post_' . self::ACTION_DEL, [ __CLASS__, 'handle_del' ] );
        add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
    }

    /** @return array<int,array{time:string,text:string,by:string}> */
    public static function get_notes( int $post_id ): array {
        $n = get_post_meta( $post_id, self::META, true );
        return is_array( $n ) ? array_values( $n ) : [];
    }

    public static function add_box(): void {
        add_meta_box(
            'vw_meldung_public_notes',
            __( 'Öffentliche Antwort der Verwaltung', 'vw-melder' ),
            [ __CLASS__, 'render' ],
            'vw_meldung',
            'normal',
            'default'
        );
    }

    public static function render( WP_Post $post ): void {
        $notes = self::get_notes( $post->ID );
        wp_nonce_field( 'vwn_save_' . $post->ID, self::NONCE );
        ?>
        <style>
            .vwn-note { border-left:3px solid #2a3196; background:#f6f7f7; padding:8px 12px; margin:0 0 8px; border-radius:0 4px 4px 0; }
            .vwn-when { color:#646970; font-size:12px; display:flex; justify-content:space-between; align-items:center; }
            .vwn-empty { color:#646970; font-style:italic; }
            .vwn-del { color:#b32d2e; text-decoration:none; }
        </style>
        <p class="description"><?php esc_html_e( 'Diese Texte sind öffentlich auf der Meldungs-Seite sichtbar (z. B. Bearbeitungsstand, Zuständigkeit).', 'vw-melder' ); ?></p>

        <h4 style="margin:12px 0 6px"><?php esc_html_e( 'Veröffentlichte Antworten', 'vw-melder' ); ?></h4>
        <?php if ( $notes === [] ) : ?>
            <p class="vwn-empty"><?php esc_html_e( 'Noch keine öffentliche Antwort.', 'vw-melder' ); ?></p>
        <?php else : ?>
            <?php foreach ( array_reverse( $notes, true ) as $idx => $note ) : ?>
                <div class="vwn-note">
                    <div class="vwn-when">
                        <span>
                            <?php
                            $ts = strtotime( (string) ( $note['time'] ?? '' ) );
                            echo esc_html( $ts ? wp_date( 'd.m.Y', $ts ) . ' um ' . wp_date( 'H:i', $ts ) . ' Uhr' : '' );
                            if ( ! empty( $note['by'] ) ) {
                                echo ' — ' . esc_html( (string) $note['by'] );
                            }
                            ?>
                        </span>
                        <a class="vwn-del" href="<?php echo esc_url( self::del_url( $post->ID, (int) $idx ) ); ?>"
                           onclick="return confirm('<?php echo esc_js( __( 'Diese öffentliche Antwort löschen?', 'vw-melder' ) ); ?>');">
                            <?php esc_html_e( 'löschen', 'vw-melder' ); ?>
                        </a>
                    </div>
                    <div><?php echo nl2br( esc_html( (string) ( $note['text'] ?? '' ) ) ); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <p><label for="vwn-text"><strong><?php esc_html_e( 'Neue öffentliche Antwort:', 'vw-melder' ); ?></strong></label></p>
        <textarea id="vwn-text" name="vwn_text" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'z. B. Aktueller Stand: Die Reparatur ist für … geplant. (leer lassen = nichts veröffentlichen)', 'vw-melder' ); ?>"></textarea>
        <p class="description"><?php esc_html_e( 'Wird beim „Aktualisieren" veröffentlicht. Leer lassen ändert nichts.', 'vw-melder' ); ?></p>
        <?php
    }

    private static function del_url( int $post_id, int $idx ): string {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::ACTION_DEL . '&post_id=' . $post_id . '&idx=' . $idx ),
            self::ACTION_DEL . '_' . $post_id,
            'vwn_nonce'
        );
    }

    public static function save( int $post_id ): void {
        if ( ! isset( $_POST[ self::NONCE ] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'vwn_save_' . $post_id )
        ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $text = trim( (string) wp_unslash( $_POST['vwn_text'] ?? '' ) );
        if ( $text === '' ) {
            return;
        }
        $user    = wp_get_current_user();
        $notes   = self::get_notes( $post_id );
        $notes[] = [
            'time' => gmdate( 'c' ),
            'text' => sanitize_textarea_field( $text ),
            'by'   => $user ? $user->display_name : '',
        ];
        update_post_meta( $post_id, self::META, $notes );
        set_transient( 'vwn_notice_' . get_current_user_id(), 'added', 45 );
    }

    public static function handle_del(): void {
        $post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        $idx     = isset( $_GET['idx'] ) ? (int) $_GET['idx'] : -1;
        if ( ! $post_id
            || ! current_user_can( 'edit_post', $post_id )
            || ! isset( $_GET['vwn_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['vwn_nonce'] ) ), self::ACTION_DEL . '_' . $post_id )
        ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'vw-melder' ) );
        }
        $notes = self::get_notes( $post_id );
        if ( isset( $notes[ $idx ] ) ) {
            unset( $notes[ $idx ] );
            update_post_meta( $post_id, self::META, array_values( $notes ) );
        }
        wp_safe_redirect( add_query_arg( [ 'vwn_msg' => 'deleted' ], get_edit_post_link( $post_id, 'url' ) ) );
        exit;
    }

    public static function notice(): void {
        // „Veröffentlicht" (beim Speichern) via Transient
        $uid = get_current_user_id();
        if ( get_transient( 'vwn_notice_' . $uid ) === 'added' ) {
            delete_transient( 'vwn_notice_' . $uid );
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Öffentliche Antwort veröffentlicht.', 'vw-melder' ) );
        }
        // „Gelöscht" via Redirect-Parameter
        if ( isset( $_GET['vwn_msg'] ) && sanitize_key( (string) $_GET['vwn_msg'] ) === 'deleted' ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Öffentliche Antwort gelöscht.', 'vw-melder' ) );
        }
    }
}
