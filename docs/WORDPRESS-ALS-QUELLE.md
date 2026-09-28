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
| 28.09. | **Gesundheit** (`/leben/gesundheit/`) aus Profilen (Allgemeine Medizin, Zahnarzt, Physiotherapie, Apotheke), neu mit Sprechzeiten. Tierarzt Dr. Bauer (verstorben) entfernt; seine beiden Profile lagen schon im Papierkorb |
| 28.09. | **Einkaufen** (`/leben/einkaufen/`) aus Profilen. In WordPress 5 Unterkategorien von „Einkaufen“ angelegt (IDs 339 bis 343, Beschreibung = Unterzeile), alle 20 Geschäfte zugeordnet. Neue Geschäfte ohne Gruppe erscheinen unter „Weitere Geschäfte“. Symbole je Geschäft stehen im Code |
| 28.09. | Hilfsfunktion `getProfilUnterkategorien(slug)` |
| 28.09. | Hilfsfunktion `getProfileNachKategorie(slug)` in `apps/gruenhainichen/src/lib/cms-cpt.ts` |

## Offen · Grünhainichen (fester Text, obwohl es die Einträge in WordPress gibt)

| Seite | Umstellen auf |
|---|---|
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

## Datenpflege in WordPress

**Erledigt 28.09.2026 (von Stefan freigegeben, per WP-CLI):**
- Alle 60 aktiven Profile haben einen Ortsteil (vorher fehlte er bei 54). Floßmühle zählt zu Borstendorf. Grundschule: Grünhainichen (Sitz) und Börnichen (Schüler).
- Physiotherapie Holler zusammengeführt: #41758 bleibt (Kategorie Physiotherapie), „Ines Holler“ #41786 im Papierkorb.
- Neues Profil „Ev.-Luth. Kirchgemeinde Waldkirchen“ (#48603, Foto St. Georg). Kirchenchor #41411 nicht mehr unter „Kirchen“, PLZ korrigiert.
- Eismühle im Flöhatal: Einkaufen › Essen & Trinken.
- Adressen ergänzt: Kirche Borstendorf „An der Kirche, Borstendorf“; Rolle Mühle „Zschopenthal 15, OT Waldkirchen“.
- Dr.-Ing. Jörg Walther (#41727): Titel korrigiert, Beschreibung „Ingenieur für Bauplanung und Sachverständiger“ (Auskunft Stefan).
- **Falle:** `wp post update <id>` ohne Feld schlägt fehl und löst keinen Neubau aus; immer mit `--post_modified=…` aufrufen.
- **Falle:** Bei neu per WP-CLI angelegten Profilen auch die ACF-Verweise `_feldname` = `field_…` setzen (von einem bestehenden Profil übernehmen), sonst zeigt der Editor die Felder leer.
- Namen: Zahnarztpraxis Anke Nüßler, Ev.-Luth. Kirche Borstendorf, Ev.-Luth. Kirchgemeinde Börnichen, Frühere Grundschule Waldkirchen, Metallbau Fuhrmann Borstendorf.

**Offen, Stefan klärt mit dem Verband:**
- Ohne Kontaktdaten: Heizung Sanitär Hänel, Gebäudereinigung Knoch, Zimmerei Grämer, Hörgeräte-Akustik Rochhausen. Gibt es sie noch?
- Kirche Borstendorf: Text fehlt (Adresse „An der Kirche“ seit 28.09. eingetragen, keine Hausnummer).
- Kirchenchor und Feuerwehr Börnichen haben keine Profilkategorie (eigentlich Vereine).
- Hort „Waldis Kids“ steht doppelt: eigenes Profil und Zusatzfeld im Schulprofil.
- „Museum Erzgebirgische Volkskunst“ doppelt im Tourismus.
- **Falle:** Kategorie- und Ortsteil-Zuordnungen per WP-CLI ändern das Änderungsdatum nicht; danach `post_modified` setzen, sonst baut der Build aus dem Zwischenspeicher.

## Offen · Zuverlässigkeit der Auslieferung

1. Neubau aus WordPress immer **ohne Zwischenspeicher** (heute können gelöschte oder auf Entwurf gesetzte Einträge bis zu 6 h sichtbar bleiben).
2. Drossel im Webhook umdrehen: **nach 90 s Ruhe bauen** statt beim ersten Speichern (heute können Korrekturen innerhalb von 90 s bis zum nächsten Morgen hängen).
3. Auch **Änderungen in der Mediathek** (Bild tauschen, Bildunterschrift) lösen einen Neubau aus.

## Bilder: zu kleine Uploads

Die Websites laden immer das größte verfügbare Bild. Von 198 Originalen auf den
Hauptseiten sind aber **84 unter 800 px breit** (Stand 28.09.2026), vor allem
Wendt-&-Kühn-Termine mit 350 px, Plakate als Bildschirmfoto, Logos.

- 28.09.: Mediathek per Bildabdruck (dHash, PHP/Imagick auf dem Server, Skript
  `/tmp/bildabdruck.php`) nach größeren inhaltsgleichen Fassungen durchsucht und jeden
  Treffer von Hand geprüft. 4 echte Treffer getauscht, 22 Einträge umgestellt
  (Fachwerkhaus W&K 350 › 1000 px, Kinder-Führungen 350 › 940, Museum 472 › 1200,
  Baustellenschild 400 › 996). Ähnliche, aber andere Motive (anderes Jahr, anderer
  Geburtstag, anderer Flyer) bewusst nicht getauscht.
- Offen: **Darstellungsschutz** auf der Website (kleine Bilder nicht auf volle Breite
  aufblasen, sondern in echter Größe auf ruhiger Fläche) und Hinweis an den Verband
  bzw. im Einreichformular: Bilder ab 1200 px, Plakate als Originaldatei.

## Reihenfolge, wie mit Stefan besprochen

Klein anfangen, Seite für Seite: Kirchen und Grundschule sind erledigt. Nächste Kandidaten:
Gewerbe auf echte Firmen beschränken, Zuverlässigkeit der Auslieferung, danach Datenpflege Ortsteile, dann Börnichen.
