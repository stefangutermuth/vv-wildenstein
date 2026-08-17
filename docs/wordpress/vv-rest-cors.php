<?php
/**
 * Plugin Name: VV — CORS für die REST-Schnittstelle
 * Description: Erlaubt den Astro-Seiten des Verbands, Inhalte per JavaScript nachzuladen.
 * Version:     1.0.0
 *
 * Hintergrund
 * -----------
 * Die Astro-Seiten liegen als fertige Dateien auf dem Server und holen sich
 * aktuelle Inhalte — Neuigkeiten, Termine, Freibad-Status — beim Aufruf per
 * JavaScript von hier. Das ist ein Zugriff über Domaingrenzen hinweg, den der
 * Browser nur zulässt, wenn diese Seite ihn ausdrücklich erlaubt.
 *
 * Bis zum 16.08.2026 lief Grünhainichen unter grh.vv-wildenstein.com und war
 * damit von der Regel für *.vv-wildenstein.com im Amtsblatt-Plugin gedeckt.
 * Mit dem Umzug auf www.gruenhainichen.com fiel sie heraus — sämtliche
 * Nachlade-Funktionen scheiterten seitdem stillschweigend, ohne dass auf der
 * Website etwas kaputt aussah: Die Seiten zeigten weiter den Stand vom
 * letzten Bauen.
 *
 * Diese Datei regelt es zentral für alle REST-Anfragen statt pro Plugin.
 *
 * Ablage: wp-content/mu-plugins/vv-rest-cors.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ist dieser Ursprung eine unserer eigenen Seiten?
 *
 * Bewusst eine feste Liste statt eines Platzhalters: Ein zu weiter Ausdruck
 * würde auch Domains wie „gruenhainichen.com.angreifer.de" durchlassen.
 */
function vv_cors_erlaubter_ursprung(): ?string {

	$origin = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );
	if ( $origin === '' ) return null;

	$host   = parse_url( $origin, PHP_URL_HOST )   ?: '';
	$schema = parse_url( $origin, PHP_URL_SCHEME ) ?: '';

	// Produktivseiten des Verbands
	$erlaubt = [
		'vv-wildenstein.com',
		'www.gruenhainichen.com',
		'gruenhainichen.com',
		'alt.gruenhainichen.com',
		'boernichen.de',
		'www.boernichen.de',
		'feuerwehr-gruenhainichen.de',
	];

	if ( in_array( $host, $erlaubt, true ) && $schema === 'https' ) {
		return $origin;
	}

	// Sämtliche eigenen Subdomains (melder., cloud., 2026. …)
	if ( str_ends_with( $host, '.vv-wildenstein.com' ) && $schema === 'https' ) {
		return $origin;
	}

	// Lokale Entwicklung — hier ist http richtig, es gibt kein Zertifikat.
	if ( $host === 'localhost' || $host === '127.0.0.1' ) {
		return $origin;
	}

	return null;
}

function vv_cors_kopfzeilen(): void {

	$origin = vv_cors_erlaubter_ursprung();
	if ( ! $origin ) return;

	// replace = true: Falls ein Plugin bereits eine Zeile gesetzt hat, wird sie
	// ersetzt statt ergänzt. Zwei Access-Control-Allow-Origin-Zeilen lehnt
	// jeder Browser ab — das wäre schlimmer als gar keine.
	header( 'Access-Control-Allow-Origin: ' . $origin, true );

	// Ohne Vary würde ein Zwischenspeicher die Antwort für einen Ursprung an
	// alle anderen ausliefern.
	header( 'Vary: Origin', false );

	header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS', true );
	header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce', true );
	header( 'Access-Control-Allow-Credentials: true', true );
	header( 'Access-Control-Max-Age: 600', true );
}

// Für jede ausgelieferte REST-Antwort.
add_filter( 'rest_pre_serve_request', function ( $served, $result, $request, $server ) {
	vv_cors_kopfzeilen();
	return $served;
}, 99, 4 );

// Die Vorabanfrage (OPTIONS) schickt der Browser vor manchen Zugriffen. Sie
// muss beantwortet werden, bevor WordPress die eigentliche Route sucht.
add_action( 'rest_api_init', function () {
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
		vv_cors_kopfzeilen();
		status_header( 204 );
		exit;
	}
}, 0 );
