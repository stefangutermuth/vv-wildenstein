# `vw-events` — WordPress-Plugin-Spezifikation

> Selbstgeschriebenes Kalender-Plugin für die WP-Installation `vv-wildenstein.com` als Headless-CMS-Backend für mehrere Astro-Frontends (`gruenhainichen.com`, `vv-wildenstein.com`, `boernichen.de`, …).
>
> Diese Datei ist die **vollständige Anforderungsspezifikation**. Sie ist self-contained und kann ohne weiteren Kontext als Brief an einen Coding-Agenten übergeben werden.

---

## 1. Kontext & Ziel

Eine WordPress-Multi-Site-Architektur (Single Install) versorgt mehrere Astro-Frontends mit Inhalten. Events sollen einmal in WP gepflegt und über eine Standort-Taxonomie an die richtigen Frontends ausgeliefert werden. Das aktuell genutzte Plugin „Events Manager" (https://wp-events-plugin.com/features/) hat zu wenig REST-API und zu wenig Anpassungsspielraum. Wir bauen ein eigenes, schlankes, modernes Plugin.

**Was das Plugin liefern muss:**
1. Ein eigener Custom Post Type für Events mit allen redaktionellen Feldern
2. Standort-Taxonomie für Multi-Site-Verteilung
3. Saubere REST-Endpunkte für headless Astro-Frontends
4. Ein **öffentliches Frontend-Formular**, mit dem Gäste (auch ohne WP-Login) Events einreichen können — diese landen als „ausstehend" und müssen vom Admin freigegeben werden
5. **Bild-Upload bis 10 MB** durch Gäste
6. iCal-Feed pro Standort
7. Build-Webhook (Cloudflare) bei Veröffentlichung

**Was es ausdrücklich nicht braucht (Scope-Schutz):**
- Buchungssystem / Ticketing
- Bezahlung
- Mehrsprachigkeit
- Wiederholungen mit RRULE-Komplexität (siehe unten — wir machen es einfach)
- Geo-Maps (Adressen reichen als Freitext)
- Frontend-Calendar-View — wird das Astro-Frontend bauen

---

## 2. Technische Anforderungen

| Punkt | Wert |
|-------|------|
| WordPress | ≥ 6.4 |
| PHP | ≥ 8.1 |
| Plugin-Slug | `vw-events` |
| Text-Domain | `vw-events` |
| Plugin-Prefix (Funktionen, Hooks) | `vw_events_` |
| CPT-Slug | `vw_event` |
| Taxonomy-Slugs | `vw_standort`, `vw_event_category` |
| REST-Namespace | `vw-events/v1` |
| Externer Vendor-Code | nur falls nötig (Composer optional, aber Plugin sollte ohne Composer-Install in einer normalen WP-Umgebung laufen) |
| ACF-Abhängigkeit | **NEIN** — alle Felder selbst registrieren über `add_meta_box` + `register_post_meta` |

---

## 3. Datenmodell

### 3.1 Custom Post Type `vw_event`

```php
register_post_type('vw_event', [
  'labels'      => 'Veranstaltung' / 'Veranstaltungen',
  'public'      => true,
  'show_in_rest'=> true,                 // wir nutzen aber eigenen REST-Namespace, der angereicherte Felder liefert
  'rest_base'   => 'events-internal',    // Block-Editor-Anbindung; öffentliche API ist /vw-events/v1/events
  'supports'    => ['title', 'editor', 'thumbnail', 'author', 'revisions'],
  'has_archive' => true,
  'menu_icon'   => 'dashicons-calendar-alt',
  'menu_position' => 22,
  'capability_type' => 'post',
  'rewrite'     => ['slug' => 'veranstaltungen'],
]);
```

### 3.2 Taxonomien

**`vw_standort`** — multi-select, hierarchical = false, public = true

Begriffe (initial):
- `gruenhainichen`
- `borstendorf`
- `waldkirchen`
- `boernichen`
- `verband-weit` (= alle Standorte)

**`vw_event_category`** — multi-select, hierarchical = false

Begriffe (initial, redaktionell erweiterbar):
- `kultur`
- `sport`
- `kirche`
- `verein`
- `markt`
- `bildung`
- `sonstige`

### 3.3 Custom-Meta-Felder (alle als `register_post_meta`, `single` true außer Datum-Liste)

| Meta-Key                  | Typ         | Beschreibung |
|---------------------------|-------------|--------------|
| `_vw_event_start`         | string (ISO 8601, Local-Time) | Pflicht. Beispiel: `2026-05-30T19:00:00` |
| `_vw_event_end`           | string (ISO 8601, Local-Time) | Optional. Wenn leer → eintägiges/timepoint-Event |
| `_vw_event_all_day`       | boolean     | wenn true: Uhrzeit ignoriert, Datum-only |
| `_vw_event_repeat`        | string enum | `none` \| `daily` \| `weekly` \| `monthly` |
| `_vw_event_repeat_until`  | string (Date) | wenn `_vw_event_repeat ≠ none` |
| `_vw_event_location_name` | string      | optional, z.B. „Kulturhaus Borstendorf" |
| `_vw_event_location_addr` | string      | optional Mehrzeilen-Adresse |
| `_vw_event_organizer_name`| string      | optional |
| `_vw_event_organizer_email`| string     | optional, **NICHT öffentlich** (REST-Antwort verbirgt es) |
| `_vw_event_url`           | string (URL) | externer Veranstalter-Link |
| `_vw_event_submitter_name`| string      | wenn von Gast eingereicht |
| `_vw_event_submitter_email`| string     | für Bestätigungs-Mail an Einreicher; **nicht öffentlich** |
| `_vw_event_submission_ip` | string      | für Spam-Audit, **nicht öffentlich** |
| `_vw_event_source`        | string enum | `admin` \| `frontend_form` |

> Alle nicht-öffentlichen Felder müssen im REST-Endpoint **vor der Antwort gestrippt werden**. Tests müssen sicherstellen, dass z.B. `_vw_event_organizer_email` nicht im Response auftaucht.

---

## 4. REST-API (Namespace `vw-events/v1`)

### 4.1 `GET /events`

Listet veröffentlichte (publizierte) Events.

**Query-Parameter:**
| Param | Typ | Beschreibung |
|-------|-----|--------------|
| `standort` | string | Slug. Filtert auf Events mit diesem Standort ODER `verband-weit`. Mehrfachwerte komma-separiert. |
| `from` | ISO-Date | Nur Events mit start ≥ from |
| `to` | ISO-Date | Nur Events mit start ≤ to |
| `category` | string | wie `standort` |
| `per_page` | int | Default 20, Max 100 |
| `page` | int | 1-basiert |
| `_embed` | flag | bei vorhanden: bettet Featured-Image-URL ein |

**Response-Item-Format (JSON):**
```json
{
  "id": 123,
  "slug": "feuerwehrfest-borstendorf-2026",
  "title": "Feuerwehrfest in Borstendorf",
  "description_html": "<p>Lorem …</p>",
  "start": "2026-05-30T14:00:00+02:00",
  "end":   "2026-05-30T22:00:00+02:00",
  "all_day": false,
  "repeat": "none",
  "repeat_until": null,
  "location": {
    "name": "Festplatz Hauptstraße",
    "address": "Hauptstr. 12, 09579 Borstendorf"
  },
  "organizer": {
    "name": "Freiwillige Feuerwehr Borstendorf"
    // E-Mail NICHT enthalten
  },
  "url": "https://example.com/feuerwehrfest",
  "image": {
    "url": "https://vv-wildenstein.com/wp-content/uploads/...",
    "alt": "Plakat Feuerwehrfest"
  },
  "standort": ["borstendorf"],
  "category": ["verein"],
  "permalink": "https://vv-wildenstein.com/veranstaltungen/feuerwehrfest-…/"
}
```

**Response-Header:**
- `X-WP-Total: <gesamt>`
- `X-WP-TotalPages: <pages>`

### 4.2 `GET /events/{id}`

Single Event, gleiches Format wie Listen-Item.

### 4.3 `GET /ical?standort=…&category=…`

Liefert iCal-VCALENDAR-Stream (`text/calendar; charset=utf-8`) mit allen passenden zukünftigen Events. Erweiterung von Wiederholungen direkt in iCal (RRULE für simple Patterns):
- `_vw_event_repeat=weekly` → `RRULE:FREQ=WEEKLY;UNTIL=…`
- `_vw_event_repeat=daily`  → `FREQ=DAILY;UNTIL=…`
- `_vw_event_repeat=monthly`→ `FREQ=MONTHLY;UNTIL=…`

### 4.4 `POST /submissions` (öffentlich, zum Frontend-Formular gehörig)

Akzeptiert `multipart/form-data`:

| Feld | Pflicht | Notiz |
|------|---------|-------|
| `title` | ja | max 200 Zeichen |
| `description` | ja | max 8000 Zeichen, wird via `wp_kses_post` gefiltert (sicheres HTML) |
| `start` | ja | ISO-Datetime |
| `end` | nein | |
| `all_day` | nein | bool |
| `location_name` | nein | |
| `location_addr` | nein | |
| `organizer_name` | ja | |
| `organizer_email` | ja | wird validiert |
| `url` | nein | URL-validiert |
| `standort` | ja | Slug-Liste |
| `category` | nein | |
| `image` | nein | File, max 10 MB, jpeg/png/webp |
| `turnstile_token` | ja | wird serverseitig gegen Cloudflare Turnstile validiert (Endpoint: `https://challenges.cloudflare.com/turnstile/v0/siteverify`) |
| `honeypot` | (versteckt) | wenn ausgefüllt → silently ignorieren |
| `submitter_name` | ja | (wird in `_vw_event_submitter_name` gespeichert; oft identisch mit organizer_name) |
| `submitter_email` | ja | nur intern für Bestätigungs-Mail |

**Verarbeitung:**
1. Validieren (alle Pflicht-Felder, MIME-Check, Größen-Check, Turnstile)
2. Bild via `wp_handle_upload` ablegen, an den neuen Post anhängen, als Featured Image setzen
3. Post als `'post_status' => 'pending'` speichern (= „ausstehend zur Überprüfung")
4. Standort + Kategorie zuweisen
5. Custom-Meta speichern, inkl. `_vw_event_source = frontend_form` und `_vw_event_submission_ip`
6. Mail an Admin (siehe §6)
7. Mail an Einreicher (siehe §6)
8. Response: `{ ok: true, message: "Vielen Dank, dein Event wird geprüft." }` (KEIN Post-ID-Echo, um Enumeration zu verhindern)

**Response bei Fehlern:** HTTP 400 mit `{ ok: false, errors: { feldname: "Meldung", … } }`

**Rate-Limit:** max 5 Submissions pro IP pro Stunde (Transient-basiert).

---

## 5. Frontend-Formular

### 5.1 Bereitstellung

- **Shortcode:** `[vw_event_submit]` — kann auf jeder WP-Seite eingebettet werden
- **Gutenberg-Block:** `vw-events/submit-form` — gleiches Verhalten

### 5.2 Felder & UI

Reihenfolge im Formular:

1. **Titel** *
2. **Beschreibung** * (Textarea, 6 Zeilen, optional einfacher Rich-Editor wie quill.js — JS-Rendering, kein Block-Editor!)
3. **Datum & Uhrzeit Start** * (`<input type="datetime-local">`)
4. **Datum & Uhrzeit Ende** (`<input type="datetime-local">`, optional)
5. **Ganztägig** (Checkbox)
6. **Ort-Name** (z.B. „Kulturhaus Borstendorf")
7. **Adresse** (Mehrzeilig)
8. **Standort(e)** * (Multi-Checkbox für `vw_standort`)
9. **Kategorie** (Select)
10. **Veranstalter-Name** *
11. **Veranstalter-E-Mail** *
12. **Veranstalter-Website / Event-Link**
13. **Plakat / Bild** (`<input type="file" accept="image/jpeg,image/png,image/webp">`, Hinweis „max. 10 MB")
14. **Hinweis-Text:** „Dein Event wird vor Veröffentlichung von der Verwaltung geprüft."
15. **Cloudflare Turnstile Widget**
16. **Honeypot** (`<input name="website_url" style="display:none">`)
17. **Submit-Button**

### 5.3 Frontend-JS

- **Image preview** (Client-seitig): nach File-Auswahl Bild-Vorschau zeigen + Größe-Indikator
- **Client-side Resize** als Komfort: Bild auf max. 2400 px Kantenlänge per Canvas/createImageBitmap herunterskalieren, dann hochladen — dadurch klemmt's seltener am 10-MB-Limit
- **Soft validation**: vor Submit pro Feld inline-Fehler anzeigen
- **AJAX-Submit** an `POST /wp-json/vw-events/v1/submissions`
- Bei Erfolg: Formular ausblenden, Erfolgs-Message zeigen, Möglichkeit „Noch ein Event einreichen"
- Bei Fehler: Feld-Fehler darunter zeigen

### 5.4 Bild-Upload-Sicherheit

- **MIME-Check serverseitig** mit `wp_check_filetype_and_ext` (nicht nur MIME-Header trauen!)
- **Dimension-Check**: max. 8000×8000 px, sonst ablehnen (Decompression-Bombe)
- **EXIF-Stripping** beim Speichern (PII-Schutz)
- **Ablage** in `wp-content/uploads/vw-events-submissions/YYYY/MM/`
- Dateien werden nicht in Tax-Cloud / CDN repliziert solange Post `pending` ist

---

## 6. E-Mail-Benachrichtigungen

Alle Mails verwenden `wp_mail` mit HTML-Body. Vorlagen liegen unter `templates/email/*.php`.

### 6.1 Bei neuer Submission

- **An Admin** (E-Mail konfigurierbar in Settings, Default `get_option('admin_email')`):
  - Betreff: „Neues Event eingereicht: {Titel}"
  - Inhalt: Felder-Zusammenfassung + Link zum Edit-Screen
- **An Einreicher** (Submitter):
  - Betreff: „Vielen Dank — dein Event wird geprüft"
  - Inhalt: kurze Bestätigung, Eingabe-Daten, Hinweis auf manuelle Prüfung

### 6.2 Bei Freigabe (Admin setzt Status auf `publish`)

- **An Einreicher**:
  - Betreff: „Dein Event ist online: {Titel}"
  - Link zur veröffentlichten Seite

### 6.3 Bei Ablehnung (Admin setzt Status auf `trash` oder benutzt einen optionalen „Ablehnen mit Begründung"-Button)

- **An Einreicher**:
  - Betreff: „Hinweis zu deinem eingereichten Event"
  - Inhalt: optionale Begründung des Admins

---

## 7. Admin-Workflow

### 7.1 Edit-Screen für `vw_event`

- Standard-WP-Edit-Screen mit Title + Editor + Featured Image
- **Custom-Metabox „Veranstaltungs-Daten"** mit allen Datums-/Veranstalter-/Ort-Feldern (rechts oder hauptspaltig)
- Standort + Kategorie als Standard-Taxonomy-Boxen
- Bei `pending`-Status: Hinweis-Banner oben „Diese Veranstaltung wartet auf Freigabe. {Einreicher-Name} hat sie am {Datum} eingereicht."

### 7.2 Übersichtsliste

- Spalten: Titel · Start-Datum · Standort(e) · Status · Quelle (admin/frontend)
- Filter oben: nach Status („ausstehend"), Standort, Kategorie
- Bulk-Actions: „Veröffentlichen", „In Papierkorb"

### 7.3 Dashboard-Widget

Kleines Widget oben rechts auf dem WP-Dashboard: „N eingereichte Events warten auf Freigabe" mit Link zur Übersichtsseite. Nur wenn N > 0.

### 7.4 Settings-Seite (`Veranstaltungen → Einstellungen`)

- **Admin-Benachrichtigungs-E-Mail** (Default = Site-Admin)
- **Cloudflare Turnstile Site-Key + Secret-Key**
- **Cloudflare Deploy-Hooks** (Liste): Pro Standort eine URL (für Build-Webhook bei Save)
- **Standort-zu-Hook-Mapping** (Tabelle): „Standort `gruenhainichen` → Webhook-URL", etc.

---

## 8. Build-Webhooks (Cloudflare-Sync)

**Auslöser:** WP-Hook `transition_post_status`.

**Logik:**
- Wenn `$new_status === 'publish'` UND Post-Type `vw_event`:
  - Hole alle zugewiesenen `vw_standort`-Slugs des Posts
  - Für jeden Slug: schaue im Settings-Mapping, welche Cloudflare-Deploy-Hook-URL hinterlegt ist
  - Sende `POST` an alle gefundenen URLs (parallel via `wp_remote_post`, kein Wait)
- Bei `verband-weit` als Standort: triggere ALLE konfigurierten Hooks
- Optional: dasselbe bei `'publish' → '*'` Übergang (Update an publiziertem Event)

**Throttling:** Wenn mehrere Posts in 60s gespeichert werden, hooks per Cron-Queue zusammenfassen, sodass nicht 20 Builds in 5 Minuten laufen.

---

## 9. Sicherheit

- **Nonces** auf alle Admin-Actions
- **Capability-Checks**: `edit_posts` für Backend-Actions
- **Rate-Limiting** (siehe §4.4) auf `/submissions`
- **Cloudflare Turnstile** Pflicht
- **Honeypot** zusätzlich
- **MIME + Dimension + Größe** auf Upload
- **wp_kses_post** für alle freien Texte (Beschreibung)
- **sanitize_email**, **sanitize_text_field**, **esc_url_raw** für jeweilige Typen
- **Output-Escaping** in REST-Antwort: nutze `wp_kses_post` für `description_html`
- **Permalink-Vorhersagbarkeit**: Submitter-IP als Hash speichern, nicht im Klartext
- **Email-Spam-Schutz**: Mail-Versand asynchron via Action-Scheduler (sonst kann Submit aufgrund SMTP-Hang sehr langsam werden)

---

## 10. Datei-Struktur

```
vw-events/
├── vw-events.php                    # Plugin-Header, Init, Activation/Deactivation
├── includes/
│   ├── class-cpt.php                # CPT + Taxonomien registrieren
│   ├── class-meta.php               # register_post_meta, Sanitizer
│   ├── class-admin-ui.php           # Metaboxes, Listenspalten, Settings-Page, Dashboard-Widget
│   ├── class-rest-events.php        # GET /events, GET /events/{id}
│   ├── class-rest-ical.php          # GET /ical
│   ├── class-rest-submissions.php   # POST /submissions
│   ├── class-frontend-form.php      # Shortcode + Gutenberg-Block + JS-Enqueue
│   ├── class-mailer.php             # alle Mail-Vorlagen + Versand
│   ├── class-webhooks.php           # Cloudflare-Deploy-Hooks-Trigger
│   ├── class-turnstile.php          # Turnstile-Verifikation
│   └── helpers.php                  # gemeinsame Utility-Funktionen
├── templates/
│   ├── frontend/
│   │   ├── form.php                 # HTML-Template Submission-Form
│   │   └── form-success.php
│   └── email/
│       ├── admin-new-submission.php
│       ├── submitter-thanks.php
│       ├── submitter-published.php
│       └── submitter-rejected.php
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend-form.css
│   └── js/
│       ├── admin.js
│       └── frontend-form.js         # Validation, Bild-Resize, AJAX, Turnstile-Init
├── languages/
│   └── vw-events-de_DE.po           # deutsche Übersetzungen (Rest greift WP-Default)
└── readme.txt                       # WP-Plugin-Header-Standard
```

---

## 11. MVP-Scope vs. spätere Iterationen

### MVP (1. Release-Version)
- CPT + Taxonomien + Custom Fields ✓
- Admin-Metaboxen + Liste + Filter ✓
- REST: `/events`, `/events/{id}`, `/submissions`, `/ical`
- Frontend-Submission-Form als Shortcode
- Bild-Upload, Turnstile, Honeypot, Rate-Limit
- E-Mails: alle vier Typen
- Webhook-Auslöser bei Publish
- Settings-Page

### v1.1
- Gutenberg-Block für Form
- „Ablehnen mit Begründung" als One-Click-Action im Edit-Screen
- Action-Scheduler statt synchroner Mails

### v1.2
- Admin-Calendar-View (FullCalendar.js) als Übersicht
- Dublette-Erkennung beim Submit (gleicher Titel + gleicher Tag)

### v2 (zukünftig, optional)
- ICS-Import (Veranstalter:in lädt eigene .ics-Datei hoch)
- Frontend-User können Vorschläge bearbeiten (mit Magic-Link statt Account)

---

## 12. Akzeptanzkriterien

Plugin gilt als „MVP-fertig", wenn:

- [ ] Plugin-Aktivierung legt CPT + Taxonomien + Default-Terms an, ohne Fehler
- [ ] Admin kann Event mit allen Feldern anlegen, speichern, bearbeiten, veröffentlichen
- [ ] Frontend-Formular auf einer leeren WP-Seite via `[vw_event_submit]` funktional, akzeptiert Submission, sendet beide Mails
- [ ] Submitted Event taucht im Admin als „Ausstehend" auf, lässt sich publizieren, sendet dann Bestätigungs-Mail an Einreicher
- [ ] Cloudflare-Turnstile-Verifikation aktiv und funktional (Bot-Submissions werden abgewiesen)
- [ ] Bild-Upload bis 10 MB, kleiner Bilder funktioniert; größere werden mit Fehlermeldung abgelehnt
- [ ] `GET /wp-json/vw-events/v1/events?standort=gruenhainichen` liefert nur publizierte Events des Standorts in dokumentiertem JSON-Format
- [ ] `_vw_event_organizer_email`, `_vw_event_submitter_email`, `_vw_event_submission_ip` tauchen im REST-Response NICHT auf
- [ ] iCal-Feed lädt im Apple-Kalender und in Outlook ohne Warnung
- [ ] Webhook-Trigger bei Publish funktioniert (manueller Test mit `webhook.site` oder `requestbin`)
- [ ] Plugin lässt sich deaktivieren ohne Datenverlust; Reaktivierung stellt alle Funktionen wieder her
- [ ] PHP 8.1 + WP 6.4 ohne Notice/Warning (WP_DEBUG=true)

---

## 13. Hand-Off-Hinweise für die Umsetzung

1. **Erst die Datenstruktur** (CPT + Meta + Taxonomies + Migrate-Logik) — ohne diese funktioniert nichts anderes
2. **Dann der REST-Endpoint `/events`** — erlaubt parallel Astro-Frontend-Tests
3. **Dann das Admin-UI** — die Redaktion kann anfangen Inhalte zu pflegen
4. **Dann das Frontend-Formular** — kann auf Staging getestet werden
5. **Webhooks zuletzt** — sobald die Cloudflare-Deploy-Hooks bekannt sind
6. **iCal als finaler Polish**

**Wichtige Dependencies, die der Code-Agent klären muss vor Start:**
- Cloudflare Turnstile Site-Key / Secret-Key (zwei separate Werte) — werden in WP-Options gespeichert; Defaults leer = Form ist deaktiviert
- Liste der Cloudflare-Deploy-Hook-URLs pro Standort
- Admin-E-Mail für Submission-Benachrichtigungen
- Optional: Custom-Logo / Header für E-Mail-Templates

---

## 14. Out-of-Scope (klar ausschließen)

- Bezahlte Tickets / Paypal / Stripe
- Kalender-Sync mit externen Anbietern (Google Calendar, Outlook 365)
- Frontend-Calendar-View — wird im Astro-Frontend implementiert
- Automatische Übersetzung
- App / Mobile-Push
- Mehrere Bilder pro Event (genau eines reicht für MVP)
- Wiederkehrende Events mit Ausnahmen („jeden Mittwoch außer 24.12.")
