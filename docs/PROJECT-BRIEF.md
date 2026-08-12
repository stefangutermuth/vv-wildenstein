# Gemeinde Grünhainichen — Projekt-Brief

Stand: 2026-07-07 · Übergabe an eine neue Chat-Session.
Kopiere das Dokument in den neuen Chat, dann kann er ohne Kontextverlust weiterarbeiten.

---

## 1. Was das Projekt ist

**Neue offizielle Website der Gemeinde Grünhainichen (Sachsen, Erzgebirge)** — statische Astro-Seite, die Inhalte aus einem headless-WordPress zieht. Ablösung der alten `gruenhainichen.com` (WordPress) sobald fertig.

- **Ortsteile:** Borstendorf · Grünhainichen · Waldkirchen/Erzgeb.
- **Auftraggeber:** Verwaltungsverband Wildenstein (der auch Börnichen mitverwaltet)
- **Zwei zentrale Themen der Gemeinde:** Wendt-&-Kühn-Engel + Schachwanderweg (Borstendorf)

---

## 2. Hosting & Domains

| Zweck | URL |
|---|---|
| **Neue Astro-Seite (live)** | https://grh.vv-wildenstein.com |
| **WordPress-Master (Content-Quelle)** | https://vv-wildenstein.com |
| **Alte WP-Seite (wird abgelöst)** | https://gruenhainichen.com |
| **Redaktion** | https://vv-wildenstein.com/wp-admin |
| **Ziel-Domain nach Go-Live** | www.gruenhainichen.com (Redirect + DNS-Switch, noch offen) |

**All-Inkl-Konto:** w01f6038 · SSH-Alias `vv-wildenstein` in `~/.ssh/config`, Key `~/.ssh/vv_wildenstein`.
Server-Pfade: `/www/htdocs/w01f6038/{domain}/`

---

## 3. Tech-Stack

- **Astro 4.16.19** (statisches Rendering, kein SSR), im Monorepo unter `apps/gruenhainichen/`
- **Node 20, npm workspaces**
- **Frontend:** Vanilla JS, kein React/Vue. CSS-Custom-Properties für Tokens.
- **Icons:** `@iconify/tabler` inline (SVG), keine Icon-Fonts
- **Fonts:** `@fontsource-variable/fraunces` + `@fontsource-variable/inter` (im Bundle)
- **Karten:** Leaflet via CDN (Sperrungen), FullCalendar v6 via CDN (Raumbuchung)
- **Bilder:** WP liefert JPGs, wir zeigen sie ohne Astro-Image (Build sonst zu langsam)
- **Deploy:** GitHub Actions → rsync auf All-Inkl (SSH)
- **Master-Repo:** https://github.com/stefangutermuth/vv-wildenstein (Monorepo)

---

## 4. Design-System

### Schriften (variable Fonts, im Bundle)

| Rolle | Familie | Fallback |
|---|---|---|
| Display (Headings, Buttons) | **Fraunces Variable** | Georgia, Times, serif |
| Body (Fließtext, UI) | **Inter Variable** | system-ui, -apple-system |
| Script (nur Akzente) | Caveat | Petit Formal Script |

CSS-Variablen: `--grh-font-display`, `--grh-font-body`, `--grh-font-script`.

### Farb-Tokens (alle in `apps/gruenhainichen/src/styles/tokens.css`)

**Grün-Ramp (Kernidentität):**
- `--grh-forest-950` #142B1F
- `--grh-forest-900` #1A3D27 (Standard-Text-Farbe für Headings)
- `--grh-forest-800` #1B4A2F
- `--grh-forest-700` #2A6B43
- `--grh-moss-600` #3E8C5A (Standard-Link)
- `--grh-moss-500` #5BAE78
- `--grh-sage-300` #A8CDB3
- `--grh-sage-100` #E4F0E6

**Holz + Kerze (Akzentwärme):**
- `--grh-wood-700` #6B4A2A
- `--grh-wood-500` #A6794E
- `--grh-sandstone-300` #D9C4A3
- `--grh-candle-500` #E8B658 (Kerzengold, der Signal-Akzent)
- `--grh-candle-300` #F6DDA0

**Papier + Stein (Neutrale):**
- `--grh-cream-50` #FAF7F1 (Seitenhintergrund)
- `--grh-paper-100` #F2EDE3 (leicht erhöhter Block)
- `--grh-stone-700` #3D423A (Standard-Fließtext)
- `--grh-stone-400` #8D9388 (Placeholder, gedämpft)
- `--grh-line` #D8D2C4 (Rahmen)

**Signale:**
- `--grh-alert-600` #A8401E (Sperrungen, Fehler)
- `--grh-info-600` #2A6B73 (neutrale Hinweise)

**Typische Kombinationen:**
- Body-Layout: cream-50 BG + stone-700 Text + forest-900 Headings
- Karten: cream-50 Fill + line Border + candle-500 auf Hover
- Buttons primär: forest-900 Fill + candle-500 Border/Text
- Buttons ghost: transparent + line + forest-800

### Sizing / Spacing (Auszug)

- Schriftgrößen: `--grh-fs-xs` (12px) bis `--grh-fs-hero` (clamp 2.75-5rem)
- Abstände: `--grh-space-1` (4px) bis `--grh-space-10` (128px)
- Radien: sm=4 · md=10 · lg=18 · xl=28 · pill=999
- Container: `--grh-container-max: 1240px`, Gutter `--grh-space-5`
- Motion: fast=180ms · base=320ms · slow=700ms · easing `cubic-bezier(0.22, 1, 0.36, 1)`

---

## 5. Seitenstruktur

Kernbereiche unter `apps/gruenhainichen/src/pages/`:

- `/` — Startseite mit Hero-Slider, Dual-Portal (Bürger / Gäste), Ortsteil-Karten, Traditions-Showcase, Events, Featured Card
- `/gemeinde/` — Bürgermeister, Verwaltung, Gemeinderat, Amtsblatt, Feuerwehren, Bauleitplanung, Geschichte, Ortsteile, **Räume buchen** (neu), **Amtsblatt einreichen** (neu)
- `/neuigkeiten/` — News-Übersicht + Sperrungen (mit Leaflet-Karte)
- `/tourismus/` — Sehenswertes, Wandern, Museen, Baden, Übernachten
- `/gewerbe/` — Firmenübersicht, Stellenausschreibungen
- `/vereine/` — Vereinsverzeichnis
- `/leben/` — Kirche, Bücherei, Grundschule, Kita, Adventskalender, Einkaufen, Heiraten, Gesundheit
- `/veranstaltungen/` — Kalender mit Filtern (Karten/Liste/Monat), Heimatfest-Rückblick, EURORANDO 2026

---

## 6. CMS-Adapter (Astro liest WP)

Dateien: `apps/gruenhainichen/src/lib/cms-wordpress.ts` (News + Events) und `cms-cpt.ts` (alles andere).

**CPTs die gelesen werden:**
- `posts` (Standard, mit Kategorien für Filter)
- `tourismus`, `verein`, `profile` (Gewerbe), `personen`, `amter`, `gemeinderatssitzung`, `amtsblatt_download`
- `vvw_room` (Raumbuchungs-Plugin, via `/vvw/v1/rooms`)

**Ortsteil-Filter:** Nur Beiträge mit Gemeindeteil-Term `borstendorf`, `gruenhainichen`, `waldkirchen*` werden angezeigt.

**Auth:** WP-Media-API ist gesperrt → per Basic Auth mit Application Password aus `.env.local`:
```
WP_AUTH_USER=mrmek
WP_AUTH_PASS="XXXX XXXX XXXX XXXX XXXX"   # in 4er-Gruppen mit Spaces
```
User `mrmek` = Stefan im WP.

---

## 7. WordPress-Plugins (selbst gebaut, produktiv)

### `vvw-roombooking`
- **Repo:** https://github.com/stefangutermuth/raumbuchung · **Slug:** `vvw-roombooking` · aktiv auf vv-wildenstein.com
- Namespace `VVW\RoomBooking` · REST `vvw/v1`
- CPT `vvw_room`, Taxonomien `vvw_municipality`, `vvw_amenity`
- Custom Tables `vvw_bookings`, `vvw_messages`
- FullCalendar v6 im Frontend, iCal-Feed pro Raum
- 5 Demo-Räume angelegt (Kulturhaus Borstendorf, Turnhalle Grünhainichen, Vereinshaus Waldkirchen, Sitzungssaal Rathaus, Aula Grundschule)
- **Astro-Frontend:** `/gemeinde/raeume/`, `/gemeinde/raeume/[slug]/`, `/gemeinde/raeume/belegungsplan/`

### `vvw-amtsblatt`
- **Repo:** https://github.com/stefangutermuth/amtsblatt · **Slug:** `vvw-amtsblatt` · aktiv auf vv-wildenstein.com
- Namespace `VVW\Amtsblatt` · REST `vvw-amt/v1` · DB-Prefix `vvw_amt_`
- CPTs `vvw_amt_ausgabe`, `vvw_amt_beitrag`
- Taxonomien `vvw_amt_kategorie` (7 Kats), `vvw_amt_gruppe` (8 Zielgruppen: Firma/Verein/Redakteur/Bürger/Schule/Kirche/Fraktion/Autor)
- Custom Tables `vvw_amt_uploader`, `vvw_amt_tokens`
- Magic-Link-Auth (Token 1h, Session-Cookie 8h HMAC-signiert)
- Bearer-Token für Cross-Origin von `grh.vv-wildenstein.com`
- CORS-Header für Subdomains
- 3 Demo-Ausgaben angelegt (August/September/Oktober 2026)
- **Astro-Frontend:** `/gemeinde/amtsblatt/einreichen/` mit 4-Stage-SPA (Login → Mail-Versand → Editor → Danke)
- Bild-Upload via `POST /media` (Bearer, multipart, 10 MB Limit, JPG/PNG/WebP/PDF)

**Nicht in v0.1.0 vom Amtsblatt-Plugin (Etappe 2/3):**
- Nextcloud-WebDAV-Sync
- PDF-Redaktionsplan-Parser

---

## 8. Deploy-Pipeline

**Workflow:** `.github/workflows/deploy-allinkl.yml`

**Trigger:**
- Push auf `main`
- Täglicher Cron 05:24 UTC
- WP-Webhook (repository_dispatch, `vv-content-updated`)

**Ablauf:**
1. Node 20 einrichten, npm ci
2. WP-Cache aus vorherigem Run laden (via `actions/cache@v4`, Key `wp-cache-grh-*`)
3. `npm run build:gruenhainichen` (Astro-Build)
4. SSH-Agent starten, All-Inkl-Host in known_hosts
5. rsync dist/ nach `/www/htdocs/w01f6038/grh.vv-wildenstein.com/`

**Deploy-Zeiten:**
- Vor A+B-Umbau: ~40 min pro Deploy
- Nach A+B, erster Deploy (Cache leer): ~17 min
- Nach A+B, warmer Deploy: **~5–8 min erwartet**

---

## 9. A+B-Umbau (07.07.2026 gelaufen)

**A) Smart WP-Fetch-Cache** — `apps/gruenhainichen/src/lib/wp-cache.ts`
- Alle WP-Responses landen als JSON-Files in `apps/gruenhainichen/.wp-cache/`
- Cache-Key = sha256(URL)
- Ein `orderby=modified&per_page=1`-Check pro Build (über alle CPTs) → wenn WP nichts Neues hat, kommt alles aus Cache
- Wenn WP was Neues hat → kompletter Cache wird invalidiert und neu aufgebaut
- Deaktivieren via `WP_CACHE=off`
- Warm-Build lokal: 12s (vorher: ~40 min)

**B) Live-Update-Banner** — `/neuigkeiten/` und `/neuigkeiten/sperrungen/`
- 800ms nach Page-Load ruft JS `WP/posts?after={buildDate}` auf
- Bei Treffern: rot pulsierender Banner „X neue Beiträge seit dem letzten Deploy — Seite neu laden"
- Klick → `location.reload()`

**Rollback-Tag:** `pre-abfix-2026-07-07` (git). Server-Backup unter `/www/htdocs/w01f6038/_backups_2026-07-07/` (92 MB). Zusätzlich lokale Kopie im scratchpad.

---

## 10. Sonstige Bausteine

- **GrhMegaMenu** — Vollflächen-Menü mit Nav-Spalten links + Live-Blöcken (Aktuelles/Sperrungen/Termine) rechts vertikal
- **GrhFloatingContact** — Floating-Chatbubble unten rechts mit 3 Aktionen (Kontakt, Suche, Mängelmelder)
- **GrhSperrungenMap** — Leaflet-Karte, Nominatim-Geocoding mit Disk-Cache
- **CSS-Columns Hover-Fix** — `contain: layout paint` + kein Transform auf Hover, sonst springen Karten in andere Spalten

---

## 11. Zwei Sachen die noch offen sind

1. **Detail-Seiten-Fallback** bei brandneuen Posts: 404 statt Client-side Nachrenderer. Aktuell trägt der Live-Banner das ab.
2. **Menü-Sublink Verwaltungsverband/Ortsteile** in Header-Bar zeigt noch alte alte Struktur — geklärt und live seit vorletzter Session, aber falls Änderungen kommen, in `apps/gruenhainichen/src/lib/navigation.ts`.

---

## 12. Häufige Kommandos

```bash
# Lokaler Dev-Server
npm run dev:gruenhainichen

# Lokaler Build (mit Cache)
PUBLIC_CMS_SOURCE=wordpress npm run build:gruenhainichen

# Cache killen (falls Debugging)
rm -rf apps/gruenhainichen/.wp-cache

# Deploy manuell triggern
git commit --allow-empty -m "chore: deploy" && git push origin main

# Status letzter Deploy
gh run list --workflow=deploy-allinkl.yml --limit 1

# SSH auf Server
ssh vv-wildenstein
```

---

## 13. Referenz-Memories (Anthropic-CLI)

- `project_wp_integration` — WP-Auth-Details
- `project_vw_events_plugin` — VW-Events-Plugin
- `project_boernichen_golive` — Börnichen-Deploy
- `project_wp_dubletten_gemeinde` — WP-Content-Aufräum-Termin
- `project_amtsblatt_plugin` — Amtsblatt-Plugin-Setup
- `reference_raumbuchung_plugin` — Raumbuchungs-Plugin-Details
