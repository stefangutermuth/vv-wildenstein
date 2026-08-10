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

/** Pro Anliegen: Farbe + Icon (SVG-Path) — für die „Kein Foto"-Kachel und Filter. */
export const ANLIEGEN_META: Record<string, { label: string; color: string; icon: string }> = {
  'strassen-gehwege-plaetze': {
    label: 'Straßen, Gehwege und Plätze',
    color: '#5b6b8c',
    icon: 'M19 15.18V7a4 4 0 0 0-8 0v10a2 2 0 0 1-4 0V8.82A3 3 0 0 0 6 3a3 3 0 0 0-1 5.82V17a4 4 0 0 0 8 0V7a2 2 0 0 1 4 0v8.18A3 3 0 0 0 20 21a3 3 0 0 0 1-5.82z',
  },
  strassenbeleuchtung: {
    label: 'Straßenbeleuchtung',
    color: '#d99a2b',
    icon: 'M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7z',
  },
  'muell-verschmutzung': {
    label: 'Müllablagerung und Verschmutzung',
    color: '#8a6d3b',
    icon: 'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
  },
  'gruenflaechen-baeume': {
    label: 'Grünflächen und Bäume',
    color: '#3a7d44',
    icon: 'M6.05 8.05c-2.73 2.73-2.73 7.15-.02 9.88 1.47-3.4 4.09-6.24 7.36-7.93-2.77 2.34-4.71 5.61-5.39 9.32 2.6 1.23 5.8.78 7.95-1.37C19.43 14.47 20 4 20 4S9.53 4.57 6.05 8.05z',
  },
  'wander-radwege': {
    label: 'Wander- und Radwege',
    color: '#2a6f97',
    icon: 'M13.5 5.5c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zM9.8 8.9L7 23h2.1l1.8-8 2.1 2v6h2v-7.5l-2.1-2 .6-3C14.8 12 16.8 13 19 13v-2c-1.9 0-3.5-1-4.3-2.4l-1-1.6c-.4-.6-1-1-1.7-1-.3 0-.5.1-.7.1L6 8.3V13h2V9.6l1.8-.7z',
  },
};

/** Link, der die Position in einer Karten-App / Route öffnet. */
export function mapsLink(lat: number, lng: number): string {
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
}

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

/** HTML-Escaping (Text + Attribute) — Daten stammen aus dem Backend, aber sicher ist sicher. */
function esc(s: unknown): string {
  return String(s ?? '').replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string
  );
}

/**
 * Markup einer Meldungs-Kachel — EINZIGE Quelle für Build (MeldungCard.astro via
 * set:html) UND Live-Nachladen (Karten-/Listen-Script). Styles liegen global in
 * tokens.css (nicht scoped), damit auch clientseitig erzeugte Kacheln passen.
 */
export function cardHTML(m: Meldung): string {
  const status = m.status?.slug ?? 'neu';
  const statusLabel = m.status?.label ?? 'Neue Meldung';
  const anliegenSlug = m.anliegen?.[0] ?? '';
  const meta = ANLIEGEN_META[anliegenSlug];
  const context = [meta?.label ?? '', m.location?.city ?? ''].filter(Boolean).join(' · ');
  const href = `/meldung/${m.id}`;
  const media = m.image
    ? `<img src="${esc(m.image.url)}" alt="${esc(m.image.alt || m.title)}" loading="lazy" />`
    : `<span class="card__placeholder" aria-hidden="true"${meta ? ` style="--tile:${meta.color}"` : ''}>` +
      `<svg viewBox="0 0 24 24"><path d="${meta?.icon ?? 'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z'}" /></svg></span>`;

  return (
    `<article class="card" data-status="${esc(status)}" data-anliegen="${esc((m.anliegen ?? []).join(' '))}">` +
    `<a class="card__media" href="${href}">${media}</a>` +
    `<div class="card__body">` +
    `<h3 class="card__title"><a href="${href}">${esc(m.title)}</a></h3>` +
    (context ? `<p class="card__context">${esc(context)}</p>` : '') +
    `<div class="card__meta">` +
    `<span class="badge badge--${esc(status)}">${esc(statusLabel)}</span>` +
    `<time datetime="${esc(m.created)}">${esc(formatDate(m.created))}</time>` +
    `</div></div></article>`
  );
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
