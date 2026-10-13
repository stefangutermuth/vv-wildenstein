<?php
/**
 * Plugin Name: VV Termin-Einbettung
 * Description: Kurzcode [vv_termin id="…"] zeigt die Beschreibung eines Termins
 *              (vw_event) an anderer Stelle, etwa auf einer Seite von
 *              vv-wildenstein.com. Der Text wird so nur am Termin gepflegt;
 *              die Gemeinde-Websites lesen denselben Termin über die REST-Schnittstelle.
 *              Ist der Termin vorbei, nicht veröffentlicht oder gelöscht, bleibt
 *              der Kurzcode leer.
 *
 *              Teilnehmerlisten (<li><strong>Name</strong>, Straße Nr …) bekommen
 *              dieselben Nummern und Zeichen wie auf der Gemeinde-Website
 *              (dort: apps/gruenhainichen/src/lib/teilnehmer.ts). Mit
 *              karte="https://www.gruenhainichen.com/veranstaltungen/…/karte/"
 *              steht nach der letzten Teilnehmerliste die Karte von dort, als Rahmen.
 * Author:      GUMU
 * Version:     1.1.0
 *
 * Installation: nach  wp-content/mu-plugins/vv-termin-einbettung.php  kopieren
 * (läuft automatisch über .github/workflows/deploy-mu-plugins.yml).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Von diesen Websites darf die Karte kommen (eigene Seiten des Verbands). */
const VV_TERMIN_KARTEN_HOSTS = array( 'www.gruenhainichen.com', 'gruenhainichen.com', 'www.boernichen.de', 'boernichen.de' );

/** Zeichen für Parkplatz, Shuttle-Haltestelle, WC und Essen, wie auf der Karte. */
function vv_termin_zeichen( $art, $text = '', $shuttle = false ) {
	$besteck = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>';
	$inhalt  = 'essen' === $art ? $besteck : esc_html( $text );
	$halt    = ( 'parken' === $art && $shuttle ) ? '<span class="vv-kz__h">H</span>' : '';
	return '<span class="vv-kz vv-kz--' . esc_attr( $art ) . '">' . $inhalt . $halt . '</span>';
}

function vv_termin_attribut( $tag, $name ) {
	return preg_match( '~\b' . preg_quote( $name, '~' ) . '=["\']([^"\']*)["\']~', $tag, $m ) ? trim( $m[1] ) : '';
}

/** Ist das ein Teilnehmer mit Adresse (und kein weiterer Kartenpunkt)? */
function vv_termin_ist_teilnehmer( $attribute, $adresse ) {
	if ( false !== strpos( $attribute, 'data-karte=' ) ) {
		return false;
	}
	$strasse = trim( explode( ',', wp_strip_all_tags( $adresse ) )[0] );
	return (bool) preg_match( '~\s\d+\s?[a-z]?$~iu', $strasse );
}

/**
 * Nummern vor die Teilnehmer, Zeichen vor Parkplätze, Haltestellen, Essen und WC.
 * Gibt die Zahl der Teilnehmer zurück (unter drei bleibt der Text, wie er ist).
 */
function vv_termin_nummern( $html, &$anzahl ) {
	$muster = '~<li\b([^>]*)>\s*<strong>(.*?)</strong>\s*,\s*([^<]+?)\s*(?=<br\s*/?>|</li>)~su';
	$anzahl = 0;
	if ( preg_match_all( $muster, $html, $alle, PREG_SET_ORDER ) ) {
		foreach ( $alle as $m ) {
			if ( vv_termin_ist_teilnehmer( $m[1], $m[3] ) ) {
				$anzahl++;
			}
		}
	}
	if ( $anzahl < 3 ) {
		$anzahl = 0;
		return $html;
	}

	$arten = array( 'parken' => 'P', 'haltestelle' => 'H', 'wc' => 'WC', 'essen' => '' );
	$nr    = 0;
	$html  = preg_replace_callback(
		$muster,
		function ( $m ) use ( &$nr, $arten ) {
			if ( ! vv_termin_ist_teilnehmer( $m[1], $m[3] ) ) {
				return $m[0];
			}
			$zeichen = vv_termin_attribut( $m[1], 'data-nr' );
			if ( '' === $zeichen ) {
				$zeichen = (string) ++$nr;
			}
			$dazu  = '';
			$wahl  = preg_split( '~[\s,]+~', vv_termin_attribut( $m[1], 'data-zeichen' ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $arten as $art => $text ) {
				if ( in_array( $art, $wahl, true ) ) {
					$dazu .= vv_termin_zeichen( $art, $text );
				}
			}
			return '<li class="vv-tn"><span class="vv-tn__nr" aria-hidden="true">' . esc_html( $zeichen ) . '</span><strong>' . $m[2] . '</strong>, ' . $m[3]
				. ( $dazu ? ' <span class="vv-tn__zeichen">' . $dazu . '</span>' : '' );
		},
		$html
	);

	// Weitere Punkte: „P1 Grundschule“ wird zum Zeichen P1 und dem Namen Grundschule.
	$html = preg_replace_callback(
		'~<li\b([^>]*\bdata-karte=[^>]*)>\s*<strong>(.*?)</strong>~su',
		function ( $m ) use ( $arten ) {
			$art = vv_termin_attribut( $m[1], 'data-karte' );
			if ( ! isset( $arten[ $art ] ) ) {
				return $m[0];
			}
			$text = $arten[ $art ];
			$name = $m[2];
			if ( 'parken' === $art && preg_match( '~^\s*(P\d*)\s+(.+)$~su', $name, $k ) ) {
				$text = $k[1];
				$name = $k[2];
			}
			$shuttle = (bool) preg_match( '~^(ja|1|true)$~i', vv_termin_attribut( $m[1], 'data-shuttle' ) );
			return '<li class="vv-kp">' . vv_termin_zeichen( $art, $text, $shuttle ) . '<strong>' . $name . '</strong>';
		},
		$html
	);

	return $html;
}

/** Adresse der Karte prüfen: nur https und nur von den eigenen Websites. */
function vv_termin_karten_adresse( $adresse ) {
	$adresse = trim( (string) $adresse );
	$teile   = $adresse ? wp_parse_url( $adresse ) : false;
	if ( ! $teile || 'https' !== ( $teile['scheme'] ?? '' ) || ! in_array( $teile['host'] ?? '', VV_TERMIN_KARTEN_HOSTS, true ) ) {
		return '';
	}
	return esc_url( $adresse );
}

add_shortcode( 'vv_termin', function ( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0, 'karte' => '' ), $atts, 'vv_termin' );
	$post = get_post( (int) $atts['id'] );
	if ( ! $post || 'vw_event' !== $post->post_type || 'publish' !== $post->post_status ) {
		return '';
	}

	$ende = get_post_meta( $post->ID, '_vw_event_end', true ) ?: get_post_meta( $post->ID, '_vw_event_start', true );
	if ( $ende && strtotime( $ende ) < current_time( 'timestamp' ) ) {
		return '';
	}

	// Kein the_content: Der Kurzcode steht selbst in einem Inhalt, und die
	// Seitenbaukasten-Filter sollen nicht ein zweites Mal darüber laufen.
	$html   = wp_kses_post( wpautop( do_shortcode( $post->post_content ) ) );
	$anzahl = 0;
	$html   = vv_termin_nummern( $html, $anzahl );

	// Die Karte folgt direkt auf die letzte Teilnehmerliste, wie auf der Gemeinde-Website.
	$karte = $anzahl ? vv_termin_karten_adresse( $atts['karte'] ) : '';
	if ( $karte ) {
		$letzter = strrpos( $html, 'class="vv-tn"' );
		$schnitt = false !== $letzter ? strpos( $html, '</ul>', $letzter ) : false;
		if ( false !== $schnitt ) {
			$schnitt += strlen( '</ul>' );
			$rahmen   = '<div class="vv-termin__karte" id="karte"><iframe data-vv-karte src="' . $karte . '" title="Karte der Teilnehmer" loading="lazy" scrolling="no"></iframe></div>';
			$html     = substr( $html, 0, $schnitt ) . $rahmen . substr( $html, $schnitt );
			add_action( 'wp_footer', 'vv_termin_karten_skript', 50 );
		}
	}

	return vv_termin_stil() . '<div class="vv-termin">' . $html . '</div>';
} );

/** Gestaltung der Nummern und Zeichen, einmal je Seite. Farben wie auf der Karte. */
function vv_termin_stil() {
	static $fertig = false;
	if ( $fertig ) {
		return '';
	}
	$fertig = true;
	return '<style>'
		. '.vv-termin ul:has(>li.vv-tn),.vv-termin ul:has(>li.vv-kp){margin-left:0;padding-left:0}'
		. '.vv-termin li.vv-tn,.vv-termin li.vv-kp{list-style:none;position:relative;margin-left:0}'
		. '.vv-termin li.vv-tn{padding-left:40px;margin-bottom:.7em}'
		. '.vv-termin li.vv-kp{padding-left:56px;min-height:26px;margin-bottom:.45em}'
		. '.vv-tn__nr{position:absolute;left:0;top:-.1em;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#1A3D27;color:#fff;font-size:13px;font-weight:700;line-height:1}'
		. '.vv-tn__zeichen{display:inline-flex;gap:4px;margin-left:6px;vertical-align:-.35em}'
		. '.vv-kz{display:inline-flex;align-items:center;justify-content:center;gap:3px;box-sizing:border-box;min-width:24px;height:24px;padding:0 5px;border-radius:6px;background:#1A3D27;color:#fff;font-size:12px;font-weight:700;line-height:1;white-space:nowrap}'
		. '.vv-kz--parken{background:#1957aa}'
		. '.vv-kz--haltestelle{width:24px;padding:0;border-radius:50%;border:2px solid #1A3D27;background:#ffd60a;color:#142B1F}'
		. '.vv-kz__h{display:flex;align-items:center;justify-content:center;width:16px;height:16px;margin-right:-1px;border-radius:50%;background:#ffd60a;color:#142B1F;font-size:10px}'
		. '.vv-tn__zeichen .vv-kz{min-width:22px;height:22px;font-size:11px}'
		. '.vv-tn__zeichen .vv-kz--haltestelle{width:22px}'
		. '.vv-termin li.vv-kp>.vv-kz{position:absolute;left:0;top:0}'
		. '.vv-termin__karte{margin:1.5rem 0;scroll-margin-top:110px}'
		. '.vv-termin__karte iframe{display:block;width:100%;height:720px;border:0}'
		. '</style>';
}

/** Die Karte meldet ihre Höhe, der Rahmen passt sich an (kein zweiter Rollbalken). */
function vv_termin_karten_skript() {
	static $fertig = false;
	if ( $fertig ) {
		return;
	}
	$fertig = true;
	?>
	<script>
	(function () {
		window.addEventListener('message', function (e) {
			if (!e.data || e.data.typ !== 'grh-karte-hoehe') return;
			var hoehe = parseInt(e.data.hoehe, 10);
			if (!hoehe) return;
			document.querySelectorAll('iframe[data-vv-karte]').forEach(function (f) {
				try {
					if (f.contentWindow === e.source && new URL(f.src).origin === e.origin) {
						f.style.height = Math.max(300, Math.min(2000, hoehe)) + 'px';
					}
				} catch (x) {}
			});
		});
	})();
	</script>
	<?php
}
