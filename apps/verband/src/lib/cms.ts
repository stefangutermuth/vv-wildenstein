/**
 * CMS-Adapter des Verbands-Frontends — einheitliche Schnittstelle für
 * Neuigkeiten/Bekanntmachungen und Veranstaltungen.
 *
 * Quelle steuert `PUBLIC_CMS_SOURCE`:
 *   - "local"     → Astro Content Collections (Default, Skeleton-Phase)
 *   - "wordpress" → zentrale REST-API des Verwaltungsverbands
 *
 * Anders als das Grünhainichen-Frontend filtert der Verband NICHT auf einen
 * Ortsteil — er ist die Klammer über alle Mitgliedsgemeinden (Grünhainichen +
 * Börnichen) und zeigt verbandsweite Bekanntmachungen.
 */

import type { CollectionEntry } from 'astro:content';
import { getCollection } from 'astro:content';
import { fetchWordPressNews, fetchWordPressEvents } from './cms-wordpress';

export type NewsCategory = 'bekanntmachung' | 'veranstaltung' | 'sperrung' | 'ausschreibung';
export type Severity = 'info' | 'warn' | 'alert';

/** Normalisiertes News-Item — egal ob aus Local oder WP. */
export interface NewsItem {
  slug: string;
  title: string;
  date: Date;
  category: NewsCategory;
  image?: string;
  excerpt: string;
  featured: boolean;
  href: string;
  /** Volltext (gerendertes HTML) — nur bei Quelle WordPress befüllt */
  contentHtml?: string;
  // Sperrungs-spezifische Felder (optional)
  affectedStreet?: string;
  detour?: string;
  validUntil?: Date;
  severity?: Severity;
}

// Default = wordpress: die Seite lebt von echten Inhalten aus dem zentralen
// Backend. "local" bleibt als expliziter Dev-/Notfall-Modus wählbar.
const SOURCE = (
  import.meta.env.PUBLIC_CMS_SOURCE ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_CMS_SOURCE : undefined) ??
  'wordpress'
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

/** Normalisiertes Event-Item. */
export interface EventItem {
  slug: string;
  title: string;
  startDate: Date;
  endDate?: Date;
  location: string;
  teaser: string;
  featured: boolean;
  image?: string;
  href: string;
  /** Volltext (gerendertes HTML) — nur bei Quelle WordPress befüllt */
  contentHtml?: string;
  organizer?: string;
  allDay?: boolean;
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
