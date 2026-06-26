# Migration-Protokoll — Mängelmelder → zentrales WP

> Durchgeführt am 26.06.2026.

## Schritt 1 — alten Melder eingefroren
- `melder.vv-wildenstein.com`: Must-Use-Plugin `wp-content/mu-plugins/vv-melder-wartung.php`
  liefert allen nicht eingeloggten Besuchern eine gebrandete Wartungsseite (HTTP 503,
  `Retry-After: 86400`). Admin-Login + wp-admin bleiben erreichbar. Statische Uploads
  (Fotos) bleiben abrufbar. Reversibel: Datei löschen.

## Schritt 2 — Plugin `vw-melder` im zentralen WP
- Installiert + aktiviert auf `vv-wildenstein.com`
  (`wp-content/plugins/vw-melder/`, Source im Repo unter `wp-plugin/vw-melder/`).
- Registriert CPT `vw_meldung`, Taxonomien `vw_anliegen` + `vw_meldung_status`,
  Meta-Felder, REST-API (`/wp-json/vw-melder/v1/meldungen`, `/single`, `/geojson`).
- Default-Terme angelegt (Anliegen 5, Status 3).

## Schritt 3 — 28 Bestands-Meldungen migriert
- Export auf altem Melder via `export-meldungen.php` (`wp eval-file`) → JSON.
- Import auf zentralem WP via `wp vw-melder import … --images` (idempotent über
  `_vw_meldung_import_src_id`).
- **Ergebnis:** 28 Meldungen angelegt, 10 Fotos übernommen, HTML-Entities in Titeln
  dekodiert. Standort-Array entpackt in `lat/lng/address/city/postcode`.

### Verifikation (REST, live)
- `/meldungen?per_page=100` → 28 Einträge, HTTP 200
- `/geojson` → 14 Features (nur Meldungen mit Koordinaten; im Bestand hatten nicht alle einen Pin)
- Status: In Bearbeitung 23 · Erledigt 3 · Neu 2 (1 Bestandspost ohne Status → Default „Neu")
- Anliegen: Straßen/Gehwege 10 · Straßenbeleuchtung 7 · Müll 6 · Grünflächen 3 (2 ohne Anliegen)
- Datenschutz: Melder-Name/E-Mail, interne Notiz und IP werden **nicht** über REST ausgeliefert.

## Noch offen (nächste Phase)
- Astro-PWA-Frontend unter `melder2026.vv-wildenstein.com` (Liste + Karte + Detailseiten).
- Submission-Endpoint im `vw-melder`-Plugin (Frontend-Meldung anlegen, analog
  `vw-events` Frontend-Form + Turnstile + Mailer/BNFW-Ersatz).
- Karte: Leaflet/MapLibre (OSM) statt Google Maps — speist sich aus `/geojson`.
