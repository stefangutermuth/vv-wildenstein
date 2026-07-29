<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class VW_Melder_Admin_UI {

    /** Status-Slug → Farbe (wie im Frontend). */
    public const STATUS_COLORS = [
        'neu'            => '#2a3196',
        'in-bearbeitung' => '#ec7d20',
        'erledigt'       => '#0a5f2b',
    ];

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_vw_meldung', [ __CLASS__, 'save_meta' ], 10, 2 );

        add_filter( 'manage_vw_meldung_posts_columns', [ __CLASS__, 'columns' ], 9999 );
        add_action( 'manage_vw_meldung_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
        add_action( 'admin_footer-edit.php', [ __CLASS__, 'list_css' ] );

        // Editor: klassisch erzwingen, kein Block-Editor, keinen Page-Builder.
        add_filter( 'use_block_editor_for_post_type', [ __CLASS__, 'force_classic' ], 10, 2 );
        // Fremde Metaboxen (WPBakery, Rank Math, Autor, Buffer, Complianz, Seitenlayout …)
        // auf der Meldungs-Seite ausblenden — nur unsere + Kern-Boxen bleiben.
        add_action( 'add_meta_boxes_vw_meldung', [ __CLASS__, 'prune_meta_boxes' ], PHP_INT_MAX );

        // Leaflet für die Standort-Karte im Editor.
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );

        // Zähler-Blase am Menü „Mängelmelder“ für neue (ausstehende) Meldungen.
        add_action( 'admin_menu', [ __CLASS__, 'menu_badge' ], 999 );
    }

    /** Anzahl neuer/ausstehender Meldungen (warten auf Freigabe). */
    public static function pending_count(): int {
        $counts = wp_count_posts( 'vw_meldung' );
        return (int) ( $counts->pending ?? 0 );
    }

    public static function menu_badge(): void {
        $pending = self::pending_count();
        if ( $pending < 1 ) {
            return;
        }
        global $menu, $submenu;
        $slug   = 'edit.php?post_type=vw_meldung';
        $bubble = sprintf(
            ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
            $pending
        );
        // Top-Level-Menü
        foreach ( (array) $menu as $i => $item ) {
            if ( isset( $item[2] ) && $item[2] === $slug ) {
                $menu[ $i ][0] .= $bubble;
                break;
            }
        }
        // Untermenü „Alle Meldungen“ (Slug == Parent-Slug)
        if ( ! empty( $submenu[ $slug ] ) ) {
            foreach ( $submenu[ $slug ] as $i => $sub ) {
                if ( isset( $sub[2] ) && $sub[2] === $slug ) {
                    $submenu[ $slug ][ $i ][0] .= $bubble;
                    break;
                }
            }
        }
    }

    /* ---------- Editor klassisch halten ---------- */

    public static function force_classic( $use, $post_type ) {
        return $post_type === 'vw_meldung' ? false : $use;
    }

    /**
     * Lässt auf der Meldungs-Seite nur eine Positivliste an Metaboxen zu und
     * entfernt den Rest (WPBakery, Rank Math, Autor, WP-to-Buffer, Complianz,
     * Seitenlayout usw.). Der eigentliche Text-Editor ist keine Metabox und bleibt.
     */
    public static function prune_meta_boxes(): void {
        global $wp_meta_boxes;
        if ( empty( $wp_meta_boxes['vw_meldung'] ) ) {
            return;
        }
        $keep = [
            'submitdiv',          // Veröffentlichen
            'slugdiv',            // Titelform/Slug (harmlos)
            'postimagediv',       // Beitragsbild
            'tagsdiv-vw_anliegen',// Anliegen
            'vw_meldung_data',
            'vw_meldung_status_box',
            'vw_meldung_communication',
            'vw_meldung_public_notes',
            'vw_meldung_export',
        ];
        foreach ( $wp_meta_boxes['vw_meldung'] as $context => $priorities ) {
            foreach ( $priorities as $boxes ) {
                if ( ! is_array( $boxes ) ) {
                    continue;
                }
                foreach ( array_keys( $boxes ) as $id ) {
                    if ( ! in_array( $id, $keep, true ) ) {
                        remove_meta_box( $id, 'vw_meldung', $context );
                    }
                }
            }
        }
    }

    /* ---------- Assets ---------- */

    public static function enqueue_admin( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'vw_meldung' ) {
            return;
        }

        wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4' );
        wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true );

        $icon = esc_url( set_url_scheme( VW_MELDER_URL . 'assets/marker-neu.png' ) );
        $js   = <<<JS
(function(){
  if (typeof L === 'undefined') return;
  var el = document.getElementById('vw-meldung-map');
  if (!el) return;
  var latI = document.getElementById('_vw_meldung_lat');
  var lngI = document.getElementById('_vw_meldung_lng');
  var read = function(i){ return parseFloat(String(i.value||'').replace(',', '.')); };
  var lat = read(latI), lng = read(lngI);
  var has = !isNaN(lat) && !isNaN(lng);
  var center = has ? [lat, lng] : [50.773, 13.172];
  var map = L.map(el).setView(center, has ? 16 : 13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 19 }).addTo(map);
  var icon = L.icon({ iconUrl: '$icon', iconSize: [38, 38], iconAnchor: [19, 36] });
  var marker = null;
  var write = function(la, ln){ latI.value = la.toFixed(6); lngI.value = ln.toFixed(6); };
  var place = function(la, ln){
    if (marker) { marker.setLatLng([la, ln]); }
    else {
      marker = L.marker([la, ln], { icon: icon, draggable: true }).addTo(map);
      marker.on('dragend', function(){ var p = marker.getLatLng(); write(p.lat, p.lng); });
    }
    write(la, ln);
  };
  if (has) place(lat, lng);
  map.on('click', function(e){ place(e.latlng.lat, e.latlng.lng); });
  setTimeout(function(){ map.invalidateSize(); }, 250);
})();
JS;
        wp_add_inline_script( 'leaflet', $js );

        // Status-Dropdown: robuste Event-Delegation (läuft unabhängig vom Lade-Zeitpunkt).
        wp_enqueue_script( 'jquery' );
        $dd = <<<JS
document.addEventListener('click', function(e){
  var opt = e.target.closest ? e.target.closest('.vw-status-option') : null;
  if (opt) {
    var dd = opt.closest('.vw-status-dd');
    dd.querySelector('#vw-status-value').value = opt.dataset.slug;
    var pill = opt.querySelector('span');
    if (pill) dd.querySelector('.vw-status-current').innerHTML = pill.outerHTML;
    dd.querySelectorAll('.vw-status-option').forEach(function(o){ o.setAttribute('aria-selected', o === opt ? 'true' : 'false'); });
    var menu = dd.querySelector('.vw-status-menu');
    menu.hidden = true;
    dd.querySelector('.vw-status-trigger').setAttribute('aria-expanded','false');
    return;
  }
  var trig = e.target.closest ? e.target.closest('.vw-status-trigger') : null;
  if (trig) {
    e.preventDefault();
    var dd = trig.closest('.vw-status-dd');
    var menu = dd.querySelector('.vw-status-menu');
    var willOpen = menu.hidden;
    document.querySelectorAll('.vw-status-menu').forEach(function(m){ m.hidden = true; });
    menu.hidden = !willOpen;
    trig.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    return;
  }
  if (!e.target.closest || !e.target.closest('.vw-status-dd')) {
    document.querySelectorAll('.vw-status-menu').forEach(function(m){ m.hidden = true; });
  }
});
JS;
        wp_add_inline_script( 'jquery', $dd );
    }

    /* ---------- Metabox ---------- */

    public static function add_meta_boxes(): void {
        add_meta_box(
            'vw_meldung_data',
            __( 'Meldungs-Daten', 'vw-melder' ),
            [ __CLASS__, 'render_metabox' ],
            'vw_meldung',
            'normal',
            'high'
        );

        // Standard-„Schlagwörter"-Box des Status durch eigene Pillen-Auswahl ersetzen.
        remove_meta_box( 'tagsdiv-vw_meldung_status', 'vw_meldung', 'side' );
        add_meta_box(
            'vw_meldung_status_box',
            __( 'Status', 'vw-melder' ),
            [ __CLASS__, 'render_status_box' ],
            'vw_meldung',
            'side',
            'high'
        );
    }

    public static function render_status_box( WP_Post $post ): void {
        $current = wp_get_post_terms( $post->ID, 'vw_meldung_status', [ 'fields' => 'slugs' ] );
        $current = ( is_array( $current ) && $current ) ? $current[0] : 'neu';
        $defaults = VW_Melder_Helpers::STATUS_DEFAULTS;
        $current_name = $defaults[ $current ] ?? reset( $defaults );
        ?>
        <div class="vw-status-dd">
            <input type="hidden" name="vw_meldung_status" id="vw-status-value" value="<?php echo esc_attr( $current ); ?>">
            <button type="button" class="vw-status-trigger" aria-haspopup="listbox" aria-expanded="false">
                <span class="vw-status-current"><?php echo self::pill_markup( $current, $current_name ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                <span class="vw-status-caret" aria-hidden="true">▾</span>
            </button>
            <ul class="vw-status-menu" role="listbox" hidden>
                <?php foreach ( $defaults as $slug => $name ) : ?>
                    <li role="option" class="vw-status-option" data-slug="<?php echo esc_attr( $slug ); ?>" data-name="<?php echo esc_attr( $name ); ?>" aria-selected="<?php echo $slug === $current ? 'true' : 'false'; ?>">
                        <?php echo self::pill_markup( $slug, $name ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <style>
            .vw-status-dd { position:relative; }
            .vw-status-trigger { display:flex; align-items:center; justify-content:space-between; gap:8px;
                width:100%; background:#fff; border:1px solid #8c8f94; border-radius:4px; padding:6px 8px; cursor:pointer; }
            .vw-status-trigger:hover { border-color:#0a5f2b; }
            .vw-status-caret { color:#646970; }
            .vw-status-menu { position:absolute; z-index:10; left:0; right:0; margin:4px 0 0; padding:4px; list-style:none;
                background:#fff; border:1px solid #c3c4c7; border-radius:4px; box-shadow:0 4px 14px rgba(0,0,0,.12); }
            .vw-status-option { padding:4px; border-radius:6px; cursor:pointer; }
            .vw-status-option:hover { background:#f0f0f1; }

            /* Pillen voll breit + größer (Inline-Styles per !important überschreiben) */
            .vw-status-trigger .vw-status-current { flex:1; }
            .vw-status-dd .vw-status-current > span,
            .vw-status-dd .vw-status-option > span {
                display:flex !important;
                width:100%;
                box-sizing:border-box;
                justify-content:flex-start;
                gap:8px !important;
                padding:9px 16px !important;
                font-size:14px !important;
            }
            .vw-status-dd .vw-status-current > span img,
            .vw-status-dd .vw-status-option > span img {
                width:20px !important;
                height:20px !important;
            }
        </style>
        <?php
    }

    public static function render_metabox( WP_Post $post ): void {
        wp_nonce_field( 'vw_meldung_save_meta', 'vw_meldung_nonce' );
        $get    = static fn( $k ) => esc_attr( (string) get_post_meta( $post->ID, $k, true ) );
        $notify = (bool) get_post_meta( $post->ID, '_vw_meldung_notify', true );
        $source = (string) ( get_post_meta( $post->ID, '_vw_meldung_source', true ) ?: 'admin' );
        ?>
        <table class="form-table">
            <tr><th colspan="2"><strong><?php esc_html_e( 'Standort', 'vw-melder' ); ?></strong></th></tr>
            <tr><th><label for="_vw_meldung_lat"><?php esc_html_e( 'Breitengrad (lat)', 'vw-melder' ); ?></label></th>
                <td><input type="text" name="_vw_meldung_lat" id="_vw_meldung_lat" value="<?php echo $get( '_vw_meldung_lat' ); ?>" class="regular-text" placeholder="50.7688"></td></tr>
            <tr><th><label for="_vw_meldung_lng"><?php esc_html_e( 'Längengrad (lng)', 'vw-melder' ); ?></label></th>
                <td><input type="text" name="_vw_meldung_lng" id="_vw_meldung_lng" value="<?php echo $get( '_vw_meldung_lng' ); ?>" class="regular-text" placeholder="13.1579"></td></tr>
            <tr><th><label for="_vw_meldung_address"><?php esc_html_e( 'Adresse', 'vw-melder' ); ?></label></th>
                <td><textarea name="_vw_meldung_address" id="_vw_meldung_address" rows="2" class="large-text"><?php echo esc_textarea( (string) get_post_meta( $post->ID, '_vw_meldung_address', true ) ); ?></textarea></td></tr>
            <tr><th><label for="_vw_meldung_city"><?php esc_html_e( 'Ort', 'vw-melder' ); ?></label></th>
                <td><input type="text" name="_vw_meldung_city" id="_vw_meldung_city" value="<?php echo $get( '_vw_meldung_city' ); ?>" class="regular-text"></td></tr>
            <tr><th><label for="_vw_meldung_postcode"><?php esc_html_e( 'PLZ', 'vw-melder' ); ?></label></th>
                <td><input type="text" name="_vw_meldung_postcode" id="_vw_meldung_postcode" value="<?php echo $get( '_vw_meldung_postcode' ); ?>"></td></tr>
            <tr><th><?php esc_html_e( 'Karte', 'vw-melder' ); ?></th>
                <td>
                    <div id="vw-meldung-map" style="height:320px;max-width:640px;border:1px solid #c3c4c7;border-radius:6px;"></div>
                    <p class="description"><?php esc_html_e( 'Pin zeigt den gemeldeten Standort. Auf die Karte klicken oder Pin ziehen, um die Koordinaten anzupassen.', 'vw-melder' ); ?></p>
                </td></tr>

            <tr><th colspan="2"><strong><?php esc_html_e( 'Melder (intern, nicht öffentlich)', 'vw-melder' ); ?></strong></th></tr>
            <tr><th><label for="_vw_meldung_reporter_name"><?php esc_html_e( 'Name', 'vw-melder' ); ?></label></th>
                <td><input type="text" name="_vw_meldung_reporter_name" id="_vw_meldung_reporter_name" value="<?php echo $get( '_vw_meldung_reporter_name' ); ?>" class="regular-text"></td></tr>
            <tr><th><label for="_vw_meldung_reporter_email"><?php esc_html_e( 'E-Mail', 'vw-melder' ); ?></label></th>
                <td><input type="email" name="_vw_meldung_reporter_email" id="_vw_meldung_reporter_email" value="<?php echo $get( '_vw_meldung_reporter_email' ); ?>" class="regular-text"></td></tr>
            <tr><th><?php esc_html_e( 'Update-Nachrichten', 'vw-melder' ); ?></th>
                <td><label><input type="checkbox" name="_vw_meldung_notify" value="1" <?php checked( $notify ); ?>> <?php esc_html_e( 'Melder möchte bei Statusänderung benachrichtigt werden', 'vw-melder' ); ?></label></td></tr>

            <tr><th><?php esc_html_e( 'Quelle', 'vw-melder' ); ?></th>
                <td><code><?php echo esc_html( $source ); ?></code>
                <p class="description"><?php esc_html_e( 'Status & Anliegen werden rechts über die Kategorien gesetzt.', 'vw-melder' ); ?></p></td></tr>
        </table>
        <?php
    }

    public static function save_meta( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['vw_meldung_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vw_meldung_nonce'] ) ), 'vw_meldung_save_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $text_keys = [
            '_vw_meldung_lat',
            '_vw_meldung_lng',
            '_vw_meldung_address',
            '_vw_meldung_city',
            '_vw_meldung_postcode',
            '_vw_meldung_reporter_name',
            '_vw_meldung_reporter_email',
        ];
        foreach ( $text_keys as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $key, wp_unslash( $_POST[ $key ] ) );
            }
        }
        update_post_meta( $post_id, '_vw_meldung_notify', isset( $_POST['_vw_meldung_notify'] ) ? 1 : 0 );

        // Status aus eigener Pillen-Auswahl speichern (ersetzt die Standard-Tag-Box).
        if ( isset( $_POST['vw_meldung_status'] ) ) {
            $status = sanitize_key( wp_unslash( $_POST['vw_meldung_status'] ) );
            if ( array_key_exists( $status, VW_Melder_Helpers::STATUS_DEFAULTS ) ) {
                wp_set_post_terms( $post_id, [ $status ], 'vw_meldung_status', false );
            }
        }
    }

    /* ---------- List columns ---------- */

    /** Key der Seitenaufrufe-Spalte (dynamisch erkannt), für die schmale Breite. */
    private static ?string $views_col = null;

    public static function columns( array $cols ): array {
        // Autor + Rank-Math-SEO-Spalten entfernen.
        unset( $cols['author'] );
        foreach ( array_keys( $cols ) as $key ) {
            if ( strpos( (string) $key, 'rank_math' ) === 0 ) {
                unset( $cols[ $key ] );
            }
        }

        // Seitenaufrufe-Spalte erkennen (Koko o. ä.) und ans Ende verschieben.
        $views_key = null;
        $views_label = null;
        foreach ( $cols as $key => $label ) {
            if ( mb_stripos( (string) $label, 'aufrufe' ) !== false || strpos( (string) $key, 'koko' ) !== false ) {
                $views_key   = $key;
                $views_label = $label;
                break;
            }
        }
        if ( $views_key !== null ) {
            unset( $cols[ $views_key ] );
        }

        // Status-Pille + Adresse direkt nach dem Titel; „Antworten"-Zähler vor dem Datum.
        $new = [];
        foreach ( $cols as $key => $label ) {
            if ( $key === 'date' ) {
                $new['vw_antworten'] = '<span class="dashicons dashicons-admin-comments" title="' . esc_attr__( 'Öffentliche Antworten', 'vw-melder' ) . '"></span><span class="screen-reader-text">' . esc_html__( 'Antworten', 'vw-melder' ) . '</span>';
            }
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['vw_status']  = __( 'Status', 'vw-melder' );
                $new['vw_adresse'] = __( 'Adresse', 'vw-melder' );
            }
        }

        // Seitenaufrufe als letzte Spalte.
        if ( $views_key !== null ) {
            $new[ $views_key ] = $views_label;
            self::$views_col   = (string) $views_key;
        }

        return $new;
    }

    public static function render_column( string $col, int $post_id ): void {
        if ( $col === 'vw_status' ) {
            echo self::status_pill( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        if ( $col === 'vw_adresse' ) {
            $addr = trim( (string) get_post_meta( $post_id, '_vw_meldung_address', true ) );
            if ( $addr === '' ) {
                $addr = trim( (string) get_post_meta( $post_id, '_vw_meldung_city', true ) );
            }
            echo $addr !== '' ? esc_html( $addr ) : '—';
            return;
        }
        if ( $col === 'vw_antworten' ) {
            $count = count( VW_Melder_Public_Notes::get_notes( $post_id ) );
            if ( $count > 0 ) {
                printf(
                    '<span title="%1$s" style="display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:22px;padding:0 7px;background:#646970;color:#fff;border-radius:6px;font-size:12px;font-weight:600;line-height:1;">%2$d</span>',
                    esc_attr__( 'Öffentliche Antworten', 'vw-melder' ),
                    $count
                );
            } else {
                echo '<span style="color:#c3c4c7">—</span>';
            }
        }
    }

    /** Status schmaler halten, Seitenaufrufe-Spalte breit genug für das ganze Wort. */
    public static function list_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-vw_meldung' ) {
            return;
        }
        $css = '.wp-list-table .column-vw_status{width:150px}'
            . '.wp-list-table .column-vw_antworten{width:48px;text-align:center}'
            . '.wp-list-table .column-vw_antworten .dashicons{color:#646970}';
        if ( self::$views_col !== null ) {
            $css .= sprintf(
                '.wp-list-table .column-%1$s{width:110px;text-align:center;white-space:nowrap}',
                esc_attr( self::$views_col )
            );
        }
        printf( '<style>%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /** Status als farbige Pille mit Marker-Icon (wie im Frontend). */
    public static function status_pill( int $post_id ): string {
        $terms = wp_get_post_terms( $post_id, 'vw_meldung_status' );
        if ( is_wp_error( $terms ) || ! $terms ) {
            return '<span style="color:#646970">—</span>';
        }
        return self::pill_markup( $terms[0]->slug, $terms[0]->name );
    }

    /** Baut eine farbige Status-Pille mit Marker-Icon (für Liste + Status-Auswahl). */
    public static function pill_markup( string $slug, string $name ): string {
        $color = self::STATUS_COLORS[ $slug ] ?? '#646970';
        $icon  = esc_url( set_url_scheme( VW_MELDER_URL . 'assets/marker-' . $slug . '.png' ) );

        return sprintf(
            '<span style="display:inline-flex;align-items:center;gap:5px;background:%1$s;color:#fff;padding:3px 10px 3px 6px;border-radius:999px;font-size:12px;font-weight:600;line-height:1;white-space:nowrap;">'
            . '<img src="%2$s" width="16" height="16" alt="" style="display:block">%3$s</span>',
            esc_attr( $color ),
            $icon,
            esc_html( $name )
        );
    }
}
