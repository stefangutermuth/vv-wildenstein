<?php
/**
 * Plugin Name: VV Termin-Einbettung
 * Description: Kurzcode [vv_termin id="…"] zeigt die Beschreibung eines Termins
 *              (vw_event) an anderer Stelle, etwa im Fenster auf der Startseite
 *              von vv-wildenstein.com. Der Text wird so nur am Termin gepflegt;
 *              die Gemeinde-Websites lesen denselben Termin über die REST-Schnittstelle.
 *              Ist der Termin vorbei, nicht veröffentlicht oder gelöscht, bleibt
 *              der Kurzcode leer.
 * Author:      GUMU
 * Version:     1.0.0
 *
 * Installation: nach  wp-content/mu-plugins/vv-termin-einbettung.php  kopieren
 * (läuft automatisch über .github/workflows/deploy-mu-plugins.yml).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'vv_termin', function ( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'vv_termin' );
	$post = get_post( (int) $atts['id'] );
	if ( ! $post || 'vw_event' !== $post->post_type || 'publish' !== $post->post_status ) {
		return '';
	}

	$ende = get_post_meta( $post->ID, '_vw_event_end', true ) ?: get_post_meta( $post->ID, '_vw_event_start', true );
	if ( $ende && strtotime( $ende ) < current_time( 'timestamp' ) ) {
		return '';
	}

	// Kein the_content: Der Kurzcode steht selbst in einem Inhalt, und die
	// Seitenbaukasten-Filter sollen nicht ein zweites Mal darüber laufen.
	$html = wpautop( do_shortcode( $post->post_content ) );
	return '<div class="vv-termin">' . wp_kses_post( $html ) . '</div>';
} );
