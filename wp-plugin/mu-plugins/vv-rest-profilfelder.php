<?php
/**
 * Plugin Name: VV → REST: Profil-/Tourismus-Kontaktfelder
 * Description: Hängt die Impreza-Custom-Felder (Kontaktdaten) der Custom-Post-Types
 *              „profile", „tourismus" und „verein" als Objekt `vv_kontakt` an die REST-Antwort an
 *              und die „Zusatzbilder" (us_tile_additional_image, „Erweiterte
 *              Einstellungen") als Array `vv_gallery`. Damit können die statischen
 *              Gemeinde-Frontends (z. B. boernichen.de) Kontaktdaten und Foto-Galerie
 *              dynamisch auslesen. Robust über den `rest_prepare`-Filter — unabhängig
 *              vom REST-Controller des CPT und vom REST-Optimizer.
 * Author:      GUMU
 * Version:     2.2.0
 *
 * Installation: nach  wp-content/mu-plugins/vv-rest-profilfelder.php  kopieren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Baut aus dem US/Impreza-Feld `us_tile_additional_image` (kommaseparierte
 * Attachment-IDs, „Erweiterte Einstellungen") eine Liste von Bildern für die REST-API.
 * Jeder Eintrag: url (Anzeigegröße), full (Original für Lightbox), alt.
 */
if ( ! function_exists( 'vv_build_tile_gallery' ) ) {
	function vv_build_tile_gallery( $post_id ) {
		$out = array();
		$raw = get_post_meta( $post_id, 'us_tile_additional_image', true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return $out;
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $aid ) {
			if ( ! is_numeric( $aid ) ) {
				continue;
			}
			$aid  = (int) $aid;
			$full = wp_get_attachment_image_url( $aid, 'full' );
			if ( ! $full ) {
				continue;
			}
			$thumb = wp_get_attachment_image_url( $aid, 'medium_large' );
			if ( ! $thumb ) {
				$thumb = wp_get_attachment_image_url( $aid, 'large' );
			}
			if ( ! $thumb ) {
				$thumb = $full;
			}
			$alt = get_post_meta( $aid, '_wp_attachment_image_alt', true );
			$out[] = array(
				'url'  => $thumb,
				'full' => $full,
				'alt'  => is_string( $alt ) ? trim( $alt ) : '',
			);
		}
		return $out;
	}
}

add_action( 'rest_api_init', function () {
	// Feld-Namen je Inhaltstyp. Die Vereine wurden mit einer eigenen Feldgruppe
	// angelegt (ansprechpartner / strase_und_hausnummer / plz_und_ort / mgl) —
	// deshalb pro Typ ein eigenes Mapping statt einer gemeinsamen Liste.
	$keys_profile = array(
		'fuhrende_person'      => 'fuehrende_person',
		't-strasse_hausnummer' => 'strasse_hausnummer',
		't-plz_ort'            => 'plz_ort',
		'telefon'              => 'telefon',
		'e-mail_adresse'       => 'email',
		'website'              => 'website',
	);
	$keys_verein = array(
		'ansprechpartner'         => 'fuehrende_person',
		'strase_und_hausnummer'   => 'strasse_hausnummer', // Schreibweise wie im Backend
		'plz_und_ort'             => 'plz_ort',
		'telefon'                 => 'telefon',
		'e-mail_adresse'          => 'email',
		'website'                 => 'website',
		'mgl'                     => 'mitglieder',
	);

	$typen = array(
		'profile'   => $keys_profile,
		'tourismus' => $keys_profile,
		'verein'    => $keys_verein,
	);

	foreach ( $typen as $pt => $keys ) {
		add_filter( "rest_prepare_{$pt}", function ( $response, $post ) use ( $keys ) {
			$data = $response->get_data();

			$kontakt = array();
			foreach ( $keys as $meta_key => $out_key ) {
				$val = get_post_meta( $post->ID, $meta_key, true );
				$kontakt[ $out_key ] = is_string( $val ) ? trim( $val ) : $val;
			}
			$data['vv_kontakt'] = $kontakt;
			$data['vv_gallery'] = vv_build_tile_gallery( $post->ID );

			$response->set_data( $data );
			return $response;
		}, 10, 2 );
	}
} );
