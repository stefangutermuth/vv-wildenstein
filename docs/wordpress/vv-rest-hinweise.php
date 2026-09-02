<?php
/**
 * Plugin Name: VV — Servicehinweise als REST
 * Description: Liefert kurzfristige Hinweise (geänderte Öffnungszeiten, Erreichbarkeit) unter /wp-json/vvw/v1/hinweise — nur solange sie gelten.
 * Version:     1.0.0
 *
 * Wozu
 * ----
 * Im Fuß jeder Seite von gruenhainichen.com stehen die Sprechzeiten der
 * Verwaltung, darunter „Fr 9–12". Als das Einwohnermeldeamt am 04.09.2026
 * ausgerechnet von 9 bis 12 Uhr schloss, widersprach diese Angabe auf 589
 * Seiten der Wirklichkeit — und ein Beitrag in den Neuigkeiten erreicht nicht,
 * wer nur eben die Öffnungszeit nachschlägt.
 *
 * Dieser Endpunkt liefert solche Hinweise dorthin, wo die falsche Erwartung
 * entsteht.
 *
 * Redaktionell gepflegt wird nichts Neues: ein normaler Beitrag in der
 * Kategorie „Servicehinweis", mit einem Ablaufdatum aus dem ohnehin genutzten
 * Post-Expirator. Nach Ablauf verschwindet der Hinweis von selbst — das ist
 * der wichtigste Teil. Einen Hinweis, den niemand wieder entfernt, liest man
 * nach zwei Wochen nicht mehr, und beim dritten Mal glaubt man ihm nicht.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-hinweise.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const VV_HINWEIS_KATEGORIE = 'servicehinweis';

add_action( 'rest_api_init', function () {
	register_rest_route( 'vvw/v1', '/hinweise', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'vv_hinweise_rest',
	] );
} );

function vv_hinweise_rest() {

	$jetzt = time();

	$q = new WP_Query( [
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'category_name'  => VV_HINWEIS_KATEGORIE,
		'posts_per_page' => 5,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	] );

	$out = [];
	foreach ( $q->posts as $p ) {

		$ablauf = (int) get_post_meta( $p->ID, '_expiration-date', true );

		// Ohne Ablaufdatum wird nichts angezeigt. Das ist Absicht: Ein Hinweis
		// ohne Enddatum bleibt sonst für immer stehen, und niemandem fällt es
		// auf, weil er ja irgendwann einmal richtig war.
		if ( ! $ablauf || $ablauf <= $jetzt ) continue;

		$text = trim( wp_strip_all_tags( get_the_excerpt( $p ) ) );
		if ( $text === '' ) {
			$text = trim( wp_strip_all_tags( $p->post_content ) );
		}
		$text = preg_replace( '/\s+/u', ' ', $text );
		if ( mb_strlen( $text ) > 220 ) {
			$text = mb_substr( $text, 0, 217 ) . '…';
		}

		$out[] = [
			'id'         => $p->ID,
			'titel'      => html_entity_decode( wp_strip_all_tags( $p->post_title ), ENT_QUOTES, 'UTF-8' ),
			'text'       => $text,
			// Nur der Kurzname — die Adresse baut die jeweilige Website selbst,
			// sie zeigt auf ihre eigene Beitragsseite, nicht auf vv-wildenstein.
			'slug'       => $p->post_name,
			'gueltigBis' => wp_date( 'c', $ablauf ),
		];
	}

	return [ 'hinweise' => $out, 'stand' => current_time( 'c' ) ];
}
