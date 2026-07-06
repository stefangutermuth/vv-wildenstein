# Grünhainichen — Content-Inventar & Unterseiten-Plan

Stand: 29.06.2026 · Quelle: WordPress-API `https://vv-wildenstein.com/wp-json/wp/v2/`

Diese Datei listet alle **Inhalts-Typen** auf, die heute im WordPress-Backend
gepflegt werden, und ordnet ihnen geplante Astro-Routen für die neue
Grünhainichen-Seite zu.

---

## 1. Custom Post Types (CPTs)

| CPT-Slug                | Label              | Anzahl | REST-Endpoint               | Felder (zusätzlich zu Standard) |
| ----------------------- | ------------------ | -----: | --------------------------- | ------------------------------- |
| `verein`                | Vereine            |     38 | `/wp/v2/verein`             | `gemeindeteil`, `content`       |
| `profile`               | Profile (Firmen)   |     60 | `/wp/v2/profile`            | `gemeindeteil`, `profilkategorie`, `vv_kontakt` (Person, Adresse, Tel, Mail, Web) |
| `tourismus`             | Tourismus          |     25 | `/wp/v2/tourismus`          | `gemeindeteil`, `tourismus_kat`, `vv_kontakt`, `excerpt` |
| `personen`              | Personen           |     28 | `/wp/v2/personen`           | `gremien` (z. B. Gemeinderat) |
| `amter`                 | Ämter              |     14 | `/wp/v2/amter`              | `content` |
| `gemeinderatssitzung`   | Gemeinderatssitzungen | 4  | `/wp/v2/gemeinderatssitzung`| Datum als Titel, Tagesordnung im `content` |
| `amtsblatt_download`    | Amtsblätter        |     68 | `/wp/v2/amtsblatt_download` | `downloadkategorie`, PDF im `excerpt`/Anhang |
| `vw_event`              | Veranstaltungen    |   live | `/wp/v2/events-internal`    | (kommt aus vw-events Plugin) |
| `vw_meldung`            | Mängelmeldungen    |   live | `/wp/v2/meldungen-internal` | (separater Mängelmelder, eigene App) |
| `ausschreibungen`       | Ausschreibungen    |      0 | `/wp/v2/ausschreibungen`    | leer — vorerst ignorieren |

**Taxonomie `gemeindeteil`** (verknüpft viele CPTs):

| Slug             | Name           | Beiträge |
| ---------------- | -------------- | -------: |
| `gruenhainichen` | Grünhainichen  |      124 |
| `borstendorf`    | Borstendorf    |       87 |
| `waldkirchen`    | Waldkirchen    |       72 |
| `boernichen`     | Börnichen      |       40 |

→ Für die Grünhainichen-Seite filtern wir auf `gemeindeteil ∈ {gruenhainichen, borstendorf, waldkirchen}` (Börnichen ist eigenständig).

---

## 2. Standard-Beiträge nach Kategorien

Top-relevante Kategorien (Auszug, sortiert nach Anzahl):

| Slug                          | Name                       | Beiträge |
| ----------------------------- | -------------------------- | -------: |
| `allgemein`                   | Allgemein                  |      280 |
| `gemeinderat`                 | Gemeinderat                |       62 |
| `gruenhainichen`              | Grünhainichen              |       57 |
| `gemeinderat-gruenhainichen`  | Gemeinderat Grünhainichen  |       52 |
| `gemeinde`                    | Gemeinde                   |       30 |
| `verwaltungsverband`          | Verwaltungsverband         |       29 |
| `sperrung`                    | Sperrung (Verkehr)         |       20 |
| `kultur`                      | Kultur                     |       19 |
| `waldkirchen`                 | Waldkirchen                |       13 |
| `borstendorf`                 | Borstendorf                |       11 |
| `verkehr`                     | Verkehr                    |        9 |
| `verbandsversammlung`         | Verbandsversammlung        |        8 |
| `leben-in-gruenhainichen`     | Leben in Grünhainichen     |        5 |
| `bauleitplanung`              | Bauleitplanung             |        4 |
| `stellenanzeigen`             | Stellenanzeigen            |        2 |
| `wohnungsmarkt`               | Wohnungsmarkt              |        1 |

→ News auf der Grünhainichen-Seite = Beiträge mit Kategorie aus
`{allgemein, gemeinde, gruenhainichen, borstendorf, waldkirchen, kultur,
sperrung, verkehr, bauleitplanung, ...}` (nicht: börnichen-spezifisch,
nicht: verwaltungsverband-pur).

---

## 3. Geplante Astro-Routen

Aktuell existiert nur `apps/gruenhainichen/src/pages/index.astro`. Vorschlag:

### 3.1 Statische Seiten (Inhalte fest oder aus WP-Pages)

| Route                       | Inhalt / Quelle                                  | Status |
| --------------------------- | ------------------------------------------------ | ------ |
| `/`                         | Startseite (existiert)                           | ✅ |
| `/gemeinde/`                | Übersicht: Bürgermeister, Verwaltung, Kontakt    | offen |
| `/gemeinde/buergermeister/` | Profilseite                                      | offen |
| `/gemeinde/ortsteile/`      | Übersicht 3 Ortsteile                            | offen |
| `/gemeinde/ortsteile/gruenhainichen/` | Detail je Ortsteil                     | offen |
| `/gemeinde/ortsteile/borstendorf/` | …                                         | offen |
| `/gemeinde/ortsteile/waldkirchen/`  | …                                        | offen |
| `/impressum/`               | aus WP-Page                                      | offen |
| `/datenschutz/`             | aus WP-Page                                      | offen |
| `/barrierefreiheit/`        | aus WP-Page                                      | offen |

### 3.2 Aktuelles & Mitteilungen

| Route                            | Quelle                                    |
| -------------------------------- | ----------------------------------------- |
| `/aktuelles/`                    | Liste aller News (Kategorien-Filter oben) |
| `/aktuelles/[slug]/`             | Einzelner Beitrag                         |
| `/sperrungen/`                   | Kategorie `sperrung` + `verkehr`          |
| `/amtsblatt/`                    | CPT `amtsblatt_download` (Liste + Filter Jahr) |

### 3.3 Veranstaltungen

| Route                | Quelle                                    |
| -------------------- | ----------------------------------------- |
| `/veranstaltungen/`  | CPT `vw_event`, Filter (heute/Woche/Monat/Kat) |
| `/veranstaltungen/[slug]/` | Einzelevent                          |
| `/veranstaltungen/einreichen/` | Formular → WP                   |

### 3.4 Erleben & Entdecken (Tourismus)

| Route                    | Quelle                                       |
| ------------------------ | -------------------------------------------- |
| `/erleben/`              | Übersicht Tourismus + Kategorien             |
| `/erleben/[slug]/`       | Detail (`tourismus`)                         |
| `/erleben/kategorie/[kat]/` | Filter nach `tourismus_kat`               |

### 3.5 Vereine

| Route                    | Quelle                                |
| ------------------------ | ------------------------------------- |
| `/vereine/`              | Alle Vereine (Filter Ortsteil)        |
| `/vereine/[slug]/`       | Vereinsdetail                         |

### 3.6 Firmen & Gewerbe (Profile)

| Route                    | Quelle                                |
| ------------------------ | ------------------------------------- |
| `/gewerbe/`              | Liste (Filter Kategorie + Ortsteil)   |
| `/gewerbe/[slug]/`       | Detail mit `vv_kontakt`-Block         |

### 3.7 Politik & Verwaltung

| Route                    | Quelle                                |
| ------------------------ | ------------------------------------- |
| `/gemeinderat/`          | Übersicht + nächste Sitzung           |
| `/gemeinderat/mitglieder/` | CPT `personen` (Filter `gremien`)   |
| `/gemeinderat/sitzungen/`  | CPT `gemeinderatssitzung` (Liste)   |
| `/gemeinderat/sitzungen/[slug]/` | Tagesordnung + Protokoll     |
| `/aemter/`               | CPT `amter` (Liste)                   |
| `/aemter/[slug]/`        | Detail                                |

### 3.8 Service

| Route                   | Quelle                                   |
| ----------------------- | ---------------------------------------- |
| `/kontakt/`             | Formular + Anschrift                     |
| `/stellenanzeigen/`     | Kategorie `stellenanzeigen`              |
| `/wohnungsmarkt/`       | Kategorie `wohnungsmarkt`                |
| `/maengelmelder/`       | Link/Embed zur Mängelmelder-PWA          |
| `/suche/`               | Volltext (Astro Pagefind)                |

---

## 4. Was im CMS-Adapter noch fehlt

Aktuell in `src/lib/cms.ts` / `cms-wordpress.ts`:
- ✅ `getNews()`, `getEvents()`

**Zu ergänzen:**
- [ ] `getTourism({ kategorie?, ortsteil? })`
- [ ] `getVereine({ ortsteil? })`
- [ ] `getProfile({ kategorie?, ortsteil? })`  ← Gewerbe
- [ ] `getPersonen({ gremium? })`
- [ ] `getAemter()`
- [ ] `getGemeinderatssitzungen()`
- [ ] `getAmtsblaetter({ jahr? })`
- [ ] `getPostBySlug(slug)` / `getEventBySlug(slug)` / etc. für Detailseiten
- [ ] `getCategories()` für News-Filter

Alle nach gleichem Schema:
1. WP-Filter `?gemeindeteil=gruenhainichen,borstendorf,waldkirchen`
2. Basic-Auth nutzen (für `vv_kontakt`-Felder)
3. Featured-Media + Bilder über `_embed` mitliefern
4. PDF-Anhänge bei `amtsblatt_download` als `_links.wp:attachment`

---

## 5. Vorgehensplan (Empfehlung)

**Schritt 1 — Datenflüsse:** CMS-Adapter um die fehlenden Loader erweitern
(siehe §4), getypte Modelle, lokales Fallback. *Geschätzt: 1 Session.*

**Schritt 2 — Listen-Seiten zuerst:** `/aktuelles/`, `/veranstaltungen/`,
`/vereine/`, `/gewerbe/`, `/erleben/`, `/amtsblatt/` als reine
Listen-/Übersicht-Seiten. Layout-Vorlage in einer Komponente bündeln. *1 Session.*

**Schritt 3 — Detailseiten dynamisch:** `[slug].astro` für jeden CPT.
Pattern identisch zu Börnichens dynamischen CPT-Seiten (Vorlage in
`apps/boernichen/src/pages/`). *1 Session.*

**Schritt 4 — Politik-Bereich:** Gemeinderat, Sitzungen, Personen, Ämter —
separate Sub-Routen mit eigenem Visual. *1 Session.*

**Schritt 5 — Service-Seiten + Statisches:** Impressum, Datenschutz,
Barrierefreiheit, Kontakt-Formular, Suche. *0.5 Session.*

→ **Gesamt etwa 4–5 Arbeitssitzungen** bis alle Unterseiten leben.

---

## 6. Offene Entscheidungen

- **Suche:** Pagefind (statisch, schnell) vs. WP-Search-Endpoint (live)?
- **Detailseiten-Bilder:** Featured-Media oder Bilder im `content`?
- **Sprachen:** nur DE (kein i18n)?
- **Druck-PDFs:** Amtsblatt als direkter Download oder eigene Vorschauseite?
- **Kategorien-Mapping:** wollen wir die WP-Kategorien-Slugs 1:1 übernehmen
  oder kuratierte Cluster bauen (z. B. „Politik" = Gemeinderat ∪ Bauleitplanung
  ∪ Verbandsversammlung)?
