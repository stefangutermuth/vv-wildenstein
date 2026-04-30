<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Events_Admin_UI {

    public const SETTINGS_OPTION = 'vw_events_settings';

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_vw_event', [ __CLASS__, 'save_meta' ], 10, 2 );

        add_filter( 'manage_vw_event_posts_columns', [ __CLASS__, 'columns' ] );
        add_action( 'manage_vw_event_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
        add_filter( 'manage_edit-vw_event_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'sort_query' ] );

        add_action( 'restrict_manage_posts', [ __CLASS__, 'list_filters' ] );

        add_action( 'admin_menu', [ __CLASS__, 'settings_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        add_action( 'wp_dashboard_setup', [ __CLASS__, 'dashboard_widget' ] );

        add_action( 'edit_form_top', [ __CLASS__, 'pending_banner' ] );
    }

    public static function get_settings(): array {
        $defaults = [
            'admin_email'      => get_option( 'admin_email' ),
            'turnstile_site'   => '',
            'turnstile_secret' => '',
            'archive_url'      => '',
            'submit_url'       => '',
            'master_blog_id'   => 0,
            'webhook_map'      => [],
        ];
        $opt = get_option( self::SETTINGS_OPTION, [] );
        return array_merge( $defaults, is_array( $opt ) ? $opt : [] );
    }

    /* ---------- Metabox ---------- */

    public static function add_meta_boxes(): void {
        add_meta_box(
            'vw_event_data',
            __( 'Veranstaltungs-Daten', 'vw-events' ),
            [ __CLASS__, 'render_metabox' ],
            'vw_event',
            'normal',
            'high'
        );
    }

    public static function render_metabox( WP_Post $post ): void {
        wp_nonce_field( 'vw_event_save_meta', 'vw_event_nonce' );
        $get = static fn( $k ) => esc_attr( (string) get_post_meta( $post->ID, $k, true ) );
        $all_day = (bool) get_post_meta( $post->ID, '_vw_event_all_day', true );
        $repeat  = (string) ( get_post_meta( $post->ID, '_vw_event_repeat', true ) ?: 'none' );
        $source  = (string) ( get_post_meta( $post->ID, '_vw_event_source', true ) ?: 'admin' );
        ?>
        <table class="form-table">
            <tr><th><label for="_vw_event_start"><?php esc_html_e( 'Start (lokale Zeit)', 'vw-events' ); ?> *</label></th>
                <td><input type="datetime-local" name="_vw_event_start" id="_vw_event_start" value="<?php echo $get( '_vw_event_start' ); ?>" class="regular-text"></td></tr>
            <tr><th><label for="_vw_event_end"><?php esc_html_e( 'Ende (lokale Zeit)', 'vw-events' ); ?></label></th>
                <td><input type="datetime-local" name="_vw_event_end" id="_vw_event_end" value="<?php echo $get( '_vw_event_end' ); ?>" class="regular-text"></td></tr>
            <tr><th><?php esc_html_e( 'Ganztägig', 'vw-events' ); ?></th>
                <td><label><input type="checkbox" name="_vw_event_all_day" value="1" <?php checked( $all_day ); ?>> <?php esc_html_e( 'Ja', 'vw-events' ); ?></label></td></tr>
            <tr><th><label for="_vw_event_repeat"><?php esc_html_e( 'Wiederholung', 'vw-events' ); ?></label></th>
                <td><select name="_vw_event_repeat" id="_vw_event_repeat">
                    <?php foreach ( [ 'none' => 'Keine', 'daily' => 'Täglich', 'weekly' => 'Wöchentlich', 'monthly' => 'Monatlich' ] as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $repeat, $v ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select></td></tr>
            <tr><th><label for="_vw_event_repeat_until"><?php esc_html_e( 'Wiederholung bis', 'vw-events' ); ?></label></th>
                <td><input type="date" name="_vw_event_repeat_until" id="_vw_event_repeat_until" value="<?php echo $get( '_vw_event_repeat_until' ); ?>"></td></tr>
            <tr><th><label for="_vw_event_location_name"><?php esc_html_e( 'Ort-Name', 'vw-events' ); ?></label></th>
                <td><input type="text" name="_vw_event_location_name" id="_vw_event_location_name" value="<?php echo $get( '_vw_event_location_name' ); ?>" class="regular-text"></td></tr>
            <tr><th><label for="_vw_event_location_addr"><?php esc_html_e( 'Adresse', 'vw-events' ); ?></label></th>
                <td><textarea name="_vw_event_location_addr" id="_vw_event_location_addr" rows="3" class="large-text"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_vw_event_location_addr', true ) ); ?></textarea></td></tr>
            <tr><th><label for="_vw_event_organizer_name"><?php esc_html_e( 'Veranstalter', 'vw-events' ); ?></label></th>
                <td><input type="text" name="_vw_event_organizer_name" id="_vw_event_organizer_name" value="<?php echo $get( '_vw_event_organizer_name' ); ?>" class="regular-text"></td></tr>
            <tr><th><label for="_vw_event_organizer_email"><?php esc_html_e( 'Veranstalter-E-Mail (intern)', 'vw-events' ); ?></label></th>
                <td><input type="email" name="_vw_event_organizer_email" id="_vw_event_organizer_email" value="<?php echo $get( '_vw_event_organizer_email' ); ?>" class="regular-text">
                <p class="description"><?php esc_html_e( 'Wird NICHT öffentlich ausgeliefert.', 'vw-events' ); ?></p></td></tr>
            <tr><th><label for="_vw_event_url"><?php esc_html_e( 'Externer Event-Link', 'vw-events' ); ?></label></th>
                <td><input type="url" name="_vw_event_url" id="_vw_event_url" value="<?php echo $get( '_vw_event_url' ); ?>" class="regular-text"></td></tr>
            <tr><th><?php esc_html_e( 'Quelle', 'vw-events' ); ?></th>
                <td><code><?php echo esc_html( $source ); ?></code></td></tr>
        </table>
        <?php
    }

    public static function save_meta( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! isset( $_POST['vw_event_nonce'] ) || ! wp_verify_nonce( $_POST['vw_event_nonce'], 'vw_event_save_meta' ) ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $text_keys = [
            '_vw_event_start',
            '_vw_event_end',
            '_vw_event_repeat_until',
            '_vw_event_location_name',
            '_vw_event_organizer_name',
        ];
        foreach ( $text_keys as $k ) {
            if ( isset( $_POST[ $k ] ) ) {
                update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) );
            }
        }
        if ( isset( $_POST['_vw_event_location_addr'] ) ) {
            update_post_meta( $post_id, '_vw_event_location_addr', sanitize_textarea_field( wp_unslash( $_POST['_vw_event_location_addr'] ) ) );
        }
        if ( isset( $_POST['_vw_event_organizer_email'] ) ) {
            update_post_meta( $post_id, '_vw_event_organizer_email', sanitize_email( wp_unslash( $_POST['_vw_event_organizer_email'] ) ) );
        }
        if ( isset( $_POST['_vw_event_url'] ) ) {
            update_post_meta( $post_id, '_vw_event_url', esc_url_raw( wp_unslash( $_POST['_vw_event_url'] ) ) );
        }
        if ( isset( $_POST['_vw_event_repeat'] ) ) {
            $r = sanitize_key( wp_unslash( $_POST['_vw_event_repeat'] ) );
            update_post_meta( $post_id, '_vw_event_repeat', in_array( $r, [ 'none', 'daily', 'weekly', 'monthly' ], true ) ? $r : 'none' );
        }
        update_post_meta( $post_id, '_vw_event_all_day', ! empty( $_POST['_vw_event_all_day'] ) );

        if ( ! get_post_meta( $post_id, '_vw_event_source', true ) ) {
            update_post_meta( $post_id, '_vw_event_source', 'admin' );
        }
    }

    /* ---------- Liste ---------- */

    public static function columns( array $cols ): array {
        $new = [];
        foreach ( $cols as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['vw_start']    = __( 'Start', 'vw-events' );
                $new['vw_standort'] = __( 'Standort', 'vw-events' );
                $new['vw_source']   = __( 'Quelle', 'vw-events' );
            }
        }
        return $new;
    }

    public static function render_column( string $col, int $post_id ): void {
        if ( $col === 'vw_start' ) {
            echo wp_kses_post( vw_events_format_date_range(
                (string) get_post_meta( $post_id, '_vw_event_start', true ),
                (string) get_post_meta( $post_id, '_vw_event_end', true ),
                (bool)   get_post_meta( $post_id, '_vw_event_all_day', true ),
                '<br>'
            ) );
        } elseif ( $col === 'vw_standort' ) {
            $terms = wp_get_post_terms( $post_id, 'vw_standort', [ 'fields' => 'names' ] );
            echo esc_html( is_array( $terms ) ? implode( ', ', $terms ) : '—' );
        } elseif ( $col === 'vw_source' ) {
            echo esc_html( (string) ( get_post_meta( $post_id, '_vw_event_source', true ) ?: 'admin' ) );
        }
    }

    public static function sortable_columns( array $cols ): array {
        $cols['vw_start'] = 'vw_start';
        return $cols;
    }

    public static function sort_query( WP_Query $q ): void {
        if ( ! is_admin() || ! $q->is_main_query() ) { return; }
        if ( $q->get( 'orderby' ) === 'vw_start' ) {
            $q->set( 'meta_key', '_vw_event_start' );
            $q->set( 'orderby', 'meta_value' );
        }
    }

    public static function list_filters(): void {
        global $typenow;
        if ( $typenow !== 'vw_event' ) { return; }
        $tax = get_taxonomy( 'vw_standort' );
        if ( $tax ) {
            $sel = isset( $_GET['vw_standort'] ) ? sanitize_key( wp_unslash( $_GET['vw_standort'] ) ) : '';
            wp_dropdown_categories( [
                'show_option_all' => __( 'Alle Standorte', 'vw-events' ),
                'taxonomy'        => 'vw_standort',
                'name'            => 'vw_standort',
                'value_field'     => 'slug',
                'selected'        => $sel,
                'hide_empty'      => false,
            ] );
        }
    }

    /* ---------- Banner ---------- */

    public static function pending_banner( WP_Post $post ): void {
        if ( $post->post_type !== 'vw_event' || $post->post_status !== 'pending' ) { return; }
        $name = (string) get_post_meta( $post->ID, '_vw_event_submitter_name', true );
        $date = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post->post_date );
        echo '<div class="notice notice-warning"><p>'
            . esc_html( sprintf( __( 'Diese Veranstaltung wartet auf Freigabe. Eingereicht von %1$s am %2$s.', 'vw-events' ), $name ?: '—', $date ) )
            . '</p></div>';
    }

    /* ---------- Settings ---------- */

    public static function settings_page(): void {
        add_submenu_page(
            'edit.php?post_type=vw_event',
            __( 'Einstellungen', 'vw-events' ),
            __( 'Einstellungen', 'vw-events' ),
            'manage_options',
            'vw-events-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting( 'vw_events_settings_group', self::SETTINGS_OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
            'default'           => [],
        ] );
    }

    public static function sanitize_email_list( $input ): string {
        $parts = preg_split( '/[\s,;]+/', (string) $input ) ?: [];
        $valid = [];
        foreach ( $parts as $p ) {
            $e = sanitize_email( trim( $p ) );
            if ( $e && is_email( $e ) ) {
                $valid[] = $e;
            }
        }
        $valid = array_values( array_unique( $valid ) );
        return $valid ? implode( ', ', $valid ) : get_option( 'admin_email' );
    }

    public static function sanitize_settings( $input ): array {
        $out = [
            'admin_email'      => self::sanitize_email_list( $input['admin_email'] ?? '' ),
            'turnstile_site'   => sanitize_text_field( $input['turnstile_site'] ?? '' ),
            'turnstile_secret' => sanitize_text_field( $input['turnstile_secret'] ?? '' ),
            'archive_url'      => esc_url_raw( $input['archive_url'] ?? '' ),
            'submit_url'       => esc_url_raw( $input['submit_url'] ?? '' ),
            'master_blog_id'   => max( 0, (int) ( $input['master_blog_id'] ?? 0 ) ),
            'webhook_map'      => [],
        ];
        if ( ! empty( $input['webhook_map'] ) && is_array( $input['webhook_map'] ) ) {
            foreach ( $input['webhook_map'] as $slug => $url ) {
                $slug = sanitize_key( $slug );
                $url  = esc_url_raw( $url );
                if ( $slug && $url ) {
                    $out['webhook_map'][ $slug ] = $url;
                }
            }
        }
        return $out;
    }

    public static function render_settings_page(): void {
        $s = self::get_settings();
        $standorte = get_terms( [ 'taxonomy' => 'vw_standort', 'hide_empty' => false ] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'VW Events — Einstellungen', 'vw-events' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'vw_events_settings_group' ); ?>
                <table class="form-table">
                    <tr><th><label><?php esc_html_e( 'Admin-Benachrichtigungs-E-Mail', 'vw-events' ); ?></label></th>
                        <td><textarea class="large-text" rows="3" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[admin_email]" placeholder="info@example.com, redaktion@example.com"><?php echo esc_textarea( $s['admin_email'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Mehrere E-Mail-Adressen mit Komma, Semikolon oder Zeilenumbruch trennen. Alle erhalten die Benachrichtigung bei neuen Frontend-Einreichungen.', 'vw-events' ); ?></p></td></tr>
                    <tr><th><label><?php esc_html_e( 'Turnstile Site-Key', 'vw-events' ); ?></label></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[turnstile_site]" value="<?php echo esc_attr( $s['turnstile_site'] ); ?>"></td></tr>
                    <tr><th><label><?php esc_html_e( 'Turnstile Secret-Key', 'vw-events' ); ?></label></th>
                        <td><input type="password" class="regular-text" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[turnstile_secret]" value="<?php echo esc_attr( $s['turnstile_secret'] ); ?>"></td></tr>
                    <?php if ( is_multisite() ) : ?>
                    <tr><th><label><?php esc_html_e( 'Master-Blog-ID (Multisite)', 'vw-events' ); ?></label></th>
                        <td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[master_blog_id]" value="<?php echo esc_attr( (string) $s['master_blog_id'] ); ?>">
                        <p class="description"><?php printf(
                            esc_html__( 'Aktuelle Blog-ID: %d. Auf Subsites die ID des Master-Blogs eintragen, dann werden Events von dort gelesen. Auf dem Master-Blog leer lassen oder die eigene ID eintragen.', 'vw-events' ),
                            (int) get_current_blog_id()
                        ); ?></p></td></tr>
                    <?php endif; ?>
                    <tr><th><label><?php esc_html_e( 'Übersichtsseite (URL)', 'vw-events' ); ?></label></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[archive_url]" value="<?php echo esc_attr( $s['archive_url'] ); ?>" placeholder="/leben-freizeit/veranstaltungen/">
                        <p class="description"><?php esc_html_e( 'WP-Seite mit dem Shortcode [vw_events_list]. Wenn gesetzt, wird /veranstaltungen/ dorthin weitergeleitet und der „Zurück"-Link auf Detailseiten zeigt dorthin.', 'vw-events' ); ?></p></td></tr>
                    <tr><th><label><?php esc_html_e( 'Einreichungs-Seite (URL)', 'vw-events' ); ?></label></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[submit_url]" value="<?php echo esc_attr( $s['submit_url'] ); ?>" placeholder="https://vv-wildenstein.com/event-einreichen/">
                        <p class="description"><?php esc_html_e( 'Volle URL der Seite mit dem [vw_event_submit]-Form (sollte nur auf der Master-Site liegen). Auf Subsites zeigt der Shortcode dann nur einen Hinweis + Button zu dieser URL — der Upload läuft ausschließlich auf dem Master.', 'vw-events' ); ?></p></td></tr>
                </table>
                <h2><?php esc_html_e( 'Cloudflare Deploy-Hooks pro Standort', 'vw-events' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Eine Webhook-URL pro Standort. Bei Veröffentlichung wird der jeweilige Hook ausgelöst. „verband-weit" triggert alle Hooks.', 'vw-events' ); ?></p>
                <table class="form-table">
                    <?php if ( is_array( $standorte ) ) : foreach ( $standorte as $term ) : ?>
                    <tr><th><label><?php echo esc_html( $term->name ); ?> <code><?php echo esc_html( $term->slug ); ?></code></label></th>
                        <td><input type="url" class="regular-text" name="<?php echo esc_attr( self::SETTINGS_OPTION ); ?>[webhook_map][<?php echo esc_attr( $term->slug ); ?>]" value="<?php echo esc_attr( $s['webhook_map'][ $term->slug ] ?? '' ); ?>" placeholder="https://api.cloudflare.com/..."></td></tr>
                    <?php endforeach; endif; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ---------- Dashboard-Widget ---------- */

    public static function dashboard_widget(): void {
        if ( ! current_user_can( 'edit_posts' ) ) { return; }
        $count = (int) wp_count_posts( 'vw_event' )->pending;
        if ( $count < 1 ) { return; }
        wp_add_dashboard_widget( 'vw_events_pending', __( 'Eingereichte Veranstaltungen', 'vw-events' ), static function () use ( $count ) {
            $url = admin_url( 'edit.php?post_status=pending&post_type=vw_event' );
            echo '<p>' . esc_html( sprintf( _n( '%d Event wartet auf Freigabe.', '%d Events warten auf Freigabe.', $count, 'vw-events' ), $count ) )
                . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Jetzt prüfen →', 'vw-events' ) . '</a></p>';
        } );
    }
}
