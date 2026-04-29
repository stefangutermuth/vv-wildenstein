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

import type { NewsItem, NewsCategory, Ortsteil } from './cms';

const WP_BASE =
  (import.meta.env.PUBLIC_WP_API_BASE as string | undefined) ??
  'https://vv-wildenstein.com/wp-json/wp/v2';

/**
 * Build-Time Basic-Auth (für die Media-API, die in dieser Installation
 * für anonyme Aufrufer gesperrt ist). Werte kommen aus Server-Env-Variablen,
 * die NICHT mit PUBLIC_ präfixiert sind und damit nie ans Frontend gelangen.
 */
const WP_AUTH_USER = (import.meta.env.WP_AUTH_USER as string | undefined) ?? '';
const WP_AUTH_PASS = (import.meta.env.WP_AUTH_PASS as string | undefined) ?? '';

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
  const url = new URL(`${WP_BASE}/posts`);
  url.searchParams.set('per_page', String(PER_PAGE));
  url.searchParams.set('_embed', 'wp:featuredmedia,wp:term');
  url.searchParams.set('orderby', 'date');
  url.searchParams.set('order', 'desc');

  const res = await fetch(url.toString(), {
    headers: {
      Accept: 'application/json',
      ...buildAuthHeader(),
    },
  });
  if (!res.ok) {
    throw new Error(`WP REST ${res.status} ${res.statusText} (${url.pathname})`);
  }
  const posts = (await res.json()) as WPPost[];

  return posts
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
  const excerpt = stripHtml(p.excerpt.rendered).trim();

  return {
    slug: p.slug,
    title,
    date: new Date(p.date),
    category,
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
  const candidate =
    sizes['medium_large']?.source_url ??
    sizes['large']?.source_url ??
    sizes['medium']?.source_url ??
    media.source_url;
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

function decodeEntities(html: string): string {
  return html
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&#8211;/g, '–')
    .replace(/&#8212;/g, '—')
    .replace(/&#8216;/g, '‘')
    .replace(/&#8217;/g, '’')
    .replace(/&#8220;/g, '“')
    .replace(/&#8221;/g, '”')
    .replace(/&hellip;/g, '…');
}
