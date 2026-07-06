/**
 * Geocoder über OpenStreetMap Nominatim.
 *
 * Wird zur Build-Zeit aufgerufen — z.B. um aus Sperrungs-Titeln (per Regex
 * gesäuberte Straßennamen) Koordinaten zu ermitteln.
 *
 * Hinweise:
 *  - Nominatim ist kostenlos, verlangt aber maximal 1 Anfrage pro Sekunde
 *    und einen aussagekräftigen User-Agent (siehe Usage Policy).
 *  - Antworten werden modul-weit gecached — innerhalb eines Builds dieselbe
 *    Anfrage nicht zweimal stellen.
 *  - Fehler / 0-Treffer schlucken wir — die UI rendert dann eben keinen Marker.
 */

import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const USER_AGENT = 'Gruenhainichen-Astro/1.0 (contact: info@wildenstein.ws)';
const RATE_LIMIT_MS = 1100;
const CACHE_FILE = new URL('./geocode-cache.json', import.meta.url).pathname;

const cache = new Map<string, GeoPoint | null>();
let lastCall = 0;
let cacheLoaded = false;

/** Bekannte Koordinaten — Override für Nominatim-Antworten und Seed-Cache. */
const KNOWN_PLACES: Record<string, GeoPoint> = {
  'borstendorf, sachsen':                      { lat: 50.7722, lon: 13.1781 },
  'grünhainichen, sachsen':                    { lat: 50.7675, lon: 13.1539 },
  'waldkirchen, sachsen':                      { lat: 50.7667, lon: 13.1094 },
  'börnichen, sachsen':                        { lat: 50.7800, lon: 13.2350 },
  'floßmühle, borstendorf, sachsen':           { lat: 50.7606, lon: 13.1792 },
  'mühlenstraße, grünhainichen, sachsen':      { lat: 50.7669, lon: 13.1535 },
  'eppendorfer straße, borstendorf, sachsen':  { lat: 50.7740, lon: 13.1800 },
  'ortsdurchfahrt borstendorf, sachsen':       { lat: 50.7722, lon: 13.1781 },
  's 235, borstendorf, sachsen':               { lat: 50.7740, lon: 13.1800 },
  'b 174, waldkirchen, sachsen':               { lat: 50.7667, lon: 13.1094 },
};

function loadCacheFromDisk() {
  if (cacheLoaded) return;
  cacheLoaded = true;
  // 1) Seed: bekannte Orte
  for (const [k, v] of Object.entries(KNOWN_PLACES)) cache.set(k, v);
  // 2) Persistierter Cache von früheren Builds
  if (existsSync(CACHE_FILE)) {
    try {
      const raw = JSON.parse(readFileSync(CACHE_FILE, 'utf8')) as Record<string, GeoPoint | null>;
      for (const [k, v] of Object.entries(raw)) {
        if (!cache.has(k)) cache.set(k, v);
      }
    } catch (err) {
      console.warn('[geocode] Cache-Datei konnte nicht geladen werden:', err);
    }
  }
}

function persistCacheToDisk() {
  try {
    mkdirSync(dirname(CACHE_FILE), { recursive: true });
    const obj: Record<string, GeoPoint | null> = {};
    for (const [k, v] of cache.entries()) obj[k] = v;
    writeFileSync(CACHE_FILE, JSON.stringify(obj, null, 2));
  } catch (err) {
    console.warn('[geocode] Cache konnte nicht persistiert werden:', err);
  }
}

export interface GeoPoint {
  lat: number;
  lon: number;
}

async function throttle(): Promise<void> {
  const elapsed = Date.now() - lastCall;
  if (elapsed < RATE_LIMIT_MS) {
    await new Promise((r) => setTimeout(r, RATE_LIMIT_MS - elapsed));
  }
  lastCall = Date.now();
}

export async function geocode(query: string): Promise<GeoPoint | null> {
  loadCacheFromDisk();
  const key = query.trim().toLowerCase();
  if (!key) return null;
  if (cache.has(key)) return cache.get(key)!;

  await throttle();

  try {
    const url = new URL('https://nominatim.openstreetmap.org/search');
    url.searchParams.set('q', query);
    url.searchParams.set('format', 'json');
    url.searchParams.set('limit', '1');
    url.searchParams.set('countrycodes', 'de');

    // 5 s Timeout — wenn Nominatim ratelimitet, soll der Astro-Server
    // nicht ewig warten. Cache-Treffer beim nächsten Build sollten dann greifen.
    const ctrl = new AbortController();
    const tHandle = setTimeout(() => ctrl.abort(), 5000);
    let res: Response;
    try {
      res = await fetch(url.toString(), {
        headers: { 'User-Agent': USER_AGENT, Accept: 'application/json' },
        signal: ctrl.signal,
      });
    } finally {
      clearTimeout(tHandle);
    }
    if (!res.ok) {
      // Bei 429/5xx NICHT als „null" cachen — der nächste Build soll es erneut versuchen.
      if (res.status === 429 || res.status >= 500) return null;
      cache.set(key, null);
      persistCacheToDisk();
      return null;
    }
    let data: Array<{ lat: string; lon: string }> = [];
    try { data = await res.json(); } catch { return null; }
    if (data.length === 0) {
      cache.set(key, null);
      persistCacheToDisk();
      return null;
    }
    const result: GeoPoint = { lat: parseFloat(data[0].lat), lon: parseFloat(data[0].lon) };
    cache.set(key, result);
    persistCacheToDisk();
    return result;
  } catch (err) {
    console.warn(`[geocode] failed for "${query}":`, err);
    return null;
  }
}

/**
 * Versucht aus einem Sperrungs-Titel ein oder mehrere geocoder-taugliche
 * Queries zu bauen. Die erste, die einen Treffer liefert, gewinnt.
 *
 * Strategie:
 *  1) Wenn Titel ein „X-straße/X-weg/X-platz" + Ortsteil enthält → exakt das.
 *  2) Sonst: gesäuberter Titel + Ortsteil-Anker.
 *  3) Sonst: nur erkannter Ortsteil (zumindest grob verorten).
 */
export function buildGeoQueriesFromTitle(title: string): string[] {
  const queries: string[] = [];

  // Welcher Ortsteil ist im Titel erwähnt?
  const ortMatch = title.match(/(grünhainichen|gruenhainichen|borstendorf|waldkirchen|börnichen|boernichen)/i);
  const ortsteil = ortMatch ? capitalize(ortMatch[1]) : 'Grünhainichen';

  // 1) Straßenname extrahieren (Wort endend auf -straße/-strasse/-str./-weg/-platz/-gasse/-allee)
  const streetRegex = /([A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß-]+(?:straße|strasse|str\.|weg|platz|gasse|allee|tal))/gi;
  const streetMatches = Array.from(title.matchAll(streetRegex)).map((m) => m[1]);
  for (const street of streetMatches) {
    queries.push(`${street}, ${ortsteil}, Sachsen`);
  }

  // 2) S/B/K-Klassifizierungen ("S 235", "B 174", "K 8523")
  const roadClass = title.match(/\b([SBK])\s*\d{2,4}\b/);
  if (roadClass) {
    queries.push(`${roadClass[0]}, ${ortsteil}, Sachsen`);
  }

  // 3) Bekannte Begriffe wie „Ortsdurchfahrt" + Ortsteil
  if (/ortsdurchfahrt/i.test(title)) {
    queries.push(`${ortsteil}, Sachsen`);
  }

  // 4) Gesäuberter Titel als Fallback
  let cleaned = title;
  cleaned = cleaned.replace(/\b\d{1,2}\.\s*-?\s*\d{0,2}\.?\s*(\d{1,2}\.)?\s*(\d{2,4})?\b/g, ' ');
  cleaned = cleaned.replace(/\b(ab|bis|vom|am|im|in der|in dem|aufgrund|wegen|eines)\s+/gi, ' ');
  cleaned = cleaned.replace(/\b(sperrung|vollsperrung|halbseitige|teilweise|sperrungen|umleitung|baustelle|verkehrshinweis|aktualisierung|einschränkungen?|erweiterung|leerung|hinweis)\b/gi, ' ');
  cleaned = cleaned.replace(/[„""\(\)]/g, ' ');
  cleaned = cleaned.replace(/[—–-]+/g, ' ').replace(/\s+/g, ' ').trim();

  if (cleaned.length > 3) {
    const hasOrt = ortMatch && new RegExp(ortsteil, 'i').test(cleaned);
    queries.push(hasOrt ? `${cleaned}, Sachsen` : `${cleaned}, ${ortsteil}, Sachsen`);
  }

  // 5) Letzter Notnagel: nur der Ortsteil
  queries.push(`${ortsteil}, Sachsen`);

  // Dedupe und Reihenfolge wahren
  return Array.from(new Set(queries.map((q) => q.replace(/\s+/g, ' ').trim())));
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
}

/** Bequemer Wrapper: probiert mehrere Queries, gibt ersten Treffer zurück. */
export async function geocodeTitle(title: string): Promise<GeoPoint | null> {
  for (const q of buildGeoQueriesFromTitle(title)) {
    const hit = await geocode(q);
    if (hit) return hit;
  }
  return null;
}
