<?php
/**
 * Plugin Name: VV — Textauszug für Profile
 * Description: Schaltet für den Inhaltstyp „Profile“ das Feld „Textauszug“ frei.
 *              Die Websites (Grünhainichen, Börnichen, Verband) nutzen es als
 *              Kurzbeschreibung, z. B. als Einleitung auf der Grundschulseite
 *              oder in Übersichtskarten. Vorher gab es für eine solche Einleitung
 *              kein Feld, sie stand fest im Code der Websites.
 * Version:     1.0.0
 *
 * Ablage: wp-content/mu-plugins/vv-profil-auszug.php
 */
add_action( 'init', static function (): void {
	if ( post_type_exists( 'profile' ) ) {
		add_post_type_support( 'profile', 'excerpt' );
	}
}, 20 );
