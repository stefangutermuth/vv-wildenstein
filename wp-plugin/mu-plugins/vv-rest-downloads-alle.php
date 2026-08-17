<?php
/**
 * Plugin Name: VV — Alle Downloads als REST
 * Description: Liefert alle Pakete des Download-Managers (CPT wpdmpro) nach Kategorie
 *              gruppiert unter /wp-json/vvw/v1/downloads-alle — für die Downloads-Seite
 *              der statischen Frontends.
 * Version:     1.0.0
 *
 * Warum: Der Inhaltstyp `wpdmpro` ist nicht über wp/v2 erreichbar (404), und die
 * Original-Seite nutzt ein jQuery-Dateibaum-Widget, das ohne die Plugin-Skripte
 * nicht funktioniert. Dieser Endpoint gibt die Pakete als schlichte Liste aus:
 * Titel, Datei-URL, Größe, Kategorie. Die Redaktion pflegt weiter im
 * Download-Manager — die Website zieht beim nächsten Bauen automatisch nach.
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
		$datei = '';
		if ( is_array( $files ) && $files ) {
			$erste = reset( $files );
			if ( is_string( $erste ) && $erste !== '' ) {
				$upload = wp_get_upload_dir();
				$datei  = $upload['baseurl'] . '/' . ltrim( str_replace( '\\', '/', $erste ), '/' );
			}
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
			'typ'   => $datei !== '' ? strtoupper( pathinfo( $datei, PATHINFO_EXTENSION ) ) : '',
		];
	}

	ksort( $gruppen, SORT_NATURAL | SORT_FLAG_CASE );

	return [
		'anzahl'  => count( $posts ),
		'stand'   => current_time( 'c' ),
		'gruppen' => array_values( $gruppen ),
	];
}
