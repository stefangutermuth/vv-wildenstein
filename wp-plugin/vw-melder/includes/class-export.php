<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Export der Meldungen für die Verwaltung:
 *  - Excel (.xlsx, echtes Format via ZipArchive) — alle Meldungen als Tabelle
 *  - Druck-/PDF-Ansicht — eine einzelne oder alle Meldungen als sauber
 *    formatiertes Dokument; der Browser-Druckdialog erzeugt daraus das PDF
 *    („Als PDF sichern") oder druckt direkt für die Papierakte.
 *
 * Zugriff: Redakteure/Admins (edit_others_posts) — die Exporte enthalten
 * interne Angaben (Melder-Kontakt, interner Hinweis) und sind daher
 * ausdrücklich NICHT öffentlich.
 */
final class VW_Melder_Export {

    public const ACTION_XLSX  = 'vw_melder_export_xlsx';
    public const ACTION_PRINT = 'vw_melder_print';
    public const CAP           = 'edit_others_posts';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION_XLSX, [ __CLASS__, 'handle_xlsx' ] );
        add_action( 'admin_post_' . self::ACTION_PRINT, [ __CLASS__, 'handle_print' ] );

        // Buttons oben in der Meldungs-Liste
        add_action( 'restrict_manage_posts', [ __CLASS__, 'list_buttons' ], 10, 2 );
        // „Drucken / PDF" als Zeilen-Aktion je Meldung
        add_filter( 'post_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
        // Metabox auf der Bearbeiten-Seite
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_box' ] );
    }

    /* ================= UI-Einbau ================= */

    public static function xlsx_url(): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_XLSX ), self::ACTION_XLSX );
    }

    public static function print_url( int $post_id = 0 ): string {
        $args = 'action=' . self::ACTION_PRINT . ( $post_id ? '&post_id=' . $post_id : '&all=1' );
        return wp_nonce_url( admin_url( 'admin-post.php?' . $args ), self::ACTION_PRINT );
    }

    public static function list_buttons( string $post_type, string $which = 'top' ): void {
        if ( $post_type !== 'vw_meldung' || $which !== 'top' || ! current_user_can( self::CAP ) ) {
            return;
        }
        printf(
            '<a href="%1$s" class="button" style="margin-left:6px"><span class="dashicons dashicons-media-spreadsheet" style="vertical-align:text-bottom"></span> %2$s</a>'
            . '<a href="%3$s" class="button" target="_blank" style="margin-left:4px"><span class="dashicons dashicons-printer" style="vertical-align:text-bottom"></span> %4$s</a>',
            esc_url( self::xlsx_url() ),
            esc_html__( 'Excel-Export', 'vw-melder' ),
            esc_url( self::print_url() ),
            esc_html__( 'Drucken / PDF (alle)', 'vw-melder' )
        );
    }

    public static function row_action( array $actions, WP_Post $post ): array {
        if ( $post->post_type === 'vw_meldung' && current_user_can( self::CAP ) ) {
            $actions['vw_print'] = sprintf(
                '<a href="%s" target="_blank">%s</a>',
                esc_url( self::print_url( $post->ID ) ),
                esc_html__( 'Drucken / PDF', 'vw-melder' )
            );
        }
        return $actions;
    }

    public static function add_box(): void {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }
        add_meta_box(
            'vw_meldung_export',
            __( 'Export / Druck', 'vw-melder' ),
            [ __CLASS__, 'render_box' ],
            'vw_meldung',
            'side',
            'low'
        );
    }

    public static function render_box( WP_Post $post ): void {
        ?>
        <p>
            <a class="button button-secondary" style="width:100%;text-align:center" target="_blank"
               href="<?php echo esc_url( self::print_url( $post->ID ) ); ?>">
                🖨 <?php esc_html_e( 'Diese Meldung drucken / als PDF', 'vw-melder' ); ?>
            </a>
        </p>
        <p>
            <a class="button button-secondary" style="width:100%;text-align:center"
               href="<?php echo esc_url( self::xlsx_url() ); ?>">
                ⬇ <?php esc_html_e( 'Alle Meldungen als Excel', 'vw-melder' ); ?>
            </a>
        </p>
        <p class="description"><?php esc_html_e( 'PDF: im Druckdialog „Als PDF sichern" wählen. Exporte enthalten interne Angaben — nicht zur Veröffentlichung.', 'vw-melder' ); ?></p>
        <?php
    }

    /* ================= Daten ================= */

    /** @return WP_Post[] */
    private static function get_meldungen( int $only_id = 0 ): array {
        if ( $only_id ) {
            $p = get_post( $only_id );
            return ( $p && $p->post_type === 'vw_meldung' ) ? [ $p ] : [];
        }
        return get_posts( [
            'post_type'      => 'vw_meldung',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
    }

    private static function meldung_row( WP_Post $post ): array {
        $meta = static fn( string $k ): string => trim( (string) get_post_meta( $post->ID, $k, true ) );

        $status = wp_get_post_terms( $post->ID, 'vw_meldung_status' );
        $status = ( is_array( $status ) && $status ) ? $status[0]->name : '';

        $anliegen = wp_get_post_terms( $post->ID, 'vw_anliegen', [ 'fields' => 'names' ] );
        $anliegen = is_array( $anliegen ) ? implode( ', ', $anliegen ) : '';

        $notes = [];
        foreach ( VW_Melder_Public_Notes::get_notes( $post->ID ) as $n ) {
            $ts      = strtotime( (string) ( $n['time'] ?? '' ) );
            $notes[] = ( $ts ? wp_date( 'd.m.Y', $ts ) . ': ' : '' ) . (string) ( $n['text'] ?? '' );
        }

        $freigabe = [ 'publish' => 'Veröffentlicht', 'pending' => 'Ausstehend', 'draft' => 'Entwurf' ][ $post->post_status ] ?? $post->post_status;

        return [
            'id'        => $post->ID,
            'titel'     => get_the_title( $post ),
            'datum'     => get_post_time( 'd.m.Y H:i', false, $post ),
            'freigabe'  => $freigabe,
            'status'    => $status,
            'anliegen'  => $anliegen,
            'adresse'   => $meta( '_vw_meldung_address' ),
            'ort'       => $meta( '_vw_meldung_city' ),
            'plz'       => $meta( '_vw_meldung_postcode' ),
            'lat'       => $meta( '_vw_meldung_lat' ),
            'lng'       => $meta( '_vw_meldung_lng' ),
            'beschreibung' => trim( wp_strip_all_tags( (string) $post->post_content ) ),
            'melder'    => $meta( '_vw_meldung_reporter_name' ),
            'email'     => $meta( '_vw_meldung_reporter_email' ),
            'intern'    => $meta( '_vw_meldung_internal_note' ),
            'antworten' => implode( "\n", $notes ),
            'link'      => VW_Melder_Settings::frontend_url() . '/meldung/' . $post->ID,
        ];
    }

    private static function check( string $action ): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vw-melder' ) );
        }
        if ( ! isset( $_GET['_wpnonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action )
        ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen — bitte die Seite neu laden.', 'vw-melder' ) );
        }
    }

    /* ================= Excel (.xlsx) ================= */

    public static function handle_xlsx(): void {
        self::check( self::ACTION_XLSX );

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( esc_html__( 'ZipArchive fehlt auf dem Server.', 'vw-melder' ) );
        }

        $header = [
            'ID', 'Titel', 'Eingereicht', 'Freigabe', 'Status', 'Anliegen',
            'Adresse', 'Ort', 'PLZ', 'Breitengrad', 'Längengrad', 'Beschreibung',
            'Melder (intern)', 'E-Mail (intern)', 'Interner Hinweis',
            'Öffentliche Antworten', 'Link',
        ];
        $rows = [];
        foreach ( self::get_meldungen() as $post ) {
            $rows[] = array_values( self::meldung_row( $post ) );
        }

        $file = self::build_xlsx( $header, $rows );
        if ( $file === '' ) {
            wp_die( esc_html__( 'Excel-Datei konnte nicht erstellt werden.', 'vw-melder' ) );
        }

        $name = 'maengelmelder-' . wp_date( 'Y-m-d' ) . '.xlsx';
        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        header( 'Content-Length: ' . (string) filesize( $file ) );
        readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        unlink( $file );  // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /** XML-sicher (inkl. Steuerzeichen raus). */
    private static function xml( string $s ): string {
        $s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s ) ?? $s;
        return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    /**
     * Minimales, valides XLSX ohne Fremdbibliothek (Inline-Strings).
     * @param string[]              $header
     * @param array<int,array>      $rows
     * @return string Pfad der Temp-Datei oder ''.
     */
    private static function build_xlsx( array $header, array $rows ): string {
        $widths = [ 6, 34, 16, 13, 15, 26, 30, 16, 8, 12, 12, 50, 20, 26, 30, 60, 34 ];

        $cols = '<cols>';
        foreach ( $widths as $i => $w ) {
            $n     = $i + 1;
            $cols .= sprintf( '<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $n, $w );
        }
        $cols .= '</cols>';

        $sheet_rows = '<row r="1" s="1" customFormat="1">';
        foreach ( $header as $h ) {
            $sheet_rows .= '<c t="inlineStr" s="1"><is><t>' . self::xml( $h ) . '</t></is></c>';
        }
        $sheet_rows .= '</row>';

        foreach ( $rows as $row ) {
            $sheet_rows .= '<row>';
            foreach ( $row as $i => $cell ) {
                if ( $i === 0 && is_numeric( $cell ) ) {
                    $sheet_rows .= '<c t="n" s="2"><v>' . (int) $cell . '</v></c>';
                } else {
                    $sheet_rows .= '<c t="inlineStr" s="2"><is><t xml:space="preserve">'
                        . self::xml( (string) $cell ) . '</t></is></c>';
                }
            }
            $sheet_rows .= '</row>';
        }

        $files = [
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Meldungen" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
                . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
                . '<fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF0A5F2B"/></patternFill></fill></fills>'
                . '<borders count="1"><border/></borders>'
                . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
                . '<cellXfs count="3">'
                . '<xf xfId="0"/>'
                . '<xf xfId="0" fontId="1" fillId="2" applyFont="1" applyFill="1"/>'
                . '<xf xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
                . '</cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Standard" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
            'xl/worksheets/sheet1.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>'
                . $cols
                . '<sheetData>' . $sheet_rows . '</sheetData>'
                . '</worksheet>',
        ];

        $tmp = wp_tempnam( 'vw-melder-export' );
        $zip = new ZipArchive();
        if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
            return '';
        }
        foreach ( $files as $path => $content ) {
            $zip->addFromString( $path, $content );
        }
        $zip->close();
        return $tmp;
    }

    /* ================= Druck-/PDF-Ansicht ================= */

    public static function handle_print(): void {
        self::check( self::ACTION_PRINT );

        $only_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        $posts   = self::get_meldungen( $only_id );
        if ( $posts === [] ) {
            wp_die( esc_html__( 'Keine Meldung gefunden.', 'vw-melder' ) );
        }

        header( 'Content-Type: text/html; charset=utf-8' );
        echo self::render_document( $posts, false, '', ! isset( $_GET['preview'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Baut das komplette Report-Dokument (HTML) für eine oder mehrere Meldungen.
     * Genutzt für die Druck-/PDF-Ansicht UND die E-Mail-Weiterleitung — gleiches Layout.
     *
     * @param WP_Post[] $posts
     * @param bool      $email       true = ohne Werkzeugleiste/Druck-Skript (für E-Mail)
     * @param string    $intro_html  optionaler, serverseitig gebauter HTML-Block ganz oben
     * @param bool      $auto_print  true = Druckdialog automatisch öffnen
     */
    public static function render_document( array $posts, bool $email = false, string $intro_html = '', bool $auto_print = false ): string {
        $doc_title = count( $posts ) === 1 ? 'Meldung #' . $posts[0]->ID : 'Mängelmelder — alle Meldungen';
        ob_start();
        ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $doc_title ); ?></title>
<style>
    :root { --green:#0a5f2b; --navy:#2a3196; --muted:#646970; --border:#d5d7da; }
    * { box-sizing:border-box; }
    body { font:14px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:#1d2327; margin:0; background:#f0f0f1; }
    .toolbar { position:sticky; top:0; background:#fff; border-bottom:1px solid var(--border);
        padding:10px 16px; display:flex; gap:12px; align-items:center; }
    .toolbar button { background:var(--green); color:#fff; border:0; border-radius:6px;
        padding:9px 18px; font-size:14px; font-weight:600; cursor:pointer; }
    .toolbar .hint { color:var(--muted); font-size:12.5px; }
    .sheet { max-width:820px; margin:16px auto; background:#fff; padding:28px 34px;
        box-shadow:0 1px 6px rgba(0,0,0,.12); }
    .kopf { display:flex; justify-content:space-between; align-items:baseline;
        border-bottom:3px solid var(--green); padding-bottom:8px; margin-bottom:18px; }
    .kopf strong { font-size:16px; color:var(--green); }
    .kopf span { color:var(--muted); font-size:12px; }
    .meldung { page-break-after:always; }
    .meldung:last-of-type { page-break-after:auto; }
    .meldung + .meldung { margin-top:34px; border-top:1px dashed var(--border); padding-top:26px; }
    h1 { font-size:20px; margin:0 0 4px; color:var(--green); }
    .mid { color:var(--muted); font-size:12.5px; margin:0 0 12px; }
    .badge { display:inline-block; padding:3px 12px; border-radius:999px; color:#fff;
        font-size:12px; font-weight:700; }
    table.daten { width:100%; border-collapse:collapse; margin:12px 0 16px; }
    table.daten th, table.daten td { text-align:left; padding:6px 10px; border:1px solid var(--border);
        vertical-align:top; font-size:13px; }
    table.daten th { width:170px; background:#f6f7f7; font-weight:600; }
    .abschnitt h2 { font-size:14.5px; margin:18px 0 6px; color:var(--navy); }
    .beschreibung, .antwort { white-space:pre-line; margin:0 0 8px; }
    .antwort { border-left:3px solid var(--navy); background:#f6f7f7; padding:8px 12px;
        border-radius:0 4px 4px 0; }
    .antwort .wann { color:var(--muted); font-size:12px; display:block; margin-bottom:2px; }
    .foto { max-width:100%; max-height:340px; border-radius:6px; border:1px solid var(--border); }
    .intern { border:1px solid #d63638; border-radius:6px; padding:10px 14px; margin-top:16px; }
    .intern h2 { color:#d63638 !important; margin-top:0 !important; }
    .fuss { color:var(--muted); font-size:11px; margin-top:26px; border-top:1px solid var(--border); padding-top:6px; }
    @media print {
        body { background:#fff; }
        .toolbar { display:none; }
        .sheet { max-width:none; margin:0; padding:0 6mm; box-shadow:none; }
        @page { margin:14mm 12mm; }
    }
</style>
</head>
<body>
<?php if ( ! $email ) : ?>
<div class="toolbar">
    <button onclick="window.print()">🖨 Drucken / Als PDF sichern</button>
    <span class="hint">PDF: im Druckdialog als Ziel „Als PDF sichern" wählen.</span>
</div>
<?php endif; ?>
<div class="sheet">
<?php
        echo $intro_html; // phpcs:ignore WordPress.Security.EscapeOutput — serverseitig gebaut
        $status_colors = VW_Melder_Admin_UI::STATUS_COLORS;
        foreach ( $posts as $post ) :
            $d      = self::meldung_row( $post );
            $sterm  = wp_get_post_terms( $post->ID, 'vw_meldung_status' );
            $sslug  = ( is_array( $sterm ) && $sterm ) ? $sterm[0]->slug : 'neu';
            $scolor = $status_colors[ $sslug ] ?? '#646970';
            $thumb  = get_post_thumbnail_id( $post );
            $img    = $thumb ? wp_get_attachment_image_url( $thumb, 'large' ) : '';
            $notes  = VW_Melder_Public_Notes::get_notes( $post->ID );
?>
    <div class="meldung">
        <div class="kopf">
            <strong>Verwaltungsverband Wildenstein — Mängelmelder</strong>
            <span>Ausdruck vom <?php echo esc_html( wp_date( 'd.m.Y H:i' ) ); ?> Uhr</span>
        </div>
        <h1><?php echo esc_html( $d['titel'] ); ?></h1>
        <p class="mid">Meldung #<?php echo (int) $d['id']; ?> · eingereicht am <?php echo esc_html( $d['datum'] ); ?> Uhr</p>
        <span class="badge" style="background:<?php echo esc_attr( $scolor ); ?>"><?php echo esc_html( $d['status'] ?: 'Neue Meldung' ); ?></span>

        <table class="daten">
            <tr><th>Anliegen</th><td><?php echo esc_html( $d['anliegen'] ?: '—' ); ?></td></tr>
            <tr><th>Adresse</th><td><?php echo esc_html( trim( $d['adresse'] . ( $d['plz'] || $d['ort'] ? ', ' . trim( $d['plz'] . ' ' . $d['ort'] ) : '' ), ', ' ) ?: '—' ); ?></td></tr>
            <tr><th>Koordinaten</th><td><?php echo esc_html( $d['lat'] && $d['lng'] ? $d['lat'] . ', ' . $d['lng'] : '—' ); ?></td></tr>
            <tr><th>Freigabe</th><td><?php echo esc_html( $d['freigabe'] ); ?></td></tr>
            <tr><th>Online-Ansicht</th><td><?php echo esc_html( $d['link'] ); ?></td></tr>
        </table>

        <div class="abschnitt">
            <h2>Beschreibung</h2>
            <p class="beschreibung"><?php echo esc_html( $d['beschreibung'] ?: 'Keine Beschreibung angegeben.' ); ?></p>
        </div>

        <?php if ( $img ) : ?>
        <div class="abschnitt">
            <h2>Foto</h2>
            <img class="foto" src="<?php echo esc_url( $img ); ?>" alt="">
        </div>
        <?php endif; ?>

        <div class="abschnitt">
            <h2>Öffentliche Antworten der Verwaltung</h2>
            <?php if ( $notes === [] ) : ?>
                <p class="beschreibung">Noch keine öffentliche Antwort.</p>
            <?php else : ?>
                <?php foreach ( array_reverse( $notes ) as $n ) :
                    $ts = strtotime( (string) ( $n['time'] ?? '' ) ); ?>
                <div class="antwort">
                    <span class="wann"><?php echo esc_html( ( $ts ? wp_date( 'd.m.Y H:i', $ts ) . ' Uhr' : '' ) . ( ! empty( $n['by'] ) ? ' — ' . $n['by'] : '' ) ); ?></span>
                    <?php echo esc_html( (string) ( $n['text'] ?? '' ) ); ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="intern">
            <h2>Interne Angaben — nicht zur Veröffentlichung</h2>
            <table class="daten" style="margin-bottom:0">
                <tr><th>Melder</th><td><?php echo esc_html( $d['melder'] ?: '—' ); ?></td></tr>
                <tr><th>E-Mail</th><td><?php echo esc_html( $d['email'] ?: '—' ); ?></td></tr>
                <tr><th>Interner Hinweis</th><td><?php echo esc_html( $d['intern'] ?: '—' ); ?></td></tr>
            </table>
        </div>

        <p class="fuss">Verwaltungsverband „Wildenstein" · Chemnitzer Straße 41 · 09579 Grünhainichen · Mängelmelder — Ausdruck aus dem internen System</p>
    </div>
<?php endforeach; ?>
</div>
<?php if ( $auto_print ) : ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });</script>
<?php endif; ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }
}
