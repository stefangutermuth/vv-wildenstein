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
  /** Freitext der Redaktion: Öffnungszeiten, Anmeldung, Kontakt für Besuche.
   *  Bei vielen Tourismus-Einträgen stehen hier die einzigen Besuchsangaben. */
  oeffnungszeiten?: string;
  mobil?: string;
  fax?: string;
  /** Freitext „Informationen" (Leistungen, Hinweise) — v. a. bei Firmenprofilen */
  informationen?: string;
  /** Ämter: Funktion des Ansprechpartners, mehrzeilige Anschrift */
  funktion?: string;
  anschrift?: string;
}

const FETCH_TIMEOUT_MS = 8000;

import { cachedFetch } from './wp-cache';

async function fetchWithTimeout(url: string, init: RequestInit = {}, timeoutMs = FETCH_TIMEOUT_MS): Promise<Response> {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    return await cachedFetch(url, { ...init, signal: ctrl.signal }, WP_BASE);
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
  const items = data.filter(hasGrhOrtsteil).map((p) => ({
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    excerpt: decodeEntities(stripHtml(p.excerpt?.rendered ?? '')),
    contentHtml: p.content?.rendered ?? '',
    image: pickImage(p),
    ortsteil: pickOrtsteil(termSlugs(p, 'gemeindeteil')),
    kategorien: termSlugs(p, 'tourismus_kat'),
    kontakt: p.vv_kontakt,
    link: p.link,
  }));
  // Frontend-Dedup: In vv-wildenstein.com liegen einzelne Tourismus-Beiträge
  // mehrfach unter verschiedenen Slugs. Wir behalten den INHALTSREICHSTEN
  // pro Titel — „der erste" hat beim Museum „Erzgebirgische Volkskunst" die
  // Variante ohne Anschrift und Telefonnummer gewonnen.
  const inhalt = (it: (typeof items)[number]): number => {
    const felder = Object.values(it.kontakt ?? {}).filter(
      (v) => typeof v === 'string' && v.trim() !== '',
    ).length;
    return felder * 1000 + (it.contentHtml?.replace(/<[^>]+>/g, '').trim().length ?? 0);
  };
  const beste = new Map<string, (typeof items)[number]>();
  for (const it of items) {
    const key = it.title.trim().toLowerCase();
    const bisher = beste.get(key);
    if (!bisher || inhalt(it) > inhalt(bisher)) beste.set(key, it);
  }
  // Ursprüngliche Reihenfolge beibehalten
  return items.filter((it) => beste.get(it.title.trim().toLowerCase()) === it);
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
      excerpt: decodeEntities(stripHtml(p.excerpt?.rendered ?? '')),
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
        excerpt: decodeEntities(stripHtml(excerptHtml)),
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

/* ============================================================
 * Räume (vvw_room aus dem VVW-Roombooking-Plugin)
 * Nur die drei Grünhainichen-Ortsteile (Municipality-Taxonomie).
 * ============================================================ */

export interface RoomItem {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  contentHtml: string;
  image?: string;
  ortsteil?: Ortsteil;
  capacity?: number;
  address?: string;
  priceDisplay?: string;
  amenities: string[];
  status?: string;
  openFrom?: string;
  openTo?: string;
  availableWeekdays?: number[];
  leadDays?: number;
  maxAdvance?: number;
  gallery?: Array<{ src: string; alt: string }>;
  link: string;
}

/**
 * Antwort-Schema vom Plugin-eigenen Endpoint `/vvw/v1/rooms`.
 * Wir nutzen diesen statt `wp/v2/vvw_room`, weil das Plugin dort
 * die Meta-Felder ohne Prefix und die Terms normalisiert ausliefert
 * (der Standard-Endpoint liefert `meta: {}` weil `show_in_rest` fehlt).
 */
interface VVWv1Room {
  id: number;
  title: string;
  content: string;
  excerpt: string;
  permalink: string;
  thumbnail: string;
  thumbnail_thumb: string;
  meta: {
    capacity?: string | number;
    address?: string;
    price_display?: string;
    open_from?: string;
    open_to?: string;
    available_weekdays?: string | number[];
    lead_days?: string | number;
    max_advance?: string | number;
    status?: string;
  };
  municipality?: { slug?: string } | null;
  amenities?: Array<{ slug: string; name: string }>;
}

function slugFromPermalink(link: string): string {
  const m = link.match(/\/raum\/([^/]+)\/?/);
  return m ? m[1] : '';
}

function toOrtsteil(slug: string | undefined | null): Ortsteil | undefined {
  if (!slug) return undefined;
  if (GRH_ORTSTEILE.has(slug)) return slug as Ortsteil;
  if (slug.startsWith('waldkirchen')) return 'waldkirchen';
  return undefined;
}

function hasGrhMunicipalitySlug(slug: string | undefined | null): boolean {
  if (!slug) return true; // ohne Zuordnung → universal
  return GRH_ORTSTEILE.has(slug) || slug.startsWith('waldkirchen');
}

function mapVVWv1Room(r: VVWv1Room): RoomItem {
  const rawWd = r.meta?.available_weekdays;
  const weekdays: number[] | undefined = Array.isArray(rawWd)
    ? rawWd.map((n) => Number(n))
    : (typeof rawWd === 'string' && rawWd
        ? rawWd.split(',').map((s) => Number(s.trim())).filter((n) => !Number.isNaN(n))
        : undefined);
  const capacity = r.meta?.capacity ? Number(r.meta.capacity) : undefined;
  const leadDays = r.meta?.lead_days ? Number(r.meta.lead_days) : undefined;
  const maxAdvance = r.meta?.max_advance ? Number(r.meta.max_advance) : undefined;

  return {
    id: r.id,
    slug: slugFromPermalink(r.permalink),
    title: decodeEntities(r.title),
    excerpt: decodeEntities(stripHtml(r.excerpt ?? '')),
    contentHtml: r.content ?? '',
    image: r.thumbnail || undefined,
    ortsteil: toOrtsteil(r.municipality?.slug),
    capacity,
    address: r.meta?.address || undefined,
    priceDisplay: r.meta?.price_display || undefined,
    amenities: (r.amenities ?? []).map((a) => a.slug),
    status: r.meta?.status || undefined,
    openFrom: r.meta?.open_from || undefined,
    openTo: r.meta?.open_to || undefined,
    availableWeekdays: weekdays,
    leadDays,
    maxAdvance,
    link: r.permalink,
  };
}

const VVW_API_BASE =
  (import.meta.env.PUBLIC_VVW_API_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_VVW_API_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/vvw/v1';

async function fetchVVWRooms(): Promise<VVWv1Room[]> {
  try {
    const res = await fetchWithTimeout(`${VVW_API_BASE}/rooms`, {
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) return [];
    return (await res.json()) as VVWv1Room[];
  } catch (err) {
    console.warn('[cms-cpt] vvw/v1/rooms Fehler:', (err as Error).message);
    return [];
  }
}

interface WPMedia {
  id: number;
  source_url: string;
  alt_text?: string;
  media_details?: { sizes?: Record<string, { source_url: string }> };
  post?: number;
}

async function fetchRoomGallery(roomId: number): Promise<Array<{ src: string; alt: string }>> {
  try {
    const url = new URL(`${WP_BASE}/media`);
    url.searchParams.set('parent', String(roomId));
    url.searchParams.set('per_page', '20');
    url.searchParams.set('media_type', 'image');
    const res = await fetchWithTimeout(url.toString(), {
      headers: { Accept: 'application/json', ...authHeader() },
    });
    if (!res.ok) return [];
    const media = (await res.json()) as WPMedia[];
    return media.map((m) => ({
      src: m.media_details?.sizes?.large?.source_url
         ?? m.media_details?.sizes?.medium_large?.source_url
         ?? m.source_url,
      alt: m.alt_text ?? '',
    }));
  } catch (err) {
    console.warn('[cms-cpt] media parent fetch fehler:', (err as Error).message);
    return [];
  }
}

export async function getRoomBySlug(slug: string): Promise<RoomItem | null> {
  const all = await fetchVVWRooms();
  const room = all.find((r) => slugFromPermalink(r.permalink) === slug);
  if (!room) return null;
  const mapped = mapVVWv1Room(room);
  const gallery = await fetchRoomGallery(mapped.id);
  // Hero-Bild rausfiltern, falls es doppelt kommt
  const heroUrl = mapped.image;
  mapped.gallery = gallery.filter((g) => !heroUrl || g.src !== heroUrl);
  return mapped;
}

export async function getRooms(): Promise<RoomItem[]> {
  const all = await fetchVVWRooms();
  return all
    .map(mapVVWv1Room)
    .filter((r) => hasGrhMunicipalitySlug(r.ortsteil));
}

/* ------------------------------------------------------------------ */
/*  Freibad Borstendorf — Öffnungsstatus                              */
/*                                                                     */
/*  Kommt aus dem mu-Plugin wuw-freibad-oeffnung.php auf               */
/*  vv-wildenstein.com. Dessen Inhaltstyp ist bewusst nicht öffentlich, */
/*  deshalb liefert vv-rest-freibad.php die Daten unter vvw/v1/freibad  */
/*  (Quelle im Repo: docs/wordpress/vv-rest-freibad.php).              */
/* ------------------------------------------------------------------ */

export interface FreibadZeitraum {
  von: string;
  bis: string;
  zeitVon: string;
  zeitBis: string;
  status: 'geoeffnet' | 'voraussichtlich' | 'geschlossen' | 'abgesagt';
  label: string;
  hinweis: string;
  offen: boolean;
}

export interface FreibadStatus {
  verfuegbar: boolean;
  aktuell: FreibadZeitraum | null;
  kommend: FreibadZeitraum[];
  stand: string;
}

export async function getFreibadStatus(): Promise<FreibadStatus | null> {
  try {
    // Bewusst ohne den Platten-Zwischenspeicher: Dessen Erneuerung hängt an
    // Änderungen im Redaktionssystem. Ob das Bad heute offen hat, ändert sich
    // aber allein durch den Kalender — ein gestern zwischengespeicherter
    // „geöffnet"-Stand wäre heute schlicht falsch, ohne dass jemand etwas
    // bearbeitet hätte. Genau das ist am 17.08.2026 passiert.
    // Es ist eine einzige kleine Anfrage pro Bauvorgang.
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
    let res: Response;
    try {
      res = await fetch(`${VVW_API_BASE}/freibad`, {
        headers: { Accept: 'application/json' },
        signal: ctrl.signal,
      });
    } finally {
      clearTimeout(t);
    }
    if (!res.ok) return null;
    const data = (await res.json()) as FreibadStatus;
    // Ohne laufenden und ohne kommenden Zeitraum gibt es nichts zu zeigen —
    // dann bleibt der Baustein auf der Startseite ganz weg, statt eine leere
    // Fläche zu hinterlassen.
    if (!data.verfuegbar) return null;
    if (!data.aktuell && data.kommend.length === 0) return null;
    return data;
  } catch (err) {
    // Ein nicht erreichbares Freibad darf niemals den Build der ganzen
    // Website scheitern lassen.
    console.warn('[cms-cpt] vvw/v1/freibad Fehler:', (err as Error).message);
    return null;
  }
}

/* ------------------------------------------------------------------ */
/*  Kindertagesstätten                                                 */
/*                                                                     */
/*  Eigener Loader statt getProfile(): Dort greift ein Filter auf die   */
/*  Grünhainichener Ortsteile — die Kita „Wunderland" in Börnichen      */
/*  fiele damit heraus, obwohl sie auf der Seite bewusst mitläuft, weil */
/*  beide Gemeinden im selben Verwaltungsverband sind.                  */
/* ------------------------------------------------------------------ */

/** Kennung der Taxonomie „profilkategorie" für Kindergärten auf vv-wildenstein.com */
const KITA_KATEGORIE_ID = '182';

/**
 * Entfernt Reste des alten Themes aus übernommenen Inhalten.
 *
 * Im Profil des Horts steckt ein Dateibaum-Widget des Download-Managers: ein
 * leerer Container, ein Stylesheet von vv-wildenstein.com und ein jQuery-
 * Skript. Auf unseren Seiten gibt es kein jQuery — sichtbar bliebe nur die
 * Überschrift „Downloads:" über einer leeren Fläche. Das Stylesheet wäre
 * zudem ein unnötiger Abruf bei einem Dritten.
 *
 * Bewusst im Loader und nicht in der Seite: So greift es überall, wo diese
 * Inhalte auftauchen, auch bei künftigen Einträgen.
 */
function entferneThemeReste(html: string): string {
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/<link\b[^>]*>/gi, '')
    .replace(/<div\b[^>]*id=["'](?:wpdmtree|tree[0-9a-f]+)["'][^>]*>\s*<\/div>/gi, '')
    // Die verwaiste Überschrift, die nun über nichts mehr steht. Nur entfernen,
    // wenn wirklich nichts Inhaltliches folgt — der alte Seitenbaukasten lässt
    // am Ende eine Reihe schließender Tags zurück, die nicht zählen.
    .replace(
      /<p[^>]*>\s*(?:<strong>|<b>)?\s*Downloads?:?\s*(?:<\/strong>|<\/b>)?\s*<\/p>(?=(?:\s|<\/(?:div|section|p|span)>)*$)/i,
      '',
    )
    .trim();
}

export async function getKitaProfile(): Promise<ProfilItem[]> {
  const data = await fetchJson<WPCPTBase>('profile', { profilkategorie: KITA_KATEGORIE_ID });
  return data.map((p) => ({
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    contentHtml: entferneThemeReste(p.content?.rendered ?? ''),
    image: pickImage(p),
    ortsteil: pickOrtsteil(termSlugs(p, 'gemeindeteil')),
    kategorien: termSlugs(p, 'profilkategorie'),
    kontakt: p.vv_kontakt,
    link: p.link,
  }));
}

/* ------------------------------------------------------------------ */
/*  Download-Listen                                                    */
/*                                                                     */
/*  Der Inhaltstyp des Download-Managers ist nicht über wp/v2          */
/*  erreichbar (404). vv-rest-downloads.php liest stattdessen die       */
/*  vorhandene Redaktionsseite aus und liefert die Dateien gruppiert.   */
/*  Quelle im Repo: docs/wordpress/vv-rest-downloads.php               */
/* ------------------------------------------------------------------ */

export interface DownloadDatei {
  id: number;
  titel: string;
  url: string;
  typ: string;
  /** Bytes; 0, wenn die Datei auf dem Server nicht lesbar war */
  groesse: number;
  /** JJJJ-MM-TT der letzten Änderung, leer wenn unbekannt */
  stand: string;
}

export interface DownloadGruppe {
  titel: string;
  dateien: DownloadDatei[];
}

export async function getDownloadListe(name: string): Promise<DownloadGruppe[]> {
  try {
    const res = await fetchWithTimeout(`${VVW_API_BASE}/downloads/${name}`, {
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) return [];
    const data = (await res.json()) as { gruppen?: DownloadGruppe[] };
    return data.gruppen ?? [];
  } catch (err) {
    console.warn('[cms-cpt] vvw/v1/downloads Fehler:', (err as Error).message);
    return [];
  }
}

/* ------------------------------------------------------------------ */
/*  Servicehinweise                                                    */
/*                                                                     */
/*  Kurzfristige Meldungen zu Öffnungszeiten und Erreichbarkeit. Sie    */
/*  erscheinen dort, wo sonst die falsche Erwartung entsteht — im Fuß   */
/*  neben den Sprechzeiten.                                            */
/*                                                                     */
/*  Quelle im Repo: docs/wordpress/vv-rest-hinweise.php                */
/* ------------------------------------------------------------------ */

export interface Servicehinweis {
  id: number;
  titel: string;
  text: string;
  slug: string;
  /** ISO-Zeitpunkt, ab dem der Hinweis nicht mehr gilt. */
  gueltigBis: string;
}

export async function getServicehinweise(): Promise<Servicehinweis[]> {
  try {
    // Ohne Platten-Zwischenspeicher: Ein Hinweis, der eine geänderte
    // Öffnungszeit ankündigt, ist nur solange etwas wert, wie er stimmt.
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
    let res: Response;
    try {
      res = await fetch(`${VVW_API_BASE}/hinweise`, {
        headers: { Accept: 'application/json' },
        signal: ctrl.signal,
      });
    } finally {
      clearTimeout(t);
    }
    if (!res.ok) return [];
    const data = (await res.json()) as { hinweise?: Servicehinweis[] };
    return data.hinweise ?? [];
  } catch (err) {
    console.warn('[cms-cpt] vvw/v1/hinweise Fehler:', (err as Error).message);
    return [];
  }
}
