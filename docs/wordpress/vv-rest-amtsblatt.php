<?php
/**
 * Plugin Name: VV — Amtsblatt-Ausgaben als REST
 * Description: Reicht dem CPT amtsblatt_download das Feld vv_amtsblatt (PDF, Größe, Datum, Ausgabennummer) in wp/v2 nach und liefert dieselben Daten unter /wp-json/vvw/v1/amtsblatt.
 * Version:     1.1.0
 *
 * Warum: wp/v2/amtsblatt_download liefert weder die ACF-Felder (acf = [])
 * noch die PDF-Datei als Anhang (_embedded leer), und Inhalt wie Auszug sind
 * bei diesen Einträgen leer. Der PDF-Link steckt in den Post-Metas
 * `datei_url` bzw. `datei` (Attachment-ID) — genau die liest dieses Plugin
 * aus, mehr nicht.
 *
 * ZWEI WEGE, absichtlich:
 *
 *  1. register_rest_field: hängt `vv_amtsblatt` an wp/v2/amtsblatt_download.
 *     Genau so lesen es die drei Astro-Frontends (Verband, Grünhainichen,
 *     Börnichen) — sie brauchen aus derselben Abfrage auch die Taxonomie
 *     `downloadkategorie` und den Titel, und sie blättern über wp/v2.
 *     Dieses Feld fehlte bis Version 1.1.0: das Plugin registrierte NUR die
 *     Route unten. Die Frontends lasen also ein Feld, das nie ankam — die
 *     Verbandsseite zeigte dadurch „Alle 0 Ausgaben", obwohl 71 Ausgaben
 *     vorliegen, weil sie Einträge ohne PDF-Adresse herausfiltert.
 *
 *  2. Die Route /wp-json/vvw/v1/amtsblatt: eine flache, fertig sortierte
 *     Liste. Bleibt bestehen, weil sie ohne Kenntnis der Metafelder
 *     auskommt und sich zum Prüfen von Hand eignet.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-amtsblatt.php (vv-wildenstein.com)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {

	register_rest_route( 'vvw/v1', '/amtsblatt', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'vv_amtsblatt_rest',
	] );

	register_rest_field( 'amtsblatt_download', 'vv_amtsblatt', [
		'get_callback' => static function ( $post ) {
			return vv_amtsblatt_daten( (int) $post['id'] );
		},
		'schema'       => [
			'description' => 'PDF-Adresse, Dateigröße, Veröffentlichungsdatum und Ausgabennummer',
			'type'        => 'object',
			'context'     => [ 'view', 'edit' ],
		],
	] );
} );

/**
 * Die Angaben zu einer Ausgabe aus den Post-Metas.
 *
 * @param int $id Post-ID im CPT amtsblatt_download.
 * @return array{pdfUrl:?string,groesse:int,veroeffentlicht:?string,ausgabeMonat:?int,ausgabeJahr:?int}
 */
function vv_amtsblatt_daten( int $id ): array {

	// Veröffentlichungsdatum: ACF-Feld (JJJJMMTT), sonst Post-Datum.
	$raw = (string) get_post_meta( $id, 'veroffentlichungsdatum', true );
	$veroeffentlicht = preg_match( '/^\d{8}$/', $raw )
		? substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 )
		: get_post_time( 'Y-m-d', false, $id );

	// PDF: direkte URL aus dem Meta, sonst über die Attachment-ID.
	$pdf = (string) get_post_meta( $id, 'datei_url', true );
	$att = (int) get_post_meta( $id, 'datei', true );
	if ( $pdf === '' && $att ) {
		$pdf = (string) wp_get_attachment_url( $att );
	}

	// Dateigröße nur, wenn die Datei über einen Anhang bekannt ist.
	$groesse = 0;
	if ( $att ) {
		$pfad = get_attached_file( $att );
		if ( $pfad && file_exists( $pfad ) ) {
			$groesse = (int) filesize( $pfad );
		}
	}

	/* Monat und Jahr der AUSGABE stehen nur im Titel („Amtsblatt 08/2026") —
	   ein eigenes Metafeld dafür gibt es nicht. Das ist auch die richtige
	   Quelle: Ausgabe 08/2026 erschien am 31. Juli, nach dem Erscheinungsdatum
	   stünde sie unter „Juli".
	   Bleibt null, wenn der Titel keine Nummer trägt — daran erkennen die
	   Frontends „Anzeigenpreise" und „Terminplan", die im selben Inhaltstyp
	   liegen, aber keine Ausgaben sind. */
	$monat = null;
	$jahr  = null;
	if ( preg_match( '#(\d{1,2})\s*/\s*(\d{4})#', (string) get_the_title( $id ), $m ) ) {
		$monat = (int) $m[1];
		$jahr  = (int) $m[2];
	}

	return [
		'pdfUrl'          => $pdf !== '' ? $pdf : null,
		'groesse'         => $groesse,
		'veroeffentlicht' => $veroeffentlicht ?: null,
		'ausgabeMonat'    => $monat,
		'ausgabeJahr'     => $jahr,
	];
}

function vv_amtsblatt_rest() {

	$posts = get_posts( [
		'post_type'      => 'amtsblatt_download',
		'post_status'    => 'publish',
		'posts_per_page' => 500,
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	$items = [];
	foreach ( $posts as $p ) {
		$d = vv_amtsblatt_daten( $p->ID );

		$items[] = [
			'id'           => $p->ID,
			'titel'        => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'datum'        => (string) $d['veroeffentlicht'],
			'jahr'         => substr( (string) $d['veroeffentlicht'], 0, 4 ),
			'pdfUrl'       => (string) $d['pdfUrl'],
			'groesse'      => $d['groesse'],
			'ausgabeMonat' => $d['ausgabeMonat'],
			'ausgabeJahr'  => $d['ausgabeJahr'],
		];
	}

	// Nach echtem Veröffentlichungsdatum absteigend (ACF-Datum weicht z. T. vom Post-Datum ab).
	usort( $items, static fn( $a, $b ) => strcmp( $b['datum'], $a['datum'] ) );

	return [
		'anzahl' => count( $items ),
		'stand'  => current_time( 'c' ),
		'items'  => $items,
	];
}
