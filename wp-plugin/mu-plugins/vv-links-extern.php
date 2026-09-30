<?php
/**
 * Plugin Name: VV Externe Links in neuem Tab
 * Description: Externe Links auf vv-wildenstein.com öffnen immer in einem neuen Tab
 *              (wie auf den Astro-Seiten). Extern = http(s)-Adresse auf einem anderen
 *              Rechner (www. zählt nicht). Links mit eigenem target und Links auf
 *              Bilddateien bleiben unverändert. Screenreader hören „öffnet in neuem Tab“.
 * Author:      GUMU
 * Version:     1.0.0
 *
 * Installation: nach  wp-content/mu-plugins/vv-links-extern.php  kopieren
 * (läuft automatisch über .github/workflows/deploy-mu-plugins.yml).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
	<script>
	(function () {
		var hier = location.hostname.replace(/^www\./, '');
		var HINWEIS = 'öffnet in neuem Tab';
		var BILD = /\.(jpe?g|png|webp|gif|avif)(\?.*)?$/i;
		function extern(a) {
			if (a.hasAttribute('target')) return false;
			try {
				var u = new URL(a.href, location.href);
				return /^https?:$/.test(u.protocol) && u.hostname.replace(/^www\./, '') !== hier && !BILD.test(u.pathname);
			} catch (e) { return false; }
		}
		function neu(a) {
			a.target = '_blank';
			var rel = (a.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
			if (rel.indexOf('noopener') < 0) rel.push('noopener');
			a.setAttribute('rel', rel.join(' '));
		}
		document.querySelectorAll('a[href]').forEach(function (a) {
			if (!extern(a)) return;
			neu(a);
			var label = a.getAttribute('aria-label');
			if (label) {
				if (label.indexOf(HINWEIS) < 0) a.setAttribute('aria-label', label + ' (' + HINWEIS + ')');
			} else {
				var s = document.createElement('span');
				s.className = 'screen-reader-text';
				s.textContent = ' (' + HINWEIS + ')';
				a.appendChild(s);
			}
		});
		document.addEventListener('click', function (ev) {
			var a = ev.target.closest && ev.target.closest('a[href]');
			if (a && extern(a)) neu(a);
		}, true);
	})();
	</script>
	<?php
}, 99 );
