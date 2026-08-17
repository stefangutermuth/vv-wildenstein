<?php
/**
 * Plugin Name: VV — Admin-Bar aufräumen
 * Description: Entfernt unnötige Einträge aus der Admin-Leiste (aktuell: Rank Math SEO).
 *              Die Funktionen der Plugins bleiben unberührt — nur die Leisten-Einträge
 *              verschwinden. Rückgängig: Datei löschen oder Eintrag aus der Liste nehmen.
 * Version:     1.0.0
 *
 * Ablage: wp-content/mu-plugins/vv-adminbar-aufraeumen.php (lädt im ganzen Netzwerk)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_bar_menu', static function ( WP_Admin_Bar $bar ): void {
	// Rank Math hängt sich bei Priorität 100 ein — hier (999) fliegt es wieder raus.
	$entfernen = [
		'rank-math', // Rank Math SEO (inkl. aller Unterpunkte)
	];
	foreach ( $entfernen as $id ) {
		$bar->remove_node( $id );
	}
}, 999 );
