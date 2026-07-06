/**
 * CMS-Adapter — Custom Post Types (CPTs) von vv-wildenstein.com
 *
 * Liefert Vereine, Tourismus, Gewerbe-Profile, Personen, Ämter, Sitzungen,
 * Amtsblätter. Alle Loader filtern automatisch auf die für Grünhainichen
 * relevanten Ortsteile (gruenhainichen, borstendorf, waldkirchen).
 *
 * Fehler werden geschluckt → leeres Array zurückgegeben. Die UI rendert dann
 * einen leeren-Zustand-Hinweis. So bricht ein Build nie wegen WP-Aussetzer.
 */

import type { Ortsteil } from './cms';

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

function authHeader(): Record<string, string> {
  if (!WP_AUTH_USER || !WP_AUTH_PASS) return {};
  const tok = Buffer.from(`${WP_AUTH_USER}:${WP_AUTH_PASS}`).toString('base64');
  return { Authorization: `Basic ${tok}` };
}

const PER_PAGE = 100;
const GRH_ORTSTEILE = new Set<string>(['gruenhainichen', 'borstendorf', 'waldkirchen']);

/* ============================================================
 * Generischer Fetch + Helfer
 * ============================================================ */

interface WPTerm { id: number; slug: string; name: string; taxonomy: string }

interface WPMedia {
  source_url?: string;
  mime_type?: string;
  alt_text?: string;
  media_details?: { sizes?: Record<string, { source_url: string; width: number }> };
}

interface WPCPTBase {
  id: number;
  slug: string;
  link: string;
  date: string;
  modified: string;
  title:   { rendered: string };
  excerpt?: { rendered: string };
  content?: { rendered: string };
  featured_media: number;
  _embedded?: {
    'wp:featuredmedia'?: WPMedia[];
    'wp:term'?: WPTerm[][];
    'wp:attachment'?: WPMedia[];
  };
  // benutzerdefinierte Felder, die wir sehen
  vv_kontakt?: VVKontakt;
  gemeindeteil?: number[];
  profilkategorie?: number[];
  tourismus_kat?: number[];
  downloadkategorie?: number[];
  gremien?: number[];
}

export interface VVKontakt {
  fuehrende_person?: string;
  strasse_hausnummer?: string;
  plz_ort?: string;
  telefon?: string;
  email?: string;
  website?: string;
}

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

async function fetchJson<T>(endpoint: string, params: Record<string, string> = {}): Promise<T[]> {
  const url = new URL(`${WP_BASE}/${endpoint}`);
  url.searchParams.set('per_page', String(PER_PAGE));
  url.searchParams.set('_embed', 'wp:featuredmedia,wp:term,wp:attachment');
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  try {
    const res = await fetchWithTimeout(url.toString(), { headers: { Accept: 'application/json', ...authHeader() } });
    if (!res.ok) {
      console.warn(`[cms-cpt] ${endpoint} → ${res.status}`);
      return [];
    }
    return (await res.json()) as T[];
  } catch (err) {
    console.warn(`[cms-cpt] ${endpoint} fetch failed:`, err);
    return [];
  }
}

function termSlugs(p: WPCPTBase, taxonomy?: string): string[] {
  const out: string[] = [];
  for (const grp of p._embedded?.['wp:term'] ?? []) {
    for (const t of grp) {
      if (!taxonomy || t.taxonomy === taxonomy) out.push(t.slug);
    }
  }
  return out;
}

function pickOrtsteil(slugs: string[]): Ortsteil | undefined {
  for (const s of slugs) {
    if (GRH_ORTSTEILE.has(s)) return s as Ortsteil;
    if (s.startsWith('waldkirchen')) return 'waldkirchen';
  }
  return undefined;
}

function hasGrhOrtsteil(p: WPCPTBase): boolean {
  const slugs = termSlugs(p, 'gemeindeteil');
  if (slugs.length === 0) return true; // ohne Zuordnung → universal
  return slugs.some((s) => GRH_ORTSTEILE.has(s) || s.startsWith('waldkirchen'));
}

function pickImage(p: WPCPTBase): string | undefined {
  const media = p._embedded?.['wp:featuredmedia']?.[0];
  if (!media || (media.mime_type && !media.mime_type.startsWith('image/'))) return undefined;
  const sizes = media.media_details?.sizes ?? {};
  return (
    sizes['medium_large']?.source_url ??
    sizes['large']?.source_url ??
    sizes['medium']?.source_url ??
    media.source_url
  );
}

const NAMED_ENTITIES: Record<string, string> = {
  amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ',
  hellip: '…', laquo: '«', raquo: '»',
  ndash: '–', mdash: '—',
  lsquo: '‘', rsquo: '’', ldquo: '“', rdquo: '”', bdquo: '„', sbquo: '‚',
};

/**
 * Dekodiert HTML-Entities — sowohl benannte (`&amp;`, `&hellip;`) als auch
 * numerische (`&#8222;`, `&#x201E;`). Spiegelt die Implementierung in
 * cms-wordpress.ts; bei Änderungen dort bitte angleichen.
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

function stripHtml(html: string): string {
  return html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
}

/* ============================================================
 * Tourismus
 * ============================================================ */

export interface TourismItem {
  slug: string;
  title: string;
  excerpt: string;
  contentHtml: string;
  image?: string;
  ortsteil?: Ortsteil;
  kategorien: string[];
  kontakt?: VVKontakt;
  link: string;
}

export async function getTourism(): Promise<TourismItem[]> {
  const data = await fetchJson<WPCPTBase>('tourismus');
  return data.filter(hasGrhOrtsteil).map((p) => ({
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    excerpt: stripHtml(p.excerpt?.rendered ?? ''),
    contentHtml: p.content?.rendered ?? '',
    image: pickImage(p),
    ortsteil: pickOrtsteil(termSlugs(p, 'gemeindeteil')),
    kategorien: termSlugs(p, 'tourismus_kat'),
    kontakt: p.vv_kontakt,
    link: p.link,
  }));
}

/* ============================================================
 * Vereine
 * ============================================================ */

export interface VereinItem {
  slug: string;
  title: string;
  contentHtml: string;
  image?: string;
  ortsteil?: Ortsteil;
  kontakt?: VVKontakt;
  link: string;
}

export async function getVereine(): Promise<VereinItem[]> {
  const data = await fetchJson<WPCPTBase>('verein');
  return data.filter(hasGrhOrtsteil).map((p) => ({
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    contentHtml: p.content?.rendered ?? '',
    image: pickImage(p),
    ortsteil: pickOrtsteil(termSlugs(p, 'gemeindeteil')),
    kontakt: p.vv_kontakt,
    link: p.link,
  }));
}

/* ============================================================
 * Gewerbe-Profile
 * ============================================================ */

export interface ProfilItem {
  slug: string;
  title: string;
  contentHtml: string;
  image?: string;
  ortsteil?: Ortsteil;
  kategorien: string[];
  kontakt?: VVKontakt;
  link: string;
}

export async function getProfile(): Promise<ProfilItem[]> {
  const data = await fetchJson<WPCPTBase>('profile');
  return data.filter(hasGrhOrtsteil).map((p) => ({
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    contentHtml: p.content?.rendered ?? '',
    image: pickImage(p),
    ortsteil: pickOrtsteil(termSlugs(p, 'gemeindeteil')),
    kategorien: termSlugs(p, 'profilkategorie'),
    kontakt: p.vv_kontakt,
    link: p.link,
  }));
}

/* ============================================================
 * Personen (Gemeinderats-Mitglieder etc.)
 * ============================================================ */

export interface PersonItem {
  slug: string;
  title: string;
  image?: string;
  gremien: string[];
  link: string;
}

export async function getPersonen(opts: { gremium?: string } = {}): Promise<PersonItem[]> {
  const data = await fetchJson<WPCPTBase>('personen');
  return data
    .map((p) => ({
      slug: p.slug,
      title: decodeEntities(p.title.rendered),
      image: pickImage(p),
      gremien: termSlugs(p, 'gremien'),
      link: p.link,
    }))
    .filter((p) => !opts.gremium || p.gremien.includes(opts.gremium));
}

/* ============================================================
 * Posts nach Kategorie (für Themen-Listen wie Gemeinderats-Beiträge)
 * ============================================================ */

export interface CategorizedPost {
  slug: string;
  title: string;
  date: Date;
  excerpt: string;
  link: string;
}

export async function getPostsByCategory(categorySlug: string, limit = 100): Promise<CategorizedPost[]> {
  // Zuerst Kategorie-ID auflösen
  const cats = await fetchJson<{ id: number; slug: string }>('categories', { slug: categorySlug });
  const catId = cats[0]?.id;
  if (!catId) return [];
  const posts = await fetchJson<WPCPTBase>('posts', { categories: String(catId), per_page: String(Math.min(limit, 100)) });
  return posts
    .map((p) => ({
      slug: p.slug,
      title: decodeEntities(p.title.rendered),
      date: new Date(p.date),
      excerpt: stripHtml(p.excerpt?.rendered ?? ''),
      link: p.link,
    }))
    .sort((a, b) => b.date.valueOf() - a.date.valueOf());
}

/* ============================================================
 * Ämter
 * ============================================================ */

export interface AmtItem {
  slug: string;
  title: string;
  contentHtml: string;
  link: string;
}

export async function getAemter(): Promise<AmtItem[]> {
  const data = await fetchJson<WPCPTBase>('amter');
  return data.map((p) => ({
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    contentHtml: p.content?.rendered ?? '',
    link: p.link,
  }));
}

/* ============================================================
 * Gemeinderats-Sitzungen
 * ============================================================ */

export interface SitzungItem {
  slug: string;
  title: string;
  date: Date;
  contentHtml: string;
  link: string;
}

export async function getGemeinderatssitzungen(): Promise<SitzungItem[]> {
  const data = await fetchJson<WPCPTBase>('gemeinderatssitzung');
  return data
    .map((p) => ({
      slug: p.slug,
      title: decodeEntities(p.title.rendered),
      date: new Date(p.date),
      contentHtml: p.content?.rendered ?? '',
      link: p.link,
    }))
    .sort((a, b) => b.date.valueOf() - a.date.valueOf());
}

/* ============================================================
 * Amtsblätter (mit PDF-Anhang)
 * ============================================================ */

export interface AmtsblattItem {
  slug: string;
  title: string;
  date: Date;
  excerpt: string;
  jahr: string;
  pdfUrl?: string;
  link: string;
}

export async function getAmtsblaetter(): Promise<AmtsblattItem[]> {
  const data = await fetchJson<WPCPTBase>('amtsblatt_download');
  return data
    .map((p) => {
      const date = new Date(p.date);
      // Erstes PDF aus den Anhängen finden
      const pdfFromAttach = p._embedded?.['wp:attachment']?.find(
        (a) => a.mime_type === 'application/pdf',
      )?.source_url;
      // Fallback: PDF-Link aus dem Excerpt-HTML extrahieren
      const excerptHtml = p.excerpt?.rendered ?? '';
      const pdfFromExcerpt = excerptHtml.match(/href="([^"]+\.pdf)"/i)?.[1];
      return {
        slug: p.slug,
        title: decodeEntities(p.title.rendered),
        date,
        excerpt: stripHtml(excerptHtml),
        jahr: String(date.getFullYear()),
        pdfUrl: pdfFromAttach ?? pdfFromExcerpt,
        link: p.link,
      };
    })
    .sort((a, b) => b.date.valueOf() - a.date.valueOf());
}

/* ============================================================
 * Generischer Detail-Loader: einzelner Post per Slug + CPT
 * ============================================================ */

export interface CPTDetail extends WPCPTBase {
  decodedTitle: string;
  image?: string;
  ortsteil?: Ortsteil;
}

export async function getCPTBySlug(endpoint: string, slug: string): Promise<CPTDetail | null> {
  const list = await fetchJson<WPCPTBase>(endpoint, { slug });
  const p = list[0];
  if (!p) return null;
  return {
    ...p,
    decodedTitle: decodeEntities(p.title.rendered),
    image: pickImage(p),
    ortsteil: pickOrtsteil(termSlugs(p, 'gemeindeteil')),
  };
}

/* ============================================================
 * Standard-Post per Slug (für News-Detail)
 * ============================================================ */

interface WPPost extends WPCPTBase {}

export async function getPostBySlug(slug: string): Promise<CPTDetail | null> {
  return getCPTBySlug('posts', slug);
}
