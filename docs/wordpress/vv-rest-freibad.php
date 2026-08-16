<?php
/**
 * Plugin Name: VV — Freibad-Status als REST
 * Description: Stellt die Öffnungszeiten aus wuw-freibad-oeffnung.php unter /wp-json/vvw/v1/freibad bereit, damit die Astro-Seiten sie lesen können.
 * Version:     1.0.0
 *
 * Warum ein eigenes mu-Plugin statt einer Änderung am Original:
 * wuw-freibad-oeffnung.php registriert seinen Inhaltstyp mit `public => false`
 * und ohne `show_in_rest`. Das ist richtig so — die Einträge sollen weder
 * einzeln aufrufbar noch durchsuchbar sein. Statt diese Entscheidung
 * aufzuweichen, liest diese Datei die vorhandene Methode get_openings() aus
 * und gibt genau das zurück, was die Website anzeigen soll.
 *
 * Bleibt das Original unverändert, überlebt diese Anbindung auch ein Update
 * des Freibad-Plugins.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-freibad.php auf vv-wildenstein.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () {

	register_rest_route( 'vvw/v1', '/freibad', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'vv_freibad_rest',
	] );

} );

function vv_freibad_rest() {

	if ( ! class_exists( 'WuW_Freibad_Oeffnung' ) ) {
		// Das Freibad-Plugin ist nicht aktiv — kein Fehler, nur nichts zu melden.
		return [
			'verfuegbar' => false,
			'aktuell'    => null,
			'kommend'    => [],
			'stand'      => current_time( 'c' ),
		];
	}

	$stati = WuW_Freibad_Oeffnung::statuses();
	$items = WuW_Freibad_Oeffnung::get_openings();
	$heute = current_time( 'Y-m-d' );

	$auf = function ( $i ) use ( $stati ) {
		$key = $i['status'] ?: 'voraussichtlich';
		$s   = $stati[ $key ] ?? $stati['voraussichtlich'];
		return [
			'von'      => $i['von'],
			'bis'      => $i['bis'],
			'zeitVon'  => $i['zv'],
			'zeitBis'  => $i['zb'],
			'status'   => $key,
			'label'    => $s['label'],
			'hinweis'  => $i['hinweis'] ?: '',
			// `geoeffnet` ist der einzige Status, bei dem man wirklich hinfahren kann.
			'offen'    => $key === 'geoeffnet',
		];
	};

	$aktuell = null;
	$kommend = [];

	foreach ( $items as $i ) {
		$e = $auf( $i );
		if ( $i['von'] <= $heute && $i['bis'] >= $heute ) {
			// Läuft gerade — der erste Treffer gewinnt, die Liste ist nach Beginn sortiert.
			if ( $aktuell === null ) $aktuell = $e;
		} elseif ( $i['von'] > $heute ) {
			$kommend[] = $e;
		}
	}

	return [
		'verfuegbar' => true,
		'aktuell'    => $aktuell,
		'kommend'    => array_slice( $kommend, 0, 3 ),
		'stand'      => current_time( 'c' ),
	];
}
