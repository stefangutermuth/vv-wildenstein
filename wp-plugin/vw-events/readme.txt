=== Events im VV Wildenstein ===
Contributors: gumu
Tags: events, calendar, headless, rest-api, ical
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Headless-CMS-Backend für Veranstaltungen mit Multi-Site-Standort-Verteilung, REST-API, öffentlichem Frontend-Submission-Formular und Cloudflare-Build-Hooks.

== Description ==

VW Events stellt einen eigenen Custom Post Type `vw_event` mit Standort-Taxonomie (`vw_standort`) und Event-Kategorien (`vw_event_category`) bereit. Inhalte werden über den REST-Namespace `vw-events/v1` an Astro-Frontends ausgeliefert.

**Features (MVP)**

* CPT + Standort/Kategorie-Taxonomien mit Default-Terms
* REST: `GET /events`, `GET /events/{id}`, `POST /submissions`, `GET /ical`
* Öffentliches Submission-Formular via Shortcode `[vw_event_submit]`
* Bild-Upload (max. 10 MB), Cloudflare Turnstile, Honeypot, Rate-Limit
* E-Mails an Admin (neue Submission) und Einreicher (Bestätigung / Veröffentlichung / Ablehnung)
* Cloudflare-Deploy-Hook-Trigger pro Standort (mit 60s-Throttle)
* iCal-Feed mit RRULE für simple Wiederholungen

== Installation ==

1. Plugin nach `wp-content/plugins/vw-events/` kopieren
2. Aktivieren — CPT, Taxonomien und Default-Terms werden angelegt
3. Unter *Veranstaltungen → Einstellungen* Turnstile-Keys und Deploy-Hooks pro Standort eintragen
4. Shortcode `[vw_event_submit]` auf einer Seite einfügen

== REST-API ==

* `GET /wp-json/vw-events/v1/events?standort=gruenhainichen&from=2026-05-01&to=2026-12-31`
* `GET /wp-json/vw-events/v1/events/123`
* `POST /wp-json/vw-events/v1/submissions` (multipart/form-data)
* `GET /wp-json/vw-events/v1/ical?standort=gruenhainichen`

== Changelog ==

= 1.0.0 =
* Initial MVP-Release
