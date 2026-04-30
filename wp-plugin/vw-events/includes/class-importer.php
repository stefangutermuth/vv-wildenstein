<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Events-Manager → vw_event Importer.
 * Batch-fähig: 50 Events pro AJAX-Roundtrip.
 * Idempotent: jedes importierte Event trägt _vw_event_em_id und wird beim
 * erneuten Import übersprungen.
 */
final class VW_Events_Importer {

    public const BATCH_SIZE   = 50;
    public const MAP_OPTION   = 'vw_events_import_map';
    public const META_EM_ID   = '_vw_event_em_id';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'wp_ajax_vw_events_import_batch', [ __CLASS__, 'ajax_batch' ] );
        add_action( 'admin_post_vw_events_save_map', [ __CLASS__, 'save_map' ] );
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=vw_event',
            __( 'Events Manager Import', 'vw-events' ),
            __( 'Import', 'vw-events' ),
            'manage_options',
            'vw-events-import',
            [ __CLASS__, 'render_page' ]
        );
    }

    private static function em_table_exists(): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'em_events';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
    }

    private static function count_em_events(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}em_events" );
    }

    private static function count_imported(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META_EM_ID
        ) );
    }

    private static function unique_towns(): array {
        global $wpdb;
        $rows = $wpdb->get_col( "SELECT DISTINCT location_town FROM {$wpdb->prefix}em_locations WHERE location_town <> '' ORDER BY location_town" );
        return is_array( $rows ) ? $rows : [];
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        if ( ! self::em_table_exists() ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Events Manager Import', 'vw-events' ) . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Tabelle wp_em_events nicht gefunden. Ist Events Manager installiert?', 'vw-events' ) . '</p></div></div>';
            return;
        }

        $total    = self::count_em_events();
        $imported = self::count_imported();
        $remaining = max( 0, $total - $imported );
        $towns    = self::unique_towns();
        $map      = (array) get_option( self::MAP_OPTION, [] );

        $standorte = get_terms( [ 'taxonomy' => 'vw_standort', 'hide_empty' => false ] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Events Manager Import', 'vw-events' ); ?></h1>
            <p><?php printf( esc_html__( 'Gefunden: %1$d Events · Bereits importiert: %2$d · Offen: %3$d', 'vw-events' ),
                $total, $imported, $remaining ); ?></p>

            <h2><?php esc_html_e( '1. Standort-Mapping', 'vw-events' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Ordne jeden Ort aus den alten EM-Locations einem Standort-Term zu. „— Ignorieren —" überspringt diese Events nicht, weist aber keinen Standort zu.', 'vw-events' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="vw_events_save_map">
                <?php wp_nonce_field( 'vw_events_save_map' ); ?>
                <table class="widefat striped" style="max-width: 720px;">
                    <thead><tr><th><?php esc_html_e( 'EM-Ort (location_town)', 'vw-events' ); ?></th><th><?php esc_html_e( 'Standort', 'vw-events' ); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ( $towns as $town ) :
                        $key      = sanitize_key( str_replace( ' ', '-', strtolower( $town ) ) );
                        $current  = $map[ $town ] ?? '';
                        if ( $current === '' ) {
                            // auto-suggest from slug match
                            foreach ( $standorte as $term ) {
                                if ( $term->slug === $key ) { $current = $term->slug; break; }
                            }
                        }
                    ?>
                        <tr>
                            <td><?php echo esc_html( $town ); ?></td>
                            <td>
                                <select name="map[<?php echo esc_attr( $town ); ?>]">
                                    <option value=""><?php esc_html_e( '— Ignorieren —', 'vw-events' ); ?></option>
                                    <?php foreach ( $standorte as $term ) : ?>
                                        <option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Mapping speichern', 'vw-events' ); ?></button></p>
            </form>

            <h2><?php esc_html_e( '2. Import starten', 'vw-events' ); ?></h2>
            <p>
                <button type="button" class="button button-primary" id="vw-import-start"<?php echo $remaining < 1 ? ' disabled' : ''; ?>>
                    <?php esc_html_e( 'Import starten', 'vw-events' ); ?>
                </button>
                <span id="vw-import-status" style="margin-left:1rem;"></span>
            </p>
            <progress id="vw-import-progress" max="<?php echo (int) $total; ?>" value="<?php echo (int) $imported; ?>" style="width: 480px; height: 20px;"></progress>
            <pre id="vw-import-log" style="background:#fff;border:1px solid #ddd;padding:1rem;max-height:280px;overflow:auto;margin-top:1rem;display:none;"></pre>

            <script>
            (function () {
                const btn = document.getElementById('vw-import-start');
                const status = document.getElementById('vw-import-status');
                const log = document.getElementById('vw-import-log');
                const prog = document.getElementById('vw-import-progress');
                if (!btn) return;

                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    log.style.display = 'block';
                    log.textContent = '';
                    let offset = 0;
                    let totalDone = parseInt(prog.value, 10) || 0;
                    while (true) {
                        status.textContent = 'Importiere…';
                        const fd = new FormData();
                        fd.append('action', 'vw_events_import_batch');
                        fd.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'vw_events_import_batch' ) ); ?>');
                        fd.append('offset', offset);
                        const r = await fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
                        const data = await r.json().catch(() => ({}));
                        if (!data.ok) {
                            log.textContent += '\nFehler: ' + (data.message || 'Unbekannt');
                            status.textContent = 'Fehler';
                            btn.disabled = false;
                            return;
                        }
                        log.textContent += data.log || '';
                        totalDone += data.imported || 0;
                        prog.value = totalDone;
                        offset = data.next_offset;
                        if (data.done) break;
                    }
                    status.textContent = 'Fertig.';
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function save_map(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
        check_admin_referer( 'vw_events_save_map' );
        $map = (array) ( $_POST['map'] ?? [] );
        $clean = [];
        foreach ( $map as $town => $slug ) {
            $town = sanitize_text_field( wp_unslash( $town ) );
            $slug = sanitize_key( wp_unslash( $slug ) );
            if ( $town !== '' ) {
                $clean[ $town ] = $slug;
            }
        }
        update_option( self::MAP_OPTION, $clean, false );
        wp_safe_redirect( admin_url( 'edit.php?post_type=vw_event&page=vw-events-import&saved=1' ) );
        exit;
    }

    public static function ajax_batch(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json( [ 'ok' => false, 'message' => 'forbidden' ] ); }
        check_ajax_referer( 'vw_events_import_batch' );

        global $wpdb;
        $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, l.location_name, l.location_address, l.location_town, l.location_postcode, l.location_state
             FROM {$wpdb->prefix}em_events e
             LEFT JOIN {$wpdb->prefix}em_locations l ON l.location_id = e.location_id
             ORDER BY e.event_id ASC
             LIMIT %d OFFSET %d",
            self::BATCH_SIZE, $offset
        ) );

        if ( empty( $rows ) ) {
            wp_send_json( [ 'ok' => true, 'imported' => 0, 'next_offset' => $offset, 'done' => true, 'log' => "Keine weiteren Events.\n" ] );
        }

        $map      = (array) get_option( self::MAP_OPTION, [] );
        $imported = 0;
        $skipped  = 0;
        $log      = '';

        foreach ( $rows as $row ) {
            $existing = self::find_existing( (int) $row->event_id );
            if ( $existing ) {
                $skipped++;
                continue;
            }

            $new_id = self::import_single( $row, $map );
            if ( is_wp_error( $new_id ) ) {
                $log .= sprintf( "FEHLER #%d: %s\n", $row->event_id, $new_id->get_error_message() );
                continue;
            }
            $imported++;
            $log .= sprintf( "✓ #%d → vw_event #%d (%s)\n", $row->event_id, $new_id, $row->event_name );
        }

        $next_offset = $offset + count( $rows );
        $done        = count( $rows ) < self::BATCH_SIZE;

        $log = sprintf( "Batch (offset %d): %d importiert, %d übersprungen.\n", $offset, $imported, $skipped ) . $log;

        wp_send_json( [
            'ok'          => true,
            'imported'    => $imported,
            'skipped'     => $skipped,
            'next_offset' => $next_offset,
            'done'        => $done,
            'log'         => $log,
        ] );
    }

    private static function find_existing( int $em_id ): ?int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_EM_ID, (string) $em_id
        ) );
        return $id ? (int) $id : null;
    }

    private static function import_single( object $row, array $map ) {
        $title       = (string) $row->event_name;
        $slug        = (string) ( $row->event_slug ?? '' );
        $all_day     = ! empty( $row->event_all_day );
        $start_date  = (string) $row->event_start_date;
        $start_time  = (string) ( $row->event_start_time ?: '00:00:00' );
        $end_date    = (string) $row->event_end_date;
        $end_time    = (string) ( $row->event_end_time ?: '00:00:00' );

        $start = $start_date && $start_date !== '0000-00-00'
            ? $start_date . 'T' . substr( $start_time, 0, 5 )
            : '';
        $end = $end_date && $end_date !== '0000-00-00' && $end_date !== $start_date
            ? $end_date . 'T' . substr( $end_time, 0, 5 )
            : ( $end_time && $end_time !== $start_time && $start_date
                ? $start_date . 'T' . substr( $end_time, 0, 5 )
                : '' );

        // status mapping
        $em_status   = (string) ( $row->event_status ?? '1' );
        $post_status = $em_status === '1' ? 'publish' : 'draft';

        // Inhalt aus verknüpftem WP-Post holen, falls vorhanden
        $content   = '';
        $thumb_id  = 0;
        if ( ! empty( $row->post_id ) ) {
            $src = get_post( (int) $row->post_id );
            if ( $src ) {
                $content  = (string) $src->post_content;
                $thumb_id = (int) get_post_thumbnail_id( $src );
                if ( $post_status === 'publish' && $src->post_status !== 'publish' ) {
                    $post_status = $src->post_status === 'pending' ? 'pending' : 'draft';
                }
            }
        }

        $post_id = wp_insert_post( [
            'post_type'    => 'vw_event',
            'post_status'  => $post_status,
            'post_title'   => $title !== '' ? $title : '(ohne Titel)',
            'post_name'    => $slug,
            'post_content' => $content,
            'post_date'    => $row->event_date_created ?? '',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( $start !== '' )    update_post_meta( $post_id, '_vw_event_start', $start );
        if ( $end !== '' )      update_post_meta( $post_id, '_vw_event_end', $end );
        update_post_meta( $post_id, '_vw_event_all_day', (bool) $all_day );
        update_post_meta( $post_id, '_vw_event_repeat', 'none' );
        if ( ! empty( $row->location_name ) )    update_post_meta( $post_id, '_vw_event_location_name', $row->location_name );

        $addr_parts = array_filter( [
            $row->location_address ?? '',
            trim( ( $row->location_postcode ?? '' ) . ' ' . ( $row->location_town ?? '' ) ),
        ] );
        if ( $addr_parts ) {
            update_post_meta( $post_id, '_vw_event_location_addr', implode( "\n", $addr_parts ) );
        }

        update_post_meta( $post_id, '_vw_event_source', 'admin' );
        update_post_meta( $post_id, self::META_EM_ID, (string) $row->event_id );

        // Standort-Taxonomie
        $town = (string) ( $row->location_town ?? '' );
        if ( $town !== '' && ! empty( $map[ $town ] ) ) {
            wp_set_object_terms( $post_id, [ $map[ $town ] ], 'vw_standort', false );
        }

        // Featured Image
        if ( $thumb_id ) {
            set_post_thumbnail( $post_id, $thumb_id );
        }

        return (int) $post_id;
    }
}
