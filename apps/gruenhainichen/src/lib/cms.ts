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
import { fetchWordPressNews, fetchWordPressEvents } from './cms-wordpress';

export type Ortsteil = 'borstendorf' | 'gruenhainichen' | 'waldkirchen';
export type NewsCategory = 'verwaltung' | 'veranstaltung' | 'sperrung' | 'tourismus';
export type Severity = 'info' | 'warn' | 'alert';

/** Normalisiertes News-Item — egal ob aus Local oder WP. */
export interface NewsItem {
  slug: string;
  title: string;
  date: Date;
  category: NewsCategory;
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

export async function getEvents(opts: { includePast?: boolean } = {}): Promise<EventItem[]> {
  if (SOURCE === 'wordpress') {
    try {
      const items = await fetchWordPressEvents(opts);
      if (items.length > 0) return items;
      console.warn('[cms] vw-events lieferte 0 Events — fallback zu local');
    } catch (err) {
      console.warn('[cms] vw-events-Fetch fehlgeschlagen — fallback zu local:', err);
    }
  }
  return getLocalEvents();
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

export function getCmsSource(): 'local' | 'wordpress' {
  return SOURCE === 'wordpress' ? 'wordpress' : 'local';
}
