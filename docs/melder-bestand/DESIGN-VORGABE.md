# Design-Vorgabe — neuer Mängelmelder (nach Vorbild melder.vv-wildenstein.com)

> Screenshots: `docs/melder-bestand/screenshots/`. Assets (Logo, Marker): `docs/melder-bestand/assets/`.
> Das neue Astro-Frontend wird **nach diesem Vorbild** gestaltet (nicht von gruenhainichen kopiert).

## Marke & Tokens
- **Wappen/Logo:** `Wappen-Verband-Wildenstein_CMYK.svg` (zweifarbiges Verbands-Wappen + Schriftzug „Wildenstein / Verwaltungsverband im Freistaat Sachsen").
- **Font:** **Rubik** (sans-serif), Headlines fett (700).
- **Farben:**
  - Primär-Grün (Header/Bars): `#0a5f2b`
  - Akzent-Blau (Haupt-CTA „Neue melden / Mangel melden"): `#2a3196`
  - Akzent-Orange (Status „In Bearbeitung", Hinweise): `#ec7d20`
  - Flächen: Weiß `#ffffff`, Hellgrau `#f5f5f5`, Text Dunkel `#1a1a1a`
- **Status-Marker** (PNG, runde Pins): Neue Meldung (blau), In Bearbeitung (orange/gelb), Erledigt (grün).

## Seiten (Vorbild)
1. **Willkommen** (`/maengelmelder`): Vollbild-Hero (Saison-Foto), Overlay-Text
   „Herzlich willkommen auf der ‚Mängelmelder-App' vom Verwaltungsverband ‚Wildenstein'",
   blauer Button „Jetzt mitmachen".
2. **Karte** (Startseite): Header-Bar grün mit Logo + Nav. Tabs „Karte | Satellit".
   Vollbreite Karte mit Status-Markern + InfoWindow. Legende (Neue/In Bearbeitung/Erledigt).
   Blauer Button „Mangel melden". Darunter: **Status-Filter** + **Karten-Raster der Meldungen**
   (Card: Foto/Icon, Titel, Status-Badge, Datum). Pagination.
3. **Mangel einreichen** (`/mangel-einreichen`): Formular, Felder gestapelt:
   - Name der Meldung (Text)
   - Kategorie (Radio: die 5 Anliegen)
   - Beschreibung (Textarea/Rich-Text)
   - Ihr Name (Text)
   - Ihre E-Mail-Adresse für Rückfragen (Text)
   - Bild (Upload „Machen Sie ein Bild von Ihrer Meldung")
   - Location (Adress-Suche + Karte zum Pin-Setzen)
   - Button „Meldung einreichen"
4. **Vielen Dank!** (`/vielen-dank`): Bestätigungsseite nach Einreichung.
5. Impressum, Datenschutzerklärung.
6. **Footer:** hellgrau, mit Partner-Logos (Freistaat Sachsen, AssKomm, „Erzgebirge – Fern und Nah"),
   Links Impressum + Datenschutzerklärung.

## Karte: Umstieg
- Bestand nutzt **Google Maps JS API**. Neu: **Leaflet/MapLibre + OSM-Tiles** (kein API-Key, kein Tracking),
  gespeist aus `…/wp-json/vw-melder/v1/geojson`. Status-Marker-Icons aus `assets/marker-*.png`.

## Technik (Astro)
- Astro ^4.16, Vanilla-CSS (BEM), `~/*`-Alias, PWA (Manifest + Service Worker).
- Datenquelle: zentrale REST `vw-melder/v1` (Build-Time-Fetch + Client-Hydration für Karte/Filter).
