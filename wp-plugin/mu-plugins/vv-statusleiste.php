<?php
/**
 * Plugin Name: VV — Website-Status in der Admin-Bar
 * Description: Ampel oben in der Admin-Leiste für alle statischen Websites des Verbunds:
 *              Grün = letzter Deploy ok & Seite erreichbar · Blau (drehend) = Build läuft
 *              gerade · Rot = Deploy fehlgeschlagen oder Seite nicht erreichbar.
 * Version:     1.0.0
 *
 * Datenquellen: GitHub-Actions-Läufe der Deploy-Workflows (Token VV_DEPLOY_GH_TOKEN
 * aus wp-config.php, wie beim Deploy-Webhook) + kurzer Erreichbarkeits-Check der
 * Live-Domains. Ergebnis liegt 55 s im Site-Transient — die Admin-Bar rendert nur
 * den Cache, ein kleines Skript holt den Status per admin-ajax nach und hält ihn
 * ohne Neuladen aktuell. Sichtbar für Redakteure/Admins (edit_posts).
 *
 * Ablage: wp-content/mu-plugins/vv-statusleiste.php (lädt im ganzen Netzwerk)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class VV_Statusleiste {

	private const REPO      = 'stefangutermuth/vv-wildenstein';
	private const TRANSIENT = 'vv_statusleiste';
	private const TTL       = 55; // Sekunden Cache — JS pollt alle 60 s
	private const AJAX      = 'vv_statusleiste';

	/** Überwachte Websites: Workflow-Datei → Anzeige + Live-URLs. */
	private const ZIELE = [
		'deploy-allinkl.yml' => [
			'label' => 'Grünhainichen & Börnichen',
			'urls'  => [ 'https://www.gruenhainichen.com', 'https://boernichen.de' ],
		],
		'deploy-verband.yml' => [
			'label' => 'Verband (Staging 2026)',
			'urls'  => [ 'https://2026.vv-wildenstein.com' ],
		],
		'deploy-maengelmelder.yml' => [
			'label' => 'Mängelmelder',
			'urls'  => [ 'https://melder.vv-wildenstein.com' ],
		],
	];

	public static function init(): void {
		add_action( 'admin_bar_menu', [ __CLASS__, 'bar' ], 95 );
		add_action( 'wp_ajax_' . self::AJAX, [ __CLASS__, 'ajax' ] );
		add_action( 'admin_head', [ __CLASS__, 'assets' ] );
		add_action( 'wp_head', [ __CLASS__, 'assets' ] );
	}

	private static function darf(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/* ================= Status ermitteln ================= */

	/**
	 * @return array{aggregat:string,label:string,items:array<int,array{key:string,label:string,state:string,detail:string,url:string}>}
	 */
	public static function status( bool $frisch = false ): array {
		$cache = get_site_transient( self::TRANSIENT );
		if ( ! $frisch && is_array( $cache ) ) {
			return $cache;
		}

		$items = [];
		foreach ( self::ZIELE as $workflow => $ziel ) {
			$items[] = self::ziel_status( $workflow, $ziel );
		}

		// Aggregat: läuft > Fehler > ok.
		$agg   = 'ok';
		$label = 'Alle Seiten online';
		foreach ( $items as $i ) {
			if ( $i['state'] === 'fehler' ) { $agg = 'fehler'; $label = 'Fehler bei ' . $i['label']; }
		}
		foreach ( $items as $i ) {
			if ( $i['state'] === 'laeuft' ) { $agg = 'laeuft'; $label = 'Wird aktualisiert …'; }
		}

		$result = [ 'aggregat' => $agg, 'label' => $label, 'items' => $items ];
		set_site_transient( self::TRANSIENT, $result, self::TTL );
		return $result;
	}

	private static function ziel_status( string $workflow, array $ziel ): array {
		$out = [
			'key'    => sanitize_key( $workflow ),
			'label'  => $ziel['label'],
			'state'  => 'ok',
			'detail' => '',
			'url'    => $ziel['urls'][0],
		];

		// 1) Letzter Workflow-Lauf bei GitHub
		$run = self::letzter_lauf( $workflow );
		if ( $run === null ) {
			$out['state']  = 'unbekannt';
			$out['detail'] = 'GitHub nicht erreichbar';
			return $out;
		}
		if ( in_array( $run['status'], [ 'queued', 'in_progress', 'waiting', 'pending', 'requested' ], true ) ) {
			$out['state']  = 'laeuft';
			$out['detail'] = 'Build läuft seit ' . human_time_diff( strtotime( $run['run_started_at'] ?? $run['created_at'] ) );
			$out['url']    = $run['html_url'];
			return $out;
		}
		if ( ( $run['conclusion'] ?? '' ) !== 'success' ) {
			$out['state']  = 'fehler';
			$out['detail'] = 'Deploy ' . ( $run['conclusion'] ?: 'unklar' ) . ' — vor ' . human_time_diff( strtotime( $run['updated_at'] ) );
			$out['url']    = $run['html_url'];
			return $out;
		}
		$out['detail'] = 'aktualisiert vor ' . human_time_diff( strtotime( $run['updated_at'] ) );

		// 2) Erreichbarkeit der Live-Domains (nur wenn Deploy ok war)
		foreach ( $ziel['urls'] as $url ) {
			$resp = wp_remote_head( $url, [ 'timeout' => 4, 'redirection' => 3, 'user-agent' => 'vv-statusleiste' ] );
			$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
			if ( $code >= 400 || $code === 0 ) {
				$out['state']  = 'fehler';
				$out['detail'] = wp_parse_url( $url, PHP_URL_HOST ) . ' nicht erreichbar (' . ( $code ?: 'Timeout' ) . ')';
				$out['url']    = $url;
				return $out;
			}
		}
		return $out;
	}

	/** Jüngster Lauf eines Workflows (oder null bei API-Problemen). */
	private static function letzter_lauf( string $workflow ): ?array {
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'vv-statusleiste',
		];
		if ( defined( 'VV_DEPLOY_GH_TOKEN' ) && VV_DEPLOY_GH_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . VV_DEPLOY_GH_TOKEN;
		}
		$resp = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/actions/workflows/' . rawurlencode( $workflow ) . '/runs?per_page=1',
			[ 'timeout' => 8, 'headers' => $headers ]
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return $body['workflow_runs'][0] ?? null;
	}

	/* ================= Admin-Bar ================= */

	public static function bar( WP_Admin_Bar $bar ): void {
		if ( ! self::darf() ) {
			return;
		}
		// Nur Cache rendern — nie den Seitenaufbau blockieren. Ohne Cache
		// zeigt die Leiste „prüft…", das Skript holt den Status sofort nach.
		$cache = get_site_transient( self::TRANSIENT );
		$agg   = is_array( $cache ) ? $cache['aggregat'] : 'unbekannt';
		$label = is_array( $cache ) ? $cache['label'] : 'Status wird geprüft …';

		$bar->add_node( [
			'id'    => 'vv-status',
			'title' => '<span class="vv-st-dot" data-vv-st-dot data-state="' . esc_attr( $agg ) . '"></span>'
				. '<span class="vv-st-text" data-vv-st-label>' . esc_html( $label ) . '</span>',
			'href'  => false,
		] );

		$items = is_array( $cache ) ? $cache['items'] : [];
		foreach ( self::ZIELE as $wf => $ziel ) {
			$key  = sanitize_key( $wf );
			$item = null;
			foreach ( $items as $i ) {
				if ( $i['key'] === $key ) { $item = $i; break; }
			}
			$state  = $item['state'] ?? 'unbekannt';
			$detail = $item['detail'] ?? '';
			$bar->add_node( [
				'parent' => 'vv-status',
				'id'     => 'vv-status-' . $key,
				'title'  => '<span class="vv-st-dot" data-state="' . esc_attr( $state ) . '"></span>'
					. esc_html( $ziel['label'] )
					. '<span class="vv-st-detail">' . esc_html( $detail ) . '</span>',
				'href'   => esc_url( $item['url'] ?? $ziel['urls'][0] ),
				'meta'   => [ 'target' => '_blank', 'class' => 'vv-st-row', 'html' => '' ],
			] );
		}
		$bar->add_node( [
			'parent' => 'vv-status',
			'id'     => 'vv-status-github',
			'title'  => 'Alle Deploys auf GitHub ansehen',
			'href'   => 'https://github.com/' . self::REPO . '/actions',
			'meta'   => [ 'target' => '_blank' ],
		] );
	}

	/* ================= AJAX + Assets ================= */

	public static function ajax(): void {
		if ( ! self::darf() || ! check_ajax_referer( self::AJAX, 'n', false ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( self::status() );
	}

	public static function assets(): void {
		if ( ! self::darf() || ! is_admin_bar_showing() ) {
			return;
		}
		$ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
		$nonce = wp_create_nonce( self::AJAX );
		?>
		<style>
			#wp-admin-bar-vv-status .vv-st-dot{display:inline-block;width:10px;height:10px;border-radius:50%;
				margin-right:7px;vertical-align:middle;background:#8c8f94;position:relative;top:-1px}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="ok"]{background:#00b32c;box-shadow:0 0 5px rgba(0,179,44,.8)}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="fehler"]{background:#d63638;box-shadow:0 0 5px rgba(214,54,56,.8)}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="laeuft"]{background:#2271b1;box-shadow:0 0 5px rgba(34,113,177,.8)}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="laeuft"]::after{content:"";position:absolute;inset:-4px;
				border:2px solid rgba(34,113,177,.55);border-top-color:transparent;border-radius:50%;
				animation:vvstspin 1s linear infinite}
			@keyframes vvstspin{to{transform:rotate(360deg)}}
			#wp-admin-bar-vv-status .vv-st-detail{display:block;color:#a7aaad;font-size:11px;line-height:1.4;margin-left:17px}
			#wp-admin-bar-vv-status .ab-sub-wrapper li .ab-item{height:auto;line-height:1.6;padding-top:4px;padding-bottom:4px}
		</style>
		<script>
		(function(){
			var busy = false;
			function tick(){
				if (busy) return;
				busy = true;
				var x = new XMLHttpRequest();
				x.open('POST', <?php echo wp_json_encode( $ajax ); ?>);
				x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				x.onload = function(){
					busy = false;
					try {
						var r = JSON.parse(x.responseText);
						if (!r || !r.success) return;
						var d = r.data;
						var dot = document.querySelector('[data-vv-st-dot]');
						var lab = document.querySelector('[data-vv-st-label]');
						if (dot) dot.setAttribute('data-state', d.aggregat);
						if (lab) lab.textContent = d.label;
						(d.items || []).forEach(function(i){
							var row = document.querySelector('#wp-admin-bar-vv-status-' + i.key + ' .ab-item');
							if (!row) return;
							var rdot = row.querySelector('.vv-st-dot');
							var det  = row.querySelector('.vv-st-detail');
							if (rdot) rdot.setAttribute('data-state', i.state);
							if (det)  det.textContent = i.detail || '';
							if (i.url) row.setAttribute('href', i.url);
						});
					} catch (e) {}
				};
				x.onerror = function(){ busy = false; };
				x.send('action=<?php echo esc_js( self::AJAX ); ?>&n=<?php echo esc_js( $nonce ); ?>');
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', tick);
			} else { tick(); }
			setInterval(tick, 60000);
		})();
		</script>
		<?php
	}
}

VV_Statusleiste::init();
