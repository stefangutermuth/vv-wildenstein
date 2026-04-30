# `vw-events` — Handoff für die Plugin-Weiterentwicklung

> Dieses Dokument ist der **Einstieg** für jeden, der am Plugin arbeitet — egal ob in einem dedizierten Chat, in einem neuen Worktree oder lokal im Editor. Wenn du das Plugin änderst, lies erst das hier, dann die Spec.

---

## TL;DR

- **Source:** [`wp-plugin/vw-events/`](vw-events/) — das ist die einzige Wahrheit, das ZIP wird daraus gebaut
- **Spec:** [`../docs/vw-events-plugin-spec.md`](../docs/vw-events-plugin-spec.md) — funktional + nicht-funktionale Anforderungen
- **Status / Historie:** [`../PROJECT_STATUS.md` §4a](../PROJECT_STATUS.md)
- **Repo-Konvention:** ein Monorepo, Plugin lebt neben dem Astro-Frontend
- **Workflow:** `git pull` → Code ändern → ZIP bauen → in WP Admin neu hochladen → testen → committen + pushen

---

## Repo-Layout (was wo liegt)

```
vv-wildenstein/
├── apps/gruenhainichen/      ← Astro-Frontend (greift via REST aufs Plugin zu)
├── docs/
│   └── vw-events-plugin-spec.md
├── wp-plugin/
│   ├── README.md             ← Build- / Install-Anleitung
│   ├── PLUGIN_HANDOFF.md     ← diese Datei
│   ├── .gitignore            ← schließt das ZIP-Artefakt aus
│   └── vw-events/            ← Plugin-Source (versioniert)
│       ├── vw-events.php             ← Plugin-Header + Bootstrap
│       ├── includes/                 ← alle Klassen (siehe unten)
│       ├── templates/
│       │   ├── frontend/             ← form.php, archive-event.php, …
│       │   └── email/                ← 4 Mail-Templates
│       └── assets/                   ← CSS + JS
└── PROJECT_STATUS.md
```

---

## Was Code-seitig wichtig ist (Plugin-intern)

### Naming-Konvention

- **Funktions-/Klassen-Prefix:** `VW_Events_*` oder `vw_events_*` (snake_case für globale Funktionen)
- **CPT:** `vw_event` · **Taxonomien:** `vw_standort`, `vw_event_category`
- **Meta-Keys:** `_vw_event_*` (mit Leading-Underscore = nicht in Standard-Custom-Fields-Box)
- **Text-Domain:** `vw-events`
- **REST-Namespace:** `vw-events/v1`

### Dateistruktur

| Datei | Verantwortung |
|---|---|
| `vw-events.php` | Plugin-Header, Activation/Deactivation, Bootstrap (`require_once` + `init`-Calls) |
| `includes/helpers.php` | Globale Utility-Fns (`vw_events_format_date_range`, IP-Hash etc.) |
| `includes/class-cpt.php` | CPT + Taxonomie-Registrierung + Default-Terms |
| `includes/class-meta.php` | `register_post_meta` + Sanitizer |
| `includes/class-admin-ui.php` | Metabox, Listenspalten, Settings-Page, Dashboard-Widget |
| `includes/class-rest-events.php` | `GET /events`, `GET /events/{id}` |
| `includes/class-rest-ical.php` | `GET /ical` |
| `includes/class-rest-submissions.php` | `POST /submissions` |
| `includes/class-frontend-form.php` | Shortcodes (`[vw_event_submit]`, `[vw_events_list]`, `[vw_events_upcoming]`) + JS-Enqueue |
| `includes/class-single-view.php` | Detailseite + Archive-Template + `archive_url`-Redirect |
| `includes/class-mailer.php` | Alle Mail-Versendungen + `transition_post_status`-Hooks |
| `includes/class-webhooks.php` | Cloudflare-Deploy-Hook-Trigger mit 60s-Throttle |
| `includes/class-turnstile.php` | Server-Side Turnstile-Verifikation |
| `includes/class-importer.php` | Events-Manager-Migration (Admin-Page + Batch-AJAX) |
| `includes/class-multisite.php` | `switch_to_blog()`-Wrapper für Subsites |

Jede Klasse hat eine statische `init()`-Methode, die im Plugin-Bootstrap aufgerufen wird. Konventionen:
- `init()` registriert nur Hooks (`add_action`, `add_filter`)
- Keine globalen Variablen, keine Singletons über Statisches hinaus
- DI nicht nötig — WordPress-Hook-System reicht

### Settings-Schema

`get_option( 'vw_events_settings' )` liefert ein Array mit:

```php
[
  'admin_email'      => 'a@b.de, c@d.de',     // mehrere via , ; \n trennbar
  'turnstile_site'   => '0x4AAAAAA…',
  'turnstile_secret' => '...',
  'archive_url'      => '/leben-freizeit/veranstaltungen/',
  'submit_url'       => 'https://master.tld/event-einreichen/',
  'master_blog_id'   => 1,                    // Multisite, 0 = Master / kein Switch
  'webhook_map'      => [ 'gruenhainichen' => 'https://…' ],
]
```

Defaults werden in `VW_Events_Admin_UI::get_settings()` zentralisiert — wenn du ein neues Feld hinzufügst, erweitere dort `$defaults` UND `sanitize_settings()`.

---

## REST-API-Kontrakt (Achtung: bricht das Astro-Frontend!)

Das Astro-Frontend ([apps/gruenhainichen/src/lib/cms-wordpress.ts](../apps/gruenhainichen/src/lib/cms-wordpress.ts)) erwartet folgendes Response-Schema:

### `GET /vw-events/v1/events`

```json
[
  {
    "id": 123,
    "slug": "string",
    "title": "string (HTML-Entities erlaubt)",
    "description_html": "string",
    "start": "2026-05-30T14:00:00+02:00",
    "end":   "2026-05-30T22:00:00+02:00",
    "all_day": false,
    "location": { "name": "string", "address": "string" },
    "organizer": { "name": "string" },
    "url": "string",
    "image": { "url": "string", "alt": "string" } | null,
    "standort": ["string", …],
    "category": ["string", …],
    "permalink": "string"
  }
]
```

**Wenn du das Schema änderst** (Feldnamen, Datentypen, Pflicht/optional), passe **gleichzeitig** [`apps/gruenhainichen/src/lib/cms-wordpress.ts`](../apps/gruenhainichen/src/lib/cms-wordpress.ts) (`mapVWEvent` + `interface VWEvent`) an. Sonst läuft Astro auf Fallback (lokale Markdowns) und niemand merkt's außer beim Live-Check.

### Astro fetcht mit

- `from = heute - 14 Tage` (damit aktuell laufende mehrtägige Events drinbleiben)
- `per_page = 100`
- Keine Auth-Header — Endpunkt ist öffentlich

### Private Felder (NIE in Response!)

- `_vw_event_organizer_email`
- `_vw_event_submitter_name`
- `_vw_event_submitter_email`
- `_vw_event_submission_ip`

`VW_Events_Helpers::format_event()` ist die einzige Stelle, die Events ausgibt — solange du da nichts dazumachst, bleibt's privat.

---

## Build & Deployment

### Plugin als ZIP bauen

```bash
cd wp-plugin
rm -f vw-events.zip
zip -rq vw-events.zip vw-events
```

Die ZIP ist `.gitignore`d — committe sie nicht.

### In WP installieren / aktualisieren

- *Plugins → Hochladen* → ZIP-Datei wählen → installieren
- Bei Update: vorhandenes Plugin **deaktivieren + löschen**, dann neu hochladen
- Settings + Daten in der DB bleiben erhalten (Plugin-Daten liegen in `wp_options`, `wp_posts`, `wp_postmeta`, `wp_terms`)

### Multisite

Plugin **netzwerkweit aktivieren** (*Network-Admin → Plugins*). Auf Subsites unter *Veranstaltungen → Einstellungen* die **Master-Blog-ID** eintragen (vermutlich `1`).

---

## Testen

### Lokal

Es gibt aktuell **keine PHPUnit-Tests** im Repo (Plugin liegt in einem Astro-Monorepo, das hat keine PHP-Tooling). Wenn du Tests willst:
- WP-CLI + WP-PHPUnit + Docker oder Lando — out-of-scope für dieses Repo
- Pragmatisch: manuelles Testen auf einer Staging-WP-Installation

### Lint

```bash
# auf einem System mit PHP-CLI:
find wp-plugin/vw-events -name '*.php' -exec php -l {} \;
```

### REST-Smoke-Test

```bash
curl -sS https://vv-wildenstein.com/wp-json/vw-events/v1/events?from=2026-01-01 \
  | python3 -m json.tool | head -30
```

---

## Was offen ist (laut Spec, v1.1 + v1.2)

### v1.1
- [ ] Gutenberg-Block für Submission-Form (`vw-events/submit-form`) — analog zum Shortcode
- [ ] „Ablehnen mit Begründung" als One-Click-Action im Edit-Screen (Mail-Template `submitter-rejected.php` ist schon da)
- [ ] Action-Scheduler für asynchrone Mails statt synchronem `wp_mail` in `submissions`-Handler

### v1.2
- [ ] Admin-Calendar-View (FullCalendar.js) als Übersicht
- [ ] Dublette-Erkennung beim Submit (gleicher Titel + gleicher Tag)

### Bekannte Bugs / Aufräumarbeiten
- [ ] Turnstile-Site-Key auf der produktiven Master-Site ist aktuell `mrmek` (Username-Eintrag) statt eines echten CF-Keys → Frontend-Form damit deaktiviert
- [ ] HTML-Entity-Decoder im Astro-Frontend deckt nicht alle Entities ab (`&#8222;`, `&#8230;`) — nicht plugin-seitig, aber kontrakt-relevant: das Plugin liefert HTML-Entities im `title`, das Frontend muss decoden
- [ ] `class-importer.php` arbeitet idempotent über `_vw_event_em_id`, hat aber kein UI für „Re-Import nur dieser Standort" — falls Bedarf

---

## Workflow für den Plugin-Chat

1. **Erste Aktion in jeder Session:** `git pull origin main` im Repo
2. **Bevor du Code änderst:** lies relevante Klasse(n) in `wp-plugin/vw-events/includes/`
3. **Spec-Lookup:** `docs/vw-events-plugin-spec.md` — der Wahrheit für funktionale Fragen
4. **Astro-Auswirkung prüfen:** falls REST-Schema betroffen → Hinweis im Commit-Body
5. **Test-ZIP bauen + manuell in WP testen** — keine CI für PHP
6. **Commit + Push** auf `main` (Solo-Dev-Modell, keine PRs nötig sofern alles testet)
7. **CF-Build wird nicht ausgelöst** durch Plugin-Code-Änderungen — nur durch Event-Publish in WP. Plugin-Updates sind Backend-only.

---

## Was du _nicht_ anfassen solltest

- **`apps/gruenhainichen/`** — das ist Astro-Frontend, Aufgabe des anderen Chats
- **REST-Schema** ohne Astro-Anpassung (siehe oben)
- **Cloudflare-Settings** (Deploy-Hook etc.) — die sind über das WP-Settings-Panel an die richtige Stelle gemappt
- **`docs/vw-events-plugin-spec.md`** — solange du nicht eine echte Scope-Änderung dokumentieren willst (Spec ist der Vertrag)

Wenn du was davon brauchst → kurz im Astro-Chat Bescheid geben.

---

## Kontakt-Info

- Repo: https://github.com/stefangutermuth/vv-wildenstein
- WP-Master: https://vv-wildenstein.com
- Astro-Live: https://vv-wildenstein-gruenhainichen.stefan-0ea.workers.dev
- User: `mrmek` / stefan@gumu-agentur.de

Letzte Änderung dieses Handoffs: 30.04.2026
