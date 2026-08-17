# Go-Live Verbandsseite — Ablaufplan

**Ausgangslage (Stand 17.08.2026)**

Die neue Seite ist inhaltlich fertig (Audit: 207 Einträge, keine Lücken) und läuft auf
`2026.vv-wildenstein.com`. Was den Go-Live blockiert, ist **nicht** die Seite, sondern die
Serverstruktur.

## Warum der Grünhainichen-Weg hier nicht funktioniert

| | Grünhainichen | Verband |
|---|---|---|
| Rolle im Netzwerk | Unterseite (blog_id 2) | **Hauptinstallation** (blog_id 1) |
| WordPress liegt physisch in | `vv-wildenstein.com/` | **`vv-wildenstein.com/`** |
| Domain umhängen | ein DB-Eintrag → fertig | greift ins Fundament |

Bei Grünhainichen wurde in der Datenbank die Domain der Unterseite von `gruenhainichen.com`
auf `alt.gruenhainichen.com` geändert und das Verzeichnis `gruenhainichen.com/` mit dem
Astro-Build gefüllt. WordPress selbst blieb unberührt, weil es woanders liegt.

Bei `vv-wildenstein.com` liegt WordPress **selbst** in dem Verzeichnis, das überschrieben
würde. Ohne Vorbereitung wären sofort weg:

- `vv-wildenstein.com/wp-admin` — Backend für **alle** Seiten des Netzwerks
- `/wp-json/...` — die Schnittstelle, aus der sich Grünhainichen, Börnichen, Mängelmelder
  **und** die neue Verbandsseite bedienen (alle vier fallen beim nächsten Build leer aus)
- Meldungs-Eingang des Mängelmelders

## Der Weg, der funktioniert

Vorher: **Vollbackup** (Datenbank + Dateien), am besten außerhalb der Sprechzeiten.

1. **Subdomain anlegen** (KAS, durch Stefan): `cms.vv-wildenstein.com`,
   Dokumentenstamm auf das **bestehende** Verzeichnis `/www/htdocs/w01f6038/vv-wildenstein.com/`.
   *Namenswahl:* nicht `alt.` — dort läuft künftig das produktive Redaktionssystem.
2. **WordPress umziehen:**
   - `wp-config.php`: `DOMAIN_CURRENT_SITE` → `cms.vv-wildenstein.com`
   - Datenbank: `wp_site.domain`, `wp_blogs.domain` (blog_id 1), `siteurl`/`home` der Hauptsite
   - Suchen/Ersetzen in Inhalten: `vv-wildenstein.com` → `cms.vv-wildenstein.com`
     **nur** für `/wp-content/`-Medien; Beitragslinks bleiben, sie werden ohnehin umgeschrieben
   - Prüfen: Login, Netzwerkverwaltung, Medien, REST (`/wp-json/wp/v2/pages`)
3. **Frontends nachziehen** — je eine Stelle pro App (`PUBLIC_WP_API_BASE`) plus:
   - `wp-plugin/vw-melder` → Einstellung `frontend_url`
   - mu-Plugins mit fester Domain (`vv-rest-*`, `vv-statusleiste`, `vv-deploy-webhook`)
   - CORS-Freigabe (`vv-rest-cors`) auf die neue Backend-Domain
4. **Verzeichnis für die neue Seite:** `/www/htdocs/w01f6038/vv-wildenstein-frontend/`,
   Domain `vv-wildenstein.com` im KAS darauf zeigen lassen, Deploy-Workflow umstellen
   (`deploy-verband.yml`: Ziel + `PUBLIC_STAGING` entfernen → indexierbar).
5. **Nachlauf:** Weiterleitungen alter Pfade prüfen, Sitemap, Suchmaschinen-Sperre der
   Staging-Domain behalten, Statusleiste um die neue Domain ergänzen.

**Rückfallebene:** Schritt 2 ist in Minuten umkehrbar (Konfiguration + drei DB-Werte zurück).
Ab Schritt 4 hängt die Rückkehr am KAS-Eintrag der Domain — ebenfalls schnell.

Realistische Dauer: rund eine Stunde konzentriert, plus Prüfzeit.

## Archiv-Ansicht (bereits erledigt)

Auf dem Server liegt unter `/www/htdocs/w01f6038/alt.vv-wildenstein.com/` ein **statischer
Schnappschuss** des heutigen WordPress-Stands: 948 Seiten (alle Ämter, 38 Vereine, 58 Profile,
25 Tourismus-Einträge), 283 MB, mit rotem Archiv-Hinweis auf jeder Seite, `noindex`, kein PHP.
Alle internen Links sind wurzel-relativ — die Navigation bleibt im Archiv.

Sobald die Subdomain `alt.vv-wildenstein.com` im KAS auf dieses Verzeichnis zeigt, ist der
Stand zum Abgleichen erreichbar. Er ist vom Live-System **völlig unabhängig** und übersteht
den Umzug unverändert.
