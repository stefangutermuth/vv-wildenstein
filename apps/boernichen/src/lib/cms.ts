/**
 * CMS-Adapter — eine einheitliche Schnittstelle für News, Events und Ortsteile.
 *
 * Quelle steuert die ENV-Variable `PUBLIC_CMS_SOURCE`:
 *   - "local"     → liest aus Astro Content Collections (Phase 1, Default)
 *   - "wordpress" → fetched aus dem WordPress-REST von vv-wildenstein.com (Phase 2)
 *
 * Events bleiben aktuell immer lokal — das WP nutzt das Events-Manager-Plugin,
 * das keine REST-Endpunkte für Events liefert. Wir lösen das in Phase 2.5.
 */

import type { CollectionEntry } from 'astro:content';
import { getCollection } from 'astro:content';
import { fetchWordPressNews, fetchWordPressEvents, fetchAmtsblaetter, fetchProfiles, fetchTourismus } from './cms-wordpress';

export type Ortsteil = 'borstendorf' | 'gruenhainichen' | 'waldkirchen';
export type NewsCategory = 'verwaltung' | 'veranstaltung' | 'sperrung' | 'tourismus';
export type Severity = 'info' | 'warn' | 'alert';

/** Normalisiertes News-Item — egal ob aus Local oder WP. */
export interface NewsItem {
  slug: string;
  title: string;
  date: Date;
  category: NewsCategory;
  /** Rohe WordPress-Kategorie-Slugs (z. B. 'allgemein', 'boernichen') — für Filter. */
  categories?: string[];
  ortsteil?: Ortsteil | 'alle';
  image?: string;
  excerpt: string;
  featured: boolean;
  href: string;
  // Sperrungs-spezifische Felder (optional)
  affectedStreet?: string;
  detour?: string;
  validUntil?: Date;
  severity?: Severity;
}

/**
 * Anzeige-Namen der echten WordPress-Kategorien (Stand boernichen.de).
 * Quelle: REST /wp/v2/categories des gemeinsamen VV-Wildenstein-Backends.
 */
export const CATEGORY_LABELS: Record<string, string> = {
  allgemein: 'Allgemein',
  boernichen: 'Börnichen',
  gemeinde: 'Gemeinde',
  kultur: 'Kultur',
  gemeinderat: 'Gemeinderat',
  verwaltungsverband: 'Verwaltungsverband',
  verbandsversammlung: 'Verbandsversammlung',
  sperrung: 'Sperrung',
  bauleitplanung: 'Bauleitplanung',
  verkehr: 'Verkehr',
  stellenausschreibungen: 'Stellenausschreibungen',
  stellenanzeigen: 'Stellenanzeigen',
  senioren: 'Senioren',
  kirche: 'Kirche',
  kindergarten: 'Kindergarten',
  'kommunale-gesundheitsfoerderung': 'kommunale Gesundheitsförderung',
  verwaltung: 'Verwaltung',
  // Standort-Kategorien (werden in der Badge-Anzeige ausgeblendet)
  'leben-in-gruenhainichen': 'Leben in Grünhainichen',
  gruenhainichen: 'Grünhainichen',
  waldkirchen: 'Waldkirchen',
  borstendorf: 'Borstendorf',
  // interne Alt-Enum-Werte (Fallback)
  veranstaltung: 'Veranstaltung',
  tourismus: 'Tourismus',
};

/** Standort-Slugs, die nicht als Kategorie-Badge erscheinen sollen. */
const HIDDEN_CATEGORY_SLUGS = new Set([
  'gruenhainichen', 'waldkirchen', 'borstendorf', 'leben-in-gruenhainichen',
  'gemeinderat-gruenhainichen',
]);

/** Anzeige-Kategorien eines News-Items (echte WP-Kategorien, Standort gefiltert). */
export function displayCategories(n: Pick<NewsItem, 'categories' | 'category'>): string[] {
  const raw = n.categories?.length ? n.categories : [n.category];
  const labels = raw
    .filter((s) => !HIDDEN_CATEGORY_SLUGS.has(s))
    .map((s) => CATEGORY_LABELS[s] ?? s);
  return [...new Set(labels)];
}

const SOURCE = (
  import.meta.env.PUBLIC_CMS_SOURCE ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_CMS_SOURCE : undefined) ??
  'local'
).toLowerCase();

export async function getNews(): Promise<NewsItem[]> {
  if (SOURCE === 'wordpress') {
    try {
      const items = await fetchWordPressNews();
      if (items.length > 0) return sortByDateDesc(items);
      console.warn('[cms] WordPress lieferte 0 Posts — fallback zu local');
    } catch (err) {
      console.warn('[cms] WordPress-Fetch fehlgeschlagen — fallback zu local:', err);
    }
  }
  return sortByDateDesc(await getLocalNews());
}

async function getLocalNews(): Promise<NewsItem[]> {
  const entries = await getCollection('news');
  return entries.map((e: CollectionEntry<'news'>) => ({
    slug: e.slug,
    title: e.data.title,
    date: e.data.date,
    category: e.data.category,
    // „allgemein" = ohne festen Ortsteil (entspricht der WP-Kategorie 'allgemein')
    categories: (!e.data.ortsteil || e.data.ortsteil === 'alle')
      ? [e.data.category, 'allgemein']
      : [e.data.category],
    ortsteil: e.data.ortsteil,
    image: e.data.image,
    excerpt: e.data.excerpt,
    featured: e.data.featured ?? false,
    href: `/neuigkeiten/${e.slug}`,
    affectedStreet: e.data.affectedStreet,
    detour: e.data.detour,
    validUntil: e.data.validUntil,
    severity: e.data.severity,
  }));
}

/** Normalisiertes Event-Item — gleiche Form wie das alte Content-Schema. */
export interface EventItem {
  slug: string;
  title: string;
  startDate: Date;
  endDate?: Date;
  location: string;
  ortsteil?: Ortsteil;
  teaser: string;
  featured: boolean;
  image?: string;
  href: string;
}

export async function getEvents(): Promise<EventItem[]> {
  if (SOURCE === 'wordpress') {
    try {
      const items = await fetchWordPressEvents();
      if (items.length > 0) return items;
      console.warn('[cms] vw-events lieferte 0 Events — fallback zu local');
    } catch (err) {
      console.warn('[cms] vw-events-Fetch fehlgeschlagen — fallback zu local:', err);
    }
  }
  return getLocalEvents();
}

/* ----------------------------------------------------------------
 * Amtsblätter — CPT `amtsblatt_download` vom Master vv-wildenstein.com.
 * PDF hängt als Anhang (media?parent=ID) am jeweiligen Eintrag.
 * ---------------------------------------------------------------- */
export interface AmtsblattItem {
  id: number;
  title: string;
  date: Date;
  pdfUrl: string | null;
  link: string;
  isInfo: boolean;
}

export async function getAmtsblaetter(): Promise<AmtsblattItem[]> {
  if (SOURCE === 'wordpress') {
    try {
      return await fetchAmtsblaetter();
    } catch (err) {
      console.warn('[cms] amtsblatt-Fetch fehlgeschlagen:', err);
    }
  }
  return [];
}

async function getLocalEvents(): Promise<EventItem[]> {
  const entries = await getCollection('events');
  const now = new Date();
  return entries
    .map((e: CollectionEntry<'events'>) => ({
      slug: e.slug,
      title: e.data.title,
      startDate: e.data.startDate,
      endDate: e.data.endDate,
      location: e.data.location,
      ortsteil: e.data.ortsteil,
      teaser: e.data.teaser,
      featured: e.data.featured ?? false,
      image: e.data.image,
      href: `/veranstaltungen/${e.slug}`,
    }))
    .filter((e) => (e.endDate ?? e.startDate).valueOf() >= now.valueOf())
    .sort((a, b) => a.startDate.valueOf() - b.startDate.valueOf());
}

function sortByDateDesc(items: NewsItem[]): NewsItem[] {
  return [...items].sort((a, b) => b.date.valueOf() - a.date.valueOf());
}

/* ----------------------------------------------------------------
 * Profile & Tourismus — CPTs `profile` / `tourismus` vom Master.
 * Kontaktfelder kommen via `vv_kontakt` (mu-Plugin vv-rest-profilfelder).
 * Börnichen-gefiltert über die Taxonomie `gemeindeteil` (Börnichen = 175).
 * ---------------------------------------------------------------- */
export interface CptKontakt {
  fuehrende_person?: string;
  strasse_hausnummer?: string;
  plz_ort?: string;
  telefon?: string;
  email?: string;
  website?: string;
}
export interface CptEntry {
  /** Original-Slug aus WordPress (für URL-Zuordnung). */
  cptSlug: string;
  title: string;
  /** Sauberes Beschreibungs-HTML (content.rendered). */
  contentHtml: string;
  image?: string;
  kontakt: CptKontakt;
  /** profilkategorie-Term-IDs (nur bei Profilen relevant). */
  kategorie: number[];
  /** WordPress-Permalink (Fallback/Referenz). */
  link: string;
}
export type ProfileItem = CptEntry;
export type TourismusItem = CptEntry;

export async function getProfiles(): Promise<ProfileItem[]> {
  if (SOURCE === 'wordpress') {
    try {
      return await fetchProfiles();
    } catch (err) {
      console.warn('[cms] profile-Fetch fehlgeschlagen:', err);
    }
  }
  return [];
}

export async function getTourismus(): Promise<TourismusItem[]> {
  if (SOURCE === 'wordpress') {
    try {
      return await fetchTourismus();
    } catch (err) {
      console.warn('[cms] tourismus-Fetch fehlgeschlagen:', err);
    }
  }
  return [];
}

export function getCmsSource(): 'local' | 'wordpress' {
  return SOURCE === 'wordpress' ? 'wordpress' : 'local';
}
