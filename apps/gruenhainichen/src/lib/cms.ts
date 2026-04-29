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
import { fetchWordPressNews } from './cms-wordpress';

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

function sortByDateDesc(items: NewsItem[]): NewsItem[] {
  return [...items].sort((a, b) => b.date.valueOf() - a.date.valueOf());
}

export function getCmsSource(): 'local' | 'wordpress' {
  return SOURCE === 'wordpress' ? 'wordpress' : 'local';
}
