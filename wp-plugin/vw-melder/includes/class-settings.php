<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Einstellungen-Seite unter „Mängelmelder".
 */
final class VW_Melder_Settings {

    public const OPTION = 'vw_melder_settings';
    public const GROUP  = 'vw_melder_settings_group';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
    }

    public static function defaults(): array {
        return [
            'notify_email'        => get_option( 'admin_email' ),
            'frontend_url'        => 'https://melder2026.vv-wildenstein.com',
            'deploy_enabled'      => 0,
            'github_token'        => '',
            'forward_test_mode'   => 1,
            'forward_test_email'  => 'stefan@gumu-agentur.de',
        ];
    }

    public static function get(): array {
        $opt = get_option( self::OPTION, [] );
        return array_merge( self::defaults(), is_array( $opt ) ? $opt : [] );
    }

    /** Basis-URL des Astro-Frontends (ohne abschließenden Slash). */
    public static function frontend_url(): string {
        $url = trim( (string) ( self::get()['frontend_url'] ?? '' ) );
        return $url !== '' ? rtrim( $url, '/' ) : 'https://melder2026.vv-wildenstein.com';
    }

    /** Empfänger der „neue Meldung"-Benachrichtigung (Array, Fallback admin_email). */
    public static function notify_recipients(): array {
        $raw   = (string) ( self::get()['notify_email'] ?? '' );
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $valid = array_values( array_filter( $parts, 'is_email' ) );
        return $valid !== [] ? $valid : [ get_option( 'admin_email' ) ];
    }

    /** Test-Modus für die Weiterleitung an Zuständige (alle Mails an Test-Adresse). */
    public static function forward_test_mode(): bool {
        return ! empty( self::get()['forward_test_mode'] );
    }

    /** Test-Empfänger der Weiterleitung (Fallback: interne Test-Adresse). */
    public static function forward_test_email(): string {
        $e = trim( (string) ( self::get()['forward_test_email'] ?? '' ) );
        return ( $e !== '' && is_email( $e ) ) ? $e : 'stefan@gumu-agentur.de';
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=vw_meldung',
            __( 'Einstellungen – Mängelmelder', 'vw-melder' ),
            __( 'Einstellungen', 'vw-melder' ),
            'manage_options',
            'vw-melder-settings',
            [ __CLASS__, 'render' ]
        );
    }

    public static function register(): void {
        register_setting( self::GROUP, self::OPTION, [ __CLASS__, 'sanitize' ] );

        add_settings_section(
            'vw_melder_benachrichtigung',
            __( 'Benachrichtigungen', 'vw-melder' ),
            static function () {
                echo '<p>' . esc_html__( 'Wer wird informiert, wenn eine neue Meldung eingereicht wird?', 'vw-melder' ) . '</p>';
            },
            'vw-melder-settings'
        );

        add_settings_field(
            'notify_email',
            __( 'Benachrichtigungs-E-Mail', 'vw-melder' ),
            [ __CLASS__, 'field_notify_email' ],
            'vw-melder-settings',
            'vw_melder_benachrichtigung'
        );

        add_settings_section(
            'vw_melder_frontend',
            __( 'Frontend', 'vw-melder' ),
            static function () {
                echo '<p>' . esc_html__( 'Adresse der öffentlichen Mängelmelder-App. „Ansehen“ und Permalinks der Meldungen verweisen dorthin.', 'vw-melder' ) . '</p>';
            },
            'vw-melder-settings'
        );

        add_settings_field(
            'frontend_url',
            __( 'Frontend-URL', 'vw-melder' ),
            [ __CLASS__, 'field_frontend_url' ],
            'vw-melder-settings',
            'vw_melder_frontend'
        );

        add_settings_section(
            'vw_melder_deploy',
            __( 'Automatische Aktualisierung (Auto-Deploy)', 'vw-melder' ),
            static function () {
                echo '<p>' . esc_html__( 'Wenn aktiv, baut sich die öffentliche App automatisch neu, sobald eine Meldung freigegeben, geändert, depubliziert oder gelöscht wird — meist innerhalb von 1–2 Minuten. Dadurch sind fast keine geplanten Läufe mehr nötig (weniger GitHub-Fehlermails).', 'vw-melder' ) . '</p>';
            },
            'vw-melder-settings'
        );

        add_settings_field(
            'deploy_enabled',
            __( 'Auto-Deploy', 'vw-melder' ),
            [ __CLASS__, 'field_deploy_enabled' ],
            'vw-melder-settings',
            'vw_melder_deploy'
        );

        add_settings_field(
            'github_token',
            __( 'GitHub-Token', 'vw-melder' ),
            [ __CLASS__, 'field_github_token' ],
            'vw-melder-settings',
            'vw_melder_deploy'
        );

        add_settings_section(
            'vw_melder_forward',
            __( 'Weiterleitung an Zuständige', 'vw-melder' ),
            static function () {
                echo '<p>' . esc_html__( 'Aus einer Meldung heraus kann sie an die zuständige Fachkraft weitergeleitet werden (Empfänger je Kategorie unter „Anliegen“ hinterlegt). Solange der Test-Modus aktiv ist, gehen ALLE Weiterleitungen an die Test-Adresse statt an die echten Zuständigen.', 'vw-melder' ) . '</p>';
            },
            'vw-melder-settings'
        );

        add_settings_field(
            'forward_test_mode',
            __( 'Test-Modus', 'vw-melder' ),
            [ __CLASS__, 'field_forward_test_mode' ],
            'vw-melder-settings',
            'vw_melder_forward'
        );

        add_settings_field(
            'forward_test_email',
            __( 'Test-Empfänger', 'vw-melder' ),
            [ __CLASS__, 'field_forward_test_email' ],
            'vw-melder-settings',
            'vw_melder_forward'
        );
    }

    public static function field_frontend_url(): void {
        $val = esc_attr( self::frontend_url() );
        echo '<input type="url" name="' . esc_attr( self::OPTION ) . '[frontend_url]" value="' . $val . '" class="regular-text" placeholder="https://melder2026.vv-wildenstein.com">';
        echo '<p class="description">' . esc_html__( 'Basis-URL des Astro-Frontends (ohne /). Später auf die Produktiv-Domain umstellen.', 'vw-melder' ) . '</p>';
    }

    public static function field_notify_email(): void {
        $val = esc_attr( (string) ( self::get()['notify_email'] ?? '' ) );
        echo '<input type="text" name="' . esc_attr( self::OPTION ) . '[notify_email]" value="' . $val . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '">';
        echo '<p class="description">' . esc_html__( 'Empfänger der Mail bei neuer Meldung. Mehrere Adressen mit Komma trennen. Leer = WordPress-Administrator.', 'vw-melder' ) . '</p>';
    }

    public static function field_deploy_enabled(): void {
        $on = ! empty( self::get()['deploy_enabled'] );
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[deploy_enabled]" value="1" ' . checked( $on, true, false ) . '> '
            . esc_html__( 'Bei Änderungen automatisch neu bauen', 'vw-melder' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Braucht einen gültigen GitHub-Token (siehe unten). Ohne Token bleibt die Aktualisierung beim geplanten Sicherheits-Lauf (1×/Tag) bzw. dem manuellen Auslösen.', 'vw-melder' ) . '</p>';
    }

    public static function field_forward_test_mode(): void {
        $on = ! empty( self::get()['forward_test_mode'] );
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[forward_test_mode]" value="1" ' . checked( $on, true, false ) . '> '
            . esc_html__( 'Test-Modus aktiv — Weiterleitungen gehen an den Test-Empfänger', 'vw-melder' ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Zum Ausprobieren anlassen. Erst ausschalten, wenn alles passt — dann gehen Weiterleitungen an die echten Zuständigen (je Kategorie unter „Anliegen“).', 'vw-melder' ) . '</p>';
    }

    public static function field_forward_test_email(): void {
        $val = esc_attr( self::forward_test_email() );
        echo '<input type="email" name="' . esc_attr( self::OPTION ) . '[forward_test_email]" value="' . $val . '" class="regular-text" placeholder="stefan@gumu-agentur.de">';
        echo '<p class="description">' . esc_html__( 'An diese Adresse gehen alle Weiterleitungen, solange der Test-Modus aktiv ist.', 'vw-melder' ) . '</p>';
    }

    public static function field_github_token(): void {
        $has = trim( (string) ( self::get()['github_token'] ?? '' ) ) !== '';
        $ph  = $has
            ? __( '•••••••••• gespeichert — zum Ändern neuen Token eingeben', 'vw-melder' )
            : 'github_pat_…';
        echo '<input type="password" name="' . esc_attr( self::OPTION ) . '[github_token]" value="" autocomplete="off" spellcheck="false" class="regular-text" placeholder="' . esc_attr( $ph ) . '">';
        if ( $has ) {
            echo ' <label style="margin-left:6px"><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[github_token_clear]" value="1"> '
                . esc_html__( 'gespeicherten Token entfernen', 'vw-melder' ) . '</label>';
        }
        $link = 'https://github.com/settings/personal-access-tokens/new';
        echo '<p class="description">' . wp_kses(
            sprintf(
                /* translators: %s: URL zum Erstellen eines Tokens */
                __( 'Fein-granularer GitHub-Token für <code>stefangutermuth/vv-wildenstein</code> mit der Berechtigung <strong>Contents: Read and write</strong>. Erstellen: <a href="%s" target="_blank" rel="noopener">github.com/settings/personal-access-tokens/new</a> → <em>Resource owner</em>: stefangutermuth · <em>Repository access</em>: nur <code>vv-wildenstein</code> · <em>Permissions → Contents</em>: Read and write. Der Token beginnt mit <code>github_pat_</code> und wird sicher in der Datenbank gespeichert (hier nie wieder angezeigt).', 'vw-melder' ),
                esc_url( $link )
            ),
            [ 'code' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
        ) . '</p>';
    }

    public static function sanitize( $input ): array {
        $out = self::get();

        $emails_raw = isset( $input['notify_email'] ) ? (string) $input['notify_email'] : '';
        $parts      = array_filter( array_map( 'trim', explode( ',', $emails_raw ) ) );
        $valid      = [];
        foreach ( $parts as $p ) {
            $e = sanitize_email( $p );
            if ( $e && is_email( $e ) ) {
                $valid[] = $e;
            }
        }
        $out['notify_email'] = implode( ', ', $valid );

        if ( isset( $input['frontend_url'] ) ) {
            $out['frontend_url'] = esc_url_raw( trim( (string) $input['frontend_url'] ) );
        }

        $out['deploy_enabled'] = empty( $input['deploy_enabled'] ) ? 0 : 1;

        // GitHub-Token: „entfernen" angehakt = löschen; leer = bestehenden behalten;
        // sonst neuen speichern (nur die in PATs erlaubten Zeichen).
        if ( ! empty( $input['github_token_clear'] ) ) {
            $out['github_token'] = '';
        } elseif ( isset( $input['github_token'] ) && trim( (string) $input['github_token'] ) !== '' ) {
            $out['github_token'] = preg_replace( '/[^A-Za-z0-9_]/', '', trim( (string) $input['github_token'] ) );
        }

        $out['forward_test_mode'] = empty( $input['forward_test_mode'] ) ? 0 : 1;
        if ( isset( $input['forward_test_email'] ) ) {
            $e = sanitize_email( trim( (string) $input['forward_test_email'] ) );
            $out['forward_test_email'] = ( $e && is_email( $e ) ) ? $e : '';
        }

        return $out;
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $deploy_on  = ! empty( self::get()['deploy_enabled'] );
        $has_token  = trim( (string) ( self::get()['github_token'] ?? '' ) ) !== '';
        $last_iso   = VW_Melder_Deploy_Hook::last_dispatch();
        $last_ts    = $last_iso !== '' ? strtotime( $last_iso ) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Mängelmelder – Einstellungen', 'vw-melder' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::GROUP );
                do_settings_sections( 'vw-melder-settings' );
                submit_button();
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Auto-Deploy – Status', 'vw-melder' ); ?></h2>
            <?php if ( $deploy_on && $has_token ) : ?>
                <p style="color:#0a5f2b">
                    <span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom"></span>
                    <?php esc_html_e( 'Aktiv — Änderungen an Meldungen lösen automatisch einen Deploy aus.', 'vw-melder' ); ?>
                </p>
                <?php if ( $last_ts ) : ?>
                    <p class="description">
                        <?php echo esc_html( sprintf(
                            /* translators: %s: Datum/Uhrzeit */
                            __( 'Zuletzt automatisch ausgelöst: %s Uhr', 'vw-melder' ),
                            wp_date( 'd.m.Y H:i', $last_ts )
                        ) ); ?>
                    </p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
                    <input type="hidden" name="action" value="<?php echo esc_attr( VW_Melder_Deploy_Hook::ACTION_TEST ); ?>">
                    <?php wp_nonce_field( VW_Melder_Deploy_Hook::ACTION_TEST ); ?>
                    <?php submit_button( __( 'Verbindung testen (löst einen Deploy aus)', 'vw-melder' ), 'secondary', 'submit', false ); ?>
                </form>
            <?php elseif ( $has_token && ! $deploy_on ) : ?>
                <p class="description"><?php esc_html_e( 'Token gespeichert, aber Auto-Deploy ist ausgeschaltet. Oben „Bei Änderungen automatisch neu bauen“ ankreuzen und speichern.', 'vw-melder' ); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'Inaktiv. Zum Aktivieren oben „Auto-Deploy“ ankreuzen und einen GitHub-Token speichern.', 'vw-melder' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
