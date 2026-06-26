/**
 * Datenquelle: zentrale WordPress-REST des Plugins `vw-melder` auf vv-wildenstein.com.
 * Base-URL via PUBLIC_MELDER_API_BASE überschreibbar (Default = Live).
 */

const API_BASE =
  (import.meta.env.PUBLIC_MELDER_API_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_MELDER_API_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/vw-melder/v1';

export type StatusSlug = 'neu' | 'in-bearbeitung' | 'erledigt';

export interface MeldungLocation {
  lat: number | null;
  lng: number | null;
  address: string;
  city: string;
  postcode: string;
}

export interface Meldung {
  id: number;
  slug: string;
  title: string;
  description_html: string;
  created: string;
  anliegen: string[];
  status: { slug: StatusSlug; label: string } | null;
  location: MeldungLocation;
  image: { url: string; alt: string } | null;
  public_notes: { date: string; text: string }[];
  permalink: string;
}

export interface GeoFeature {
  type: 'Feature';
  geometry: { type: 'Point'; coordinates: [number, number] };
  properties: {
    id: number;
    title: string;
    status: StatusSlug;
    anliegen: string[];
    permalink: string;
  };
}

export interface GeoCollection {
  type: 'FeatureCollection';
  features: GeoFeature[];
}

/** Anzeige-Labels der Anliegen (Slug → Name), Vorbild aus dem Bestand. */
export const ANLIEGEN_LABELS: Record<string, string> = {
  'strassen-gehwege-plaetze': 'Straßen, Gehwege und Plätze',
  strassenbeleuchtung: 'Straßenbeleuchtung',
  'muell-verschmutzung': 'Müllablagerung und Verschmutzung',
  'gruenflaechen-baeume': 'Grünflächen und Bäume',
  'wander-radwege': 'Wander- und Radwege',
};

export const STATUS_LABELS: Record<StatusSlug, string> = {
  neu: 'Neue Meldung',
  'in-bearbeitung': 'In Bearbeitung',
  erledigt: 'Erledigt',
};

async function getJSON<T>(path: string, fallback: T): Promise<T> {
  try {
    const res = await fetch(`${API_BASE}${path}`, { headers: { Accept: 'application/json' } });
    if (!res.ok) return fallback;
    return (await res.json()) as T;
  } catch {
    return fallback;
  }
}

export async function getMeldungen(): Promise<Meldung[]> {
  return getJSON<Meldung[]>('/meldungen?per_page=200', []);
}

export async function getGeoJSON(): Promise<GeoCollection> {
  return getJSON<GeoCollection>('/geojson', { type: 'FeatureCollection', features: [] });
}

export function formatDate(iso: string): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/** Relative deutsche Zeitangabe („vor 9 Stunden"). Build-Zeit-Fallback; clientseitig aktualisiert. */
export function relativeTime(iso: string): string {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  if (isNaN(then)) return '';
  const sec = Math.round((Date.now() - then) / 1000);
  if (sec < 60) return 'gerade eben';
  const min = Math.round(sec / 60);
  if (min < 60) return `vor ${min} Minute${min === 1 ? '' : 'n'}`;
  const hr = Math.round(min / 60);
  if (hr < 24) return `vor ${hr} Stunde${hr === 1 ? '' : 'n'}`;
  const day = Math.round(hr / 24);
  if (day < 31) return `vor ${day} Tag${day === 1 ? '' : 'en'}`;
  const mon = Math.round(day / 30);
  if (mon < 12) return `vor ${mon} Monat${mon === 1 ? '' : 'en'}`;
  const yr = Math.round(day / 365);
  return `vor ${yr} Jahr${yr === 1 ? '' : 'en'}`;
}
