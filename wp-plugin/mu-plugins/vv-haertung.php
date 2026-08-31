<?php
/**
 * Plugin Name: VV — Grundabsicherung
 * Description: Schließt die typischen Einfallstore, die bei Angriffen auf kommunale
 *              Websites zuerst probiert werden: XML-RPC, Auslesen der Benutzernamen,
 *              Preisgabe der WordPress-Version, fehlende Sicherheits-Kopfzeilen.
 * Version:     1.0.0
 *
 * Bewusst NICHT eingeschränkt: die Inhalts-Schnittstelle (/wp-json/wp/v2/posts,
 * pages, amter, vvw/v1 …). Davon leben die vier statischen Websites — sie bleibt
 * unverändert offen erreichbar.
 *
 * Ablage: wp-content/mu-plugins/vv-haertung.php (gilt netzwerkweit)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* --- 1) XML-RPC abschalten -------------------------------------------------
 * Uraltschnittstelle, die von den Frontends nicht gebraucht wird. Sie erlaubt
 * hunderte Passwortversuche in einer einzigen Anfrage und wird für
 * Verstärkungsangriffe missbraucht.                                          */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', static function () { return array(); } );
add_action( 'init', static function () {
	if ( isset( $_SERVER['REQUEST_URI'] ) && str_contains( (string) $_SERVER['REQUEST_URI'], 'xmlrpc.php' ) ) {
		status_header( 403 );
		exit( 'XML-RPC ist auf diesem Server abgeschaltet.' );
	}
}, 1 );

/* --- 2) Benutzernamen nicht mehr preisgeben --------------------------------
 * Ohne diesen Riegel liefert /wp-json/wp/v2/users die Anmeldenamen aller
 * Redakteure — die halbe Arbeit für einen Passwortangriff. Angemeldete
 * Redaktion und Verwaltung merken davon nichts.                              */
add_filter( 'rest_endpoints', static function ( array $endpoints ): array {
	if ( is_user_logged_in() ) {
		return $endpoints;
	}
	unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	return $endpoints;
} );

// Autor-Abfragen (…/?author=1) nicht auf den Anmeldenamen umleiten lassen
add_action( 'template_redirect', static function () {
	if ( ! is_admin() && isset( $_GET['author'] ) && ! is_user_logged_in() ) {
		wp_safe_redirect( home_url(), 301 );
		exit;
	}
} );

/* --- 3) Version und Hinweise verbergen ------------------------------------ */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
// Anmeldemaske: keine Auskunft, ob Name oder Passwort falsch war
add_filter( 'login_errors', static function () {
	return __( 'Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.', 'default' );
} );

/* --- 3b) Auch Plugin-Kennungen aus dem Kopfbereich entfernen ---------------
 * Manche Plugins schreiben ihre Version direkt in den Seitenkopf (z. B.
 * „WordPress Download Manager 3.3.67"). Damit lässt sich gezielt nach
 * Schwachstellen genau dieser Version suchen. Der Filter unten räumt alle
 * generator-Angaben aus der fertigen Seite — unabhängig davon, welches
 * Plugin sie gesetzt hat.                                                    */
add_action( 'template_redirect', static function () {
	if ( is_admin() ) {
		return;
	}
	ob_start( static function ( $html ) {
		return preg_replace( '#<meta[^>]+name=["\']generator["\'][^>]*>\s*#i', '', $html ) ?? $html;
	} );
}, 1 );

/* --- 4) Sicherheits-Kopfzeilen -------------------------------------------- */
add_action( 'send_headers', static function () {
	if ( is_admin() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
	if ( is_ssl() ) {
		header( 'Strict-Transport-Security: max-age=15768000' );
	}
} );

/* --- 5) Dateibearbeitung im Backend sperren --------------------------------
 * Verhindert, dass ein übernommenes Konto direkt Schadcode in Theme- oder
 * Plugin-Dateien schreiben kann.                                             */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}
