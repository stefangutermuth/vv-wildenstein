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
 * Version:     1.0.0
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
}

VV_Stellenanzeigen::init();
