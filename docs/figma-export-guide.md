# Figma-Export-Guide für Stakeholder-Reviews

Der Astro-Erstwurf ist der gestalterische Master. Für Reviews mit Bürgermeister, Gemeinderat und Tourismus-Verantwortlichen empfiehlt sich, daraus ein Figma-Mock-Set zu erzeugen — ohne den Astro-Build zu verlassen. Es gibt zwei Wege:

## Pfad A — Screenshot-Layouts (schnellster Weg, empfohlen)

1. `npm run dev` starten und die Startseite in Chrome bei drei festgelegten Viewports öffnen:
   - Desktop: 1440 × 900
   - Tablet:  834 × 1112
   - Mobile:  390 × 844 (iPhone 14)
2. **Sektions-Screenshots** statt Full-Page (bessere Lesbarkeit). Mit Chrome DevTools (Cmd+Shift+P → "Capture node screenshot") jede `<GrhSection>` einzeln aufnehmen.
3. Screenshots in Figma in einem **Frame pro Sektion** anordnen. Reihenfolge folgt Briefing-Abschnitt 6.2.
4. Die Token-Datei `docs/tokens.json` als **Figma-Variablen** importieren (Plugin: "Variables Import" oder "Tokens Studio for Figma"). Damit haben Designer-Annotationen dieselben Farben wie der Code.

## Pfad B — html.to.design / Figma-MCP

Falls eine vollständig editierbare Figma-Datei nötig ist:

1. Astro lokal builden (`npm run build`), `dist/index.html` öffnen.
2. Plugin "html.to.design" in Figma → URL bzw. lokale Datei einfügen → Import.
3. Manuell aufräumen: Auto-Layouts setzen, Variablen verknüpfen.

Achtung: Die Schwibbogen-SVG-Animation wird **nicht** mit übertragen — in Figma als statische Variante mit allen Lichtern entzündet darstellen.

## Annotationen für Stakeholder

Pro Frame folgende Hinweise empfohlen:
- **"Saison-Schalter"** am Hero erklären (Sommer/Advent-Vergleich nebeneinander).
- **"50/50 Doppelportal"** mit Pfeil aus dem Briefing-Konzept zitieren.
- **Token-Pillen** an Headlines und Buttons (z. B. `--grh-forest-800` direkt am Button).
- **Ortsteile-Reihe** als visueller Beweis für die Gleichberechtigung beschriften.

## Saison-Vergleichsfolien

Drei Varianten der Startseite als Side-by-Side: Sommer (EURORANDO-Featured-Slot, Wanderpfad-Animation), Advent (Lichtermeer-Sektion sichtbar, Schwibbogen-Animation), Standard (Frühling/Herbst). Stakeholder sehen sofort, dass die Seite "atmet".
