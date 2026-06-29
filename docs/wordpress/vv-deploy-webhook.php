<?php
/**
 * Plugin Name: VV → GitHub Deploy-Webhook
 * Description: Stößt einen Rebuild der statischen Astro-Seiten (Grünhainichen,
 *              Börnichen …) an, sobald auf vv-wildenstein.com ein Beitrag oder
 *              Termin angelegt, geändert oder gelöscht wird.
 * Author:      GUMU
 * Version:     1.0.0
 *
 * INSTALLATION (auf vv-wildenstein.com):
 *   1. Diese Datei nach  wp-content/mu-plugins/vv-deploy-webhook.php  kopieren.
 *      (Ordner mu-plugins ggf. anlegen — "mu" = must-use, lädt automatisch,
 *       lässt sich nicht versehentlich deaktivieren.)
 *   2. In wp-config.php (oberhalb von "That's all, stop editing") eintragen:
 *
 *        define( 'VV_DEPLOY_GH_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
 *
 *      → Fine-grained GitHub-Token mit Zugriff NUR auf das Repo
 *        stefangutermuth/vv-wildenstein und Berechtigung
 *        "Contents: Read and write"  (das deckt repository_dispatch ab).
 *        Token NICHT in diese Datei schreiben — nur in wp-config.php.
 *
 *   Fertig. Ab jetzt löst jedes Veröffentlichen/Bearbeiten/Löschen einen
 *   Deploy aus (gebündelt, max. 1× pro 90 Sekunden).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VV_DEPLOY_REPO       = 'stefangutermuth/vv-wildenstein';
const VV_DEPLOY_EVENT_TYPE = 'vv-content-updated';
const VV_DEPLOY_THROTTLE   = 90; // Sekunden: Bursts (mehrere Speichervorgänge) zu 1 Build bündeln

/**
 * Post-Typen, die einen Rebuild auslösen. Beiträge (News) + alle Event-artigen
 * Custom-Post-Types. Interne Typen (Revisionen, Menüeinträge, Medien …) lösen
 * bewusst KEINEN Build aus.
 */
function vv_deploy_is_relevant_post_type( $post_type ) {
	$ignore = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'attachment', 'acf-field', 'acf-field-group' );
	if ( in_array( $post_type, $ignore, true ) ) {
		return false;
	}
	// Inhalte, die das Börnichen-Frontend live nutzt → lösen einen Rebuild aus:
	//  post (News), page, amtsblatt_download (Amtsblatt), profile (Vereine/Leben),
	//  tourismus (Unterkünfte/Ausflugsziele) + verwandte CPTs.
	$relevant = array(
		'post',
		'page',
		'amtsblatt_download',
		'profile',
		'tourismus',
		'gemeinderatssitzung',
		'ausschreibungen',
		'verein',
	);
	if ( in_array( $post_type, $relevant, true ) ) {
		return true;
	}
	// Alles, was nach "event" aussieht (vw-events-CPT).
	if ( false !== stripos( $post_type, 'event' ) ) {
		return true;
	}
	/**
	 * Filter: weitere Post-Typen freischalten/sperren.
	 *   add_filter( 'vv_deploy_relevant_post_type', fn($ok,$pt) => $ok || $pt === 'mein_cpt', 10, 2 );
	 */
	return (bool) apply_filters( 'vv_deploy_relevant_post_type', false, $post_type );
}

/** Beim Speichern/Aktualisieren eines Beitrags oder Termins. */
add_action( 'save_post', function ( $post_id, $post, $update ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! vv_deploy_is_relevant_post_type( $post->post_type ) ) {
		return;
	}
	// Nur veröffentlichte Inhalte (Entwürfe/Auto-Drafts ignorieren).
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	vv_deploy_trigger( "save:{$post->post_type}#{$post_id}" );
}, 10, 3 );

/** Beim Löschen (Papierkorb / endgültig) bzw. Wiederherstellen. */
foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
	add_action( $hook, function ( $post_id ) use ( $hook ) {
		$post = get_post( $post_id );
		if ( $post && ! vv_deploy_is_relevant_post_type( $post->post_type ) ) {
			return;
		}
		vv_deploy_trigger( "{$hook}#{$post_id}" );
	} );
}

/**
 * Schickt das repository_dispatch an GitHub — gedrosselt über ein Transient,
 * damit ein Schwung Speichervorgänge nur einen Build erzeugt.
 */
function vv_deploy_trigger( $reason ) {
	if ( ! defined( 'VV_DEPLOY_GH_TOKEN' ) || ! VV_DEPLOY_GH_TOKEN ) {
		return; // Token fehlt → still nichts tun (Seite funktioniert normal weiter).
	}
	// Netzwerkweite Drossel (Multisite): Änderungen auf verschiedenen Subsites
	// innerhalb des Fensters bündeln sich zu einem Build.
	if ( get_site_transient( 'vv_deploy_pending' ) ) {
		return; // Innerhalb des Drossel-Fensters bereits ausgelöst.
	}
	set_site_transient( 'vv_deploy_pending', 1, VV_DEPLOY_THROTTLE );

	$response = wp_remote_post(
		'https://api.github.com/repos/' . VV_DEPLOY_REPO . '/dispatches',
		array(
			'timeout' => 8,
			'headers' => array(
				'Accept'        => 'application/vnd.github+json',
				'Authorization' => 'Bearer ' . VV_DEPLOY_GH_TOKEN,
				'User-Agent'    => 'vv-deploy-webhook',
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'event_type'     => VV_DEPLOY_EVENT_TYPE,
					'client_payload' => array(
						'reason' => (string) $reason,
						'site'   => home_url(),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[vv-deploy] Fehler: ' . $response->get_error_message() );
	} else {
		$code = wp_remote_retrieve_response_code( $response );
		// 204 = OK (kein Inhalt). Alles andere protokollieren.
		if ( 204 !== (int) $code ) {
			error_log( '[vv-deploy] GitHub-Antwort ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
		}
	}
}
