/**
 * Disk-Cache für WordPress-REST-Antworten.
 *
 * Ablauf:
 *   1. cachedFetch(url) prüft ob eine kompatible Antwort schon auf Disk liegt
 *   2. wenn ja + Cache "frisch" → aus Cache zurückgeben (0 HTTP-Requests)
 *   3. sonst → HTTP fetchen, Antwort speichern, zurückgeben
 *
 * "Frisch" heißt: die letzte modified-GMT-Timestamp aller relevanten CPTs
 * ist ≤ dem Cache-Zeitpunkt. Das prüft `latestWpModified()` einmal pro Build
 * über einen einzigen HTTP-Call an /wp/v2/{cpt}?orderby=modified&per_page=1.
 *
 * Cache-Dir: `apps/gruenhainichen/.wp-cache/`
 *   - {sha256(url)}.json   — die eigentliche Antwort
 *   - _meta.json           — Timestamp des zuletzt geprüften modified_gmt
 *
 * Deaktivieren pro Build via ENV: `WP_CACHE=off`
 */

import { createHash } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';

const CACHE_DIR = path.resolve('.wp-cache');
const META_FILE = path.join(CACHE_DIR, '_meta.json');

const CACHE_DISABLED =
  (typeof process !== 'undefined' ? process.env.WP_CACHE : undefined) === 'off';

interface CacheMeta {
  /** ISO-Timestamp: höchstes modified_gmt aus allen relevanten CPTs beim letzten fresh-check */
  lastWpModified?: string;
  /** ISO-Timestamp: wann fresh-check gelaufen ist */
  lastCheckAt?: string;
  /** ISO-Timestamp der lokalen Cache-Erstellung (früheste Datei) */
  cachedAt?: string;
}

let meta: CacheMeta | null = null;
let freshChecked = false;

function keyFor(url: string): string {
  return createHash('sha256').update(url).digest('hex') + '.json';
}

async function ensureDir(): Promise<void> {
  await fs.mkdir(CACHE_DIR, { recursive: true });
}

async function readMeta(): Promise<CacheMeta> {
  if (meta) return meta;
  try {
    meta = JSON.parse(await fs.readFile(META_FILE, 'utf8'));
  } catch {
    meta = {};
  }
  return meta!;
}

async function writeMeta(next: CacheMeta): Promise<void> {
  meta = next;
  await ensureDir();
  await fs.writeFile(META_FILE, JSON.stringify(next, null, 2));
}

/**
 * Prüft einmal pro Build, ob sich in WP seit dem letzten Cache-Bau etwas
 * geändert hat. Wenn ja → Cache wird invalidiert (verwendet für alle
 * folgenden cachedFetch-Aufrufe).
 *
 * "Alle relevanten CPTs" = die, die wir überhaupt anfassen. Wenn User in einem
 * dieser CPTs etwas ändert oder ein neues Attachment für eines lädt, greift's.
 */
async function ensureFreshness(wpBase: string): Promise<boolean> {
  if (freshChecked) return true;
  freshChecked = true;

  const cts = ['posts', 'tourismus', 'verein', 'profile', 'personen', 'amter', 'gemeinderatssitzung', 'amtsblatt_download', 'vvw_room'];
  let latest = '';
  for (const t of cts) {
    try {
      const u = `${wpBase}/${t}?orderby=modified&order=desc&per_page=1&_fields=modified_gmt`;
      const r = await fetch(u, { headers: { Accept: 'application/json' } });
      if (!r.ok) continue;
      const arr = (await r.json()) as Array<{ modified_gmt?: string }>;
      const m = arr?.[0]?.modified_gmt;
      if (m && m > latest) latest = m;
    } catch { /* ignore */ }
  }

  const m = await readMeta();

  /*
   * Höchstalter — unabhängig vom Änderungsdatum.
   *
   * Der Vergleich oben erkennt nur Änderungen, die `post_modified` bewegen.
   * Am 17.08.2026 änderte der Verband die Leitung einer Kita über ein
   * Zusatzfeld; das Änderungsdatum des Beitrags blieb dabei auf dem 29. Juli
   * stehen. Ein solcher Eingriff bliebe hier für immer unsichtbar, und die
   * Website zeigte dauerhaft den alten Namen — ohne dass irgendwo ein Fehler
   * aufträte.
   *
   * Sechs Stunden begrenzen den Schaden: Spätestens beim nächtlichen Bau ist
   * jede Änderung drin, auch die, die niemand melden kann.
   */
  const MAX_ALTER_MS = 6 * 60 * 60 * 1000;
  if (m.cachedAt && Date.now() - Date.parse(m.cachedAt) > MAX_ALTER_MS) {
    console.log(`[wp-cache] Invalidiere Cache (älter als 6 h, angelegt ${m.cachedAt})`);
    await invalidateAll();
    await writeMeta({
      lastWpModified: latest || m.lastWpModified,
      lastCheckAt: new Date().toISOString(),
      cachedAt: new Date().toISOString(),
    });
    return false;
  }

  if (latest && (!m.lastWpModified || latest > m.lastWpModified)) {
    // Etwas hat sich geändert → alte Cache-Files löschen
    console.log(`[wp-cache] Invalidiere Cache (WP-modified ${latest} > cached ${m.lastWpModified ?? 'never'})`);
    await invalidateAll();
    await writeMeta({ lastWpModified: latest, lastCheckAt: new Date().toISOString(), cachedAt: new Date().toISOString() });
    return false;
  }
  await writeMeta({ ...m, lastCheckAt: new Date().toISOString() });
  return true;
}

async function invalidateAll(): Promise<void> {
  try {
    const files = await fs.readdir(CACHE_DIR);
    await Promise.all(
      files.filter((f) => f.endsWith('.json') && f !== '_meta.json')
        .map((f) => fs.unlink(path.join(CACHE_DIR, f))),
    );
  } catch { /* nichts da */ }
}

/**
 * Wichtigste öffentliche Funktion. Ersetzt normalen `fetch()` für WP-REST-Calls.
 */
export async function cachedFetch(url: string, init: RequestInit = {}, wpBase = ''): Promise<Response> {
  if (CACHE_DISABLED) return fetch(url, init);

  // Falls's ein WP-Base gibt, prüfen wir gemeinsam einmal die Frische
  if (wpBase) await ensureFreshness(wpBase);

  await ensureDir();
  const file = path.join(CACHE_DIR, keyFor(url));

  // 1) Cache-Hit?
  try {
    const cached = await fs.readFile(file, 'utf8');
    return new Response(cached, {
      status: 200,
      headers: { 'Content-Type': 'application/json', 'X-Cache': 'HIT' },
    });
  } catch { /* miss */ }

  // 2) Live fetchen + speichern
  const res = await fetch(url, init);
  if (!res.ok) return res;
  const body = await res.text();
  try {
    await fs.writeFile(file, body);
  } catch (err) {
    console.warn('[wp-cache] konnte nicht speichern:', (err as Error).message);
  }
  return new Response(body, {
    status: res.status,
    headers: res.headers,
  });
}

/** Optional für Debug-Ausgabe am Ende des Builds */
export async function stats(): Promise<{ files: number; sizeKB: number }> {
  try {
    const files = (await fs.readdir(CACHE_DIR)).filter((f) => f.endsWith('.json') && f !== '_meta.json');
    let bytes = 0;
    for (const f of files) {
      const st = await fs.stat(path.join(CACHE_DIR, f));
      bytes += st.size;
    }
    return { files: files.length, sizeKB: Math.round(bytes / 1024) };
  } catch {
    return { files: 0, sizeKB: 0 };
  }
}
