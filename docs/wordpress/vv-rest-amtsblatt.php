<?php
/**
 * Plugin Name: VV — Amtsblatt-Felder in der REST-Schnittstelle
 * Description: Ergänzt wp/v2/amtsblatt_download um PDF-Adresse, Veröffentlichungsdatum und Ausgabennummer.
 * Version:     1.0.0
 *
 * Das Problem
 * -----------
 * Die Ausgaben des Amtsblatts liegen als CPT `amtsblatt_download` vor. Die
 * eigentliche PDF steckt in den Zusatzfeldern `datei_url` und `datei` — beide
 * erscheinen nicht in der REST-Antwort (`acf` ist dort ein leeres Array).
 *
 * Die Astro-Seite suchte die PDF deshalb vergeblich in den Anhängen und im
 * Auszug und verlinkte ersatzweise die WordPress-Seite der Ausgabe. Die zeigt
 * aber nur den Titel und ein Suchfeld — jeder „Öffnen"-Knopf führte ins Leere.
 *
 * Zwei weitere Felder kommen mit, weil sie dasselbe Missverständnis betreffen:
 *
 *   veroffentlichungsdatum — Das Datum, das die Redaktion setzt. Es weicht vom
 *   Anlagedatum des Beitrags ab, wenn eine Ausgabe vorab hochgeladen wird.
 *
 *   ausgabe — Die Nummer aus dem Titel, etwa „08/2026". Sie ist maßgeblich für
 *   die Einordnung: Das Amtsblatt 08/2026 erschien am 31. Juli, gehört aber in
 *   den August. Nach dem Anlagedatum sortiert und beschriftet landete es im
 *   Juli — genau der Fehler, den die Redaktion gemeldet hat.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-amtsblatt.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {

	register_rest_field( 'amtsblatt_download', 'vv_amtsblatt', [
		'get_callback' => 'vv_amtsblatt_felder',
		'schema'       => [
			'description' => 'PDF-Adresse, Veröffentlichungsdatum und Ausgabennummer',
			'type'        => 'object',
		],
	] );

} );

function vv_amtsblatt_felder( $post ) {

	$id = (int) $post['id'];

	// Bevorzugt die eingetragene Adresse; sonst die Datei aus der Mediathek.
	$pdf = trim( (string) get_post_meta( $id, 'datei_url', true ) );
	if ( $pdf === '' ) {
		$anhang = (int) get_post_meta( $id, 'datei', true );
		if ( $anhang ) {
			$pdf = (string) ( wp_get_attachment_url( $anhang ) ?: '' );
		}
	}

	// Redaktionelles Datum, gespeichert als JJJJMMTT.
	$roh    = trim( (string) get_post_meta( $id, 'veroffentlichungsdatum', true ) );
	$datum  = '';
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $roh, $m ) ) {
		$datum = "{$m[1]}-{$m[2]}-{$m[3]}";
	}

	// Ausgabennummer aus dem Titel: „Amtsblatt 08/2026" → 8 / 2026
	$monat = null;
	$jahr  = null;
	if ( preg_match( '#(\d{1,2})\s*/\s*(\d{4})#', (string) get_the_title( $id ), $m ) ) {
		$monat = (int) $m[1];
		$jahr  = (int) $m[2];
	}

	return [
		'pdfUrl'        => $pdf ?: null,
		'veroeffentlicht' => $datum ?: null,
		'ausgabeMonat'  => $monat,
		'ausgabeJahr'   => $jahr,
	];
}
