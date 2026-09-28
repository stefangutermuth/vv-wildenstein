# VV-Wildenstein Web-Monorepo

Astro-Websites für Grünhainichen (`apps/gruenhainichen`), Börnichen (`apps/boernichen`),
den Verwaltungsverband (`apps/verband`) und den Mängelmelder (`apps/maengelmelder`).
Inhalte kommen aus WordPress auf vv-wildenstein.com.

**Aktueller Stand und alle offenen Punkte: `docs/STAND-2026-09-28.md`** (zuerst lesen).
Weitere Dokumente: `docs/WORDPRESS-ALS-QUELLE.md` (Plan: alles in WordPress pflegen),
`docs/SAMMELRUNDE-PROFILE-2026-09-28.md` (Gewerbe-Abgleich mit Rückfragen).

## Regeln
- Inhalte gehören in WordPress (Inhaltstypen und Felder), nicht in den Code. Änderungen an
  Profilen, Terminen usw. direkt in WordPress per `ssh vv-wildenstein` und WP-CLI.
- Keine Gedankenstriche („—“, „ – “) in Website-Texten; Komma, Punkt oder „ · “ benutzen.
- Kein kursiver Schnitt auf gruenhainichen.com.
- Vor jedem Push `git fetch && git rebase origin/main` (parallele Sitzungen).
- Deploy läuft über GitHub Actions; WordPress stößt Neubauten selbst an.
