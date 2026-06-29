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

import type { NewsItem, NewsCategory, Ortsteil, EventItem, AmtsblattItem, CptEntry, CptKontakt } from './cms';

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

/** Wie viele Posts pro Anfrage maximal (WP-Default ist 10, Max 100) */
const PER_PAGE = 50;

/** WP-Slugs, die wir im Frontend von Grünhainichen sehen wollen */
const ORTSTEIL_SLUGS: Record<string, Ortsteil> = {
  gruenhainichen: 'gruenhainichen',
  borstendorf:    'borstendorf',
  // Waldkirchen kann mehrere Slugs haben — wir matchen alles, was so heißt
  waldkirchen:    'waldkirchen',
};

/**
 * Grünhainichen-spezifische Kategorien, die KEIN Ortsteil-Tag tragen, aber inhaltlich
 * nur Grünhainichen betreffen (z. B. „Gemeinderat Grünhainichen"). Auf der Börnichen-Seite
 * werden solche Beiträge nicht angezeigt — außer sie sind zusätzlich „boernichen"-markiert.
 */
const GRH_EXCLUSIVE_SLUGS = new Set([
  'gemeinderat-gruenhainichen',
  'leben-in-gruenhainichen',
]);

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

export async function fetchWordPressNews(): Promise<NewsItem[]> {
  const all: WPPost[] = [];
  const MAX_PAGES = 25; // Sicherheitsnetz (25 × 100 = 2500 Beiträge)
  let page = 1;

  while (page <= MAX_PAGES) {
    const url = new URL(`${WP_BASE}/posts`);
    url.searchParams.set('per_page', '100');
    url.searchParams.set('page', String(page));
    url.searchParams.set('_embed', 'wp:featuredmedia,wp:term');
    url.searchParams.set('orderby', 'date');
    url.searchParams.set('order', 'desc');

    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
    });

    // WP liefert 400 (rest_post_invalid_page_number), wenn die Seite über das Ende hinausgeht
    if (res.status === 400 && page > 1) break;
    if (!res.ok) {
      if (page === 1) throw new Error(`WP REST ${res.status} ${res.statusText} (${url.pathname})`);
      break;
    }

    const batch = (await res.json()) as WPPost[];
    all.push(...batch);

    const totalPages = parseInt(res.headers.get('X-WP-TotalPages') ?? '1', 10);
    if (page >= totalPages || batch.length < 100) break;
    page++;
  }

  return all
    .map(mapWPPostToNewsItem)
    .filter((n): n is NewsItem => n !== null);
}

function mapWPPostToNewsItem(p: WPPost): NewsItem | null {
  const termSlugs = collectTermSlugs(p);

  // Nur Posts behalten, die für Börnichen relevant sind:
  //   entweder mit „boernichen" markiert ODER keinem Ortsteil zugeordnet (allgemein).
  // Posts, die nur einem Grünhainichen-Ortsteil zugeordnet sind, fallen raus.
  const grhOrtsteil = pickOrtsteil(termSlugs);
  const isBoernichen = termSlugs.has('boernichen');
  const isGrhExclusive = [...termSlugs].some((s) => GRH_EXCLUSIVE_SLUGS.has(s));
  // Alt-Beiträge: Grünhainichen-Ratssitzungen, die nur in der allgemeinen Kategorie
  // „gemeinderat" liegen (vor Anlegen von „gemeinderat-gruenhainichen"). Erkennbar nur
  // am Titel: nennt Grünhainichen, aber nicht Börnichen. Börnichen-Sitzungen &
  // Verbandsversammlungen bleiben dadurch erhalten.
  const rawTitle = p.title?.rendered ?? '';
  const isGrhCouncilLegacy =
    termSlugs.has('gemeinderat') && /grünhainichen/i.test(rawTitle) && !/börnichen/i.test(rawTitle);
  if ((grhOrtsteil || isGrhExclusive || isGrhCouncilLegacy) && !isBoernichen) return null;
  // Börnichen kennt keine eigenen Ortsteile → Feld bleibt leer.
  const ortsteil = undefined;

  const category = pickCategory(termSlugs);
  // Bild-Strategie:
  // 1) Beitragsbild — nur wenn es ein echtes Bild ist (kein PDF/Doc)
  // 2) Sonst: erstes <img> aus dem Beitragstext (sofern kein Platzhalter)
  // 3) Sonst: undefined → Gradient-Fallback in der UI
  const image = pickFeaturedImage(p) ?? pickInlineImage(p.content?.rendered ?? '');
  const title = decodeEntities(p.title.rendered);
  const excerpt = decodeEntities(stripHtml(p.excerpt.rendered)).trim();

  return {
    slug: p.slug,
    title,
    date: new Date(p.date),
    category,
    categories: Array.from(termSlugs),
    ortsteil,
    image,
    excerpt,
    featured: p.sticky ?? false,
    href: p.link,
  };
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
  // Möglichst große Variante für scharfe Karten (auch auf Retina):
  const candidate =
    sizes['1536x1536']?.source_url ??
    sizes['large']?.source_url ??
    media.source_url ??           // Original (volle Auflösung)
    sizes['medium_large']?.source_url ??
    sizes['medium']?.source_url;
  if (!candidate || isPlaceholderUrl(candidate)) return undefined;
  return candidate;
}

function pickInlineImage(html: string): string | undefined {
  if (!html) return undefined;
  // Findet alle <img>-Tags und gibt das erste echte Bild in bester Auflösung zurück.
  const regex = /<img\b([^>]*)>/gi;
  let m: RegExpExecArray | null;
  while ((m = regex.exec(html)) !== null) {
    const tag = m[1];
    const src = /\bsrc=["']([^"']+)["']/i.exec(tag)?.[1];
    if (!src || isPlaceholderUrl(src)) continue;
    const srcset = /\bsrcset=["']([^"']+)["']/i.exec(tag)?.[1];
    return upgradeImageUrl(src, srcset);
  }
  return undefined;
}

/** Hebt eine Bild-URL auf die bestmögliche Auflösung an. */
function upgradeImageUrl(src: string, srcset?: string): string {
  // 1) Aus srcset die Variante mit der größten Breite wählen
  if (srcset) {
    let best = src;
    let bestW = 0;
    for (const part of srcset.split(',')) {
      const [u, w] = part.trim().split(/\s+/);
      const width = w ? parseInt(w, 10) : 0;
      if (u && width > bestW) { bestW = width; best = u; }
    }
    if (bestW > 0) return best;
  }
  // 2) Sonst die WordPress-Größenendung (-768x1024) entfernen → Original
  //    ('-scaled' bleibt, das ist bereits die größte gespeicherte Variante)
  return src.replace(/-\d+x\d+(\.[a-z]+)(\?.*)?$/i, '$1$2');
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

  const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    throw new Error(`vw-events ${res.status} ${res.statusText} (${url.pathname})`);
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
  const endDate = ev.end ? new Date(ev.end) : undefined;

  // Nur Börnichen-relevante Events: „boernichen"-markiert ODER ohne Ortsteil.
  // Events eines reinen Grünhainichen-Ortsteils fallen raus.
  const standort = new Set(ev.standort ?? []);
  const grhOrtsteil = pickOrtsteilFromSlugs(ev.standort);
  if (grhOrtsteil && !standort.has('boernichen')) return null;
  const ortsteil = undefined;
  const location = ev.location?.name || ev.location?.address || '';

  return {
    slug: ev.slug,
    title: decodeEntities(ev.title),
    startDate,
    endDate: endDate && !Number.isNaN(endDate.valueOf()) ? endDate : undefined,
    location,
    ortsteil,
    teaser: decodeEntities(stripHtml(ev.description_html)).trim().slice(0, 180),
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

function decodeEntities(html: string): string {
  return html
    // Zuerst doppelte Kodierung auflösen (&amp;#8222; → &#8222;)
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&nbsp;/g, ' ')
    .replace(/&hellip;/g, '…')
    .replace(/&ndash;/g, '–')
    .replace(/&mdash;/g, '—')
    // Generisch: alle numerischen Entities (dezimal + hex), z. B. &#8222; → „
    .replace(/&#x([0-9a-fA-F]+);/g, (_, h) => String.fromCodePoint(parseInt(h, 16)))
    .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(parseInt(n, 10)));
}

/* ----------------------------------------------------------------
 * Amtsblätter — CPT `amtsblatt_download` vom Master vv-wildenstein.com.
 * Das PDF jeder Ausgabe hängt als Medien-Anhang (media?parent=ID).
 * Die Taxonomie `downloadkategorie` trennt Monatsausgaben von Infos
 * (Term 189 = „amtsblatt-informationen": Anzeigenpreise + Terminplan).
 * ---------------------------------------------------------------- */
const AMTSBLATT_BASE =
  (import.meta.env.PUBLIC_AMTSBLATT_API_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_AMTSBLATT_API_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/wp/v2';

const AMTSBLATT_INFO_CAT = 189; // downloadkategorie „amtsblatt-informationen"

interface WPAmtsblatt {
  id: number;
  title: { rendered: string };
  date: string;
  link: string;
  downloadkategorie?: number[];
}

export async function fetchAmtsblaetter(): Promise<AmtsblattItem[]> {
  // 1. Alle CPT-Einträge laden (paginiert).
  const posts: WPAmtsblatt[] = [];
  let page = 1;
  let totalPages = 1;
  const MAX_PAGES = 10;
  do {
    const url = new URL(`${AMTSBLATT_BASE}/amtsblatt_download`);
    url.searchParams.set('per_page', '100');
    url.searchParams.set('page', String(page));
    url.searchParams.set('orderby', 'date');
    url.searchParams.set('order', 'desc');
    url.searchParams.set('_fields', 'id,title,date,link,downloadkategorie');
    const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`amtsblatt ${res.status} ${res.statusText}`);
    totalPages = parseInt(res.headers.get('X-WP-TotalPages') ?? '1', 10) || 1;
    posts.push(...((await res.json()) as WPAmtsblatt[]));
    page++;
  } while (page <= totalPages && page <= MAX_PAGES);

  // 2. PDF je Eintrag holen (media?parent=ID) — parallel in Blöcken.
  const pdfByParent = new Map<number, string>();
  const ids = posts.map((p) => p.id);
  const CHUNK = 8;
  for (let i = 0; i < ids.length; i += CHUNK) {
    const slice = ids.slice(i, i + CHUNK);
    await Promise.all(
      slice.map(async (id) => {
        try {
          const mUrl = `${AMTSBLATT_BASE}/media?parent=${id}&per_page=10&_fields=parent,source_url,mime_type`;
          const r = await fetch(mUrl, { headers: { Accept: 'application/json' } });
          if (!r.ok) return;
          const media = (await r.json()) as Array<{ source_url?: string; mime_type?: string }>;
          const pdf = media.find((m) => m.mime_type === 'application/pdf') ?? media[0];
          if (pdf?.source_url) pdfByParent.set(id, pdf.source_url);
        } catch {
          /* einzelner Fehlschlag ignorieren */
        }
      }),
    );
  }

  return posts
    .map((p) => ({
      id: p.id,
      title: decodeEntities(p.title.rendered),
      date: new Date(p.date),
      pdfUrl: pdfByParent.get(p.id) ?? null,
      link: p.link,
      isInfo: Array.isArray(p.downloadkategorie) && p.downloadkategorie.includes(AMTSBLATT_INFO_CAT),
    }))
    .sort((a, b) => b.date.valueOf() - a.date.valueOf());
}

/* ----------------------------------------------------------------
 * Profile & Tourismus — CPTs vom Master, Börnichen-gefiltert.
 * `gemeindeteil` 175 = Börnichen. Kontaktfelder via `vv_kontakt`
 * (mu-Plugin vv-rest-profilfelder). content.rendered ist sauberes Prosa-HTML.
 * ---------------------------------------------------------------- */
const GEMEINDETEIL_BOERNICHEN = 175;

interface WPCpt {
  id: number;
  slug: string;
  link: string;
  title: { rendered: string };
  content?: { rendered: string };
  profilkategorie?: number[];
  vv_kontakt?: CptKontakt;
  featured_media?: number;
  _embedded?: WPPost['_embedded'];
}

function mapCpt(p: WPCpt): CptEntry {
  return {
    cptSlug: p.slug,
    title: decodeEntities(p.title?.rendered ?? ''),
    contentHtml: p.content?.rendered ?? '',
    image: pickFeaturedImage(p as unknown as WPPost),
    kontakt: p.vv_kontakt ?? {},
    kategorie: Array.isArray(p.profilkategorie) ? p.profilkategorie : [],
    link: p.link,
  };
}

async function fetchCptList(restBase: string, params: Record<string, string>): Promise<WPCpt[]> {
  const url = new URL(`${WP_BASE}/${restBase}`);
  url.searchParams.set('per_page', '100');
  url.searchParams.set('_embed', 'wp:featuredmedia');
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  const res = await fetch(url.toString(), { headers: { Accept: 'application/json', ...buildAuthHeader() } });
  if (!res.ok) throw new Error(`${restBase} ${res.status} ${res.statusText}`);
  return (await res.json()) as WPCpt[];
}

export async function fetchProfiles(): Promise<CptEntry[]> {
  const list = await fetchCptList('profile', { gemeindeteil: String(GEMEINDETEIL_BOERNICHEN) });
  return list.map(mapCpt);
}

export async function fetchTourismus(): Promise<CptEntry[]> {
  const list = await fetchCptList('tourismus', { gemeindeteil: String(GEMEINDETEIL_BOERNICHEN) });
  // Freibad Borstendorf zusätzlich aufnehmen (anderer Ortsteil, aber als Ausflugsziel gelistet)
  try {
    const fb = await fetchCptList('tourismus', { slug: 'freibad-borstendorf' });
    if (fb[0] && !list.some((x) => x.slug === fb[0].slug)) list.push(fb[0]);
  } catch {
    /* Freibad optional */
  }
  return list.map(mapCpt);
}
