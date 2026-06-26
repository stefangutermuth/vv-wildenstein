<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { return; }

/**
 * WP-CLI: Migration der Bestands-Meldungen aus melder.vv-wildenstein.com.
 *
 * Quelle exportieren (auf dem alten Melder):
 *   wp eval-file export-meldungen.php > meldungen.json
 *
 * Ziel importieren (auf vv-wildenstein.com):
 *   wp vw-melder import meldungen.json --images
 */
final class VW_Melder_Importer {

    /** Marker-Meta, damit der Import idempotent bleibt. */
    private const SRC_ID_META = '_vw_meldung_import_src_id';

    public static function register(): void {
        WP_CLI::add_command( 'vw-melder import', [ __CLASS__, 'cmd_import' ] );
    }

    /**
     * ## OPTIONS
     *
     * <file>
     * : Pfad zur JSON-Datei (Array von Meldungen).
     *
     * [--images]
     * : Fotos per URL nachladen und als Beitragsbild setzen.
     *
     * [--dry-run]
     * : Nur anzeigen, nichts schreiben.
     */
    public static function cmd_import( array $args, array $assoc ): void {
        $file    = $args[0] ?? '';
        $images  = isset( $assoc['images'] );
        $dry_run = isset( $assoc['dry-run'] );

        if ( ! is_readable( $file ) ) {
            WP_CLI::error( "Datei nicht lesbar: $file" );
        }
        $rows = json_decode( (string) file_get_contents( $file ), true );
        if ( ! is_array( $rows ) ) {
            WP_CLI::error( 'JSON konnte nicht gelesen werden.' );
        }

        if ( $images ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $created = 0; $skipped = 0; $img_ok = 0; $img_fail = 0;

        foreach ( $rows as $row ) {
            $src_id = (int) ( $row['source_id'] ?? 0 );
            $title  = html_entity_decode( (string) ( $row['title'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            // Idempotenz: schon importiert?
            if ( $src_id ) {
                $existing = get_posts( [
                    'post_type'   => 'vw_meldung',
                    'post_status' => 'any',
                    'meta_key'    => self::SRC_ID_META,
                    'meta_value'  => (string) $src_id,
                    'fields'      => 'ids',
                    'numberposts' => 1,
                ] );
                if ( $existing ) {
                    WP_CLI::log( "↷ übersprungen (schon importiert): #$src_id \"$title\"" );
                    $skipped++;
                    continue;
                }
            }

            if ( $dry_run ) {
                WP_CLI::log( "• würde anlegen: \"$title\" (src #$src_id)" );
                $created++;
                continue;
            }

            $post_id = wp_insert_post( [
                'post_type'    => 'vw_meldung',
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_content' => (string) ( $row['content'] ?? '' ),
                'post_date'    => (string) ( $row['date'] ?? '' ) ?: null,
            ], true );

            if ( is_wp_error( $post_id ) ) {
                WP_CLI::warning( "Fehler bei \"$title\": " . $post_id->get_error_message() );
                continue;
            }

            // Meta
            $meta = [
                '_vw_meldung_lat'            => (string) ( $row['lat'] ?? '' ),
                '_vw_meldung_lng'            => (string) ( $row['lng'] ?? '' ),
                '_vw_meldung_address'        => (string) ( $row['address'] ?? '' ),
                '_vw_meldung_city'           => (string) ( $row['city'] ?? '' ),
                '_vw_meldung_postcode'       => (string) ( $row['postcode'] ?? '' ),
                '_vw_meldung_reporter_name'  => (string) ( $row['reporter_name'] ?? '' ),
                '_vw_meldung_reporter_email' => (string) ( $row['reporter_email'] ?? '' ),
                '_vw_meldung_internal_note'  => (string) ( $row['internal_note'] ?? '' ),
                '_vw_meldung_notify'         => ! empty( $row['notify'] ) ? 1 : 0,
                '_vw_meldung_source'         => 'import',
            ];
            foreach ( $meta as $k => $v ) {
                update_post_meta( $post_id, $k, $v );
            }
            update_post_meta( $post_id, self::SRC_ID_META, (string) $src_id );

            // Anliegen-Term (alter Slug → neuer Slug)
            $old_anliegen = (string) ( $row['anliegen_slug'] ?? '' );
            $new_anliegen = VW_Melder_Helpers::ANLIEGEN_SLUG_MAP[ $old_anliegen ] ?? '';
            if ( $new_anliegen ) {
                wp_set_post_terms( $post_id, [ $new_anliegen ], 'vw_anliegen', false );
            }

            // Status-Term
            $old_status = (string) ( $row['status_slug'] ?? '' );
            $new_status = VW_Melder_Helpers::STATUS_SLUG_MAP[ $old_status ] ?? 'neu';
            wp_set_post_terms( $post_id, [ $new_status ], 'vw_meldung_status', false );

            // Bild
            $img_url = (string) ( $row['image_url'] ?? '' );
            if ( $images && $img_url ) {
                $att_id = media_sideload_image( $img_url, $post_id, $title, 'id' );
                if ( is_wp_error( $att_id ) ) {
                    WP_CLI::warning( "Bild fehlgeschlagen für \"$title\": " . $att_id->get_error_message() );
                    $img_fail++;
                } else {
                    set_post_thumbnail( $post_id, (int) $att_id );
                    $img_ok++;
                }
            }

            WP_CLI::log( "✓ angelegt: #$post_id \"$title\" [$new_status / " . ( $new_anliegen ?: '—' ) . ']' );
            $created++;
        }

        WP_CLI::success( "Fertig. Angelegt: $created, übersprungen: $skipped, Bilder ok: $img_ok, Bilder fehlgeschlagen: $img_fail" );
    }
}

VW_Melder_Importer::register();
