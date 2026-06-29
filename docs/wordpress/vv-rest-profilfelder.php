<?php
/**
 * Plugin Name: VV → REST: Profil-/Tourismus-Kontaktfelder
 * Description: Hängt die Impreza-Custom-Felder (Kontaktdaten) der Custom-Post-Types
 *              „profile" und „tourismus" als Objekt `vv_kontakt` an die REST-Antwort an
 *              (nur Lesen). Damit können die statischen Gemeinde-Frontends (z. B.
 *              boernichen.de) die Daten dynamisch auslesen. Robust über den
 *              `rest_prepare`-Filter — unabhängig vom REST-Controller des CPT und vom
 *              REST-Optimizer.
 * Author:      GUMU
 * Version:     2.0.0
 *
 * Installation: nach  wp-content/mu-plugins/vv-rest-profilfelder.php  kopieren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	$keys = array(
		'fuhrende_person'      => 'fuehrende_person',
		't-strasse_hausnummer' => 'strasse_hausnummer',
		't-plz_ort'            => 'plz_ort',
		'telefon'              => 'telefon',
		'e-mail_adresse'       => 'email',
		'website'              => 'website',
	);

	foreach ( array( 'profile', 'tourismus' ) as $pt ) {
		add_filter( "rest_prepare_{$pt}", function ( $response, $post ) use ( $keys ) {
			$data = $response->get_data();
			$kontakt = array();
			foreach ( $keys as $meta_key => $out_key ) {
				$val = get_post_meta( $post->ID, $meta_key, true );
				$kontakt[ $out_key ] = is_string( $val ) ? trim( $val ) : $val;
			}
			$data['vv_kontakt'] = $kontakt;
			$response->set_data( $data );
			return $response;
		}, 10, 2 );
	}
} );
