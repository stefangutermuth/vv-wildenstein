# WordPress als einzige Quelle · Plan und Stand

> Stand: 28.09.2026. Ziel von Stefan: **Alles wird in WordPress (vv-wildenstein.com)
> gepflegt**, in den Inhaltstypen und ihren Feldern, und von dort auf allen Seiten
> ausgespielt, auf denen es relevant ist: Grünhainichen, Börnichen, Verband. Auch Bilder.
> Im Code der Websites bleiben nur Gestaltung und Beschriftungen („Kontakt“, „Telefon“).

## Wie Änderungen durchlaufen

1. Speichern in WordPress › mu-Plugin `vv-deploy-webhook.php` schickt `repository_dispatch`
2. GitHub Actions baut Grünhainichen, Börnichen (Workflow `deploy-allinkl.yml`) und den Verband neu
3. Nach 2 bis 5 Minuten live. Zusätzlich jede Nacht ein kompletter Neubau.

**Schreibzugang für Claude:** SSH-Alias `vv-wildenstein`, WP-CLI unter `/usr/bin/wp`,
WordPress unter `/www/htdocs/w01f6038/vv-wildenstein.com`. Nach Änderungen per WP-CLI
das Änderungsdatum setzen (`wp post update <id> --post_modified=…`), sonst kein Neubau
und der Build-Zwischenspeicher bleibt alt. Das REST-Anwendungspasswort in
`apps/gruenhainichen/.env.local` gibt 401 und wird nicht gebraucht.

## Erledigt

| Datum | Was |
|---|---|
| 28.09. | **Kirchen** (`/leben/kirche/`) lesen Profile der Kategorie „Kirchen“, inkl. Börnichen |
| 28.09. | **Grundschule** (`/leben/grundschule/`) liest Titel, Textauszug, Bild, Text, Kontakt, Hort aus dem Schulprofil; Förderverein aus dem Vereinsprofil; Rückblick aus dem Profil der früheren Schule Waldkirchen |
| 28.09. | mu-Plugin `vv-profil-auszug.php`: Feld **Textauszug** für Profile (Kurzbeschreibung) |
| 28.09. | Bildunterschrift der Mediathek erscheint als Bildnachweis (Pflicht bei Commons-Fotos) |
| 28.09. | Profilbilder in mindestens 600 px Breite (vorher 225 px bei Hochformaten) |
| 28.09. | In WordPress: Fotos für Kirche Borstendorf (Commons, Miebner, CC BY-SA 3.0) und Waldkirchen (eigenes Foto St. Georg); Schulprofil umbenannt; Förderverein-Text ergänzt |
| 28.09. | **Menü › Nächste Termine** (Desktop und Handy) aus dem WordPress-Kalender statt altem Import; toter Feuertheater-Link weg |
| 28.09. | Detailseiten der Profile: Brotkrume und Zurück-Link nach Kategorie (Kirche › „Leben · Kirche“) statt immer „Gewerbe“ |
| 28.09. | Hilfsfunktion `getProfileNachKategorie(slug)` in `apps/gruenhainichen/src/lib/cms-cpt.ts` |

## Offen · Grünhainichen (fester Text, obwohl es die Einträge in WordPress gibt)

| Seite | Umstellen auf |
|---|---|
| Leben › Einkaufen | Profile „Einkaufen“ (20) |
| Leben › Gesundheit | Profile „Allgemeine Medizin“, „Zahnarzt“, „Physiotherapie“, „Apotheke“ (6); Tierarzt Dr. Bauer und „in Balance“ fehlen als Profil |
| Leben › Kirche | Einleitung (Jahreszahlen 1539/1900) und Schlusssatz noch fest |
| Gemeinde › Verwaltung | Ämter (14) + Personen |
| Gemeinde › Bürgermeister | Personen, Gremium „Bürgermeister“ (Robert Arnold) |
| Ortsteil Borstendorf | Ortsvorsteherin aus Personen |
| Gewerbe | zeigt alle Profile, auch Kitas, Kirchen, Seniorentreffs; auf echte Gewerbe-Kategorien beschränken. Kirchen-Detailseiten liegen dadurch unter /gewerbe/ |
| Feuerwehren, Heiraten | in WordPress nur als normale Seiten, kein Inhaltstyp |

## Offen · Börnichen

- **Gewerbe** und **Vereine** sind Platzhalter („wird gerade aufgebaut“). Vereine mit Ortsteil Börnichen gibt es in WordPress keine.
- **Tourismus** zeigt vier feste Kacheln; dieselben vier Einträge gibt es in WordPress.
- **Verwaltung** fest im Code (Martin Trinks und Ämter gibt es in WordPress).

## Offen · Datenpflege in WordPress (Verband oder Claude per WP-CLI, vorher Liste zur Freigabe)

- **54 von 60 Profilen ohne Ortsteil** (`gemeindeteil`). Ohne Zuordnung behandelt Grünhainichen sie als „gilt überall“, Börnichen kann nicht filtern.
- Kirchgemeinde Waldkirchen hat kein eigenes Profil, nur „Kirchenchor …“ (PLZ dort falsch: 09437 statt 09579).
- Kirchgemeinde Grünhainichen: Fax nur im Fließtext; Titel „Evangelisch-Lutherischen Kirchgemeinde“ grammatisch schief.
- „Museum Erzgebirgische Volkskunst“ doppelt im Tourismus; Seniorentreffs mit kopierten Adressen (…-borstendorf-2-2).
- Hort „Waldis Kids“ steht doppelt: als eigenes Profil und im Zusatzfeld des Schulprofils.

## Offen · Zuverlässigkeit der Auslieferung

1. Neubau aus WordPress immer **ohne Zwischenspeicher** (heute können gelöschte oder auf Entwurf gesetzte Einträge bis zu 6 h sichtbar bleiben).
2. Drossel im Webhook umdrehen: **nach 90 s Ruhe bauen** statt beim ersten Speichern (heute können Korrekturen innerhalb von 90 s bis zum nächsten Morgen hängen).
3. Auch **Änderungen in der Mediathek** (Bild tauschen, Bildunterschrift) lösen einen Neubau aus.

## Reihenfolge, wie mit Stefan besprochen

Klein anfangen, Seite für Seite: Kirchen und Grundschule sind erledigt. Nächste Kandidaten:
Einkaufen und Gesundheit, Gewerbe auf echte Firmen beschränken, Zuverlässigkeit der Auslieferung, danach Datenpflege Ortsteile, dann Börnichen.
