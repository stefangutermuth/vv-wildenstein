/**
 * WordPress-REST-Adapter für vv-wildenstein.com
 *
 * Strategie:
 *   - Lädt alle Posts in den für Grünhainichen relevanten Kategorien
 *     (gruenhainichen, borstendorf, waldkirchen + sperrung querbeet)
 *   - Mappt WP-Term-Slugs auf unser internes Schema (NewsCategory + Ortsteil)
 *   - Ergänzt Featured-Image aus der embedded `wp:featuredmedia`
 *   - Fällt bei Fehlern leise zurück (Caller entscheidet über Local-Fallback)
 *
 * Endpunkt-Wechsel: Der Base-URL wird über PUBLIC_WP_API_BASE überschrieben,
 * Default ist die Live-Installation.
 */

import type { NewsItem, NewsCategory, Ortsteil, EventItem } from './cms';

const WP_BASE =
  (import.meta.env.PUBLIC_WP_API_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_WP_API_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/wp/v2';

/**
 * Build-Time Basic-Auth (für die Media-API, die in dieser Installation
 * für anonyme Aufrufer gesperrt ist). Werte kommen aus Server-Env-Variablen,
 * die NICHT mit PUBLIC_ präfixiert sind und damit nie ans Frontend gelangen.
 *
 * In Cloudflare Workers Builds sind ENV-Variablen nur via process.env
 * verfügbar, nicht über Astro's import.meta.env. Lokal beides möglich.
 */
const WP_AUTH_USER =
  (typeof process !== 'undefined' ? process.env.WP_AUTH_USER : undefined) ??
  (import.meta.env.WP_AUTH_USER as string | undefined) ??
  '';
const WP_AUTH_PASS =
  (typeof process !== 'undefined' ? process.env.WP_AUTH_PASS : undefined) ??
  (import.meta.env.WP_AUTH_PASS as string | undefined) ??
  '';

function buildAuthHeader(): Record<string, string> {
  if (!WP_AUTH_USER || !WP_AUTH_PASS) return {};
  const token = Buffer.from(`${WP_AUTH_USER}:${WP_AUTH_PASS}`).toString('base64');
  return { Authorization: `Basic ${token}` };
}

/** Wie viele Posts pro Anfrage maximal (WP-Default ist 10, Max 100).
 *  Auf 100 gesetzt, damit auch ältere Bekanntmachungen (z.B. FNP 07/2022) im
 *  News-Index landen und für /neuigkeiten/[slug] statisch erzeugt werden. */
const PER_PAGE = 100;

/** WP-Slugs, die wir im Frontend von Grünhainichen sehen wollen */
const ORTSTEIL_SLUGS: Record<string, Ortsteil> = {
  gruenhainichen: 'gruenhainichen',
  borstendorf:    'borstendorf',
  // Waldkirchen kann mehrere Slugs haben — wir matchen alles, was so heißt
  waldkirchen:    'waldkirchen',
};

/** Mapping WP-Term-Slug → unsere NewsCategory. Erste Übereinstimmung gewinnt. */
const CATEGORY_RULES: Array<{ match: RegExp; cat: NewsCategory }> = [
  { match: /^sperrung$/i,                         cat: 'sperrung'      },
  { match: /^(kultur|tourismus|veranstaltung)/i,  cat: 'veranstaltung' },
  { match: /^(natur|wandern|sehensw)/i,           cat: 'tourismus'     },
  { match: /^(gemeinderat|gemeinde|bauleitplan|ausschreibungen|verwaltung)/i, cat: 'verwaltung' },
];

interface WPPost {
  id: number;
  date: string;
  slug: string;
  link: string;
  title:   { rendered: string };
  excerpt: { rendered: string };
  content: { rendered: string };
  categories: number[];
  featured_media: number;
  sticky?: boolean;
  _embedded?: {
    'wp:featuredmedia'?: Array<{
      source_url?: string;
      mime_type?: string;
      media_details?: { sizes?: Record<string, { source_url: string; width: number }> };
      alt_text?: string;
    }>;
    'wp:term'?: Array<Array<{ id: number; slug: string; name: string; taxonomy: string }>>;
  };
}

// Beitrags-Bilder, die wir NIE als Featured Image behandeln —
// generische Platzhalter, die der Redaktion oft als Default eingeschoben werden.
const PLACEHOLDER_PATTERNS = [
  /platzhalter/i,
  /placeholder/i,
  /beitrag_platzhalter/i,
];

function isPlaceholderUrl(url: string): boolean {
  return PLACEHOLDER_PATTERNS.some((p) => p.test(url));
}

/** Wieviele Pages à PER_PAGE wir maximal holen. Aktuell 5 = bis zu 500 Posts. */
const MAX_PAGES = 5;

/** Timeout pro WP-Anfrage (ms). Verhindert, dass der Astro-Dev-Server bei
 *  langsam reagierender WP-Site komplett hängen bleibt. */
const FETCH_TIMEOUT_MS = 8000;

async function fetchWithTimeout(url: string, init: RequestInit = {}, timeoutMs = FETCH_TIMEOUT_MS): Promise<Response> {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    return await fetch(url, { ...init, signal: ctrl.signal });
  } finally {
    clearTimeout(t);
  }
}

export async function fetchWordPressNews(): Promise<NewsItem[]> {
  const all: WPPost[] = [];
  for (let page = 1; page <= MAX_PAGES; page++) {
    const url = new URL(`${WP_BASE}/posts`);
    url.searchParams.set('per_page', String(PER_PAGE));
    url.searchParams.set('page', String(page));
    url.searchParams.set('_embed', 'wp:featuredmedia,wp:term');
    url.searchParams.set('orderby', 'date');
    url.searchParams.set('order', 'desc');

    let res: Response;
    try {
      res = await fetchWithTimeout(url.toString(), {
        headers: { Accept: 'application/json', ...buildAuthHeader() },
      });
    } catch (err) {
      console.warn(`[cms-wp] page ${page} Timeout/Abort:`, (err as Error).message);
      break; // bei Timeout bisher gesammelte Posts zurückgeben statt komplett zu scheitern
    }
    if (!res.ok) {
      // WP gibt 400 zurück, wenn page > vorhandene Seiten → Schleife beenden.
      if (page === 1) throw new Error(`WP REST ${res.status} ${res.statusText} (${url.pathname})`);
      break;
    }
    const batch = (await res.json()) as WPPost[];
    all.push(...batch);
    if (batch.length < PER_PAGE) break; // letzte Seite war kürzer
  }

  return all
    .map(mapWPPostToNewsItem)
    .filter((n): n is NewsItem => n !== null);
}

function mapWPPostToNewsItem(p: WPPost): NewsItem | null {
  const termSlugs = collectTermSlugs(p);

  // Nur Posts behalten, die für Grünhainichen relevant sind:
  //   entweder keinem Ortsteil zugeordnet (allgemein) ODER
  //   einer der drei Grünhainichen-Ortsteile.
  // Posts, die ausschließlich „boernichen" markiert sind, fallen raus.
  const ortsteil = pickOrtsteil(termSlugs);
  const isBornichenOnly = termSlugs.has('boernichen') && !ortsteil;
  if (isBornichenOnly) return null;

  const category = pickCategory(termSlugs);
  // Bild-Strategie:
  // 1) Beitragsbild — nur wenn es ein echtes Bild ist (kein PDF/Doc)
  // 2) Sonst: erstes <img> aus dem Beitragstext (sofern kein Platzhalter)
  // 3) Sonst: undefined → Gradient-Fallback in der UI
  const image = pickFeaturedImage(p) ?? pickInlineImage(p.content?.rendered ?? '');
  const title = decodeEntities(p.title.rendered);
  const excerpt = decodeEntities(stripHtml(p.excerpt.rendered)).trim();

  // WP-Slugs können URL-encodierte Sonderzeichen enthalten (z.B. %c2%a7 für „§").
  // Wir decodieren sie, damit Astros getStaticPaths saubere Routen erzeugt.
  const decodedSlug = safeDecode(p.slug);

  return {
    slug: decodedSlug,
    title,
    date: new Date(p.date),
    category,
    ortsteil,
    image,
    excerpt,
    featured: p.sticky ?? false,
    href: `/neuigkeiten/${decodedSlug}`,
  };
}

function safeDecode(s: string): string {
  try { return decodeURIComponent(s); } catch { return s; }
}

function collectTermSlugs(p: WPPost): Set<string> {
  const out = new Set<string>();
  const groups = p._embedded?.['wp:term'] ?? [];
  for (const grp of groups) {
    for (const t of grp) out.add(t.slug);
  }
  return out;
}

function pickOrtsteil(slugs: Set<string>): Ortsteil | undefined {
  for (const s of slugs) {
    if (ORTSTEIL_SLUGS[s]) return ORTSTEIL_SLUGS[s];
    // Waldkirchen kann auch z.B. "waldkirchen-kirche" heißen
    if (s.startsWith('waldkirchen')) return 'waldkirchen';
  }
  return undefined;
}

function pickCategory(slugs: Set<string>): NewsCategory {
  for (const s of slugs) {
    for (const rule of CATEGORY_RULES) {
      if (rule.match.test(s)) return rule.cat;
    }
  }
  return 'verwaltung';
}

function pickFeaturedImage(p: WPPost): string | undefined {
  const media = p._embedded?.['wp:featuredmedia']?.[0];
  if (!media) return undefined;
  // Nicht-Bild-Anhänge (PDFs, Word, Excel) ignorieren — die Redaktion nutzt
  // das Beitragsbild-Feld manchmal für Dokumenten-Uploads.
  if (media.mime_type && !media.mime_type.startsWith('image/')) return undefined;

  const sizes = media.media_details?.sizes ?? {};
  // Größte verfügbare Variante zuerst — Bilder werden im Mauerwerk-Grid
  // im Originalformat angezeigt, daher braucht es die volle Qualität.
  const candidate =
    media.source_url ??
    sizes['large']?.source_url ??
    sizes['medium_large']?.source_url ??
    sizes['medium']?.source_url;
  if (!candidate || isPlaceholderUrl(candidate)) return undefined;
  return candidate;
}

function pickInlineImage(html: string): string | undefined {
  if (!html) return undefined;
  // Findet alle <img>-Tags mit src und gibt das erste echte zurück.
  const regex = /<img[^>]+src=["']([^"']+)["'][^>]*>/gi;
  let m: RegExpExecArray | null;
  while ((m = regex.exec(html)) !== null) {
    const src = m[1];
    if (!isPlaceholderUrl(src)) return src;
  }
  return undefined;
}

function stripHtml(html: string): string {
  return html
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ');
}

/* ----------------------------------------------------------------
 * Events — vw-events Plugin (eigener Namespace vw-events/v1)
 * Liefert nur zukünftige + aktuell laufende Events.
 * ---------------------------------------------------------------- */

const VW_EVENTS_BASE =
  (import.meta.env.PUBLIC_VW_EVENTS_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_VW_EVENTS_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/vw-events/v1';

interface VWEvent {
  id: number;
  slug: string;
  title: string;
  description_html: string;
  start: string | null;
  end: string | null;
  all_day: boolean;
  location: { name: string; address: string };
  organizer: { name: string };
  url: string;
  image: { url: string; alt: string } | null;
  standort: string[];
  category: string[];
  permalink: string;
}

export async function fetchWordPressEvents(): Promise<EventItem[]> {
  const url = new URL(`${VW_EVENTS_BASE}/events`);
  url.searchParams.set('per_page', '100');
  // Plugin filtert nur auf start >= from. Wir setzen from = heute - 14 Tage,
  // damit aktuell laufende mehrtägige Events (Start in jüngster Vergangenheit,
  // Ende noch in der Zukunft) noch zurückkommen. Final filtert dann unser
  // map-Schritt unten auf "end >= now ODER start >= now".
  const fromDate = new Date();
  fromDate.setDate(fromDate.getDate() - 14);
  url.searchParams.set('from', fromDate.toISOString().slice(0, 19));

  let res: Response;
  try {
    res = await fetchWithTimeout(url.toString(), { headers: { Accept: 'application/json' } });
  } catch (err) {
    console.warn('[cms-wp] vw-events Timeout/Abort:', (err as Error).message);
    return [];
  }
  if (!res.ok) {
    console.warn(`[cms-wp] vw-events ${res.status} ${res.statusText} → leere Liste`);
    return [];
  }
  const events = (await res.json()) as VWEvent[];

  const now = new Date();
  return events
    .map(mapVWEvent)
    .filter((e): e is EventItem => e !== null)
    .filter((e) => {
      const endRef = e.endDate ?? e.startDate;
      return endRef.valueOf() >= now.valueOf();
    })
    .sort((a, b) => a.startDate.valueOf() - b.startDate.valueOf());
}

function mapVWEvent(ev: VWEvent): EventItem | null {
  if (!ev.start) return null;
  const startDate = new Date(ev.start);
  if (Number.isNaN(startDate.valueOf())) return null;
  let endDate = ev.end ? new Date(ev.end) : undefined;
  if (endDate && Number.isNaN(endDate.valueOf())) endDate = undefined;
  // Tagesgenauer Vergleich (lokal): wenn Start- und End-Datum auf den gleichen
  // Kalendertag fallen, ist es kein mehrtägiges Event — endDate weglassen,
  // sonst rendern wir Quatsch wie „23.–23. August".
  if (endDate) {
    const sameDay =
      startDate.getFullYear() === endDate.getFullYear() &&
      startDate.getMonth()    === endDate.getMonth() &&
      startDate.getDate()     === endDate.getDate();
    if (sameDay) endDate = undefined;
  }

  const ortsteil = pickOrtsteilFromSlugs(ev.standort);
  const location = ev.location?.name || ev.location?.address || '';

  return {
    slug: ev.slug,
    title: decodeEntities(ev.title),
    startDate,
    endDate,
    location,
    ortsteil,
    teaser: stripHtml(ev.description_html).trim().slice(0, 180),
    featured: false,
    image: ev.image?.url,
    href: ev.permalink,
  };
}

function pickOrtsteilFromSlugs(slugs: string[]): Ortsteil | undefined {
  for (const s of slugs) {
    if (ORTSTEIL_SLUGS[s]) return ORTSTEIL_SLUGS[s];
    if (s.startsWith('waldkirchen')) return 'waldkirchen';
  }
  return undefined;
}

// Benannte HTML-Entities, die WP-Titel & Excerpts häufig verwenden
const NAMED_ENTITIES: Record<string, string> = {
  amp:    '&',
  lt:     '<',
  gt:     '>',
  quot:   '"',
  apos:   "'",
  nbsp:   ' ',
  hellip: '…',
  laquo:  '«',
  raquo:  '»',
  ndash:  '–',
  mdash:  '—',
  lsquo:  '‘',
  rsquo:  '’',
  ldquo:  '“',
  rdquo:  '”',
  bdquo:  '„',
  sbquo:  '‚',
};

/**
 * Dekodiert HTML-Entities — sowohl benannte (`&amp;`, `&hellip;`) als auch
 * numerische (`&#8222;`, `&#x201E;`). Behandelt damit auch die deutschen
 * Anführungszeichen „..." (8222/8220) und alle anderen typischen Sonderzeichen,
 * die WP automatisch erzeugt.
 */
function decodeEntities(html: string): string {
  return html.replace(/&(#x[0-9a-fA-F]+|#\d+|[a-zA-Z][a-zA-Z0-9]+);/g, (m, ref: string) => {
    if (ref.startsWith('#x') || ref.startsWith('#X')) {
      const code = parseInt(ref.slice(2), 16);
      return Number.isFinite(code) ? String.fromCodePoint(code) : m;
    }
    if (ref.startsWith('#')) {
      const code = parseInt(ref.slice(1), 10);
      return Number.isFinite(code) ? String.fromCodePoint(code) : m;
    }
    return NAMED_ENTITIES[ref] ?? m;
  });
}
