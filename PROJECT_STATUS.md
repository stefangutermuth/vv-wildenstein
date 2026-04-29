# Project Status — VV-Wildenstein Web-Monorepo

> **Letztes Update:** 29. April 2026
> **Aktuelle Phase:** Phase 1 — Astro-Master live deployed · Phase 2 (Headless WordPress) noch ausstehend

Diese Datei dokumentiert den Stand, alle getroffenen Entscheidungen, Zugänge (ohne Geheimnisse) und die offenen Aufgaben — damit die Arbeit nahtlos weitergehen kann, auch wenn es eine Pause gibt oder die Konversation neu gestartet wird.

---

## 1. Live-URLs

| Was                          | URL                                                                       | Status        |
|------------------------------|---------------------------------------------------------------------------|---------------|
| Production-Site Grünhainichen| https://vv-wildenstein-gruenhainichen.stefan-0ea.workers.dev               | **live** ✓    |
| GitHub-Repo                  | https://github.com/stefangutermuth/vv-wildenstein                          | live          |
| Cloudflare-Projekt           | `vv-wildenstein-gruenhainichen` im Account `f0ea2c6b50485053bbacc2c5963a6eb6` | live   |
| Lokaler Dev-Server           | http://localhost:4327 (Port frei wählbar)                                  | nach `npm run dev` |
| Spätere Production-Domain    | gruenhainichen.com (DNS-Setup steht aus)                                   | offen         |

---

## 2. Architektur — der große Plan

```
                     ┌──────────────────────────────────┐
                     │  WordPress (vv-wildenstein.com)  │
                     │  — eine Installation —            │
                     │  Custom Post Types, REST/GraphQL │
                     │  Standort-Taxonomie filtert      │
                     └─────────────┬────────────────────┘
                                   │ API-Pull beim Build
                ┌──────────────────┼──────────────────────────┐
                ▼                  ▼                          ▼
     ┌────────────────────┐ ┌──────────────────┐ ┌──────────────────────┐
     │ Astro-Frontend     │ │ Astro-Frontend   │ │ Astro/PWA-Frontend   │
     │ verband            │ │ gruenhainichen ★ │ │ maengelmelder        │
     │ vv-wildenstein.com │ │ gruenhainichen.com│ │ maengelmelder.…      │
     └────────────────────┘ └──────────────────┘ └──────────────────────┘
                ▲                  ▲                          ▲
                │ jeder mit eigener Cloudflare-Pages-URL und eigener Domain
                │ ★ = aktuell live, restliche Apps in Phase 3 geplant
```

**Begründung Single-WP statt Multisite** (einmal entschieden, gilt für später):
- Eine Login-Maske, ein Admin, ein Backup
- Inhalte werden mit einer **Standort-Taxonomie** etikettiert (`verband`, `gruenhainichen`, `boernichen`, `maengelmelder`)
- Frontend filtert via `?standort=…`
- Mängelmelder = ein weiterer CPT auf demselben Backend

---

## 3. Repo-Struktur

```
vv-wildenstein/                    ← npm-Workspace-Root
├── apps/
│   └── gruenhainichen/             ← live in Cloudflare
│       ├── src/                    ← Astro-Code
│       ├── public/images/          ← Foto-Bibliothek
│       ├── astro.config.mjs
│       ├── tsconfig.json
│       └── package.json            ← Name: @vv/gruenhainichen
├── packages/                       ← (geplant) Design-System extrahieren
├── docs/                           ← Briefings, Spec-Sheets, Token-Export
│   ├── tokens.json
│   ├── component-mapping.md
│   ├── animation-recipes.md
│   ├── content-model.md
│   └── figma-export-guide.md
├── wrangler.jsonc                  ← Cloudflare-Asset-Config
├── package.json                    ← Workspace-Root
├── .gitignore
├── README.md                       ← Phasen-Doku, Setup-Hinweise
└── PROJECT_STATUS.md               ← diese Datei
```

**Workspace-Befehle (an der Repo-Wurzel):**
```bash
npm install                        # einmal
npm run dev                        # Dev-Server für Grünhainichen
npm run build:gruenhainichen       # Production-Build
```

---

## 4. Design-System (eingefroren in Phase 1)

### Tokens (alle in `apps/gruenhainichen/src/styles/tokens.css`)

- **Token-Prefix:** `--grh-*` (Single Source of Truth)
- **Klassen-Prefix:** `grh-` (BEM)
- **Farbpalette:** Forest-Grün (8 Stufen), Wood-Brown, Sandstone, Candle-Gold, Cream-Neutrals
- **Typografie:** Fraunces (Display) + Inter (Body), beide selfhosted via `@fontsource-variable`
- **Spacing-Scale:** 4-pt-Basis, Tokens `space-1` bis `space-10`
- **Radii / Schatten / Motion:** alle als Tokens

### Komponenten (alle in `apps/gruenhainichen/src/components/`)

| Bereich   | Komponenten                                                              |
|-----------|---------------------------------------------------------------------------|
| Global    | GrhHeaderCompact, GrhMegaMenu, GrhFooter, GrhWappen, GrhLoader            |
| Primitives| GrhButton, GrhBadge, GrhIcon                                              |
| Hero      | GrhHero (Slider mit Particle-Cursor), GrhFeaturedSlot                     |
| Cards     | GrhNewsCard, GrhEventCard, GrhOrtsteilCard                                |
| Sections  | GrhSection, GrhDualPortal, GrhTraditionShowcase, GrhOrtsteilSpread, GrhTourismGrid (mit Drag-Cursor), GrhEventsList |

### Highlights, die im Design-System gemerkt werden müssen

- **Hero-Slider** mit 5 Slides, Crossfade, Ken-Burns-Zoom, Particle-Layer mit Maus-Repulsion (alle Richtungen, weicher Push)
- **Loader** — beim ersten Laden der Startseite zufällig eine von 3 Animationen (Wasserrad, Schach-Springer, Engel mit 11 Punkten) — repräsentiert je einen Ortsteil
- **Stacking-Cards** für Sektionen 02–05 (Ortsteile, Wendt&Kühn, Schachdorf, Blaufarbenwerk) mit progressivem Fächer-Layout
- **Drag-Cursor** im Tourismus-Streifen (Custom GPU-Cursor via `transform: translate3d`)
- **Hover-Reveal** in Tourismus-Tiles (Salient-Style)
- **Mega-Menü** mit dunklem 1/3-Block (Live-News/Events/Sperrungen) + 2/3 kompakter Multi-Column-Navigation + Editorial-Quote-Band
- **Pulsierender Burger-Button** (sehr sanftes Atmen, Gold-Glow)
- **Goldener Progress-Strich** unter Tourismus-Streifen
- **Saison-Schaltung** (`src/lib/seasons.ts`): aus dem aktuellen Datum wird Frühling/Sommer/Herbst/Winter/Advent abgeleitet, schaltet Hero-Inhalt + Featured-Slot

### Komplette Sektions-Liste der Startseite

| Nr. | Eyebrow                     | Layout                                          |
|-----|-----------------------------|-------------------------------------------------|
| —   | Hero                        | Split + Slider + Particles + Pin                |
| —   | Schnelleinstieg             | Doppelportal (Bürger + Gast)                    |
| 01  | Worum es hier geht          | Editorial-Manifest                              |
| 02  | Drei Ortsteile              | **Stacking-Card** (im Stack mit 03–05)         |
| 03  | Holzkunst seit 1915         | **Stacking-Card** (Wendt & Kühn)                |
| 04  | Schachdorf seit 1871        | **Stacking-Card** (Borstendorf)                 |
| 05  | Waldkirchen seit 1687       | **Stacking-Card** (Blaufarbenwerk Zschopenthal) |
| 06  | Erleben & Entdecken         | Drag-Cursor-Streifen mit 6 Tiles                |
| 07  | Kalender                    | Tagesveranstaltungen (Plakat-Grid) + Mehrtägig (Editorial-Spread) |
| 08  | Aktuelles                   | Feature-News + Liste + Sperrungs-Sidebar        |
| 09  | Mittendrin                  | Stats-Reihe + 6-Tile-Service-Grid + 2 CTAs      |
| 10  | Wirtschaftsstandort         | Forest-Sektion mit italic Claim                 |
| 11  | Rathaus                     | 4-Spalten-Kontaktblock                          |

---

## 5. Inhalte — wo sie gerade leben

**Phase 1 (jetzt):** Astro Content Collections in Markdown
- News: `apps/gruenhainichen/src/content/news/*.md`
- Events: `apps/gruenhainichen/src/content/events/*.md`
- Ortsteile: `apps/gruenhainichen/src/content/ortsteile/*.md`

Schemas in `apps/gruenhainichen/src/content/config.ts`. Bilder in `public/images/`.

**Phase 2 (kommt):** WordPress als Quelle. Schemas spiegeln 1:1 in ACF-Felder. Mapping ist bereits in `docs/content-model.md` dokumentiert.

---

## 6. Hosting & Deployment

### Cloudflare-Account

- **Eigentümer:** Stefan, E-Mail: `stefan@gumu-agentur.de` (umgestellt von `Stefan@gutermuth.media` und verifiziert am 29.04.2026)
- **Account-ID:** `f0ea2c6b50485053bbacc2c5963a6eb6`
- **Login:** dash.cloudflare.com
- **Plan:** Free (für die Größenordnung mehr als ausreichend)

### Workers Builds Setup für Grünhainichen

- **Projekt:** `vv-wildenstein-gruenhainichen`
- **Repo:** stefangutermuth/vv-wildenstein, Branch `main`
- **Build-Befehl:** `npm run build:gruenhainichen`
- **Bereitstellungsbefehl:** `npx wrangler deploy`
- **Asset-Verzeichnis** (in `wrangler.jsonc`): `./apps/gruenhainichen/dist`
- **Auto-Deploy:** bei jedem Push auf `main`
- **Preview-Deploys:** automatisch pro Branch

### GitHub-Setup

- **Owner:** stefangutermuth
- **Repo:** vv-wildenstein (privat möglich, aktuell vermutlich öffentlich)
- **Default-Branch:** main
- **Aktiver PAT:** „vv-wildenstein deploy" (Fine-grained), Ablauf **29. Mai 2026**
  - Nur Repo `vv-wildenstein`, nur `Contents: Read and write` + `Metadata: Read-only`
- **Lokales Credential-Helper:** `git config credential.helper osxkeychain` (macOS-Schlüsselbund)

### Empfehlung Token-Renewal

- Vor 29. Mai 2026: Entweder neuen Fine-grained Token erstellen, **oder** auf `gh auth login` umsteigen (komfortabler, kein Token-Management mehr nötig)

---

## 7. Erledigte Aufgaben (Chronologie)

### Phase 1 — Erstwurf (abgeschlossen)
- ✅ Astro-Projekt scaffolded
- ✅ Token-System komplett (`tokens.css`)
- ✅ Reset / Typo / Utilities / Animations
- ✅ Content Collections + Sample-Daten (News, Events, Ortsteile)
- ✅ Custom-Icon-Set (Lucide-Stil + regional: angel11, chess-knight, schwibbogen, miner, fir, bridge)
- ✅ Wappen-Komponente (Triptychon-Bild der drei Ortsteile)
- ✅ Header (klassisch + compact mit Wetter-Pille, Social-Icons, pulsierender Burger)
- ✅ Footer mit Wappen, Adresse, Topo-Linien-Watermark
- ✅ Hero — vom Schwibbogen-Konzept zum aktuellen Slider-Hero refactored
- ✅ Hero-Slider mit 5 Slides + Particles (alle Richtungen, Maus-Repulsion)
- ✅ Drei Ortsteile als Triptychon-Cards
- ✅ Tradition-Showcases (Wendt & Kühn, Schachdorf, Blaufarbenwerk)
- ✅ Tourismus-Streifen mit Bild-Tiles + Drag-Cursor + Hover-Reveal
- ✅ Editorial Events-Sektion (Tages + Mehrtägig getrennt mit Plakaten)
- ✅ News-Sektion (Feature + Liste + Sperrungs-Sidebar)
- ✅ Mittendrin-Sektion (Stats + Service-Grid)
- ✅ Mega-Menü mit 1/3 dark + 2/3 kompakte Navigation + Quote-Card-Band
- ✅ Loading-Animation (3 Varianten zufällig: Wasserrad, Schach, Engel)
- ✅ Stacking-Cards für Sektionen 02–05 mit progressivem Fächer
- ✅ Bilder von der Original-Gemeinde-Site gezogen und integriert (Wappen, Floha-Foto, Schach, Wendt-Kühn, Blaufarbenwerk, Hexenfeuer, Wandern, Hiehnelmacherhaus, Fuchsturm)

### Infrastruktur (abgeschlossen)
- ✅ Monorepo-Restrukturierung (`apps/gruenhainichen/`, npm-Workspaces)
- ✅ Git-Repo bei GitHub initialisiert + erstpush
- ✅ Cloudflare-Account-E-Mail auf `stefan@gumu-agentur.de` umgestellt
- ✅ Cloudflare-Projekt verbunden + erstes Deployment live

---

## 8. Offene Aufgaben

### Bald (vor dem nächsten echten Schritt)

- [ ] **Eigene Domain anbinden** (gruenhainichen.com): DNS-CNAME bei Provider setzen → Cloudflare-Projekt → Domains → Custom Domain hinzufügen → HTTPS automatisch
- [ ] **Klären:** soll die `vv-wildenstein.workers.dev`-URL Production sein (so lange) oder direkt auf `gruenhainichen.com`?
- [ ] **`/wetter`-Route** anlegen (aktuell zeigt der Header-Wetter-Link auf eine 404)
- [ ] **Restliche Unterseiten** als Astro-Routen anlegen (`/gemeinde/buergermeister`, `/tourismus/wandern` etc.) — derzeit zeigen alle Menülinks auf 404
- [ ] **Original-Wappen-SVG** in Vektorform anfragen (statt PNG) — bessere Skalierbarkeit
- [ ] **Weitere echte Fotos** sammeln, besonders für News-Beiträge (aktuell Gradient-Platzhalter)

### Phase 2 — Headless WordPress

- [ ] **WordPress-Hosting** auswählen (All-Inkl, IONOS, Strato, Mittwald — alle managed-WP-tauglich)
- [ ] **WP-Installation** auf `vv-wildenstein.com/wp-admin` oder einer Sub-Domain wie `cms.vv-wildenstein.com`
- [ ] **Plugins:**
  - [ ] ACF Pro (Custom Fields)
  - [ ] CPT UI (oder direkt ACF)
  - [ ] Post Types: `news`, `event`, `ortsteil`, `meldung`, `featured`
  - [ ] Custom Taxonomy „Standort" mit Terms `verband`, `gruenhainichen`, `boernichen`, `maengelmelder`
- [ ] **REST-API absichern**: nur Lesezugriff für Anonyme, Schreibzugriff nur für authentifizierte Apps (Mängelmelder)
- [ ] **Astro-Content-Collections umstellen**: `getCollection('news')` → `fetch(WP_API + '/news?standort=gruenhainichen')`
- [ ] **Build-Webhook** im WP einrichten: bei jedem Save auslösender Trigger an Cloudflare → Astro-Rebuild
- [ ] **Redaktions-Schulung**: 1–2 Stunden Onboarding für die Verwaltungs-Mitarbeiter

### Phase 3 — Multi-Site-Ausbau

- [ ] **Design-System extrahieren** in `packages/design-system/`
- [ ] **`apps/verband/`** anlegen (vv-wildenstein.com), eigenes Wappen, eigene Foto-Bibliothek
- [ ] **`apps/boernichen/`** anlegen, eigenes Wappen, Foto-Bibliothek
- [ ] **`apps/maengelmelder/`** als PWA mit Schreibzugriff zur WP-API (Foto-Upload, Standort, Kategorie)
- [ ] **Cloudflare-Projekte pro App** anlegen (4 Stück)
- [ ] **DNS-Setup** für alle vier Domains

### Optional / Nice-to-have

- [ ] **Sitemap.xml** wieder aktivieren (`@astrojs/sitemap` mit fester Version, vorhin gab's einen Bug)
- [ ] **OG-Image-Generator** (dynamische Social-Cards pro News-Beitrag)
- [ ] **Suchfunktion** im Header (aktuell ist das Lupen-Icon ein Platzhalter)
- [ ] **DSGVO-Cookie-Banner** integrieren (Borlabs Cookie oder Real Cookie Banner)
- [ ] **Barrierefreiheits-Audit** vor Launch (BITV 2.0 / WCAG 2.1 AA)
- [ ] **Lighthouse-Optimierung** (Bilder als WebP/AVIF, lazy-loading verifizieren, LCP < 2.5 s)
- [ ] **GSAP-Migration prüfen** falls die aktuellen CSS-/JS-Animationen mehr Choreografie brauchen

---

## 9. Wichtige Entscheidungen (zur Erinnerung)

| Entscheidung                          | Grund                                                                |
|----------------------------------------|----------------------------------------------------------------------|
| **Astro statt klassisches WordPress-Theme** | Performance, moderne UX, Animationen kompatibel halten             |
| **Headless WP statt Strapi/Directus**  | Redakteure kennen WP, Multi-Site-Vermittlung über Standort-Taxonomie |
| **Single WP statt WP-Multisite**       | Eine Login-Maske, einfache Plugin-Updates, Cross-Site-Inhalte trivial|
| **Monorepo statt 3 separate Repos**    | Geteiltes Design-System, ein Bug-Fix wirkt überall                   |
| **npm-Workspaces statt pnpm/Turborepo**| Minimale Tool-Vorinstallation, Cloudflare versteht npm nativ         |
| **Cloudflare Pages statt GitHub Pages**| Per-Branch-Previews kostenlos, mehrere Apps aus einem Repo möglich   |
| **wrangler.jsonc statt klassische Pages-UI** | Cloudflare hat Workers + Pages zu einem Flow zusammengelegt    |
| **Vanilla CSS + IntersectionObserver, kein GSAP** | Kein zusätzliches Framework nötig, WP-Migration einfach     |
| **Kein Tailwind**                      | BEM-Klassen sind kompatibler mit späterem WPBakery-Migrationspfad    |
| **Kein Orange im Design**              | Briefing-Vorgabe — alte Site-Anmutung soll nicht weiterleben         |
| **Schwibbogen-Animation entfernt**     | Auf Wunsch — passt nicht zu jeder Saison                             |

---

## 10. Stiel-Stolpersteine (Lessons learned)

- **CSS-Variablen in `transform`** ohne `@property`-Deklaration werden nicht interpoliert → für Mausverfolgung direktes `style.transform` per JS, separater Wrapper für Scale
- **`getBoundingClientRect()` auf Animations-Elementen** liefert post-transform-Position — nicht den Wrapper messen, sondern das innere bewegte Element
- **Cloudflare hat Workers + Pages vereinheitlicht** — neue Projekte brauchen `wrangler.jsonc`, nicht mehr die alte Pages-UI
- **macOS Keychain als Credential-Helper** ist die einzige saubere Lösung für lokales Git ohne Token-Re-Eingabe
- **Astro-Content-Collections-Schema-Feld `slug`** ist reserviert — eigene Felder anders nennen (wir hatten `key` für Ortsteile)

---

## 11. Wenn die Konversation neu gestartet wird

**Neue Konversation übergeben mit:**
> Hier ist `PROJECT_STATUS.md` — bitte lies das einmal komplett. Das Projekt ist ein Astro-Monorepo unter `/Users/stefan/Claude/Website/gruenhainichen` (Workspace-Root). Live-URL ist `vv-wildenstein-gruenhainichen.stefan-0ea.workers.dev`. Das Repo ist auf GitHub unter `stefangutermuth/vv-wildenstein`. Letzter Stand: <kurze Beschreibung was zuletzt passiert ist>.

So weiß die nächste Konversation sofort, woran sie ist.

**Vor jeder größeren Änderung empfohlen:**
- Branch erstellen (`git checkout -b feature/xxx`), nicht direkt auf main
- Push auf den Branch → Cloudflare baut Preview-URL
- Erst nach Sichtkontrolle nach `main` mergen

---

## 12. Kontakt & Auftraggeber

- **Auftraggeber:** GUMU Werbeagentur (Stefan)
- **E-Mail:** stefan@gumu-agentur.de
- **Mandant:** Gemeinde Grünhainichen / Verwaltungsverband Wildenstein
