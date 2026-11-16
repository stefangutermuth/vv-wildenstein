<?php
/**
 * Plugin Name: VV → Projekte
 * Description: Eigener Inhaltstyp für die Projekte von Verband und Gemeinden. Bis
 *              November 2026 war jedes Projekt eine Unterseite der Seite „Projekte“
 *              (/projekte/…). Seiten lassen sich aber nicht nach Kategorien ordnen
 *              und tauchen in den Astro-Seiten nur als lose Inhaltsseiten auf.
 *              Jetzt: ein Projekt = ein Eintrag, mit Projektkategorien.
 *
 *              Die Adressen bleiben gleich: Projekte liegen weiter unter
 *              /projekte/<kürzel>/. Die Seite „Projekte“ (/projekte/) bleibt die
 *              Übersicht; ihr Kachelgitter zeigt jetzt diesen Inhaltstyp statt der
 *              Unterseiten.
 * Author:      GUMU
 * Version:     1.0.0
 *
 * Installation: nach wp-content/mu-plugins/vv-projekte.php kopieren
 * (läuft automatisch über .github/workflows/deploy-mu-plugins.yml).
 * Nach der ersten Installation einmal `wp rewrite flush`, damit /projekte/<kürzel>/
 * auf den Inhaltstyp zeigt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VV_Projekte {

	const TYP = 'projekt';
	const TAX = 'projektkategorie';

	/** Seite „Projekte“ (/projekte/): Übersicht und Brotkrume über jedem Projekt. */
	const UEBERSICHT = 36468;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'registriere' ) );
		// Nach ACF, das die Taxonomie „gemeindeteil“ anlegt.
		add_action( 'init', array( __CLASS__, 'ortsteile' ), 20 );
		add_filter( 'vc_check_post_type_validation', array( __CLASS__, 'seitenbaukasten' ), 10, 2 );
		add_filter( 'us_breadcrumbs_output', array( __CLASS__, 'brotkrumen' ), 10, 2 );
	}

	public static function registriere(): void {
		/* Kategorien kommen vom Verband noch. Sie sind bewusst nicht öffentlich:
		   Die alte Seite bekäme sonst eigene Kategorie-Archive, die niemand
		   gestaltet hat, und das Theme setzte die Kategorie statt „Projekte“ in
		   die Brotkrumen. Gefiltert wird auf der Übersicht. */
		register_taxonomy( self::TAX, self::TYP, array(
			'labels' => array(
				'name'          => 'Projektkategorien',
				'singular_name' => 'Projektkategorie',
				'menu_name'     => 'Kategorien',
				'all_items'     => 'Alle Kategorien',
				'add_new_item'  => 'Neue Kategorie',
				'edit_item'     => 'Kategorie bearbeiten',
				'search_items'  => 'Kategorien suchen',
				'not_found'     => 'Keine Kategorien gefunden',
			),
			'hierarchical'       => true,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => true,
			'rest_base'          => 'projektkategorien',
			'rewrite'            => false,
			'query_var'          => false,
		) );

		register_post_type( self::TYP, array(
			'labels' => array(
				'name'               => 'Projekte',
				'singular_name'      => 'Projekt',
				'add_new'            => 'Neues Projekt',
				'add_new_item'       => 'Neues Projekt',
				'edit_item'          => 'Projekt bearbeiten',
				'new_item'           => 'Neues Projekt',
				'view_item'          => 'Projekt ansehen',
				'all_items'          => 'Alle Projekte',
				'search_items'       => 'Projekte suchen',
				'not_found'          => 'Keine Projekte gefunden',
				'not_found_in_trash' => 'Keine Projekte im Papierkorb',
				'menu_name'          => 'Projekte',
			),
			'public'        => true,
			'show_in_rest'  => true,
			'rest_base'     => 'projekte',
			'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author' ),
			'taxonomies'    => array( self::TAX ),
			'menu_icon'     => 'dashicons-portfolio',
			'menu_position' => 24,
			/* Kein Archiv: /projekte/ ist die Seite „Projekte“. Ein Archiv unter
			   derselben Adresse hätte Vorrang vor der Seite und sie verdrängt. */
			'has_archive'   => false,
			'rewrite'       => array( 'slug' => 'projekte', 'with_front' => false ),
		) );
	}

	/** Ortsteile wie bei Seiten und Beiträgen, damit Gemeinde-Websites ihre Projekte finden. */
	public static function ortsteile(): void {
		if ( taxonomy_exists( 'gemeindeteil' ) ) {
			register_taxonomy_for_object_type( 'gemeindeteil', self::TYP );
		}
	}

	/** Projekte im WPBakery-Seitenbaukasten bearbeiten wie bisher die Seiten. */
	public static function seitenbaukasten( $gueltig, $typ ) {
		if ( self::TYP === $typ && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return $gueltig;
	}

	/**
	 * Brotkrumen „Start › Projekte › Projekt“ wie bei den früheren Unterseiten.
	 *
	 * Das Theme (Impreza) setzt bei eigenen Inhaltstypen sonst die erste
	 * öffentliche Taxonomie ein, hier den Ortsteil: „Start › Grünhainichen ›
	 * Projekt“, mit Verweis auf ein Ortsteil-Archiv. Deshalb wird die Kette hier
	 * mit dem eigenen Baustein des Themes neu gebaut, nur mit der Seite
	 * „Projekte“ an der Stelle der automatischen Vorfahren.
	 */
	public static function brotkrumen( $ausgabe, $a ) {
		if (
			null !== $ausgabe
			|| ! is_singular( self::TYP )
			|| ! class_exists( 'US_Breadcrumbs' )
			|| ! function_exists( 'us_implode_atts' )
		) {
			return $ausgabe;
		}

		$items   = array();
		$gesetzt = false;
		foreach ( (array) ( $a['items'] ?? array() ) as $item ) {
			$quelle = is_array( $item ) && ! empty( $item['source'] ) ? $item['source'] : 'auto';
			if ( 'auto' === $quelle ) {
				if ( ! $gesetzt ) {
					$items[] = array( 'source' => 'page', 'page' => self::UEBERSICHT );
					$gesetzt = true;
				}
				continue;
			}
			$items[] = $item;
		}
		if ( ! $gesetzt ) {
			$items[] = array( 'source' => 'page', 'page' => self::UEBERSICHT );
		}

		// Wie im Element-Template des Themes (templates/elements/breadcrumbs.php)
		$atts      = (array) ( $a['atts'] ?? array() );
		$item_atts = (array) ( $a['item_atts'] ?? array() );
		$link_attr = $name_attr = $position_attr = '';
		if ( ! empty( $a['schema'] ) ) {
			$atts['itemscope']      = '';
			$atts['itemtype']       = 'http://schema.org/BreadcrumbList';
			$item_atts['itemscope'] = '';
			$item_atts['itemprop']  = 'itemListElement';
			$item_atts['itemtype']  = 'http://schema.org/ListItem';
			$link_attr              = ' itemprop="item"';
			$name_attr              = ' itemprop="name"';
			$position_attr          = ' itemprop="position"';
		}

		$kette = new US_Breadcrumbs(
			(string) ( $a['delimiter'] ?? '' ),
			$items,
			'<div' . us_implode_atts( $item_atts ) . '>',
			'</div>',
			$link_attr,
			$name_attr,
			$position_attr,
			! empty( $a['show_current'] ),
			false,
			false
		);
		$html = $kette->render();

		return '' === $html ? '' : '<nav' . us_implode_atts( $atts ) . '>' . $html . '</nav>';
	}
}

VV_Projekte::init();
