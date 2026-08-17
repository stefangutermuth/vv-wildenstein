<?php
/**
 * Plugin Name: VV — Amtsblatt-Ausgaben als REST
 * Description: Liefert die Amtsblatt-Ausgaben (CPT amtsblatt_download) mit PDF-Link unter /wp-json/vvw/v1/amtsblatt, damit die Astro-Seiten sie listen können.
 * Version:     1.0.0
 *
 * Warum: wp/v2/amtsblatt_download liefert weder die ACF-Felder (acf = [])
 * noch die PDF-Datei als Anhang (_embedded leer). Der PDF-Link steckt in den
 * Post-Metas `datei_url` bzw. `datei` (Attachment-ID) — genau die liest dieser
 * Endpoint aus, mehr nicht.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-amtsblatt.php (Haupt-Site vv-wildenstein.com)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {
	register_rest_route( 'vvw/v1', '/amtsblatt', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'vv_amtsblatt_rest',
	] );
} );

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

		// Veröffentlichungsdatum: ACF-Feld (JJJJMMTT), sonst Post-Datum.
		$raw  = (string) get_post_meta( $p->ID, 'veroffentlichungsdatum', true );
		$date = preg_match( '/^\d{8}$/', $raw )
			? substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 )
			: get_post_time( 'Y-m-d', false, $p );

		// PDF: direkte URL aus dem Meta, sonst über die Attachment-ID.
		$pdf = (string) get_post_meta( $p->ID, 'datei_url', true );
		if ( $pdf === '' ) {
			$att = (int) get_post_meta( $p->ID, 'datei', true );
			if ( $att ) {
				$pdf = (string) wp_get_attachment_url( $att );
			}
		}

		$items[] = [
			'id'     => $p->ID,
			'titel'  => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'datum'  => $date,
			'jahr'   => substr( $date, 0, 4 ),
			'pdfUrl' => $pdf,
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
