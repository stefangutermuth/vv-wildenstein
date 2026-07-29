/**
 * WordPress-REST-Adapter für den Verwaltungsverband Wildenstein.
 *
 * Strategie (Verbandssicht = Klammer über alle Mitgliedsgemeinden):
 *   - Lädt die neuesten Posts der zentralen Installation
 *   - Filtert NICHT auf einen Ortsteil (im Gegensatz zum Grünhainichen-Frontend)
 *   - Mappt WP-Term-Slugs auf das interne Verbands-Schema (Bekanntmachung,
 *     Veranstaltung, Sperrung, Ausschreibung)
 *   - Ergänzt Featured-Image aus der embedded `wp:featuredmedia`
 *
 * REST-Base wird über PUBLIC_WP_API_BASE überschrieben (wichtig, sobald das
 * zentrale WordPress auf eine eigene Subdomain umzieht). Default = Live.
 */

import type { NewsItem, NewsCategory, EventItem } from './cms';

const WP_BASE =
  (import.meta.env.PUBLIC_WP_API_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_WP_API_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/wp/v2';

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

/** Mapping WP-Term-Slug → Verbands-NewsCategory. Erste Übereinstimmung gewinnt. */
const CATEGORY_RULES: Array<{ match: RegExp; cat: NewsCategory }> = [
  { match: /^sperrung/i,                          cat: 'sperrung'      },
  { match: /ausschreibung/i,                      cat: 'ausschreibung' },
  { match: /^(kultur|tourismus|veranstaltung)/i,  cat: 'veranstaltung' },
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

const PLACEHOLDER_PATTERNS = [/platzhalter/i, /placeholder/i, /beitrag_platzhalter/i];
function isPlaceholderUrl(url: string): boolean {
  return PLACEHOLDER_PATTERNS.some((p) => p.test(url));
}

/**
 * Alle Einträge eines Endpunkts über beliebig viele Seiten holen (per_page=100).
 * Wichtig, damit Detailseiten-Bau und Link-Umschreibung denselben Umfang sehen
 * (sonst zeigen umgeschriebene /neuigkeiten/{slug}-Links auf ungebaute Seiten).
 */
async function fetchAllPages<T>(restBase: string, params: Record<string, string> = {}): Promise<T[]> {
  const out: T[] = [];
  for (let page = 1; page <= 25; page++) {
    const url = new URL(`${WP_BASE}/${restBase}`);
    url.searchParams.set('per_page', '100');
    url.searchParams.set('page', String(page));
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
    });
    if (!res.ok) {
      if (page > 1) break; // keine weitere Seite mehr
      throw new Error(`WP REST ${restBase} ${res.status} ${res.statusText}`);
    }
    const batch = (await res.json()) as T[];
    out.push(...batch);
    if (batch.length < 100) break;
  }
  return out;
}

export async function fetchWordPressNews(): Promise<NewsItem[]> {
  const posts = (await fetchAllPages<WPPost>('posts', {
    _embed: 'wp:featuredmedia,wp:term',
    orderby: 'date',
    order: 'desc',
    // Slugs mit Prozent-Kodierung (z. B. „%c2%a7" = §) brechen Astros
    // statisches [slug]-Routing — solche (seltenen) alten Posts überspringen.
  })).filter((p) => !p.slug.includes('%'));
  const postSlugs = await ensurePostSlugs();
  return posts.map((p) => mapWPPostToNewsItem(p, postSlugs)).filter((n): n is NewsItem => n !== null);
}

function mapWPPostToNewsItem(p: WPPost, postSlugs: Set<string>): NewsItem | null {
  const termSlugs = collectTermSlugs(p);
  const category = pickCategory(termSlugs);
  const image = pickFeaturedImage(p) ?? pickInlineImage(p.content?.rendered ?? '');

  return {
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    date: new Date(p.date),
    category,
    image,
    excerpt: stripHtml(p.excerpt.rendered).trim(),
    featured: p.sticky ?? false,
    // Interne Detailseite statt Link auf die alte WP-Ansicht
    href: `/neuigkeiten/${p.slug}`,
    contentHtml: rewriteContentUrls(p.content?.rendered ?? '', postSlugs),
  };
}

function collectTermSlugs(p: WPPost): Set<string> {
  const out = new Set<string>();
  for (const grp of p._embedded?.['wp:term'] ?? []) {
    for (const t of grp) out.add(t.slug);
  }
  return out;
}

function pickCategory(slugs: Set<string>): NewsCategory {
  for (const s of slugs) {
    for (const rule of CATEGORY_RULES) {
      if (rule.match.test(s)) return rule.cat;
    }
  }
  return 'bekanntmachung';
}

function pickFeaturedImage(p: WPPost): string | undefined {
  const media = p._embedded?.['wp:featuredmedia']?.[0];
  if (!media) return undefined;
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
  const regex = /<img[^>]+src=["']([^"']+)["'][^>]*>/gi;
  let m: RegExpExecArray | null;
  while ((m = regex.exec(html)) !== null) {
    if (!isPlaceholderUrl(m[1])) return m[1];
  }
  return undefined;
}

function stripHtml(html: string): string {
  // Tags entfernen, HTML-Entities dekodieren (Auszüge/Teaser zeigten sonst
  // Roh-Codes wie &#8222; / &amp;), Whitespace normalisieren.
  const text = decodeEntities(html.replace(/<[^>]*>/g, ' '));
  return text.replace(/\s+/g, ' ').trim();
}

/**
 * Beitrags-Slugs (für das Link-Mapping /{slug}/ → /neuigkeiten/{slug}).
 * Einmal pro Build geladen, von allen Fetchern geteilt.
 */
let postSlugsPromise: Promise<Set<string>> | null = null;
function ensurePostSlugs(): Promise<Set<string>> {
  if (!postSlugsPromise) {
    postSlugsPromise = (async () => {
      try {
        // Denselben (vollständigen) Umfang wie fetchWordPressNews — nur so
        // zeigen umgeschriebene Links immer auf tatsächlich gebaute Seiten.
        const posts = await fetchAllPages<{ slug: string }>('posts', { _fields: 'slug' });
        return new Set(posts.map((p) => p.slug).filter((s) => !s.includes('%')));
      } catch {
        return new Set<string>();
      }
    })();
  }
  return postSlugsPromise;
}

/** Links auf Seiten, die wir bewusst nicht generieren → sinnvolle Ziele. */
const LINK_REMAP: Record<string, string> = {
  'maengel-melder': 'https://melder.vv-wildenstein.com',
  'cookie-policy-eu': '/datenschutzerklaerung',
  'lebendigen-adventskalender-2': '/lebendigen-adventskalender',
  '5-creative-2': '/',
  'test-formular': '/',
};

/**
 * URLs im WP-Content umschreiben:
 *  1) /wp-content (Bilder, PDFs) → immer absolut auf den aktuellen WP-Host
 *     (Day-X-sicher: folgt PUBLIC_WP_API_BASE automatisch).
 *  2) Alle anderen internen Links → RELATIV, damit Besucher auf der neuen
 *     Seite bleiben statt zur alten Live-Site zu springen. Beitrags-Links
 *     ({slug} in postSlugs) landen auf unserer Detailseite /neuigkeiten/….
 */
/**
 * Bild-Basisname ohne WP-Größen-Suffix (-1000x750, -scaled) — für Dedup.
 */
function imageBaseName(src: string): string {
  // Voller Pfad (ohne Host/Query) minus Größen-Suffix — so kollidieren
  // gleichnamige Bilder aus verschiedenen /uploads/JJJJ/MM/-Ordnern NICHT.
  return src
    .replace(/^https?:\/\/[^/]+/i, '')
    .replace(/[?#].*$/, '')
    .replace(/-\d+x\d+(\.\w+)$/i, '$1')
    .replace(/-scaled(\.\w+)$/i, '$1')
    .toLowerCase();
}

/**
 * Impreza/WPBakery-Galerien (RoyalSlider) für die statische Auslieferung
 * aufräumen: Jeder Slide liefert im `<a class="rsImg" href>` das große
 * Originalbild samt Maßen — daraus bauen wir schlanke, SCHARFE <figure>-Bilder
 * statt der 150×150-Thumbnails. Anschließend werden über den GESAMTEN Inhalt
 * doppelte Bilder (gleiche Basisdatei) entfernt — im Original stehen die
 * Galerie-Aufmacher oft zusätzlich als Einzelbild im Text.
 */
function normalizeGalleries(html: string): string {
  // 1) RoyalSlider-Slides → saubere große Bilder
  let out = html.replace(/<div class="rsContent">[\s\S]*?<\/div>/g, (slide) => {
    const href = slide.match(/<a[^>]*class="rsImg"[^>]*href="([^"]+)"/i);
    if (!href) return '';
    const w = slide.match(/data-rsw="(\d+)"/i);
    const h = slide.match(/data-rsh="(\d+)"/i);
    const dim = w && h ? ` width="${w[1]}" height="${h[1]}"` : '';
    return `<figure class="vv-gal-item"><img class="vv-gal-img" src="${href[1]}"${dim} loading="lazy" decoding="async" alt=""></figure>`;
  });

  // 2) Duplikate über den gesamten Inhalt entfernen (erste Instanz gewinnt)
  const seen = new Set<string>();
  out = out.replace(/<img\b[^>]*>/gi, (tag) => {
    const src = tag.match(/\bsrc="([^"]+)"/i);
    if (!src) return tag;
    if (/wpdm|file-type-icons/i.test(src[1])) return tag; // Datei-Icons behalten
    const base = imageBaseName(src[1]);
    if (seen.has(base)) return ''; // Duplikat raus
    seen.add(base);
    return tag;
  });
  // 3) Galerie-Wrapper, deren Bild dedupliziert wurde, komplett entfernen
  out = out.replace(/<figure class="vv-gal-item">\s*<\/figure>/g, '');
  return out;
}

function rewriteContentUrls(html: string, postSlugs: Set<string>): string {
  const wpHost = WP_BASE.replace(/\/wp-json.*$/, '');
  let out = normalizeGalleries(html);
  // Nicht aufgelöste WPBakery-Shortcodes, die als Roh-Text durchrutschen:
  //  - [vc_raw_html]…base64…[/vc_raw_html] / [vc_raw_js] (meist Redirect-/Script-Stubs)
  //  - verwaiste [vc_*]/[/vc_*]-Tags
  out = out.replace(/\[vc_raw_(?:html|js)\][\s\S]*?\[\/vc_raw_(?:html|js)\]/gi, '');
  out = out.replace(/\[\/?vc_[a-z_]*[^\]]*\]/gi, '');
  // Die echte Seiten-h1 liefert der Header — h1 im WP-Body → h2 (keine doppelte h1)
  out = out.replace(/<(\/?)h1(\s|>)/gi, '<$1h2$2');
  out = out.replace(/https?:\/\/(?:www\.)?vv-wildenstein\.com\/wp-content/g, `${wpHost}/wp-content`);
  out = out.replace(
    /href="https?:\/\/(?:www\.)?vv-wildenstein\.com(\/[^"]*)?"/g,
    (match, rawPath?: string) => {
      const path = rawPath ?? '/';
      // wp-content/wp-json/wp-login/wp-admin niemals relativieren
      if (/^\/wp-/.test(path)) return match;
      // Prozent-kodierte Pfade (z. B. §-Slugs) bauen wir nicht → absolut lassen
      if (path.includes('%')) return match;
      // erster Pfad-Teil ohne Slashes/Anker/Query
      const first = path.replace(/^\//, '').split(/[/?#]/)[0];
      if (first && LINK_REMAP[first] !== undefined) return `href="${LINK_REMAP[first]}"`;
      // Beitrags-Permalinks (/{slug}/) → interne News-Detailseite
      if (first && postSlugs.has(first)) return `href="/neuigkeiten/${first}"`;
      return `href="${path}"`;
    },
  );
  return out;
}

/* ----------------------------------------------------------------
 * Seiten — die kompletten Inhaltsseiten des Verbands.
 * Quelle der Wahrheit bleibt WordPress: Redaktion pflegt im Backend,
 * jeder Build zieht den aktuellen Stand (Auto-Deploy via Webhook/Zeitplan).
 * ---------------------------------------------------------------- */

export interface WPPageItem {
  id: number;
  slug: string;
  /** Hierarchischer Pfad wie im Original (URL-Parität für den Day-X-Umzug) */
  path: string;
  title: string;
  contentHtml: string;
  breadcrumb: Array<{ title: string; path: string }>;
  /** WP-Eltern-ID (0 = keine) — für die Kind-Seiten-Kachelgitter der Hubs */
  parentId: number;
  /** True, wenn die Seite ein Impreza-`us_grid`-Portalgitter enthielt
   *  (das wir entfernt haben) → Signal, ein natives Kachelgitter zu rendern. */
  hadHubGrid: boolean;
}

/**
 * Impreza-`us_grid`-Portalgitter aus dem Content entfernen.
 * Diese dynamischen Gitter überstehen den statischen REST-Schnappschuss nicht:
 * Die Abfrage fällt oft auf einen generischen „letzte Beiträge"-Satz zurück
 * (Amtsblatt-PDFs statt Tourismus) und ohne Impreza-CSS werden bildlose
 * Kacheln zu riesigen Leerkästen. Wir ersetzen sie durch native Kachelgitter
 * (siehe VvHubGrid), die aus den echten Daten gespeist werden.
 * Entfernt den kompletten, balancierten <div class="…us_grid…">-Block.
 */
function stripUsGrids(html: string): { html: string; had: boolean } {
  let out = html;
  let had = false;
  for (let guard = 0; guard < 50; guard++) {
    const start = /<div\b[^>]*\bus_grid\b[^>]*>/i.exec(out);
    if (!start) break;
    had = true;
    // Ab dem us_grid-Start die <div>-Tiefe zählen, bis der Block balanciert ist.
    const tag = /<\/?div\b[^>]*>/gi;
    tag.lastIndex = start.index;
    let depth = 0;
    let end = -1;
    let m: RegExpExecArray | null;
    while ((m = tag.exec(out)) !== null) {
      depth += m[0][1] === '/' ? -1 : 1;
      if (depth === 0) {
        end = m.index + m[0].length;
        break;
      }
    }
    if (end === -1) break; // unbalanciert → abbrechen, Rest unangetastet lassen
    out = out.slice(0, start.index) + out.slice(end);
  }
  return { html: out, had };
}

interface WPPageRaw {
  id: number;
  slug: string;
  parent: number;
  title: { rendered: string };
  content: { rendered: string };
}

/** Seiten, die im neuen Frontend NICHT generiert werden. */
const EXCLUDED_PAGE_SLUGS = new Set([
  '5-creative-2',                    // alte Impreza-Startseite (haben eine eigene)
  'verwaltungsverband-wildenstein-2', // Entwurfs-Kopie der Startseite
  'test-formular',                   // Testseite
  'lebendigen-adventskalender-2',    // "VORSCHAU"-Duplikat
  'cookie-policy-eu',                // Complianz-Seite (statische Site setzt keine Cookies)
  'maengel-melder',                  // ersetzt durch melder.vv-wildenstein.com
]);

export async function fetchWordPressPages(): Promise<WPPageItem[]> {
  const url = new URL(`${WP_BASE}/pages`);
  url.searchParams.set('per_page', '100');
  url.searchParams.set('_fields', 'id,slug,parent,title,content');

  const res = await fetch(url.toString(), {
    headers: { Accept: 'application/json', ...buildAuthHeader() },
  });
  if (!res.ok) {
    // Bewusst hart scheitern: lieber Build-Abbruch (alter Stand bleibt live)
    // als ein Deployment ohne Inhaltsseiten.
    throw new Error(`WP REST pages ${res.status} ${res.statusText}`);
  }
  const raw = (await res.json()) as WPPageRaw[];
  const postSlugs = await ensurePostSlugs();
  const byId = new Map(raw.map((p) => [p.id, p]));

  function chain(p: WPPageRaw): WPPageRaw[] {
    const out: WPPageRaw[] = [p];
    let cur = p;
    while (cur.parent) {
      const parent = byId.get(cur.parent);
      if (!parent) break;
      out.unshift(parent);
      cur = parent;
    }
    return out;
  }

  return raw
    .filter((p) => !EXCLUDED_PAGE_SLUGS.has(p.slug))
    .map((p) => {
      const parents = chain(p);
      const path = '/' + parents.map((x) => x.slug).join('/');
      const stripped = stripUsGrids(p.content.rendered);
      return {
        id: p.id,
        slug: p.slug,
        path,
        parentId: p.parent,
        hadHubGrid: stripped.had,
        title: decodeEntities(stripHtml(p.title.rendered).trim()),
        contentHtml: rewriteContentUrls(stripped.html, postSlugs),
        breadcrumb: parents.slice(0, -1).map((x) => ({
          title: decodeEntities(stripHtml(x.title.rendered).trim()),
          path: '/' + chain(x).map((y) => y.slug).join('/'),
        })),
      };
    });
}

/* ----------------------------------------------------------------
 * Custom Post Types — Ämter, Tourismus-Einträge, Vereine, Profile.
 * Flache Permalinks: /{prefix}/{slug}/ (wie im Original-WordPress).
 * ---------------------------------------------------------------- */

const CPT_SOURCES: Array<{
  restBase: string;
  pathPrefix: string;
  crumb: { title: string; path: string };
}> = [
  { restBase: 'amter',     pathPrefix: 'amter',     crumb: { title: 'Verwaltung',       path: '/verwaltung' } },
  { restBase: 'tourismus', pathPrefix: 'tourismus', crumb: { title: 'Tourismus',        path: '/tourismus_uebersicht' } },
  { restBase: 'verein',    pathPrefix: 'verein',    crumb: { title: 'Leben & Freizeit', path: '/leben-freizeit' } },
  { restBase: 'profile',   pathPrefix: 'profile',   crumb: { title: 'Wirtschaft',       path: '/wirtschaft' } },
];

export async function fetchWordPressCptPages(): Promise<WPPageItem[]> {
  const out: WPPageItem[] = [];
  const postSlugs = await ensurePostSlugs();
  for (const src of CPT_SOURCES) {
    // Bis zu 2 Seiten à 100 — deckt alle aktuellen Bestände (max 60)
    for (let pageNo = 1; pageNo <= 2; pageNo++) {
      const url = new URL(`${WP_BASE}/${src.restBase}`);
      url.searchParams.set('per_page', '100');
      url.searchParams.set('page', String(pageNo));
      url.searchParams.set('_fields', 'id,slug,title,content');
      const res = await fetch(url.toString(), {
        headers: { Accept: 'application/json', ...buildAuthHeader() },
      });
      if (!res.ok) {
        if (pageNo > 1) break; // keine weitere Seite vorhanden
        throw new Error(`WP REST ${src.restBase} ${res.status} ${res.statusText}`);
      }
      const raw = (await res.json()) as WPPageRaw[];
      for (const p of raw) {
        const stripped = stripUsGrids(p.content?.rendered ?? '');
        out.push({
          id: p.id,
          slug: p.slug,
          path: `/${src.pathPrefix}/${p.slug}`,
          parentId: 0,
          hadHubGrid: stripped.had,
          title: decodeEntities(stripHtml(p.title.rendered).trim()),
          contentHtml: rewriteContentUrls(stripped.html, postSlugs),
          breadcrumb: [src.crumb],
        });
      }
      if (raw.length < 100) break;
    }
  }
  return out;
}

/* ----------------------------------------------------------------
 * Hub-Kacheln — echte Datenquellen für die Übersichtsseiten, die
 * die entfernten Impreza-`us_grid`-Portalgitter ersetzen.
 * ---------------------------------------------------------------- */

export interface HubTile {
  title: string;
  href: string;
  image?: string;
  /** Tourismus-Kategorien (tourismus_kat-Slugs) — nur bei Tourismus-Einträgen */
  kats?: string[];
}

interface WPCptEmbed {
  slug: string;
  title: { rendered: string };
  _embedded?: {
    'wp:featuredmedia'?: Array<{
      source_url?: string;
      mime_type?: string;
      media_details?: { sizes?: Record<string, { source_url: string }> };
    }>;
    'wp:term'?: Array<Array<{ slug: string; taxonomy: string }>>;
  };
}

function cptImage(item: WPCptEmbed): string | undefined {
  const media = item._embedded?.['wp:featuredmedia']?.[0];
  if (!media || !media.source_url) return undefined;
  if (media.mime_type && !media.mime_type.startsWith('image/')) return undefined;
  const sizes = media.media_details?.sizes ?? {};
  const url =
    sizes['medium_large']?.source_url ??
    sizes['large']?.source_url ??
    sizes['medium']?.source_url ??
    media.source_url;
  if (isPlaceholderUrl(url)) return undefined;
  const wpHost = WP_BASE.replace(/\/wp-json.*$/, '');
  return url.replace(/https?:\/\/(?:www\.)?vv-wildenstein\.com/g, wpHost);
}

async function fetchCptTiles(
  restBase: string,
  pathPrefix: string,
  withTerms = false,
): Promise<HubTile[]> {
  const url = new URL(`${WP_BASE}/${restBase}`);
  url.searchParams.set('per_page', '100');
  url.searchParams.set('_embed', withTerms ? 'wp:featuredmedia,wp:term' : 'wp:featuredmedia');
  const res = await fetch(url.toString(), {
    headers: { Accept: 'application/json', ...buildAuthHeader() },
  });
  if (!res.ok) return [];
  const raw = (await res.json()) as WPCptEmbed[];
  const tiles = raw.map((item) => {
    const kats = withTerms
      ? (item._embedded?.['wp:term'] ?? [])
          .flat()
          .filter((t) => t.taxonomy === 'tourismus_kat')
          .map((t) => t.slug)
      : undefined;
    return {
      title: decodeEntities(stripHtml(item.title.rendered).trim()),
      href: `/${pathPrefix}/${item.slug}`,
      image: cptImage(item),
      kats,
    } as HubTile;
  });
  // Nach Titel deduplizieren (WP hat „…-2"-Dubletten) — Variante MIT Bild gewinnt.
  const byTitle = new Map<string, HubTile>();
  for (const t of tiles) {
    const key = t.title.toLowerCase();
    const existing = byTitle.get(key);
    if (!existing || (!existing.image && t.image)) byTitle.set(key, t);
  }
  return [...byTitle.values()];
}

export interface HubSources {
  amter: HubTile[];
  profile: HubTile[];
  tourismus: HubTile[];
}

/** Einmal pro Build alle CPT-Kachelquellen für die Hub-Seiten laden. */
export async function fetchHubSources(): Promise<HubSources> {
  const [amter, profile, tourismus] = await Promise.all([
    fetchCptTiles('amter', 'amter'),
    fetchCptTiles('profile', 'profile'),
    fetchCptTiles('tourismus', 'tourismus', true),
  ]);
  return { amter, profile, tourismus };
}

/* ----------------------------------------------------------------
 * Events — vw-events Plugin (Namespace vw-events/v1)
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
  const fromDate = new Date();
  fromDate.setDate(fromDate.getDate() - 14);
  url.searchParams.set('from', fromDate.toISOString().slice(0, 19));

  const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    throw new Error(`vw-events ${res.status} ${res.statusText} (${url.pathname})`);
  }
  const events = (await res.json()) as VWEvent[];
  const postSlugs = await ensurePostSlugs();

  const now = new Date();
  return events
    .map((ev) => mapVWEvent(ev, postSlugs))
    .filter((e): e is EventItem => e !== null)
    .filter((e) => (e.endDate ?? e.startDate).valueOf() >= now.valueOf())
    .sort((a, b) => a.startDate.valueOf() - b.startDate.valueOf());
}

function mapVWEvent(ev: VWEvent, postSlugs: Set<string>): EventItem | null {
  if (!ev.start) return null;
  const startDate = new Date(ev.start);
  if (Number.isNaN(startDate.valueOf())) return null;
  const endDate = ev.end ? new Date(ev.end) : undefined;

  return {
    slug: ev.slug,
    title: decodeEntities(ev.title),
    startDate,
    endDate: endDate && !Number.isNaN(endDate.valueOf()) ? endDate : undefined,
    location: ev.location?.name || ev.location?.address || '',
    teaser: stripHtml(ev.description_html).trim().slice(0, 180),
    featured: false,
    image: ev.image?.url,
    // Interne Detailseite (gleicher Pfad wie das Original-Permalink)
    href: `/veranstaltungen/${ev.slug}`,
    contentHtml: rewriteContentUrls(ev.description_html ?? '', postSlugs),
    organizer: ev.organizer?.name || undefined,
    allDay: ev.all_day || undefined,
  };
}

function decodeEntities(html: string): string {
  return html
    // numerische Entities generisch (deckt „ – — ‚ ' " … usw. ab)
    .replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)))
    .replace(/&#x([0-9a-fA-F]+);/g, (_, code) => String.fromCodePoint(parseInt(code, 16)))
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&hellip;/g, '…')
    .replace(/&nbsp;/g, ' ');
}
