<?php
/**
 * Plugin Name: VV — Alle Downloads als REST
 * Description: Liefert alle Pakete des Download-Managers (CPT wpdmpro) nach Kategorie
 *              gruppiert unter /wp-json/vvw/v1/downloads-alle — für die Downloads-Seite
 *              der statischen Frontends.
 * Version:     1.1.0
 *
 * Warum: Der Inhaltstyp `wpdmpro` ist nicht über wp/v2 erreichbar (404), und die
 * Original-Seite nutzt ein jQuery-Dateibaum-Widget, das ohne die Plugin-Skripte
 * nicht funktioniert. Dieser Endpoint gibt die Pakete als schlichte Liste aus:
 * Titel, Datei-URL, Größe, Kategorie. Die Redaktion pflegt weiter im
 * Download-Manager — die Website zieht beim nächsten Bauen automatisch nach.
 *
 * 1.1.0 (24.11.2026): Die Datei-Adresse ist jetzt die des Download-Managers
 * (/?wpdmdl=<ID>), wie in vv-rest-downloads.php. Vorher stand hier
 * wp-content/uploads/<Datei>. Dort liegen die Dateien nicht (sie liegen in
 * uploads/download-manager-files/, und der Ordner ist für direkte Aufrufe
 * gesperrt), alle Verweise der Verbandsseite liefen deshalb auf 404.
 * Pakete, deren Datei auf dem Server fehlt, werden ausgelassen.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-downloads-alle.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( 'vvw/v1', '/downloads-alle', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'vv_downloads_alle_rest',
	] );
} );

function vv_downloads_alle_rest() {

	$posts = get_posts( [
		'post_type'      => 'wpdmpro',
		'post_status'    => 'publish',
		'posts_per_page' => 800,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );

	$gruppen = [];

	foreach ( $posts as $p ) {

		// Datei(en) des Pakets — der Download-Manager legt sie als serialisiertes Meta ab.
		$files = get_post_meta( $p->ID, '__wpdm_files', true );
		$files = is_string( $files ) ? maybe_unserialize( $files ) : $files;
		$erste = ( is_array( $files ) && $files ) ? reset( $files ) : '';
		$erste = is_string( $erste ) ? trim( $erste ) : '';

		$datei = '';
		$typ   = '';
		if ( $erste !== '' ) {
			$extern = (bool) preg_match( '~^https?://~i', $erste );
			// Ein Paket ohne Datei auf dem Server lässt sich nicht herunterladen
			// (betrifft alte Amtsblätter bis 2021) und gehört in keine Liste.
			if ( ! $extern && ! vv_downloads_alle_datei_da( $erste ) ) {
				continue;
			}
			// Der Download-Manager liefert die Datei über diese Adresse aus und
			// zählt dabei mit. Der Ordner mit den Dateien selbst ist gesperrt.
			$datei = home_url( '/?wpdmdl=' . $p->ID );
			$name  = $extern ? (string) wp_parse_url( $erste, PHP_URL_PATH ) : $erste;
			$typ   = strtoupper( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		}

		// Kategorien des Pakets (Taxonomie wpdmcategory)
		$terms = wp_get_post_terms( $p->ID, 'wpdmcategory', [ 'fields' => 'names' ] );
		$kat   = ( is_array( $terms ) && $terms ) ? $terms[0] : 'Weitere Formulare';

		if ( ! isset( $gruppen[ $kat ] ) ) {
			$gruppen[ $kat ] = [ 'titel' => $kat, 'dateien' => [] ];
		}
		$gruppen[ $kat ]['dateien'][] = [
			'id'    => $p->ID,
			'titel' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'url'   => $datei !== '' ? $datei : get_permalink( $p ),
			'seite' => get_permalink( $p ),
			'typ'   => $typ,
		];
	}

	ksort( $gruppen, SORT_NATURAL | SORT_FLAG_CASE );

	$anzahl = 0;
	foreach ( $gruppen as $g ) {
		$anzahl += count( $g['dateien'] );
	}

	return [
		'anzahl'  => $anzahl,
		'stand'   => current_time( 'c' ),
		'gruppen' => array_values( $gruppen ),
	];
}

/** Liegt die Datei eines Pakets auf dem Server? Der Download-Manager speichert
 *  nur den Dateinamen (Ordner uploads/download-manager-files/), selten einen
 *  vollständigen Pfad. */
function vv_downloads_alle_datei_da( string $name ): bool {
	$name = str_replace( '\\', '/', $name );
	if ( '/' === substr( $name, 0, 1 ) && is_readable( $name ) ) {
		return true;
	}
	$upload = wp_get_upload_dir();
	$ordner = defined( 'UPLOAD_DIR' ) ? UPLOAD_DIR : trailingslashit( $upload['basedir'] ) . 'download-manager-files/';
	return is_readable( trailingslashit( $ordner ) . ltrim( $name, '/' ) );
}
