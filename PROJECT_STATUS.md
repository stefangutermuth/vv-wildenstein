# Project Status — VV-Wildenstein Web-Monorepo

> ## ⚠️ Teilweise überholt (Stand 29. Juni 2026)
>
> Für **Grünhainichen** gilt dieses Dokument nicht mehr. Die Seite ging am
> 16.08.2026 unter `www.gruenhainichen.com` live; die hier genannte Testadresse
> `grh.vv-wildenstein.com` existiert nicht mehr, und `PUBLIC_STAGING` wird beim
> Deploy nicht mehr gesetzt.
>
> **Aktuelle Quellen:**
> [docs/PROJECT-BRIEF.md](docs/PROJECT-BRIEF.md) für den Aufbau ·
> [docs/GO-LIVE-2026-08-16.md](docs/GO-LIVE-2026-08-16.md) für Domains, Server und offene Punkte
>
> Für Börnichen und den Mängelmelder stimmt der Inhalt weiterhin.

> **Letztes Update:** 29. Juni 2026
> **Aktuelle Phase:** Phase 3 — **Börnichen ist LIVE** unter boernichen.de, Inhalte dynamisch aus WordPress, Auto-Deploy aktiv & getestet.

Diese Datei dokumentiert den Stand, alle getroffenen Entscheidungen, Zugänge (ohne Geheimnisse) und die offenen Aufgaben — damit die Arbeit nahtlos weitergehen kann, auch wenn es eine Pause gibt oder die Konversation neu gestartet wird.

---

## ✅ Börnichen — LIVE seit 29. Juni 2026

- **boernichen.de** liefert die neue statische Astro-App aus (Domain-Datenpfad in KAS auf `/www/htdocs/w01f6038/boernichen.de/` umgestellt). Die **WordPress-Multisite bleibt** als Headless-CMS; nur die Domain wurde umgehängt. Rollback = KAS-Datenpfad zurück auf `/vv-wildenstein.com/`.
- **Inhalte live aus vv-wildenstein** (zur Build-Zeit): News, Veranstaltungen (vw-events), Amtsblatt (CPT `amtsblatt_download`, PDFs nur verlinkt), Bauleitplanung (Kategorie) und die **Detailseiten** (CPTs `profile` & `tourismus`) — Börnichen-gefiltert über Taxonomie `gemeindeteil` (Börnichen = 175), Kontaktdaten über das REST-Feld `vv_kontakt`. Grünhainichen-Spezifisches (z. B. „Gemeinderat Grünhainichen") wird herausgefiltert.
- **Auto-Deploy aktiv & end-to-end getestet:** WordPress speichern → mu-Plugin `vv-deploy-webhook.php` feuert GitHub `repository_dispatch (vv-content-updated)` → Workflow `deploy-allinkl.yml` baut & deployt nach boernichen.de (~1–2 Min). Zusätzlich nächtlicher Fallback-Cron (04:00 UTC). Greift für `post`, `profile`, `tourismus`, `amtsblatt_download`, Events u. a.
- **WP mu-Plugins** (auf vv-wildenstein installiert, Quelle versioniert in `docs/wordpress/`):
  - `vv-deploy-webhook.php` — braucht `define('VV_DEPLOY_GH_TOKEN', …)` in `wp-config.php` (gesetzt ✅).
  - `vv-rest-profilfelder.php` — gibt die Kontaktfelder (`vv_kontakt`) der CPTs `profile`/`tourismus` read-only in der REST-API frei.
- **Backup** der gesamten Multisite (DB + Uploads, 29.06.) liegt auf dem Server unter `~/backups/boernichen-golive-2026-06-29/` und lokal; komplettes Hosting-Paket zusätzlich lokal gespiegelt.
- **Offen / optional:** Cookie-Richtlinie an „keine Cookies" angleichen · Sitemap mit Astro-4-kompatibler Version nachrüsten · Actions-Versionen (Node-20-Deprecation-Hinweis) bei Gelegenheit heben.

---

## 0. Schnellstart für neue Chats

Wenn Du als neue Konversation an **Verband / Börnichen / Mängelmelder** arbeiten sollst:

1. **Repo öffnen:** `/Users/stefan/Claude/Website/gruenhainichen/` (alle vier Apps liegen hier als Monorepo)
2. **Diese Datei zuerst lesen** (`PROJECT_STATUS.md`) — sie ist das gemeinsame Gedächtnis aller Chats
3. **App-Skelett anlegen** unter `apps/<name>/` per Copy von `apps/gruenhainichen/` als Basis. Anpassen:
   - `package.json` Name: `@vv/<name>`
   - `astro.config.mjs` Site-URL auf Staging-Subdomain
   - `src/styles/tokens.css` für app-eigene Farbpalette (siehe §10 Multi-App-Theming)
   - `src/components/global/GrhWappen.astro` durch eigenes Wappen ersetzen
   - `public/images/` mit eigenen Bildern befüllen
4. **Build-Script** im Workspace-Root (`package.json`) ergänzen: `"build:<name>": "npm run build --workspace=@vv/<name>"`
5. **GitHub-Action** (`.github/workflows/deploy-allinkl.yml`) um neuen Deploy-Job erweitern (Vorlage = Grünhainichen-Job)
6. **Push** → Auto-Deploy auf entsprechende Staging-Subdomain

**Wichtig:** Die WordPress-Anbindung und der CMS-Adapter (`src/lib/cms.ts`) sind identisch für alle Apps — sie ziehen aus demselben WP-Backend, filtern nur per Category-Slug auf den jeweiligen Standort.

Bevor Du Code änderst: gib Dem Konversations-Owner einen **Stand-Bericht** wie in §13 dokumentiert.

---

## 1. Live-URLs

| Was                          | URL                                                                       | Status        |
|------------------------------|---------------------------------------------------------------------------|---------------|
| **Staging Grünhainichen**    | https://grh.vv-wildenstein.com (All-Inkl)                                  | **live** ✓ (Vorschau für Verband, noindex)  |
| Production-Site Cloudflare   | https://vv-wildenstein-gruenhainichen.stefan-0ea.workers.dev               | **live** ✓ (läuft parallel als Backup)      |
| WordPress-Backend            | https://vv-wildenstein.com (REST + Plugin-API)                             | **live** ✓    |
| GitHub-Repo                  | https://github.com/stefangutermuth/vv-wildenstein                          | live          |
| Cloudflare-Projekt           | `vv-wildenstein-gruenhainichen` im Account `f0ea2c6b50485053bbacc2c5963a6eb6` | live   |
| All-Inkl-Hosting             | `w01f6038.kasserver.com`, User `ssh-w01f6038`, Web-Root `/www/htdocs/w01f6038/` | live  |
| Lokaler Dev-Server           | http://localhost:4321 (Astro-Default; fällt auf 4322 zurück, falls belegt) | nach `npm run dev` |
| Spätere Production-Domain    | gruenhainichen.com (DNS-Setup steht aus, Day X)                            | offen         |

### Staging-Subdomains (parallel im Aufbau)

| Subdomain | App im Repo | Web-Root auf All-Inkl | Status |
|---|---|---|---|
| **grh.vv-wildenstein.com**       | `apps/gruenhainichen/`       | `/www/htdocs/w01f6038/grh.vv-wildenstein.com/`        | **live** ✓ |
| 2026.vv-wildenstein.com          | `apps/verband/` (geplant)    | `/www/htdocs/w01f6038/2026.vv-wildenstein.com/`       | Subdomain steht, App-Skelett offen |
| boernichen.vv-wildenstein.com    | `apps/boernichen/` (geplant) | `/www/htdocs/w01f6038/boernichen.vv-wildenstein.com/` | Subdomain steht, App-Skelett offen |
| melder2026.vv-wildenstein.com    | `apps/maengelmelder/` (geplant) | `/www/htdocs/w01f6038/melder2026.vv-wildenstein.com/` | Subdomain steht, App-Skelett offen |
| melder.vv-wildenstein.com        | (Production-Slot Mängelmelder, später Day X) | bestehend | reserviert |

---

## 2. Architektur — der große Plan

```
                     ┌──────────────────────────────────┐
                     │  WordPress (vv-wildenstein.com)  │   ← LIVE
                     │  Posts mit Categories als         │
                     │  Standort-Filter, REST API        │
                     └─────────────┬────────────────────┘
                                   │ Pull bei jedem Build
                                   │ via CMS-Adapter
                ┌──────────────────┼──────────────────────────┐
                ▼                  ▼                          ▼
     ┌────────────────────┐ ┌──────────────────┐ ┌──────────────────────┐
     │ Astro-Frontend     │ │ Astro-Frontend   │ │ Astro/PWA-Frontend   │
     │ verband            │ │ gruenhainichen ★ │ │ maengelmelder        │
     │ vv-wildenstein.com │ │ gruenhainichen.com│ │ maengelmelder.…      │
     └────────────────────┘ └──────────────────┘ └──────────────────────┘
                ▲                  ▲                          ▲
                │ jeder mit eigener Cloudflare-Pages-URL und eigener Domain
                │ ★ = aktuell live mit WP-Anbindung, restliche Apps in Phase 3
```

**Begründung Single-WP statt Multisite** (einmal entschieden, gilt für später):
- Eine Login-Maske, ein Admin, ein Backup
- Inhalte werden über **Categories** als Standort-Filter etikettiert (`gruenhainichen`, `borstendorf`, `boernichen`, `waldkirchen-*`)
- Frontend-Adapter filtert beim Build die für jedes Frontend relevanten Posts
- Mängelmelder kommt später als eigene App, schreibt zurück ins selbe WP

---

## 3. Repo-Struktur

```
vv-wildenstein/                    ← npm-Workspace-Root
├── apps/
│   └── gruenhainichen/             ← live in Cloudflare
│       ├── src/
│       │   ├── components/         ← Astro-Komponenten
│       │   ├── content/            ← Astro Content Collections
│       │   │   ├── events/         ← 46 Events aus iCal-Feed importiert
│       │   │   ├── news/           ← Lokale News (Fallback, wenn WP nicht erreichbar)
│       │   │   └── ortsteile/      ← Ortsteil-Stammdaten
│       │   ├── layouts/
│       │   ├── lib/
│       │   │   ├── cms.ts                ← CMS-Adapter (local | wordpress)
│       │   │   ├── cms-wordpress.ts      ← WP-REST-Fetcher + Mapping
│       │   │   ├── navigation.ts
│       │   │   └── seasons.ts
│       │   ├── pages/
│       │   └── styles/
│       ├── public/images/loader/   ← echte SVGs (engel_01, engel_02, schachbrett, schachfigur)
│       ├── astro.config.mjs
│       ├── .env.example
│       ├── .env.local              ← lokale Credentials, GIT-IGNORIERT
│       └── package.json
├── packages/                       ← (geplant) Design-System extrahieren
├── docs/                           ← Briefings, Spec-Sheets
├── wp-plugin/
│   ├── vw-events/                  ← Source des WordPress-Plugins
│   └── vw-events.zip               ← installierbar in WP via Plugin-Upload
├── wrangler.jsonc                  ← Cloudflare-Asset-Config
├── package.json                    ← Workspace-Root
└── PROJECT_STATUS.md               ← diese Datei
```

**Workspace-Befehle (an der Repo-Wurzel):**
```bash
npm install                        # einmal
npm run dev                        # Dev-Server für Grünhainichen (Default = local)
PUBLIC_CMS_SOURCE=wordpress npm run dev   # mit Live-WP-Inhalten testen
npm run build:gruenhainichen       # Production-Build
```

Für lokale WP-Tests einfach `apps/gruenhainichen/.env.local` aus `.env.example` ableiten.

---

## 4. Headless-WordPress-Anbindung (Phase 2 — live)

### CMS-Adapter

`src/lib/cms.ts` ist die **einheitliche Schnittstelle** für News. Quelle wird über die ENV-Variable `PUBLIC_CMS_SOURCE` gesteuert:
- `local` → liest aus Astro Content Collections (Fallback)
- `wordpress` → fetched aus REST-API von `vv-wildenstein.com`

Bei WP-Fehlern fällt der Adapter automatisch auf `local` zurück.

### WP-Mapping

| WP-Quelle                      | Frontend-Feld          | Notiz                                                |
|--------------------------------|------------------------|------------------------------------------------------|
| Category-Slug `sperrung`       | `category: 'sperrung'` |                                                      |
| Category-Slug `kultur`/`tourismus`/`veranstaltung*` | `category: 'veranstaltung'` (oder `tourismus`) |        |
| Category-Slug `gemeinderat`/`gemeinde`/`bauleitplan`/`ausschreibungen` | `category: 'verwaltung'` | Default-Fallback           |
| Category-Slug `gruenhainichen` | `ortsteil: 'gruenhainichen'` |                                                |
| Category-Slug `borstendorf`    | `ortsteil: 'borstendorf'`    |                                                |
| Category-Slug `waldkirchen-*`  | `ortsteil: 'waldkirchen'`    | Mehrere Slugs möglich (Kirche etc.)            |
| Category-Slug `boernichen`     | (Post wird verworfen)        | Börnichen ist nicht für Grünhainichen relevant |
| Featured Image (Bild)          | `image`                | PDFs/Docs werden ignoriert                           |
| Erstes `<img>` im Beitragstext | `image` (Fallback)     | wenn kein Beitragsbild oder dieses kein Bild ist     |
| `Beitrag_Platzhalter.png`      | (gefiltert)            | Generischer Default-Platzhalter wird ausgeblendet    |

**Beobachtung Redaktion:** Das WP-Feld „Beitragsbild" wird oft mit PDFs/Word-Dokumenten belegt statt mit echten Fotos. Echte Bilder stehen meist inline im Beitragstext. Daher der Inline-Fallback.

### Authentifizierung

Die Media-API von vv-wildenstein.com gibt für anonyme Aufrufer 401 zurück. Lösung: Application Password im Build.

| ENV-Variable          | Wert            | Wo gesetzt                                  |
|-----------------------|-----------------|---------------------------------------------|
| `PUBLIC_CMS_SOURCE`   | `wordpress`     | Cloudflare Build-Variables + lokale `.env.local` |
| `WP_AUTH_USER`        | `mrmek`         | Cloudflare Build-Variables                  |
| `WP_AUTH_PASS`        | (Secret)        | Cloudflare Build-Variables (verschlüsselt!) |

**Wichtig:** Cloudflare Workers Builds liest ENV-Variablen nur über `process.env`, nicht über Astro's `import.meta.env`. Der Adapter prüft beide Quellen, damit lokal + Live funktioniert.

### Was ist live, was bleibt offen

✅ News-Liste, Sperrungen-Liste, Bilder live aus WP
✅ Mega-Menü zieht Live-News + Live-Sperrungen
⏳ Detailseiten `/neuigkeiten/[slug]` — aktuell springt Klick zur Original-WP-URL
⏳ Sperrungs-Felder (Straße, Umleitung, Gültig-bis, Severity) — Schema vorbereitet, in WP noch nicht als ACF-Felder gepflegt
✅ Events: eigenes `vw-events`-Plugin produktiv (siehe §4a)
✅ Build-Webhook (WP veröffentlicht → Cloudflare baut neu) — eingerichtet (siehe §4a)

---

## 4a. Eigenes Events-Plugin `vw-events` (Phase 2.5 — live, 30.04.2026)

### Warum

Der vorherige Stand nutzte „Events Manager" (Free-Version), das keine REST-API für Events lieferte. Die 46 Astro-Events kamen daher aus einem einmaligen iCal-Import und wurden lokal als Markdown gehalten. Für saubere Headless-Integration bauten wir ein eigenes WP-Plugin.

### Was das Plugin liefert

- **Custom Post Type** `vw_event` mit allen redaktionellen Feldern (Start/Ende, Ganztägig, Wiederholung, Ort-Name + Adresse, Veranstalter, externer Link)
- **Taxonomien** `vw_standort` (`gruenhainichen`, `borstendorf`, `waldkirchen`, `boernichen`, `verband-weit`) + `vw_event_category` (`kultur`, `sport`, `kirche`, `verein`, `markt`, `bildung`, `sonstige`)
- **REST-Namespace** `vw-events/v1` mit:
  - `GET /events?standort=…&from=…&to=…&category=…&per_page=…`
  - `GET /events/{id}`
  - `GET /ical?standort=…` (iCal-Feed mit RRULE für simple Wiederholungen)
  - `POST /submissions` — öffentliches Frontend-Formular (Bild bis 10 MB, Cloudflare Turnstile, Honeypot, Rate-Limit)
- **Frontend-Shortcodes:**
  - `[vw_events_list standort="…" past="false" limit="20"]` — Karten-Grid (gleiches Layout wie Archive)
  - `[vw_events_upcoming count="3"]` — kompakte Vorschau (9:16-Plakat oben, Fakten kompakt darunter)
  - `[vw_event_submit]` — Submission-Formular (auf Subsites: nur CTA-Block zur Master-URL)
- **Detailseite:** Plakat links, Meta-Block rechts (Wann, Wo, Veranstalter, Ortsteil, Kategorie, Link); Mobile stapelt
- **Admin-UI:** Metabox, Listenspalten mit formatiertem Datum (`30. April 2026 / 18:00 – 20:00`), Filter, Dashboard-Widget für ausstehende Submissions
- **E-Mails:** vier Vorlagen (Admin-Neuanmeldung, Submitter-Bestätigung, Veröffentlichung, Ablehnung); Mehrere Empfänger pro Komma/Semikolon/Zeilenumbruch trennbar
- **Build-Hook:** auf `transition_post_status → publish` triggert das Plugin per `wp_remote_post` einen Cloudflare-Deploy-Hook (60s-Throttle gegen Build-Storm)

### Multisite-Brücke (Mirror)

WP-Multisite mit Master `vv-wildenstein.com` (Blog-ID 1) und Subsites (Grünhainichen Blog-ID 2 etc.). Plugin **netzwerkweit aktiviert**. Auf Subsites ist in den Settings die **Master-Blog-ID** eingetragen → Shortcodes, REST und iCal nutzen `switch_to_blog()` und liefern damit Master-Events auch auf Subsites aus. Detail-Klicks auf Subsite-Karten landen auf der Master-Detailseite (cross-domain, akzeptiert als Übergangslösung).

### Migration aus Events Manager

`includes/class-importer.php` ist eine Admin-Page unter *Veranstaltungen → Import*:
- Liest `wp_em_events` JOIN `wp_em_locations`
- Standort-Mapping: einmalige Tabelle „EM-Ort → vw_standort-Slug"
- Batch-Import 50/Klick, idempotent via `_vw_event_em_id`
- Übernimmt Featured Image (Attachment-ID), `post_content`, Status (`publish` oder `draft`)
- 531 Events erfolgreich migriert

### Astro-Anbindung

- `src/lib/cms-wordpress.ts` → neues `fetchWordPressEvents()` ruft `https://vv-wildenstein.com/wp-json/vw-events/v1/events?from=heute-14d&per_page=100` auf, mappt auf `EventItem`, filtert client-seitig auf zukünftige + aktuell laufende Events (`endDate ?? startDate >= now`)
- `src/lib/cms.ts` → `getEvents()` analog zu `getNews()` mit Quell-Switch via `PUBLIC_CMS_SOURCE` und Local-Fallback (die 46 Markdowns bleiben als Backup)
- `src/pages/index.astro` nutzt jetzt `getEvents()`
- ENV-Variable `PUBLIC_VW_EVENTS_BASE` (optional, Default `https://vv-wildenstein.com/wp-json/vw-events/v1`)

**Wichtiger Bug-Fix während Setup:** Plugin liefert ohne `from`-Parameter ab dem ältesten Event. Bei 531 EM-Events sind die ersten 100 fast alle 2022er → Future-Filter eliminierte alles → Local-Fallback griff. Fix: `from = heute - 14 Tage`, deckt aktuell laufende mehrtägige Events ab.

### Cloudflare-Build-Hook

- In CF *Workers → vv-wildenstein-gruenhainichen → Einstellungen → Bereitstellungs-Hooks*: Hook `wp-vw-events` für Branch `main`
- Hook-URL eingetragen in WP unter *Veranstaltungen → Einstellungen → Cloudflare Deploy-Hooks pro Standort → Grünhainichen*
- Verifikation: Manueller `curl POST` triggerte `build_uuid: 7d9178cd-…` mit `success: true`
- Live-Test bestätigt: zukünftige Events („Hexenfeuer OT … am 30.04.2026", „Maifest in Börnichen am 09.05.2026" usw.) erscheinen in der Astro-Site

### Settings (auf Master)

- **Admin-Benachrichtigungs-E-Mail:** Mehrfach-Adressen via Komma/Semikolon/Newline
- **Cloudflare Turnstile Site-Key + Secret-Key**
- **Master-Blog-ID** (Multisite): leer oder eigene ID
- **Übersichtsseite (URL):** `/leben-freizeit/veranstaltungen/`
- **Einreichungs-Seite (URL):** Master-Seite mit `[vw_event_submit]`
- **Cloudflare Deploy-Hooks pro Standort**

### Plugin-Quelle im Repo

`wp-plugin/vw-events/` (Source) + `wp-plugin/vw-events.zip` (installierbar). Plugin-Anzeigename in WP: „Events im VV Wildenstein". Slug, REST-Namespace, CPT bleiben `vw-events` / `vw_event` (unverändert).

### Bekannte offene Punkte

- Turnstile Site-Key auf Master ist aktuell `mrmek` (Username) statt des korrekten `0x4AAAAAA…` — Frontend-Submission ist deshalb noch nicht funktional
- HTML-Entities (`&#8222;` „) im Astro-Output — kosmetischer Bug in `decodeEntities()`, nicht funktional
- Detail-Klicks auf Subsites führen cross-domain zur Master-Detailseite (per Design)
- Gutenberg-Block, Action-Scheduler, Admin-Calendar-View → v1.1 / v1.2 lt. Spec

---

## 5. Design-System (eingefroren in Phase 1)

### Tokens (alle in `apps/gruenhainichen/src/styles/tokens.css`)

- **Token-Prefix:** `--grh-*` (Single Source of Truth)
- **Klassen-Prefix:** `grh-` (BEM)
- **Farbpalette:** Forest-Grün (8 Stufen), Wood-Brown, Sandstone, Candle-Gold, Cream-Neutrals
- **Typografie:** Fraunces (Display) + Inter (Body), beide selfhosted via `@fontsource-variable`
- **Spacing-Scale:** 4-pt-Basis, Tokens `space-1` bis `space-10`
- **Radii / Schatten / Motion:** alle als Tokens

### Komponenten

| Bereich   | Komponenten                                                              |
|-----------|---------------------------------------------------------------------------|
| Global    | GrhHeaderCompact, GrhMegaMenu, GrhFooter, GrhWappen, GrhLoader            |
| Primitives| GrhButton (mit Pfeil-Reveal-Hover), GrhBadge, GrhIcon                     |
| Hero      | GrhHero (Slider mit Particle-Cursor), GrhFeaturedSlot                     |
| Cards     | GrhNewsCard, GrhEventCard, GrhOrtsteilCard                                |
| Sections  | GrhSection, GrhDualPortal, GrhTraditionShowcase, GrhOrtsteilSpread, GrhTourismGrid (mit Drag-Cursor), GrhEventsList (mit Carousel) |

### Highlights

- **Hero-Slider** mit 5 Slides, Crossfade, Ken-Burns-Zoom, Particle-Layer mit Maus-Repulsion
- **Loader** — beim ersten Laden der Startseite zufällig eine von 3 Animationen:
  - `wheel` (Wasserrad / Waldkirchen)
  - `chess` (Schachfigur auf echtem Schachbrett-SVG / Borstendorf)
  - `angel` (zwei Wendt-&-Kühn-Engel singen mit aufsteigenden Noten / Grünhainichen)
  - URL-Override `?loader=angel|chess|wheel` zum Testen
- **Stacking-Cards** für Sektionen 01–04 (Drei Ortsteile + Wendt&Kühn + Schachdorf + Blaufarbenwerk)
- **Drag-Cursor** im Tourismus-Streifen
- **Tagesveranstaltungen-Carousel** mit Pfeil-Navigation, Scroll-Snap, Edge-Fade
- **Aktuelles-Sektion v2:** Editorial-Spread (Feature-News mit Bild + 3-Card-News-Grid) plus eigene Sperrungen-Spalte mit Severity-Punkten + Service-Box (Schaden melden, Bauhof, Notruf)
- **Mega-Menü Mobile:** nur grünes Brand-Banner ("Gemeinde · Grünhainichen") + Navigation, kein Live-Tab
- **Mobile-Header:** nur Wappen + Burger
- **Globaler Button-Hover:** Pfeil-Reveal in Candle-Gold
- **Saison-Schaltung** (`src/lib/seasons.ts`)

### Komplette Sektions-Liste der Startseite (Phase 2.6 — Stand 04.05.2026)

| Nr. | Eyebrow                     | Layout                                          |
|-----|-----------------------------|-------------------------------------------------|
| —   | Hero                        | Split + Slider + Particles + Pin                |
| —   | Schnelleinstieg             | Doppelportal (Bürger + Gast)                    |
| —   | Willkommen                  | Editorial-Manifest                              |
| 01  | Drei Ortsteile              | **Stacking-Card** (im Stack mit 02–04)         |
| 02  | Holzkunst seit 1915         | **Stacking-Card** (Wendt & Kühn)                |
| 03  | Schachdorf seit 1871        | **Stacking-Card** (Borstendorf)                 |
| 04  | Waldkirchen seit 1687       | **Stacking-Card** (Blaufarbenwerk Zschopenthal) |
| —   | Erleben & Entdecken         | Tourismus-Strip mit Inertia-Drag + Goldcursor   |
| —   | Kalender                    | Tagesveranstaltungs-Carousel (Filter Ort + Kategorie, Inertia-Drag, alle Events) · Mehrtägige Events als **stack-aufklappbare Streifen** (`<details>`) · 2 gleichwertige CTAs: „Vollständiger Kalender" + „Termin einreichen" |
| —   | Aktuelles                   | **Editorial-Liste (10 News)** mit Smart-Cover-Mini-Plakaten · Sperrungen-Spalte rechts + Service-Box |
| —   | Wirtschaftsstandort         | Forest-Sektion mit italic Claim                 |
| —   | Mittendrin                  | Stats-Reihe + 6-Tile-Service-Grid + 2 CTAs (**heller Trenner direkt vor dem dunklen Footer**) |
| —   | ~~Rathaus~~                 | ~~Eigene Sektion entfernt — Inhalte komplett in den Footer integriert~~ |

---

## 6. Inhalte — wo sie gerade leben

| Inhaltstyp | Quelle | Notiz |
|------------|--------|-------|
| News       | **WordPress live** (mit `local`-Fallback) | Adapter `src/lib/cms.ts` |
| Sperrungen | **WordPress live** (Category `sperrung`) | Zusatzfelder noch nicht in WP gepflegt |
| Events     | Astro Content Collections (lokal) | 46 Events aus iCal-Feed importiert via `/tmp/import-events.py` |
| Ortsteile  | Astro Content Collections (lokal) | Stammdaten, ändern sich kaum |

---

## 7. Hosting & Deployment

### Cloudflare-Account

- **Eigentümer:** Stefan, E-Mail: `stefan@gumu-agentur.de` (umgestellt von `Stefan@gutermuth.media` und verifiziert am 29.04.2026)
- **Account-ID:** `f0ea2c6b50485053bbacc2c5963a6eb6`
- **Plan:** Free
- **Cloudflare-User-Anzeige:** „mrmek" — derselbe Username wie im WP-Admin

### Workers Builds Setup für Grünhainichen

- **Projekt:** `vv-wildenstein-gruenhainichen`
- **Repo:** `stefangutermuth/vv-wildenstein`, Branch `main`
- **Build-Befehl:** `npm run build:gruenhainichen`
- **Bereitstellungsbefehl:** `npx wrangler deploy`
- **Asset-Verzeichnis** (in `wrangler.jsonc`): `./apps/gruenhainichen/dist`
- **Auto-Deploy:** bei jedem Push auf `main`
- **Build-Variablen** (Settings → Erstellen → „Variablen und geheime Schlüssel"):
  - `PUBLIC_CMS_SOURCE = wordpress`
  - `WP_AUTH_USER = mrmek`
  - `WP_AUTH_PASS = <App-Password>` (Secret/encrypted!)

**Wichtig:** Build-Variablen müssen in der **„Erstellen"**-Sektion eingetragen werden, NICHT in „Variablen und geheime Schlüssel" oben (Runtime-Bindings — funktionieren nicht für Static-Asset-Worker).

### GitHub-Setup

- **Owner:** stefangutermuth
- **Repo:** vv-wildenstein
- **Default-Branch:** main
- **Authentifizierung:** seit 04.05.2026 via **`gh auth login`** (OAuth-Token im Keychain, kein Ablaufdatum). Der alte Fine-grained PAT „vv-wildenstein deploy" wird nicht mehr verwendet — läuft am 29. Mai 2026 einfach harmlos aus. Git-Credential-Helper global: `credential.https://github.com.helper = !gh auth git-credential`

### WordPress-Zugang

- **Admin-URL:** https://vv-wildenstein.com/wp-admin
- **Build-User:** `mrmek` mit Application Password (Stefan's Admin-Login)
- **App-Password im WP:** Benutzer → Profil → Anwendungs-Passwörter → „gruenhainichen-build"

---

## 8. Erledigte Aufgaben (Chronologie)

### Phase 1 — Erstwurf (abgeschlossen)
- ✅ Astro-Projekt + Token-System + Komponenten
- ✅ Header (kompakt mit Burger), Footer, Mega-Menü, Loader (3 Varianten)
- ✅ Hero-Slider, Drei-Ortsteile-Triptychon, Tradition-Showcases
- ✅ Tourismus-Streifen, Events, News, Mittendrin
- ✅ Stacking-Cards für Ortsteil-Sektionen
- ✅ Bilder von Original-Site + Pfade integriert

### Infrastruktur (abgeschlossen)
- ✅ Monorepo-Restrukturierung (`apps/gruenhainichen/`, npm-Workspaces)
- ✅ Git-Repo bei GitHub + Erstpush
- ✅ Cloudflare-Account-E-Mail umgestellt
- ✅ Cloudflare-Projekt verbunden + Auto-Deploy

### Phase 1.5 — UX-Feinschliff + Mobile (abgeschlossen 29.04.2026)
- ✅ Header-Brand-Link auf `/`, „Grünhainichen" leicht größer, Mobile = nur Wappen + Burger
- ✅ Eyebrow-Nummerierung neu: nur noch Ortsteil-Sektionen 01–04
- ✅ „Worum es hier geht" → „Willkommen"
- ✅ DualPortal: Eckzahlen entfernt
- ✅ Wirtschaftsstandort-Buttons mit hellen Varianten auf Forest-Grund
- ✅ Globaler Button-Hover: Pfeil-Reveal in Candle-Gold
- ✅ Favicon = Gemeindewappen (PNG + Apple-Touch-Icon)
- ✅ Loader-Schachfigur als echte Borstendorfer SVG, echtes Schachbrett, **zwei singende Engel mit Animation** (Wackeln, Schweben, Notenflug) für Grünhainichen
- ✅ Tagesveranstaltungen als horizontales Carousel (Pfeile, Snap, Edge-Fade)
- ✅ Aktuelles-Sektion v2 (Editorial-Spread + Sperrungen-Spalte + Service-/Notfall-Box)
- ✅ News-Schema um optionale Sperrungs-Felder erweitert
- ✅ Mobile-Polishing (Carousel, Loader-Größe via clamp, Mega-Menü auf Brand-Banner reduziert)

### Phase 2 — Headless WordPress (abgeschlossen 29.04.2026)
- ✅ CMS-Adapter (`src/lib/cms.ts`) mit `local | wordpress` Quellen
- ✅ WP-Fetcher (`src/lib/cms-wordpress.ts`) mit Kategorie- + Ortsteil-Mapping
- ✅ Inline-Bild-Fallback aus `content.rendered` (PDFs werden ignoriert, Platzhalter ausgefiltert)
- ✅ Build-Time Basic-Auth via `WP_AUTH_USER` + `WP_AUTH_PASS` für Media-API
- ✅ Cloudflare Build-Variables hinterlegt + Build-Cache geleert
- ✅ Bug-Fix: Astro liest in CF-Builds nur `process.env`, nicht `import.meta.env` — beide Quellen geprüft
- ✅ Live: News + Sperrungen + Bilder kommen aus WP
- ✅ 46 zukünftige Events aus iCal-Feed (`/events/?ical=1`) als Markdown importiert (Skript: `/tmp/import-events.py`)

### Phase 2.5 — Eigenes Events-Plugin (abgeschlossen 30.04.2026)
- ✅ Plugin `vw-events` gebaut: CPT, Taxonomien, REST-Namespace, Submission-Form mit Turnstile, iCal, Mails, Webhook-Trigger
- ✅ Multisite-Brücke: Plugin netzwerkweit aktiv, Subsites lesen via `switch_to_blog()` vom Master (Blog-ID 1)
- ✅ Detailseite (Plakat links + Meta-Block rechts), Archive-Template (Karten-Grid), Shortcodes `[vw_events_list]` + `[vw_events_upcoming]`
- ✅ Importer: 531 Events aus Events Manager (`wp_em_events`/`em_locations`) idempotent migriert via Batch-Import (50/Klick)
- ✅ Mehrere Empfänger-E-Mails möglich (Komma/Semikolon/Newline)
- ✅ Übersichtsseiten-URL + Einreichungs-Seiten-URL als Settings → Subsites zeigen CTA zur Master-Submission statt eigenem Form
- ✅ Astro-Adapter: `fetchWordPressEvents()` + `getEvents()` mit Local-Fallback (46 Markdowns)
- ✅ Cloudflare-Deploy-Hook `wp-vw-events` für Branch `main` angelegt + in WP eingetragen
- ✅ End-to-End-Test bestätigt: zukünftige Events („Hexenfeuer am 30.04.2026" etc.) erscheinen live auf gruenhainichen.com

### Phase 2.6 — UX-Feinschliff Listen + Carousels + Auth (abgeschlossen 04.05.2026)
- ✅ **News-Sektion komplett umgebaut:** aus dem 6-Card-Grid mit Feature wurde eine **Editorial-Liste mit 10 News** (kleines Plakat-Thumbnail links, Kategorie + Datum oben, Titel rechts, Pfeil ganz rechts). Sperrungen-Spalte rechts unverändert.
- ✅ **Smart-Cover-Pattern überall**: Bild zeigt sich immer komplett (`object-fit: contain`), eigener unscharf-abgedunkelter Hintergrund desselben Bildes füllt die Lücken bei gemischten Aspect-Ratios. Angewendet auf News-Thumbnails, Tagesveranstaltungs-Plakate UND mehrtägige Event-Cards.
- ✅ **Tourismus-Strip + Tagesveranstaltungs-Carousel neu gebaut:** Pointer-Events mit Inertia-Physik (Velocity-Tracking, Reibung 0.94 pro 16 ms), `scroll-snap` raus, `scroll-behavior: auto` — fühlt sich an wie Apple-Carousels.
- ✅ **5-px-Drag-Threshold + Klick-Swallow**: Klicks auf Kacheln/Cards bleiben sauber erhalten, kein versehentliches Öffnen nach Drag.
- ✅ **Goldener „Ziehen"-Cursor** wieder eingebaut (rein visuell, folgt der Maus mit Easing, schrumpft beim aktiven Ziehen) — sowohl im Tourismus-Strip als auch im Tagesveranstaltungs-Carousel.
- ✅ **Tourismus-Strip nicht mehr full-bleed**, liegt jetzt in der normalen Container-Breite. Progress-Bar von 4 px auf 2 px verschmälert, Nav-Buttons von 48 px auf 32 px verkleinert.
- ✅ **Live-Filter (Ort + Kategorie)** bei den Tagesveranstaltungen — Chips-Reihe, klick filtert sofort, Empty-State falls nichts passt. Kategorie aktuell aus Titel-Heuristik (`brauchtum` / `musik` / `natur` / `markt` / `gemeinschaft` / `leben`); wird ersetzt sobald `vw-events` echte Kategorien liefert.
- ✅ **Alle Events im Carousel** (kein `slice(0, 8)` mehr).
- ✅ **Mehrtägige Events als Stack:** `<details>`/`<summary>` mit kompaktem Streifen (Mini-Plakat + Datum-Block + Titel + Toggle), Klick klappt das volle Plakat (3:4 mit Smart-Cover) + Teaser + CTA inline auf.
- ✅ **Datum im Stack zweizeilig** (z.B. `24.–28.` über `SEP`) für ruhigere Optik.
- ✅ **Zwei gleichwertige CTAs unter den Events:** „Vollständiger Kalender" + „Termin einreichen" (Pill-Buttons identisch gestylt). Der Submit-Link zeigt auf `/veranstaltungen/einreichen` — wird durch `[vw_event_submit]` bedient sobald die Seite existiert.
- ✅ **„So erreichen Sie uns"-Sektion entfernt**, alle Inhalte sitzen im Footer; „Termin vereinbaren"-Link in der Sprechzeiten-Spalte ergänzt. `margin-top` am Footer raus → kein weißer Streifen mehr.
- ✅ **Vereinsleben** nach unten verschoben (heller Trenner direkt vor dem dunklen Footer).
- ✅ **GitHub-Auth umgestellt** von Fine-grained PAT auf `gh auth login` (OAuth, kein Ablauf). Lokaler `osxkeychain`-Helper entfernt, globaler `gh auth git-credential` aktiv.

### Phase 2.7 — Live-Wetter, FAB, Farb-Politur, All-Inkl-Staging (abgeschlossen 26.06.2026)
- ✅ **Forest-Tokens heller**: `--grh-forest-900` von `#13301F` → `#1A3D27`, `--grh-forest-950` von `#0E1F18` → `#142B1F`. Alle hardcoded Vorkommen (auch `rgba(19,48,31)` und `rgba(14,31,24)`) konsistent ersetzt.
- ✅ **Live-Wetter im Header**: Open-Meteo (kein API-Key, kein Tracking), Koordinaten Grünhainichen 50.7718/13.1578, Icon wechselt nach Wetter-Code, sessionStorage-Cache 30 Min, Widget bleibt hidden bei Fehler statt Lüge zu zeigen.
- ✅ **Floating Contact Button (FAB)** — neue Komponente `GrhFloatingContact.astro`:
  - Position fixed bottom-right
  - Drei Arms radial (90°/135°/180°): Kontakt · Mängelmelder · Suche
  - Goldene Verbindungs-Fäden vom Trigger zu jedem Arm („Netz"-Optik)
  - Kontakt-Panel: Department-Dropdown (Einwohnermeldeamt, Standesamt, Bauamt, Tourismus, Ordnungsamt, Bürgermeister, Sonstige) + Anliegen-Textarea + Name + E-Mail (untereinander, nicht in Spalten)
  - Suche-Panel: Input + Hinweis „Live-Suche kommt"
  - Mängel-Panel: CTA zu `/maengelmelder` (Foto + Standort später via Plugin)
  - Goldener Glow auf grünen Submit-Buttons (sichtbar auch auf grünem Hintergrund)
  - Eingebunden in `BaseLayout.astro` → erscheint auf jeder Seite
- ✅ **Mega-Menü Demo-Varianten**: Vier Layouts zum Vergleichen im Footer-Demo-Block (Original · A Live-Zeile zwischen Nav und Feature · B Ticker oben · C Floating Card). Variant-Switcher per `data-grh-mega-variant`.
- ✅ **All-Inkl-Staging-Pipeline** unter `.github/workflows/deploy-allinkl.yml`:
  - Trigger: Push auf `main` + manueller Workflow-Dispatch
  - Build mit `PUBLIC_CMS_SOURCE=wordpress` + `PUBLIC_STAGING=true` (Letzteres setzt `<meta robots=noindex>`)
  - rsync via SSH auf vollen Pfad `/www/htdocs/w01f6038/grh.vv-wildenstein.com/`
  - Cloudflare-Build läuft parallel weiter (Backup-Sicherheit), wird nach 2–3 Wochen abgeschaltet
- ✅ **`.htaccess` im Build** (`apps/gruenhainichen/public/.htaccess`): PHP aus, HTTPS-Redirect, `.html`-Endung aus URLs, Gzip, Caching-Header, Security-Header
- ✅ **GitHub-Secrets gesetzt**: `ALLINKL_SSH_HOST`, `ALLINKL_SSH_USER`, `ALLINKL_SSH_KEY`, `WP_AUTH_USER`, `WP_AUTH_PASS`

---

## 9. Offene Aufgaben

### Bald — niedriger Aufwand, hoher Nutzen
- [ ] **Detailseiten `/neuigkeiten/[slug].astro`** — aktuell springt Klick auf eine News-Karte zur Original-WP-URL, gewünscht: eigene Seite im Grünhainichen-Design mit WP-Inhalt embeddet
- [ ] **Archiv-Seite `/neuigkeiten`** — vollständiges News-Archiv mit Filter pro Ortsteil
- [ ] **`/wetter`-Route** anlegen (aktuell zeigt der Header-Wetter-Link auf 404)
- [ ] **Turnstile Site-Key korrigieren** auf Master (`mrmek` → korrekter `0x4AAAAAA…`-Key) — sonst Frontend-Submission deaktiviert
- [ ] **HTML-Entity-Decoder** in `cms-wordpress.ts` um `&#8222;`, `&#8230;` etc. erweitern (kosmetisch)

### Mittel — kosmetisch / inhaltlich
- [ ] **Sperrungs-Felder in WP** als ACF-Custom-Fields pflegen: `affectedStreet`, `detour`, `validUntil`, `severity` — Adapter liest sie schon
- [ ] **Restliche Unterseiten** als Astro-Routen anlegen — derzeit zeigen alle Menülinks auf 404
- [ ] **Original-Wappen-SVG** in Vektorform anfragen (statt PNG) — bessere Skalierbarkeit
- [ ] **vw-events Plugin v1.1**: Gutenberg-Block für Submission-Form, „Ablehnen mit Begründung"-Button, Action-Scheduler für asynchrone Mails

### Phase 3 — Multi-App-Ausbau (parallel in eigenen Chats)
- [ ] **`apps/verband/`** für `2026.vv-wildenstein.com` — eigenes Wappen, eigene Foto-Bibliothek, optisch eigenständige Farbpalette, Inhalte vom Verband (siehe §10 Multi-App-Theming)
- [ ] **`apps/boernichen/`** für `boernichen.vv-wildenstein.com` — analog, eigenes Wappen
- [ ] **`apps/maengelmelder/`** für `melder2026.vv-wildenstein.com` — PWA, Submission-Form, Standort-Pin, Foto-Upload
- [ ] **`vw-melder` WP-Plugin** spezifizieren + bauen (analog `vw-events`): CPT für Submissions, REST, Admin-Status-Pipeline, Mail-Benachrichtigungen
- [ ] **Design-System extrahieren** in `packages/design-system/` (nachdem Verband+Börnichen stehen, dann sehen wir genau welche Komponenten geteilt werden müssen)
- [ ] **GitHub-Action erweitern** um Deploy-Jobs für die drei zusätzlichen Apps (`.github/workflows/deploy-allinkl.yml`)
- [ ] **DNS-Switch (Day X):** `gruenhainichen.com`, `vv-wildenstein.com`, `boernichen.de`, `melder.vv-wildenstein.com` von alten WP-Themes auf die fertigen Astro-Builds umstellen

### Optional / Nice-to-have
- [ ] Sitemap.xml wieder aktivieren (`@astrojs/sitemap`)
- [ ] OG-Image-Generator (dynamische Social-Cards pro News-Beitrag)
- [ ] Suchfunktion im Header
- [ ] DSGVO-Cookie-Banner integrieren
- [ ] Barrierefreiheits-Audit (BITV 2.0 / WCAG 2.1 AA)
- [ ] Lighthouse-Optimierung (Bilder als WebP/AVIF, lazy-loading)
- [ ] GSAP-Migration prüfen falls Animations-Choreografie wächst

---

## 10. Wichtige Entscheidungen (zur Erinnerung)

| Entscheidung                          | Grund                                                                |
|----------------------------------------|----------------------------------------------------------------------|
| **Astro statt klassisches WordPress-Theme** | Performance, moderne UX, Animationen kompatibel halten             |
| **Headless WP statt Strapi/Directus**  | Redakteure kennen WP, Multi-Site-Vermittlung über Standort-Categories |
| **Single WP statt WP-Multisite**       | Eine Login-Maske, einfache Plugin-Updates, Cross-Site-Inhalte trivial|
| **Monorepo statt 3 separate Repos**    | Geteiltes Design-System, ein Bug-Fix wirkt überall                   |
| **npm-Workspaces statt pnpm/Turborepo**| Minimale Tool-Vorinstallation, Cloudflare versteht npm nativ         |
| **Cloudflare Pages statt GitHub Pages**| Per-Branch-Previews kostenlos, mehrere Apps aus einem Repo möglich   |
| **wrangler.jsonc statt klassische Pages-UI** | Cloudflare hat Workers + Pages zu einem Flow zusammengelegt    |
| **CMS-Adapter mit ENV-Switch**         | Local/WP umschaltbar, Phase-2-Migration wurde zur 5-Minuten-Sache    |
| **process.env + import.meta.env doppelt prüfen** | Cloudflare Builds verwendet nur process.env                  |
| **Application Password statt Media-API freischalten** | WP-Härtungseinstellung bleibt unangetastet                  |
| **Inline-Bild-Fallback aus content.rendered** | Redaktion nutzt „Beitragsbild" oft für PDFs, echte Bilder inline |
| **Vanilla CSS + IntersectionObserver, kein GSAP** | Kein zusätzliches Framework nötig                          |
| **Kein Tailwind**                      | BEM-Klassen sind kompatibler mit späterem WP-Theme-Migrationspfad    |
| **Kein Orange im Design**              | Briefing-Vorgabe — alte Site-Anmutung soll nicht weiterleben         |
| **Schwibbogen-Animation entfernt**     | Auf Wunsch — passt nicht zu jeder Saison                             |
| **Eyebrow-Nummerierung nur an Ortsteilen** | Sonst zu viele Zahlen auf einer Seite                           |
| **Mobile-Header: nur Wappen + Burger** | Maximale Reduktion auf Mobile, alle Tools im Mega-Menü               |

---

## 10a. Multi-App-Theming (für neue Apps in Phase 3)

Drei optisch **eigenständige** Frontends, die alle aus dem **gleichen Backend** lesen. Das Repo ist ein npm-Workspace-Monorepo, jede App ist autark.

### So legst Du eine neue App an

```bash
cd apps/
cp -R gruenhainichen verband        # vollständige Kopie als Startpunkt
cd verband/
```

Dann **in der Reihenfolge** anpassen:

1. **`package.json`** — Name auf `@vv/verband`, Version 0.1.0
2. **`astro.config.mjs`** — `site` auf Staging-URL: `https://2026.vv-wildenstein.com`
3. **`src/styles/tokens.css`** — eigene Farbpalette (Forest-Werte können bleiben oder app-spezifisch sein), eigene Display-Font wenn anders
4. **`public/images/logos/wappen-*.png`** — eigenes Wappen, dann in `GrhWappen.astro` referenzieren
5. **`public/images/`** — alle Fotos durch app-eigene ersetzen (Hero-Slides, Ortsteile, Tradition)
6. **`src/lib/navigation.ts`** — Mega-Menü-Struktur an die andere Site anpassen
7. **`src/pages/index.astro`** — Sektions-Texte (Manifest, Tradition-Showcases, Ortsteile) inhaltlich umschreiben
8. **`src/content/`** — lokale Fallback-Inhalte austauschen (oder leer lassen wenn WP-Source funktioniert)
9. **`.env.example`** — `PUBLIC_CMS_SOURCE=wordpress` (sollte schon stehen)

### CMS-Filter pro App

Der CMS-Adapter (`src/lib/cms.ts` + `cms-wordpress.ts`) ist in JEDER App identisch. Die App-Spezifik kommt **rein über die Category-Filter** im WP:

| App | filtert in WP nach Categories |
|---|---|
| `apps/gruenhainichen/` | `gruenhainichen`, `borstendorf`, `waldkirchen-*`, plus alles ohne Ortsteil-Cat |
| `apps/verband/`        | `verband-weit` plus alles ohne Ortsteil-Cat |
| `apps/boernichen/`     | `boernichen` plus alles ohne Ortsteil-Cat |

→ Den Filter im `cms-wordpress.ts` jeder App pro Bedarf anpassen. Die WP-REST liefert dasselbe Datenmodell.

### Workspace-Scripts (Root-`package.json`)

Pro neuer App ergänzen:
```json
"build:verband":     "npm run build --workspace=@vv/verband",
"build:boernichen":  "npm run build --workspace=@vv/boernichen",
"build:maengelmelder": "npm run build --workspace=@vv/maengelmelder",
"dev:verband":       "npm run dev --workspace=@vv/verband"
```

### Deploy-Job pro App

In `.github/workflows/deploy-allinkl.yml` einen neuen Job nach der Vorlage `deploy-gruenhainichen` hinzufügen, nur:
- Build-Script: `npm run build:<name>`
- rsync-Quelle: `apps/<name>/dist/`
- rsync-Ziel: `/www/htdocs/w01f6038/<subdomain>/`

Jeder App-Build deployt **unabhängig** — eine kaputte Verband-App blockiert nicht das Grünhainichen-Deploy.

---

## 11. Stiel-Stolpersteine (Lessons learned)

- **CSS-Variablen in `transform`** ohne `@property`-Deklaration werden nicht interpoliert
- **`getBoundingClientRect()` auf Animations-Elementen** liefert post-transform-Position
- **Cloudflare hat Workers + Pages vereinheitlicht** — neue Projekte brauchen `wrangler.jsonc`
- **macOS Keychain als Credential-Helper** ist die einzige saubere Lösung für lokales Git ohne Token-Re-Eingabe
- **Astro-Content-Collections-Schema-Feld `slug`** ist reserviert — eigene Felder anders nennen
- **Astro `import.meta.env` ≠ Cloudflare Build-Env** — in Cloudflare Workers Builds kommen Build-Variablen nur in `process.env` an, NICHT in `import.meta.env`. Beide Quellen prüfen!
- **Cloudflare Build-Variablen sind unter Settings → „Erstellen" → „Variablen und geheime Schlüssel"** — NICHT unter dem oberen „Variablen und geheime Schlüssel" (das gibt's nur bei Workers mit Code, nicht bei Static-Asset-Workern)
- **WP-Media-API kann pro Installation gesperrt sein** — Application Password ist der saubere Workaround
- **WP-Redaktion nutzt „Beitragsbild" oft für PDFs/Word-Docs** — echte Bilder aus `content.rendered` extrahieren
- **CSS-Source-Order matters** — Mobile-Override-Regeln müssen NACH den Default-Regeln stehen, sonst werden sie überschrieben
- **Cloudflare-CDN-Cache** überlebt Re-Deploys — nach kritischen Änderungen ggf. „Cache löschen" in den Settings
- **Astro-Loaders mit Random** zeigen ihre Variante zufällig — `?loader=angel|chess|wheel` zum gezielten Testen einbauen
- **All-Inkl SSH-Chroot ≠ Web-Root**: SSH landet im chrooted Home, das anders aussieht als der echte Web-Pfad. Subdomains lesen aus `/www/htdocs/<account>/<subdomain>/`, nicht aus `~/<subdomain>/`. → rsync immer mit **vollem Pfad** als Ziel
- **PUBLIC_STAGING als Build-Flag**: setzt `<meta name="robots" content="noindex,nofollow">`, damit Staging-Subdomains nicht in Google landen. Vor dem DNS-Switch auf `false` setzen
- **Cloudflare-Build + All-Inkl-Build parallel**: beide laufen unabhängig, jeder Push triggert beide. Nach 2–3 Wochen stabilen All-Inkl-Betriebs Cloudflare deaktivieren

---

## 12. Wenn die Konversation neu gestartet wird

**Neue Konversation übergeben mit:**
> Hier ist `PROJECT_STATUS.md` — bitte lies das einmal komplett. Das Projekt ist ein Astro-Monorepo unter `/Users/stefan/Claude/Website/gruenhainichen` (Workspace-Root). Live-URL ist `vv-wildenstein-gruenhainichen.stefan-0ea.workers.dev`. WordPress-Backend ist `vv-wildenstein.com`. CMS-Adapter zieht News + Bilder live aus WP. Letzter Stand: <kurze Beschreibung was zuletzt passiert ist>.

**Vor jeder größeren Änderung empfohlen:**
- Branch erstellen (`git checkout -b feature/xxx`), nicht direkt auf main
- Push auf den Branch → Cloudflare baut Preview-URL
- Erst nach Sichtkontrolle nach `main` mergen

**Vor dem Push ggf. lokal mit WP-Daten testen:**
```bash
cp apps/gruenhainichen/.env.example apps/gruenhainichen/.env.local
# WP_AUTH_USER + WP_AUTH_PASS eintragen
PUBLIC_CMS_SOURCE=wordpress npm run dev
```

---

## 13. Kontakt & Auftraggeber

- **Auftraggeber:** GUMU Werbeagentur (Stefan)
- **E-Mail:** stefan@gumu-agentur.de
- **Mandant:** Gemeinde Grünhainichen / Verwaltungsverband Wildenstein
