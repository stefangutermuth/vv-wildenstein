# Verwaltungsverband Wildenstein — Web-Monorepo

Gemeinsames Design-System und mehrere Astro-Frontends für den Verwaltungsverband Wildenstein und seine Mitgliedsgemeinden. Inhalte werden zentral über eine WordPress-API gepflegt; jedes Frontend liest seinen relevanten Anteil.

```
vv-wildenstein/
├── apps/
│   ├── gruenhainichen/      # gruenhainichen.com  (aktuell aktiv)
│   ├── verband/             # vv-wildenstein.com  (geplant)
│   ├── boernichen/          # boernichen.de       (geplant)
│   └── maengelmelder/       # maengelmelder.…     (geplant, PWA)
├── packages/
│   └── design-system/       # gemeinsame Tokens, Komponenten, Animationen (geplant)
├── docs/                    # Briefings, Spec-Sheets, Migrations-Notes
└── package.json             # npm-Workspaces-Root
```

## Aktueller Stand

**Phase 1 — gestalterischer Master** (live):
- `apps/gruenhainichen/` ist ein vollständiger Astro-Build mit dem editorialen Design-System
- Inhalte aktuell als Astro Content Collections (Markdown). Wechsel auf WordPress-API kommt in Phase 2.

**Phase 2 — Headless WordPress** (geplant):
- Eine einzige WP-Instanz auf `vv-wildenstein.com/wp-admin` als Content-Backbone
- Custom Post Types `news`, `event`, `ortsteil`, `meldung`
- Standort-Taxonomie: jeder Eintrag wird mit den passenden Standorten getaggt
- Astro-Frontends ziehen ihren gefilterten Anteil per REST/GraphQL

**Phase 3 — alle Subsites live**:
- Design-System aus `apps/gruenhainichen/` in `packages/design-system/` extrahieren
- `apps/verband/` und `apps/boernichen/` übernehmen das gleiche System mit eigenem Wappen und Bildmaterial

## Setup

```bash
# Einmalig nach Clone
npm install

# Dev-Server für Grünhainichen
npm run dev
# → http://localhost:4321

# Production-Build
npm run build:gruenhainichen
```

## Deployment

Jede App in `apps/*` ist einzeln deploybar via Cloudflare Pages. Per Repo wird ein Cloudflare-Page-Projekt pro App eingerichtet:

| Cloudflare-Projekt | Build-Befehl                     | Output                       | Domain                         |
|--------------------|----------------------------------|------------------------------|--------------------------------|
| `gruenhainichen`   | `npm run build:gruenhainichen`   | `apps/gruenhainichen/dist`   | `gruenhainichen.com`           |
| (folgt) `verband`  | `npm run build:verband`          | `apps/verband/dist`          | `vv-wildenstein.com`           |
| (folgt) `boernichen` | `npm run build:boernichen`     | `apps/boernichen/dist`       | `boernichen.de`                |

## Design-Prinzipien (Quick Reference)

- **Token-Prefix:** `--grh-*` (zentral in `apps/gruenhainichen/src/styles/tokens.css`, später in `packages/design-system/`)
- **Klassen-Prefix:** `grh-` (BEM, scope-sicher)
- **Kein Tailwind**, keine Volkskunst-Reproduktionen, kein Orange
- **Animationen:** Vanilla CSS + IntersectionObserver, kein GSAP
- **Saison-Schaltung:** automatisch über Monat — Hero, Loader und Featured-Slot reagieren

## Übergabe-Dokumente

Siehe `docs/`:
- `tokens.json` — maschinenlesbarer Token-Export
- `component-mapping.md` — Astro-Komponenten ↔ WPBakery-Shortcodes (für ggf. WP-Theme)
- `animation-recipes.md` — alle Animationen dokumentiert
- `content-model.md` — WP-CPT-Schemas (passt zur kommenden API-Anbindung)
- `figma-export-guide.md` — Stakeholder-Review-Workflow

## Auftraggeber

GUMU Werbeagentur · Mandant: Gemeinde Grünhainichen / Verwaltungsverband Wildenstein
