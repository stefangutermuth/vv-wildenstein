<?php
/**
 * Plugin Name: VV — Download-Listen als REST
 * Description: Liest eine WordPress-Seite mit [wpdm_package]-Kurzbefehlen aus und liefert die Dateien gruppiert unter /wp-json/vvw/v1/downloads.
 * Version:     1.0.0
 *
 * Warum das nötig ist
 * -------------------
 * Der Inhaltstyp des Download-Managers (wpdmpro) ist nicht über die
 * REST-Schnittstelle erreichbar — wp/v2/wpdmpro antwortet mit 404. Die
 * Astro-Seiten kämen also nicht an die Formulare heran.
 *
 * Statt die Liste dort fest einzutragen, liest diese Datei die vorhandene
 * Redaktionsseite aus. Ergänzt die Verwaltung dort ein Formular oder tauscht
 * eine Fassung, zieht die Website beim nächsten Bauen automatisch nach —
 * niemand muss zweimal pflegen.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-downloads.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Welche Seiten dürfen abgefragt werden. Feste Liste, damit die Schnittstelle
 *  nicht zum Auslesen beliebiger Inhalte taugt. */
function vv_downloads_seiten(): array {
	return [
		// Kurzname => Seiten-ID auf vv-wildenstein.com
		'kita-formulare' => 41836,
	];
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'vvw/v1', '/downloads/(?P<liste>[a-z0-9-]+)', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'vv_downloads_rest',
	] );
} );

function vv_downloads_rest( $request ) {

	$seiten = vv_downloads_seiten();
	$name   = (string) $request['liste'];

	if ( ! isset( $seiten[ $name ] ) ) {
		return new WP_Error( 'unbekannt', 'Diese Liste gibt es nicht.', [ 'status' => 404 ] );
	}

	$page = get_post( $seiten[ $name ] );
	if ( ! $page || $page->post_status !== 'publish' ) {
		return [ 'gruppen' => [], 'stand' => current_time( 'c' ) ];
	}

	$gruppen  = [];
	$aktuelle = null;

	// Fettschrift markiert die Zwischenüberschriften, dazwischen stehen die
	// Kurzbefehle mit den Dateien. Beide in einem Durchgang der Reihe nach.
	$muster = '/<(?:b|strong)[^>]*>(.*?)<\/(?:b|strong)>|\[wpdm_package id=.(\d+).\]/is';
	preg_match_all( $muster, $page->post_content, $treffer, PREG_SET_ORDER );

	foreach ( $treffer as $t ) {

		// Zwischenüberschrift
		if ( ! empty( $t[1] ) ) {
			$titel = trim( html_entity_decode( wp_strip_all_tags( $t[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( $titel === '' ) continue;

			// „Änderung bestehender" und „Betreuungsverträge" stehen als zwei
			// getrennte Fett-Auszeichnungen nebeneinander. Solange noch keine
			// Datei folgte, gehören sie zusammen.
			if ( $aktuelle !== null && empty( $gruppen[ $aktuelle ]['dateien'] ) ) {
				$gruppen[ $aktuelle ]['titel'] .= ' ' . $titel;
				continue;
			}

			$aktuelle = count( $gruppen );
			$gruppen[] = [ 'titel' => $titel, 'dateien' => [] ];
			continue;
		}

		// Datei
		if ( empty( $t[2] ) ) continue;
		if ( $aktuelle === null ) {
			$aktuelle  = count( $gruppen );
			$gruppen[] = [ 'titel' => 'Formulare', 'dateien' => [] ];
		}

		$datei = vv_downloads_paket( (int) $t[2] );
		if ( $datei ) $gruppen[ $aktuelle ]['dateien'][] = $datei;
	}

	// Gruppen ohne Dateien fliegen raus — etwa Fettschrift im Fließtext.
	$gruppen = array_values( array_filter( $gruppen, fn( $g ) => ! empty( $g['dateien'] ) ) );

	return [
		'gruppen' => $gruppen,
		'stand'   => current_time( 'c' ),
	];
}

/** Ein einzelnes Download-Paket zu Titel, Adresse, Größe und Datum auflösen. */
function vv_downloads_paket( int $id ): ?array {

	$p = get_post( $id );
	if ( ! $p || $p->post_status !== 'publish' ) return null;

	$dateien = maybe_unserialize( get_post_meta( $id, '__wpdm_files', true ) );
	$name    = is_array( $dateien ) && $dateien ? (string) reset( $dateien ) : '';

	$groesse = 0;
	$stand   = '';
	if ( $name ) {
		$up   = wp_upload_dir();
		$pfad = trailingslashit( $up['basedir'] ) . 'download-manager-files/' . $name;
		if ( is_readable( $pfad ) ) {
			$groesse = (int) filesize( $pfad );
			$stand   = date_i18n( 'Y-m-d', filemtime( $pfad ) );
		}
	}

	return [
		'id'      => $id,
		'titel'   => html_entity_decode( wp_strip_all_tags( $p->post_title ), ENT_QUOTES, 'UTF-8' ),
		// Der Download-Manager liefert die Datei über diese Adresse aus und
		// zählt dabei mit. Direkt auf die PDF zu verlinken würde die Zählung
		// umgehen und beim nächsten Dateitausch ins Leere führen.
		'url'     => home_url( '/?wpdmdl=' . $id ),
		'typ'     => strtoupper( pathinfo( $name, PATHINFO_EXTENSION ) ?: 'PDF' ),
		'groesse' => $groesse,
		'stand'   => $stand,
	];
}
