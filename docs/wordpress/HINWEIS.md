# Dieser Ordner ist nicht mehr die Ablage der mu-Plugins

Ausgeliefert wird **`wp-plugin/mu-plugins/`** — der Workflow
`deploy-mu-plugins.yml` rsynct diesen Ordner bei jedem Push, der ihn
berührt, nach `wp-content/mu-plugins/` auf vv-wildenstein.com.

`docs/wordpress/` ist die ältere Konvention. Was hier noch liegt, wird
**nicht** automatisch ausgeliefert und muss von Hand hochgeladen werden:

- `vv-rest-cors.php`
- `vv-rest-downloads.php`
- `vv-rest-freibad.php`
- `vv-rest-hinweise.php`
- `vv-rest-profilfelder.php` — **weicht vom ausgelieferten Stand ab**
- `vv-rest-amtsblatt.php` — Kopie, abgeglichen am 11.09.2026

`vv-deploy-webhook.php` ist am 11.09.2026 nach `wp-plugin/mu-plugins/`
umgezogen: er löst jeden Neubau aus, und eine Datei, die das tut, sollte
sich nicht auf Handarbeit verlassen.

Sechs weitere Dateien liegen nur auf dem Server und in keinem der beiden
Ordner: `burst_rest_api_optimizer.php` (fremd, vom Statistik-Plugin) und
fünf `wuw-*.php`.

Siehe [STAND-2026-09-11.md](../STAND-2026-09-11.md), Abschnitt 4.
