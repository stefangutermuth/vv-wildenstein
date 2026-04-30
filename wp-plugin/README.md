# vw-events — WordPress-Plugin

Source des selbstgeschriebenen Events-Plugins für `vv-wildenstein.com`.

Vollständige Spec: [`docs/vw-events-plugin-spec.md`](../docs/vw-events-plugin-spec.md).
Anbindung im Astro-Frontend + Setup-Historie: [`PROJECT_STATUS.md §4a`](../PROJECT_STATUS.md).

## Build / Release

ZIP für WP-Upload erzeugen:

```bash
cd wp-plugin
rm -f vw-events.zip
zip -rq vw-events.zip vw-events
```

Die ZIP ist `.gitignore`d — nur Source-Dateien sind versioniert.

## Installation in WordPress

1. ZIP über *Plugins → Hochladen → Datei auswählen* hochladen
2. Aktivieren (bei Multisite: **netzwerkweit aktivieren**)
3. Auf jeder Site unter *Veranstaltungen → Einstellungen* konfigurieren:
   - Admin-Benachrichtigungs-E-Mails
   - Cloudflare Turnstile Site-Key + Secret-Key
   - Master-Blog-ID (auf Subsites die ID des Master-Blogs)
   - Übersichtsseite + Einreichungs-Seite (URLs)
   - Cloudflare Deploy-Hooks pro Standort

## Wichtigste Endpunkte

- `GET /wp-json/vw-events/v1/events` — Event-Liste (Standort/Kategorie/From/To-Filter)
- `GET /wp-json/vw-events/v1/events/{id}` — Single-Event
- `GET /wp-json/vw-events/v1/ical?standort=…` — iCal-Feed
- `POST /wp-json/vw-events/v1/submissions` — Frontend-Submission (multipart/form-data + Turnstile)

## Shortcodes

- `[vw_events_list standort="…" past="false" limit="20"]` — Karten-Grid
- `[vw_events_upcoming count="3"]` — kompakte Vorschau (Plakat + Fakten)
- `[vw_event_submit]` — Submission-Form (auf Subsites: CTA zur Master-Submission-Seite)
