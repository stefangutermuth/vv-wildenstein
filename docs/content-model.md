# Content Model

Datenschemas in Astro-Content-Collections (`src/content/config.ts`) sind so geschnitten, dass sie 1:1 als ACF-Feldgruppen in WordPress übersetzt werden können. Slug-Konvention: ACF-Field-Slug = Astro-Schema-Key (z. B. `start_date` → `startDate`-camelCase nur in TS).

## Custom Post Type `grh_news`

| Field          | Typ           | Pflicht | Beschreibung                                                           |
|----------------|---------------|---------|------------------------------------------------------------------------|
| `title`        | text          | ja      | Beitragstitel                                                          |
| `date`         | date          | ja      | Veröffentlichungsdatum                                                 |
| `category`     | select        | ja      | `verwaltung` · `veranstaltung` · `sperrung` · `tourismus`              |
| `ortsteil`     | select        | nein    | `borstendorf` · `gruenhainichen` · `waldkirchen` · `alle`              |
| `image`        | image         | nein    | Beitragsbild (Aspect 16:10)                                            |
| `excerpt`      | textarea      | ja      | Kurztext (max. 240 Zeichen) — wird in der Card angezeigt                |
| `featured`     | true_false    | nein    | Hervorhebung                                                            |

**Beispiel** in `src/content/news/sperrung-chemnitzer-strasse.md`.

## Custom Post Type `grh_event`

| Field         | Typ        | Pflicht | Beschreibung                                                          |
|---------------|------------|---------|-----------------------------------------------------------------------|
| `title`       | text       | ja      | Veranstaltungstitel                                                   |
| `start_date`  | date_time  | ja      | Beginn (Datum + Uhrzeit)                                              |
| `end_date`    | date_time  | nein    | Mehrtags-Events                                                       |
| `location`    | text       | ja      | Ort / Treffpunkt                                                      |
| `ortsteil`    | select     | nein    | wie news                                                              |
| `featured`    | true_false | nein    | Empfehlung (Badge "Empfehlung")                                       |
| `teaser`      | textarea   | ja      | 1–2 Sätze                                                             |
| `image`       | image      | nein    | Headerbild                                                            |
| `cta_url`     | url        | nein    | Link-Ziel des Buttons                                                 |
| `cta_label`   | text       | nein    | Button-Beschriftung (Default: "Weitere Informationen")               |

**Hinweis:** Falls die Gemeinde "The Events Calendar" nutzt, wird dieses CPT durch ein TEC-Template-Override ersetzt; das Mapping bleibt identisch.

## Custom Post Type `grh_ortsteil` (3 fixe Posts)

| Field         | Typ        | Pflicht | Beschreibung                                                          |
|---------------|------------|---------|-----------------------------------------------------------------------|
| `name`        | text       | ja      | Ortsteilname                                                          |
| `slug`        | select     | ja      | `borstendorf` · `gruenhainichen` · `waldkirchen` (genau 1 pro Wert)   |
| `tagline`     | text       | ja      | Eine Zeile, die den Charakter trifft                                  |
| `description` | textarea   | ja      | 2–3 Sätze für die Card                                                |
| `image`       | image      | nein    | Charakter-Foto                                                        |
| `order`       | number     | nein    | Sortierung in der Karten-Reihe (Default 0 = alphabetisch)             |

## Reusable Block / CPT `grh_featured`

Steuert den Hero-FeaturedSlot. Genau eine Instanz aktiv (Status-Flag `is_active`).

| Field      | Typ          | Beschreibung                                                |
|------------|--------------|-------------------------------------------------------------|
| `eyebrow`  | text         | Kleines Label (z. B. "EURORANDO 2026")                      |
| `title`    | text         | H2-Headline                                                 |
| `subtitle` | textarea     | 1–2 Sätze                                                   |
| `ctas`     | repeater     | je: `label`, `href`, `variant` (primary/secondary/ghost)    |
| `is_active`| true_false   | nur 1× aktiv                                                |
| `season_override` | select | optional: erzwingt eine Saison im Hero                      |

## ACF-Option-Page "Aktuelle Saison"

| Field             | Typ      | Werte                                                |
|-------------------|----------|------------------------------------------------------|
| `auto_season`     | true_false | wenn aktiv, wird Saison aus Datum berechnet         |
| `manual_season`   | select   | `spring` · `summer` · `autumn` · `winter` · `advent` |

## Beispiel-Datensätze

Siehe Verzeichnis `src/content/`:
- `news/sperrung-chemnitzer-strasse.md` (Sperrung)
- `news/gemeinderatssitzung-mai.md` (Verwaltung)
- `news/eurorando-anmeldung.md` (Tourismus, featured)
- `news/schachwanderweg-frischekur.md` (Tourismus, Borstendorf)
- `events/hexenfeuer-2026.md`
- `events/eurorando-23-09.md`
- `events/eurorando-27-09.md`
- `events/heimatfest-2026.md`
- `events/wendt-kuehn-schautag.md`
- `ortsteile/borstendorf.md`
- `ortsteile/gruenhainichen.md`
- `ortsteile/waldkirchen.md`
