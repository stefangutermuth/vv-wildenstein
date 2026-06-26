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
            'notify_email' => get_option( 'admin_email' ),
            'frontend_url' => 'https://melder2026.vv-wildenstein.com',
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

        return $out;
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
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
        </div>
        <?php
    }
}
