<?php
/**
 * Plugin Name: Events im VV Wildenstein
 * Plugin URI:  https://vv-wildenstein.com
 * Description: Headless-CMS-Backend für Veranstaltungen — CPT, REST-API, Frontend-Submission, iCal, Cloudflare-Build-Hooks.
 * Version:     1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author:      gumu Agentur
 * Text Domain: vw-events
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VW_EVENTS_VERSION', '1.0.0' );
define( 'VW_EVENTS_FILE', __FILE__ );
define( 'VW_EVENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'VW_EVENTS_URL', plugin_dir_url( __FILE__ ) );

require_once VW_EVENTS_DIR . 'includes/helpers.php';
require_once VW_EVENTS_DIR . 'includes/class-cpt.php';
require_once VW_EVENTS_DIR . 'includes/class-meta.php';
require_once VW_EVENTS_DIR . 'includes/class-admin-ui.php';
require_once VW_EVENTS_DIR . 'includes/class-rest-events.php';
require_once VW_EVENTS_DIR . 'includes/class-rest-ical.php';
require_once VW_EVENTS_DIR . 'includes/class-rest-submissions.php';
require_once VW_EVENTS_DIR . 'includes/class-frontend-form.php';
require_once VW_EVENTS_DIR . 'includes/class-single-view.php';
require_once VW_EVENTS_DIR . 'includes/class-mailer.php';
require_once VW_EVENTS_DIR . 'includes/class-webhooks.php';
require_once VW_EVENTS_DIR . 'includes/class-turnstile.php';
require_once VW_EVENTS_DIR . 'includes/class-importer.php';
require_once VW_EVENTS_DIR . 'includes/class-multisite.php';

add_action( 'plugins_loaded', static function () {
    load_plugin_textdomain( 'vw-events', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    VW_Events_CPT::init();
    VW_Events_Meta::init();
    VW_Events_Admin_UI::init();
    VW_Events_REST_Events::init();
    VW_Events_REST_Ical::init();
    VW_Events_REST_Submissions::init();
    VW_Events_Frontend_Form::init();
    VW_Events_Single_View::init();
    VW_Events_Mailer::init();
    VW_Events_Webhooks::init();
    VW_Events_Importer::init();
} );

register_activation_hook( __FILE__, static function () {
    VW_Events_CPT::register_post_type();
    VW_Events_CPT::register_taxonomies();
    VW_Events_CPT::ensure_default_terms();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function () {
    flush_rewrite_rules();
} );
