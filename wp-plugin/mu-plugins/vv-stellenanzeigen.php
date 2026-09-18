<?php
/**
 * Plugin Name: VV → Stellenanzeigen
 * Description: Eigener Inhaltstyp für Stellenanzeigen. Vorher lagen sie als
 *              Bild-Kurzcodes in der Seite „Stellenanzeigen" — dadurch waren sie
 *              nicht pflegbar, tauchten nur auf einer Seite auf und veraltete
 *              Anzeigen fielen niemandem auf (im September 2026 musste eine
 *              abgelaufene Anzeige von Hand gesucht und entfernt werden).
 *              Jetzt: eine Anzeige = ein Eintrag, mit Ablaufdatum und
 *              Standort-Auswahl für alle drei Websites.
 * Author:      GUMU
 * Version:     1.1.0
 *
 * 1.1.0 — Kurzcode [vvw_stellenanzeigen] für die alte, noch öffentliche
 *         Verbandsseite. Deren Seite „Stellenanzeigen" war ein handgemachter
 *         Spiegel aus zwei Bildern; am 18.09.2026 wurde eine Anzeige ersetzt,
 *         die drei Astro-Seiten zogen mit, vv-wildenstein.com nicht — die
 *         Verwaltung musste nachfragen. Jetzt liest auch diese Seite aus dem
 *         Inhaltstyp.
 *
 * Installation: nach wp-content/mu-plugins/vv-stellenanzeigen.php kopieren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VV_Stellenanzeigen {

	const TYP     = 'vvw_stelle';
	const TAX_ORT = 'vvw_stelle_ort';
	const TAX_ART = 'vvw_stelle_art';

	/** Meta-Schlüssel → Beschriftung im Backend */
	const FELDER = array(
		'_vvw_stelle_arbeitgeber' => 'Arbeitgeber',
		'_vvw_stelle_ort'         => 'Arbeitsort',
		'_vvw_stelle_umfang'      => 'Umfang (z. B. Vollzeit, Teilzeit, Minijob)',
		'_vvw_stelle_ab'          => 'Zu besetzen ab',
		'_vvw_stelle_frist'       => 'Bewerbungsfrist',
		'_vvw_stelle_bis'         => 'Anzeige läuft ab am',
		'_vvw_stelle_email'       => 'E-Mail für Bewerbungen',
		'_vvw_stelle_telefon'     => 'Telefon',
		'_vvw_stelle_website'     => 'Website',
		'_vvw_stelle_datei'       => 'Anzeige als PDF/Bild (Medien-ID)',
	);

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'registriere' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post_' . self::TYP, array( __CLASS__, 'speichern' ), 10, 2 );

		add_filter( 'manage_' . self::TYP . '_posts_columns', array( __CLASS__, 'spalten' ) );
		add_action( 'manage_' . self::TYP . '_posts_custom_column', array( __CLASS__, 'spalte_ausgeben' ), 10, 2 );

		add_action( 'rest_api_init', array( __CLASS__, 'rest_felder' ) );
		add_action( 'admin_notices', array( __CLASS__, 'hinweis_abgelaufen' ) );
		add_shortcode( 'vvw_stellenanzeigen', array( __CLASS__, 'kurzcode' ) );
	}

	public static function registriere(): void {
		register_post_type( self::TYP, array(
			'labels' => array(
				'name'          => 'Stellenanzeigen',
				'singular_name' => 'Stellenanzeige',
				'add_new_item'  => 'Neue Stellenanzeige',
				'edit_item'     => 'Stellenanzeige bearbeiten',
				'search_items'  => 'Stellenanzeigen suchen',
				'menu_name'     => 'Stellenanzeigen',
			),
			'public'        => true,
			'show_in_rest'  => true,
			'rest_base'     => 'stellenanzeigen',
			'supports'      => array( 'title', 'editor', 'thumbnail', 'revisions', 'author' ),
			'menu_icon'     => 'dashicons-businessperson',
			'menu_position' => 23,
			'has_archive'   => false,
			'rewrite'       => array( 'slug' => 'stellenanzeige' ),
		) );

		// Wo erscheint die Anzeige? Gleiche Einteilung wie bei den Veranstaltungen,
		// damit die Redaktion nicht zwei Systeme lernen muss.
		register_taxonomy( self::TAX_ORT, self::TYP, array(
			'labels' => array(
				'name'          => 'Anzeigen auf',
				'singular_name' => 'Website',
				'menu_name'     => 'Anzeigen auf',
			),
			'public'            => true,
			'show_in_rest'      => true,
			'rest_base'         => 'stellenanzeige-ort',
			'hierarchical'      => true,   // Mehrfachauswahl per Kästchen
			'show_admin_column' => true,
		) );

		register_taxonomy( self::TAX_ART, self::TYP, array(
			'labels' => array(
				'name'          => 'Art',
				'singular_name' => 'Art',
				'menu_name'     => 'Art',
			),
			'public'            => true,
			'show_in_rest'      => true,
			'rest_base'         => 'stellenanzeige-art',
			'hierarchical'      => true,
			'show_admin_column' => true,
		) );

		self::grundbegriffe();
	}

	/** Standard-Einträge einmalig anlegen, damit die Auswahl nicht leer ist. */
	private static function grundbegriffe(): void {
		if ( get_option( 'vvw_stelle_begriffe_v1' ) ) {
			return;
		}
		foreach ( array(
			'verband-weit'   => 'Alle Websites',
			'gruenhainichen' => 'Grünhainichen',
			'boernichen'     => 'Börnichen/Erzgeb.',
		) as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX_ORT ) ) {
				wp_insert_term( $name, self::TAX_ORT, array( 'slug' => $slug ) );
			}
		}
		foreach ( array(
			'unternehmen' => 'Unternehmen',
			'verwaltung'  => 'Verwaltung & Gemeinde',
		) as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX_ART ) ) {
				wp_insert_term( $name, self::TAX_ART, array( 'slug' => $slug ) );
			}
		}
		update_option( 'vvw_stelle_begriffe_v1', 1 );
	}

	public static function metabox(): void {
		add_meta_box(
			'vvw_stelle_details',
			'Angaben zur Stelle',
			array( __CLASS__, 'metabox_ausgeben' ),
			self::TYP,
			'normal',
			'high'
		);
	}

	public static function metabox_ausgeben( $post ): void {
		wp_nonce_field( 'vvw_stelle_speichern', 'vvw_stelle_nonce' );
		$datei_id = (int) get_post_meta( $post->ID, '_vvw_stelle_datei', true );
		?>
		<style>
			.vvw-stelle-tab th { width: 230px; text-align: left; vertical-align: top; padding-top: 10px; }
			.vvw-stelle-tab td { padding: 6px 0; }
			.vvw-stelle-tab input[type="text"],
			.vvw-stelle-tab input[type="email"],
			.vvw-stelle-tab input[type="url"],
			.vvw-stelle-tab input[type="date"] { width: 100%; max-width: 420px; }
			.vvw-stelle-hinweis { color: #646970; font-size: 12px; margin: 4px 0 0; }
			.vvw-stelle-datei { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
		</style>
		<table class="vvw-stelle-tab">
			<?php foreach ( self::FELDER as $key => $label ) : ?>
				<?php if ( $key === '_vvw_stelle_datei' ) { continue; } ?>
				<tr>
					<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input
							type="<?php echo esc_attr( self::feldtyp( $key ) ); ?>"
							id="<?php echo esc_attr( $key ); ?>"
							name="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( (string) get_post_meta( $post->ID, $key, true ) ); ?>"
						>
						<?php if ( $key === '_vvw_stelle_bis' ) : ?>
							<p class="vvw-stelle-hinweis">
								Nach diesem Tag verschwindet die Anzeige von allen Websites — ohne
								dass jemand daran denken muss. Leer lassen heißt: läuft weiter.
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th><label>Anzeige als PDF/Bild</label></th>
				<td>
					<div class="vvw-stelle-datei">
						<input type="hidden" id="_vvw_stelle_datei" name="_vvw_stelle_datei"
						       value="<?php echo esc_attr( (string) $datei_id ); ?>">
						<button type="button" class="button" id="vvw-stelle-waehlen">Datei auswählen</button>
						<button type="button" class="button-link" id="vvw-stelle-loeschen"
						        <?php echo $datei_id ? '' : 'style="display:none"'; ?>>entfernen</button>
						<span id="vvw-stelle-name">
							<?php echo $datei_id ? esc_html( (string) get_the_title( $datei_id ) ) : ''; ?>
						</span>
					</div>
					<p class="vvw-stelle-hinweis">
						Viele Betriebe schicken einen fertigen Aushang. Der liegt hier — die Felder
						oben bleiben trotzdem nützlich, weil sich damit filtern und suchen lässt.
					</p>
				</td>
			</tr>
		</table>
		<script>
		( function () {
			const knopf = document.getElementById( 'vvw-stelle-waehlen' );
			const weg   = document.getElementById( 'vvw-stelle-loeschen' );
			const feld  = document.getElementById( '_vvw_stelle_datei' );
			const name  = document.getElementById( 'vvw-stelle-name' );
			if ( ! knopf || ! window.wp || ! wp.media ) { return; }
			let rahmen;
			knopf.addEventListener( 'click', function () {
				rahmen = rahmen || wp.media( {
					title: 'Anzeige auswählen',
					button: { text: 'Übernehmen' },
					library: { type: [ 'image', 'application/pdf' ] },
					multiple: false,
				} );
				rahmen.off( 'select' ).on( 'select', function () {
					const d = rahmen.state().get( 'selection' ).first().toJSON();
					feld.value = d.id;
					name.textContent = d.title || d.filename;
					weg.style.display = '';
				} );
				rahmen.open();
			} );
			weg.addEventListener( 'click', function () {
				feld.value = '';
				name.textContent = '';
				weg.style.display = 'none';
			} );
		} )();
		</script>
		<?php
	}

	private static function feldtyp( string $key ): string {
		if ( in_array( $key, array( '_vvw_stelle_ab', '_vvw_stelle_frist', '_vvw_stelle_bis' ), true ) ) {
			return 'date';
		}
		if ( $key === '_vvw_stelle_email' ) {
			return 'email';
		}
		if ( $key === '_vvw_stelle_website' ) {
			return 'url';
		}
		return 'text';
	}

	public static function speichern( int $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! isset( $_POST['vvw_stelle_nonce'] ) || ! wp_verify_nonce( $_POST['vvw_stelle_nonce'], 'vvw_stelle_speichern' ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( array_keys( self::FELDER ) as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) { continue; }
			$wert = trim( (string) wp_unslash( $_POST[ $key ] ) );
			if ( $key === '_vvw_stelle_email' ) {
				$wert = sanitize_email( $wert );
			} elseif ( $key === '_vvw_stelle_website' ) {
				$wert = esc_url_raw( $wert );
			} elseif ( $key === '_vvw_stelle_datei' ) {
				$wert = (string) absint( $wert );
			} else {
				$wert = sanitize_text_field( $wert );
			}
			if ( $wert === '' || $wert === '0' ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $wert );
			}
		}

		// Ohne Standort-Angabe würde die Anzeige nirgends erscheinen — dann
		// gilt „Alle Websites", was der Erwartung entspricht.
		if ( ! wp_get_post_terms( $post_id, self::TAX_ORT, array( 'fields' => 'ids' ) ) ) {
			wp_set_object_terms( $post_id, 'verband-weit', self::TAX_ORT, false );
		}
	}

	public static function spalten( array $cols ): array {
		$neu = array();
		foreach ( $cols as $k => $v ) {
			$neu[ $k ] = $v;
			if ( $k === 'title' ) {
				$neu['vvw_arbeitgeber'] = 'Arbeitgeber';
				$neu['vvw_laeuft_bis']  = 'Läuft ab';
			}
		}
		return $neu;
	}

	public static function spalte_ausgeben( string $col, int $post_id ): void {
		if ( $col === 'vvw_arbeitgeber' ) {
			echo esc_html( (string) ( get_post_meta( $post_id, '_vvw_stelle_arbeitgeber', true ) ?: '—' ) );
			return;
		}
		if ( $col !== 'vvw_laeuft_bis' ) {
			return;
		}
		$bis = (string) get_post_meta( $post_id, '_vvw_stelle_bis', true );
		if ( $bis === '' ) {
			echo '<span style="color:#646970">ohne Ende</span>';
			return;
		}
		$abgelaufen = $bis < current_time( 'Y-m-d' );
		printf(
			'<span style="color:%s">%s%s</span>',
			$abgelaufen ? '#d63638' : '#00a32a',
			esc_html( mysql2date( 'j. F Y', $bis ) ),
			$abgelaufen ? ' — abgelaufen' : ''
		);
	}

	/**
	 * Abgelaufene Anzeigen sind der Grund für diesen Inhaltstyp. Sie
	 * verschwinden von den Websites automatisch (siehe REST-Felder), im
	 * Backend werden sie hier aber sichtbar gemacht — damit die Redaktion
	 * entscheiden kann, ob verlängert oder gelöscht wird.
	 */
	public static function hinweis_abgelaufen(): void {
		global $pagenow, $typenow;
		if ( $pagenow !== 'edit.php' || $typenow !== self::TYP ) { return; }
		if ( ! current_user_can( 'edit_posts' ) ) { return; }

		$alt = get_posts( array(
			'post_type'      => self::TYP,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_vvw_stelle_bis',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '<',
					'type'    => 'DATE',
				),
			),
		) );
		if ( ! $alt ) { return; }

		echo '<div class="notice notice-warning"><p><strong>'
			. sprintf(
				esc_html( _n(
					'%d Stellenanzeige ist abgelaufen und erscheint nicht mehr auf den Websites.',
					'%d Stellenanzeigen sind abgelaufen und erscheinen nicht mehr auf den Websites.',
					count( $alt )
				) ),
				count( $alt )
			)
			. '</strong><br>Bitte Ablaufdatum verlängern oder die Anzeige löschen.</p><ul style="margin:0 0 4px 18px;list-style:disc">';
		foreach ( $alt as $id ) {
			printf(
				'<li><a href="%s">%s</a> — lief bis %s</li>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( (string) get_the_title( $id ) ),
				esc_html( mysql2date( 'j. F Y', (string) get_post_meta( $id, '_vvw_stelle_bis', true ) ) )
			);
		}
		echo '</ul></div>';
	}

	/** Alle Angaben gebündelt an die REST-Antwort hängen. */
	public static function rest_felder(): void {
		register_rest_field( self::TYP, 'vvw_stelle', array(
			'get_callback' => static function ( $post ) {
				$id  = (int) $post['id'];
				$aus = array();
				foreach ( array_keys( self::FELDER ) as $key ) {
					$kurz = str_replace( '_vvw_stelle_', '', $key );
					$wert = get_post_meta( $id, $key, true );
					$aus[ $kurz ] = is_string( $wert ) ? trim( $wert ) : $wert;
				}

				// Datei auflösen, damit die Frontends nicht nachfragen müssen
				$datei_id = (int) ( $aus['datei'] ?? 0 );
				$aus['datei'] = null;
				if ( $datei_id ) {
					$url = wp_get_attachment_url( $datei_id );
					if ( $url ) {
						$aus['datei'] = array(
							'url'  => $url,
							'typ'  => (string) get_post_mime_type( $datei_id ),
							'name' => (string) get_the_title( $datei_id ),
						);
					}
				}

				$bis = (string) ( $aus['bis'] ?? '' );
				$aus['abgelaufen'] = $bis !== '' && $bis < current_time( 'Y-m-d' );
				$aus['orte'] = wp_get_post_terms( $id, self::TAX_ORT, array( 'fields' => 'slugs' ) );
				$aus['art']  = wp_get_post_terms( $id, self::TAX_ART, array( 'fields' => 'slugs' ) );
				return $aus;
			},
			'schema' => array( 'type' => 'object' ),
		) );
	}

	/**
	 * [vvw_stellenanzeigen website="verband"]
	 *
	 * Gibt die Anzeigen aus dem Inhaltstyp aus — für die alte Verbandsseite,
	 * die nicht über Astro gebaut wird. Dieselben Regeln wie im Astro-
	 * Frontend (apps/verband/src/lib/cms-wordpress.ts, fetchWordPressStellen):
	 *   - abgelaufene Anzeigen (Feld „läuft ab am" vor heute) fallen weg
	 *   - nur Anzeigen ohne Standort, für „Alle Websites" oder für diese Website
	 *   - nächste Bewerbungsfrist zuerst, Anzeigen ohne Frist danach
	 * Weichen die beiden voneinander ab, zeigen zwei Seiten verschiedene
	 * Anzeigen — genau das war der Fehler, der diesen Kurzcode nötig machte.
	 */
	public static function kurzcode( $atts ): string {
		$atts    = shortcode_atts( array( 'website' => 'verband' ), $atts, 'vvw_stellenanzeigen' );
		$website = sanitize_key( $atts['website'] );
		$heute   = current_time( 'Y-m-d' );

		$eintraege = get_posts( array(
			'post_type'      => self::TYP,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		) );

		$stellen = array();
		foreach ( $eintraege as $p ) {
			$bis = (string) get_post_meta( $p->ID, '_vvw_stelle_bis', true );
			if ( $bis !== '' && $bis < $heute ) {
				continue;
			}
			$orte = wp_get_post_terms( $p->ID, self::TAX_ORT, array( 'fields' => 'slugs' ) );
			$orte = is_wp_error( $orte ) ? array() : $orte;
			if ( $orte && ! in_array( 'verband-weit', $orte, true ) && ! in_array( $website, $orte, true ) ) {
				continue;
			}
			$stellen[] = $p;
		}

		usort( $stellen, static function ( $a, $b ) {
			$fa = (string) get_post_meta( $a->ID, '_vvw_stelle_frist', true );
			$fb = (string) get_post_meta( $b->ID, '_vvw_stelle_frist', true );
			if ( $fa !== '' && $fb !== '' ) { return strcmp( $fa, $fb ); }
			if ( $fa !== '' ) { return -1; }
			if ( $fb !== '' ) { return 1; }
			return strcmp( $a->post_title, $b->post_title );
		} );

		if ( ! $stellen ) {
			return '<p class="vvw-stellen-leer">Derzeit liegen keine Stellenanzeigen vor.</p>';
		}

		$datum = static function ( string $iso ): string {
			return $iso === '' ? '' : (string) mysql2date( 'j. F Y', $iso . ' 12:00:00' );
		};
		$m = static function ( int $id, string $feld ): string {
			return trim( (string) get_post_meta( $id, '_vvw_stelle_' . $feld, true ) );
		};

		ob_start();
		?>
		<style>
			.vvw-stellen { display: grid; gap: 28px; margin: 8px 0 32px; }
			@media (min-width: 900px) { .vvw-stellen { grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; } }
			.vvw-stelle { border: 1px solid #e2e7ef; border-radius: 10px; padding: 22px 24px; background: #fff; }
			.vvw-stelle h3 { margin: 0 0 4px; font-size: 1.2rem; line-height: 1.3; }
			.vvw-stelle__firma { margin: 0; font-weight: 600; }
			.vvw-stelle__fakten { margin: 6px 0 0; font-size: .9rem; color: #5b6576; }
			.vvw-stelle__bild { display: block; margin-top: 16px; }
			.vvw-stelle__bild img { width: 100%; height: auto; border-radius: 6px; border: 1px solid #e2e7ef; }
			.vvw-stelle__text { margin-top: 14px; font-size: .95rem; line-height: 1.6; }
			.vvw-stelle__text p { margin: 0 0 .7em; }
			.vvw-stelle__frist { margin: 12px 0 0; font-size: .95rem; }
			.vvw-stelle__aktionen { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid #e2e7ef; font-size: .95rem; }
			.vvw-stelle__knopf { display: inline-block; padding: 9px 16px; border-radius: 8px; background: #13267b; color: #fff !important; font-weight: 600; text-decoration: none !important; }
			.vvw-stelle__knopf:hover { background: #24329b; }
		</style>
		<div class="vvw-stellen">
		<?php foreach ( $stellen as $p ) :
			$id        = $p->ID;
			$datei_id  = (int) $m( $id, 'datei' );
			$datei_url = $datei_id ? (string) wp_get_attachment_url( $datei_id ) : '';
			$ist_bild  = $datei_id && wp_attachment_is_image( $datei_id );
			$fakten    = array_filter( array(
				$m( $id, 'ort' ),
				$m( $id, 'umfang' ),
				$m( $id, 'ab' ) !== '' ? 'ab ' . $datum( $m( $id, 'ab' ) ) : '',
			) );
			?>
			<article class="vvw-stelle">
				<h3><?php echo esc_html( get_the_title( $p ) ); ?></h3>
				<?php if ( $m( $id, 'arbeitgeber' ) !== '' ) : ?>
					<p class="vvw-stelle__firma"><?php echo esc_html( $m( $id, 'arbeitgeber' ) ); ?></p>
				<?php endif; ?>
				<?php if ( $fakten ) : ?>
					<p class="vvw-stelle__fakten"><?php echo esc_html( implode( ' · ', $fakten ) ); ?></p>
				<?php endif; ?>

				<?php if ( $ist_bild ) :
					$gross = wp_get_attachment_image_src( $datei_id, 'large' ); ?>
					<a class="vvw-stelle__bild" href="<?php echo esc_url( $datei_url ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $gross ? $gross[0] : $datei_url ); ?>"
						     alt="<?php echo esc_attr( 'Aushang: ' . get_the_title( $p ) ); ?>" loading="lazy">
					</a>
				<?php endif; ?>

				<?php if ( trim( $p->post_content ) !== '' ) : ?>
					<div class="vvw-stelle__text"><?php echo wp_kses_post( $p->post_content ); ?></div>
				<?php endif; ?>

				<?php if ( $m( $id, 'frist' ) !== '' ) : ?>
					<p class="vvw-stelle__frist"><strong>Bewerbungsfrist:</strong> <?php echo esc_html( $datum( $m( $id, 'frist' ) ) ); ?></p>
				<?php endif; ?>

				<?php
				$hat_aktion = ( $datei_url && ! $ist_bild ) || $m( $id, 'email' ) !== '' || $m( $id, 'telefon' ) !== '' || $m( $id, 'website' ) !== '';
				if ( $hat_aktion ) : ?>
					<div class="vvw-stelle__aktionen">
						<?php if ( $datei_url && ! $ist_bild ) : ?>
							<a class="vvw-stelle__knopf" href="<?php echo esc_url( $datei_url ); ?>" target="_blank" rel="noopener">Anzeige als PDF öffnen</a>
						<?php endif; ?>
						<?php if ( $m( $id, 'email' ) !== '' ) : ?>
							<a href="mailto:<?php echo esc_attr( $m( $id, 'email' ) ); ?>"><?php echo esc_html( $m( $id, 'email' ) ); ?></a>
						<?php endif; ?>
						<?php if ( $m( $id, 'telefon' ) !== '' ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $m( $id, 'telefon' ) ) ); ?>"><?php echo esc_html( $m( $id, 'telefon' ) ); ?></a>
						<?php endif; ?>
						<?php if ( $m( $id, 'website' ) !== '' ) : ?>
							<a href="<?php echo esc_url( $m( $id, 'website' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', rtrim( $m( $id, 'website' ), '/' ) ) ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

VV_Stellenanzeigen::init();
