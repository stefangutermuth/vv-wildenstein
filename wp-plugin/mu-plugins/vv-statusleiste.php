<?php
/**
 * Plugin Name: VV — Website-Status in der Admin-Bar
 * Description: Ampel oben in der Admin-Leiste für alle statischen Websites des Verbunds:
 *              Grün = letzter Deploy ok & Seite erreichbar · Blau (drehend) = Build läuft
 *              gerade · Rot = Deploy fehlgeschlagen oder Seite nicht erreichbar.
 * Version:     1.1.0
 *
 * Datenquellen: GitHub-Actions-Läufe der Deploy-Workflows (Token VV_DEPLOY_GH_TOKEN aus
 * wp-config.php, wie beim Deploy-Webhook) + kurzer Erreichbarkeits-Check der Live-Domains.
 * Grünhainichen und Börnichen stecken als getrennte JOBS im selben Workflow-Lauf —
 * deshalb wird für diesen Lauf zusätzlich die Job-Liste geholt, damit jede Website
 * ihren eigenen Status und Zeitstempel bekommt.
 *
 * Ergebnis liegt 55 s im Site-Transient — die Admin-Bar rendert nur den Cache, ein
 * kleines Skript holt den Status per admin-ajax nach und hält ihn ohne Neuladen aktuell.
 * Sichtbar für Redakteure/Admins (edit_posts).
 *
 * Ablage: wp-content/mu-plugins/vv-statusleiste.php (lädt im ganzen Netzwerk)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class VV_Statusleiste {

	private const REPO      = 'stefangutermuth/vv-wildenstein';
	private const TRANSIENT = 'vv_statusleiste_v2';
	private const TTL       = 55; // Sekunden Cache — JS pollt alle 60 s
	private const AJAX      = 'vv_statusleiste';

	/**
	 * Überwachte Websites. `job` = Namensteil des Jobs im Workflow-Lauf
	 * (null = der Lauf hat nur einen Job, Lauf-Status genügt).
	 */
	private const SITES = [
		'gruenhainichen' => [
			'label'    => 'Grünhainichen',
			'url'      => 'https://www.gruenhainichen.com',
			'workflow' => 'deploy-allinkl.yml',
			'job'      => 'Grünhainichen',
		],
		'boernichen' => [
			'label'    => 'Börnichen',
			'url'      => 'https://boernichen.de',
			'workflow' => 'deploy-allinkl.yml',
			'job'      => 'Börnichen',
		],
		'verband' => [
			'label'    => 'Verband (Staging 2026)',
			'url'      => 'https://2026.vv-wildenstein.com',
			'workflow' => 'deploy-verband.yml',
			'job'      => null,
		],
		'melder' => [
			'label'    => 'Mängelmelder',
			'url'      => 'https://melder.vv-wildenstein.com',
			'workflow' => 'deploy-maengelmelder.yml',
			'job'      => null,
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

		// Läufe je Workflow nur einmal holen; Jobs nur, wo nötig.
		$runs = [];
		$jobs = [];
		foreach ( self::SITES as $site ) {
			$wf = $site['workflow'];
			if ( ! array_key_exists( $wf, $runs ) ) {
				$runs[ $wf ] = self::letzter_lauf( $wf );
			}
			if ( $site['job'] !== null && $runs[ $wf ] !== null && ! array_key_exists( $wf, $jobs ) ) {
				$jobs[ $wf ] = self::lauf_jobs( (int) $runs[ $wf ]['id'] );
			}
		}

		$items = [];
		foreach ( self::SITES as $key => $site ) {
			$items[] = self::site_status( $key, $site, $runs[ $site['workflow'] ], $jobs[ $site['workflow'] ] ?? null );
		}

		// Aggregat: läuft > Fehler > ok.
		$agg   = 'ok';
		$label = 'Alle Seiten online';
		foreach ( $items as $i ) {
			if ( $i['state'] === 'fehler' ) { $agg = 'fehler'; $label = 'Fehler: ' . $i['label']; }
		}
		foreach ( $items as $i ) {
			if ( $i['state'] === 'laeuft' ) { $agg = 'laeuft'; $label = 'Wird aktualisiert …'; }
		}

		$result = [ 'aggregat' => $agg, 'label' => $label, 'items' => $items ];
		set_site_transient( self::TRANSIENT, $result, self::TTL );
		return $result;
	}

	/** Status einer einzelnen Website (Job-genau, wenn ein Job-Name hinterlegt ist). */
	private static function site_status( string $key, array $site, ?array $run, ?array $jobs ): array {
		$host = (string) wp_parse_url( $site['url'], PHP_URL_HOST );
		$out  = [
			'key'    => $key,
			'label'  => $site['label'],
			'state'  => 'ok',
			'detail' => $host,
			'url'    => $site['url'],
		];

		if ( $run === null ) {
			$out['state']  = 'unbekannt';
			$out['detail'] = $host . ' · GitHub nicht erreichbar';
			return $out;
		}

		// Job-genauer Blick (Grünhainichen/Börnichen teilen sich einen Lauf) …
		$status     = $run['status'];
		$conclusion = $run['conclusion'] ?? '';
		$zeit       = $run['updated_at'];
		$link       = $run['html_url'];
		if ( $site['job'] !== null && is_array( $jobs ) ) {
			foreach ( $jobs as $job ) {
				if ( mb_stripos( (string) $job['name'], $site['job'] ) !== false ) {
					$status     = $job['status'];
					$conclusion = $job['conclusion'] ?? '';
					$zeit       = $job['completed_at'] ?? $job['started_at'] ?? $run['updated_at'];
					$link       = $job['html_url'] ?? $run['html_url'];
					break;
				}
			}
		}

		if ( in_array( $status, [ 'queued', 'in_progress', 'waiting', 'pending', 'requested' ], true ) ) {
			$out['state']  = 'laeuft';
			$start         = $run['run_started_at'] ?? $run['created_at'];
			$out['detail'] = $host . ' · Build läuft seit ' . human_time_diff( strtotime( $start ) );
			$out['url']    = $link;
			return $out;
		}
		if ( $conclusion !== 'success' ) {
			$out['state']  = 'fehler';
			$out['detail'] = $host . ' · Deploy ' . ( $conclusion ?: 'unklar' ) . ' — vor ' . human_time_diff( strtotime( $zeit ) );
			$out['url']    = $link;
			return $out;
		}
		$out['detail'] = $host . ' · aktualisiert vor ' . human_time_diff( strtotime( $zeit ) );

		// Erreichbarkeit der Live-Domain (nur wenn der Deploy ok war).
		$resp = wp_remote_head( $site['url'], [ 'timeout' => 4, 'redirection' => 3, 'user-agent' => 'vv-statusleiste' ] );
		$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 400 || $code === 0 ) {
			$out['state']  = 'fehler';
			$out['detail'] = $host . ' · nicht erreichbar (' . ( $code ?: 'Timeout' ) . ')';
		}
		return $out;
	}

	private static function github_get( string $pfad ): ?array {
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'vv-statusleiste',
		];
		if ( defined( 'VV_DEPLOY_GH_TOKEN' ) && VV_DEPLOY_GH_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . VV_DEPLOY_GH_TOKEN;
		}
		$resp = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . $pfad,
			[ 'timeout' => 8, 'headers' => $headers ]
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $resp ), true );
	}

	/** Jüngster Lauf eines Workflows (oder null bei API-Problemen). */
	private static function letzter_lauf( string $workflow ): ?array {
		$body = self::github_get( '/actions/workflows/' . rawurlencode( $workflow ) . '/runs?per_page=1' );
		return $body['workflow_runs'][0] ?? null;
	}

	/** Jobs eines Laufs (für Workflows mit mehreren Websites). */
	private static function lauf_jobs( int $run_id ): ?array {
		$body = self::github_get( '/actions/runs/' . $run_id . '/jobs?per_page=20' );
		return $body['jobs'] ?? null;
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
		foreach ( self::SITES as $key => $site ) {
			$item = null;
			foreach ( $items as $i ) {
				if ( $i['key'] === $key ) { $item = $i; break; }
			}
			$state  = $item['state'] ?? 'unbekannt';
			$detail = $item['detail'] ?? (string) wp_parse_url( $site['url'], PHP_URL_HOST );
			$bar->add_node( [
				'parent' => 'vv-status',
				'id'     => 'vv-status-' . $key,
				'title'  => '<span class="vv-st-dot" data-state="' . esc_attr( $state ) . '"></span>'
					. '<span class="vv-st-name">' . esc_html( $site['label'] ) . '</span>'
					. '<span class="vv-st-detail">' . esc_html( $detail ) . '</span>',
				'href'   => esc_url( $item['url'] ?? $site['url'] ),
				'meta'   => [ 'target' => '_blank', 'class' => 'vv-st-row' ],
			] );
		}
		$bar->add_node( [
			'parent' => 'vv-status',
			'id'     => 'vv-status-github',
			'title'  => 'Alle Deploys auf GitHub ansehen',
			'href'   => 'https://github.com/' . self::REPO . '/actions',
			'meta'   => [ 'target' => '_blank', 'class' => 'vv-st-github' ],
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
				margin-right:8px;vertical-align:middle;background:#8c8f94;position:relative;top:-1px;flex:none}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="ok"]{background:#00b32c;box-shadow:0 0 5px rgba(0,179,44,.8)}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="fehler"]{background:#d63638;box-shadow:0 0 5px rgba(214,54,56,.8)}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="laeuft"]{background:#2271b1;box-shadow:0 0 5px rgba(34,113,177,.8)}
			#wp-admin-bar-vv-status .vv-st-dot[data-state="laeuft"]::after{content:"";position:absolute;inset:-4px;
				border:2px solid rgba(34,113,177,.55);border-top-color:transparent;border-radius:50%;
				animation:vvstspin 1s linear infinite}
			@keyframes vvstspin{to{transform:rotate(360deg)}}

			/* Untermenü: klar getrennte Karten-Zeilen */
			#wp-admin-bar-vv-status .ab-sub-wrapper{min-width:280px}
			#wp-admin-bar-vv-status .ab-sub-wrapper .ab-item{height:auto;line-height:1.5;
				padding-top:8px;padding-bottom:8px}
			#wp-admin-bar-vv-status li.vv-st-row + li.vv-st-row .ab-item{border-top:1px solid rgba(255,255,255,.10)}
			#wp-admin-bar-vv-status .vv-st-name{font-weight:600;color:#fff}
			#wp-admin-bar-vv-status .vv-st-detail{display:block;color:#a7aaad;font-size:11px;
				line-height:1.5;margin-left:18px}
			#wp-admin-bar-vv-status li.vv-st-github .ab-item{border-top:1px solid rgba(255,255,255,.22);
				margin-top:4px;color:#9ec2e6;font-size:12px}
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
