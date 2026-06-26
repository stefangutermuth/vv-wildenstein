# Bestandsaufnahme — bestehender Mängelmelder

> Aufgenommen am 26.06.2026 von der Live-Installation `melder.vv-wildenstein.com`
> (per SSH `vv-wildenstein` → `/www/htdocs/w01f6038/melder.vv-wildenstein.com/`).
> Diese Datei dokumentiert, **was vorhanden ist**, damit Inhalte + Inhaltstypen sauber
> ins zentrale WordPress (`vv-wildenstein.com`) übernommen und danach als Astro-PWA
> unter `melder2026.vv-wildenstein.com` neu gebaut werden können.

## 1. Was es ist

Eigenständige **WordPress-Installation** (DB `d041392e`, Prefix `BR3BkWVfW_`).
Frontend mit **Impreza**-Theme (WPBakery/`js_composer`), als **PWA** via *Super Progressive Web Apps*.
Eingaben laufen über **Formidable Forms (Pro)**; jede Meldung wird als Post vom Typ
`meldungen` gespeichert. Eine Karte zeigt alle Meldungen mit Status-Farben.

## 2. Datenmodell

### Post-Type: `meldungen` (public) — 28 Einträge
Registriert via **Custom Post Type UI**. Felder über **ACF Pro** (Feldgruppe „Meldungen", `group_62ab22155cbf7`):

| ACF-Feld | Key | Typ | Inhalt |
|---|---|---|---|
| Standort | `field_62ab2247f4ae5` / `location` | **ACF OpenStreetMap-Feld** | serialisiertes Array: `address, lat, lng, zoom, place_id, street_number, street_name, city, state, state_short, post_code, country, country_short` |
| Address | `field_659d7c696cd19` / `address` | Text | zusätzliches Adressfeld (meist leer) |
| Beitragsbild / featured image | `field_62ac749daeb50`, `field_62ab229b4c3e4` / `featured_image` | Bild | Foto des Mangels (auch `_thumbnail_id`) |
| Name des Nutzers | `field_62ab253230339` / `name_des_nutzers` | Text | Melder-Name |
| E-Mail des Einsenders | `field_62ab2547933b7` / `e-mail_des_einsenders` | E-Mail | Melder-Mail |
| Updatenachrichten aktivieren? | `field_62aba30c1dda0` / `allow_comments` | Bool | Melder will Status-Updates per Mail |
| Hinweis Intern | `field_62aba4a83c831` / `hinweis_intern` | Textarea | interne Bearbeitungsnotiz (nicht öffentlich) |

Zusätzliche Meta: `kategorie auswahl` (Term-ID der `angelegenheit`), `admin_form_source`
(Formidable-Formular-ID, z.B. `form63eca62282776`).

### Taxonomie: `angelegenheit` (Art des Mangels)
| Term | Slug | Meldungen |
|---|---|---|
| Straßen- u. Gehwege / öffentl. Plätze | `strassen-u-gehwege-oeffentl-plaetze` | 25 |
| Straßenbeleuchtung | `strassenbeleuchtung` | 19 |
| Müllablagerungen / Verschmutzung | `muellablagerungen-verschmutzung` | 10 |
| Grünflächen / Bäume | `gruenflaechen-baeume` | 5 |
| Wander- u. Radwege | `wander-u-radwege` | 2 |

### Taxonomie: `status_meldung` (Bearbeitungsstatus)
| Term | Slug | Anzahl | Icon |
|---|---|---|---|
| Neue Meldung | `neue-meldung` | 1 | `Neue_Meldung.png` |
| In Bearbeitung | `in-bearbeitung` | 23 | `in_bearbeitung.png` |
| Erledigt | `erledigt` | 3 | `erledigt.png` |

## 3. Eingabe-Workflow (Formidable)
- Frontend-Formular (Formidable Pro) erzeugt einen `meldungen`-Post (`admin_form_source`).
- Plugins im Umfeld: `formidable-geo`, `formidable-locations`, `formidable-acf`,
  `formidable-views`, `formidable-user-tracking`, `acf-frontend-form-element`,
  `acf-openstreetmap-field`.
- Benachrichtigungen: **BNFW** (Better Notifications for WP) + `draft-notify2`;
  Status-Updates an Melder, wenn „Updatenachrichten aktivieren?" gesetzt.
- `post-expirator` (Aktions-Workflows) für Ablauf/Archivierung.
- `zapier`-Plugin für externe Anbindungen.

## 4. Karte: eigenes Plugin `wu_vvmeldungen_map` (v0.1.3, Autor Tobias Wust)
- Shortcode `[wu_vvmeldungen_map]` → `WP_Query` über alle `meldungen`.
- Rendert pro Meldung ein `.marker`-`div` mit `data-lat/lng/status` + Titel/Status/Link.
- JS (`assets/wu_vvmeldungen_map.js`) baut daraus eine **Google-Maps-Karte**
  (`google.maps.Marker`, status-abhängiges Icon, InfoWindow, fitBounds).
- ⚠️ Eingabe über OSM-ACF-Feld, **Anzeige aber über Google Maps JS API** (API-Key nötig).
- Quellcode liegt als Referenz in diesem Ordner: `wu_vvmeldungen_map.php`, `.js`.

## 5. Sonstige aktive Plugins (Kontext)
SEO: `seo-by-rank-math(-pro)`. Bilder: `ewww-image-optimizer`, `host-webfonts-local`.
Analytics: `koko-analytics`. Backups: `updraftplus`. Export: `wp-all-export`.
Admin-Komfort: `erweiterung_admin_spalten`, `dp-intro-tours`, `check-email`,
`better-search-replace`, `classic-editor`. Theme-Stack: `us-core` + `js_composer` (Impreza).

## 6. Migration ins zentrale WP (`vv-wildenstein.com`) — Konsequenzen
- **Inhaltstypen nachbauen:** CPT `meldungen` + Taxonomien `angelegenheit`, `status_meldung`
  (analog `vw-events` als eigenes Plugin `vw-melder`, statt CPTUI/ACF-Abhängigkeit).
- **28 Meldungen + Medien migrieren** (Export via `wp-all-export` / WXR + Medien-Ordner).
- **REST freischalten:** ACF-Felder stehen aktuell auf `show_in_rest: 0` → für das
  Astro-Frontend müssen `meldungen` + Felder über REST lesbar sein (wie bei `vw-events`).
- **Karte:** Google Maps → bei OSM-Umstieg (Leaflet/MapLibre) kein API-Key/Tracking nötig,
  passt besser zur bestehenden DSGVO-freundlichen Linie (Open-Meteo etc.).
- **Eingabe:** Formidable-Formular → eigener REST-Submit-Endpoint im `vw-melder`-Plugin
  (analog `vw-events` Frontend-Submission + Turnstile-Spamschutz).
