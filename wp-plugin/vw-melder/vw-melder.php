<?php
/**
 * Plugin Name: Mängelmelder im VV Wildenstein
 * Plugin URI:  https://vv-wildenstein.com
 * Description: Headless-CMS-Backend für den Mängelmelder — CPT „Meldungen", Anliegen- & Status-Taxonomien, Standort-Felder, REST-API (inkl. GeoJSON für die Karte). Migrations-Import per WP-CLI. Auto-Deploy des Astro-Frontends bei Änderungen.
 * Version:     1.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author:      gumu Agentur
 * Text Domain: vw-melder
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VW_MELDER_VERSION', '1.1.0' );
define( 'VW_MELDER_FILE', __FILE__ );
define( 'VW_MELDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'VW_MELDER_URL', plugin_dir_url( __FILE__ ) );

require_once VW_MELDER_DIR . 'includes/helpers.php';
require_once VW_MELDER_DIR . 'includes/class-cpt.php';
require_once VW_MELDER_DIR . 'includes/class-meta.php';
require_once VW_MELDER_DIR . 'includes/class-admin-ui.php';
require_once VW_MELDER_DIR . 'includes/class-settings.php';
require_once VW_MELDER_DIR . 'includes/class-deploy-hook.php';
require_once VW_MELDER_DIR . 'includes/class-communication.php';
require_once VW_MELDER_DIR . 'includes/class-public-notes.php';
require_once VW_MELDER_DIR . 'includes/class-rest-meldungen.php';
require_once VW_MELDER_DIR . 'includes/class-rest-submit.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once VW_MELDER_DIR . 'includes/class-importer.php';
}

add_action( 'plugins_loaded', static function () {
    load_plugin_textdomain( 'vw-melder', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    VW_Melder_CPT::init();
    VW_Melder_Meta::init();
    VW_Melder_Admin_UI::init();
    VW_Melder_Settings::init();
    VW_Melder_Deploy_Hook::init();
    VW_Melder_Communication::init();
    VW_Melder_Public_Notes::init();
    VW_Melder_REST_Meldungen::init();
    VW_Melder_REST_Submit::init();
} );

register_activation_hook( __FILE__, static function () {
    VW_Melder_CPT::register_post_type();
    VW_Melder_CPT::register_taxonomies();
    VW_Melder_CPT::ensure_default_terms();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () {
    flush_rewrite_rules();
} );
