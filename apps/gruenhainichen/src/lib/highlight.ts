/**
 * Blickfang der Startseite: der nächste Termin mit der WordPress-Terminkategorie
 * „Highlight“. Ist er vorbei, verschwindet er von selbst.
 */
import type { EventItem } from './cms';

export const HIGHLIGHT_KATEGORIE = 'highlight';

export function findeHighlight(events: EventItem[]): EventItem | undefined {
  return events.find((e) => e.categories?.includes(HIGHLIGHT_KATEGORIE));
}

/** „Tag des traditionellen Handwerks 17.+18.10.26“ → „Tag des traditionellen Handwerks“ */
export function kurzTitel(titel: string): string {
  return titel.replace(/\s+(?:am|vom|den)?\s*\d{1,2}\.[\d.+\-–\s]*$/i, '').trim() || titel;
}

const monat = new Intl.DateTimeFormat('de-DE', { month: 'long' });
const wochentag = new Intl.DateTimeFormat('de-DE', { weekday: 'long' });
const uhr = (d: Date) => (d.getMinutes() ? `${d.getHours()}.${String(d.getMinutes()).padStart(2, '0')}` : `${d.getHours()}`);

/** „17. und 18. Oktober“, „30. September bis 2. Oktober“ */
export function zeitraum(e: EventItem): string {
  const a = e.startDate;
  const b = e.endDate;
  if (!b) return `${a.getDate()}. ${monat.format(a)}`;
  if (a.getMonth() !== b.getMonth()) return `${a.getDate()}. ${monat.format(a)} bis ${b.getDate()}. ${monat.format(b)}`;
  return `${a.getDate()}. ${tageDazwischen(a, b) === 1 ? 'und' : 'bis'} ${b.getDate()}. ${monat.format(b)}`;
}

function tageDazwischen(a: Date, b: Date): number {
  return Math.round((new Date(b).setHours(0, 0, 0, 0) - new Date(a).setHours(0, 0, 0, 0)) / 864e5);
}

/** „Samstag und Sonntag · 10 bis 17 Uhr“ */
export function zeitraumLang(e: EventItem): string {
  const a = e.startDate;
  const b = e.endDate;
  const teile: string[] = [];
  if (b) {
    teile.push(`${wochentag.format(a)} ${tageDazwischen(a, b) === 1 ? 'und' : 'bis'} ${wochentag.format(b)}`);
  } else {
    teile.push(wochentag.format(a));
  }
  if (!e.allDay) {
    teile.push(b ? `${uhr(a)} bis ${uhr(b)} Uhr` : `ab ${uhr(a)} Uhr`);
  }
  return teile.join(' · ');
}

/**
 * Kleinere Fassung eines WordPress-Bildes statt des Originals (das Plakat hat
 * 1343 × 1900 px). Probiert die Größen mit den angegebenen längsten Seiten, bei
 * gleichem Seitenverhältnis; mit `quadrat` zuletzt das 150er-Quadrat.
 * Fehlt alles, bleibt die Adresse.
 */
export async function bildInGroesse(
  url: string | undefined,
  laengsteSeiten: number[],
  quadrat = false,
): Promise<string | undefined> {
  if (!url) return undefined;
  const m = url.match(/^(.*?)(?:-(\d+)x(\d+))?(\.(?:jpe?g|png|webp))$/i);
  if (!m) return url;
  const [, basis, w, h, endung] = m;
  const kandidaten: string[] = [];
  if (w && h) {
    for (const seite of laengsteSeiten) {
      const f = seite / Math.max(+w, +h);
      if (f < 1) kandidaten.push(`${basis}-${Math.round(+w * f)}x${Math.round(+h * f)}${endung}`);
    }
  }
  if (quadrat) kandidaten.push(`${basis}-150x150${endung}`);
  for (const k of kandidaten) {
    try {
      const res = await fetch(k, { method: 'HEAD', signal: AbortSignal.timeout(5000) });
      if (res.ok) return k;
    } catch { /* nächster Versuch */ }
  }
  return url;
}

/** Vorschaubild für den Blickfang: mittlere Größe (300 px), sonst das Quadrat. */
export const vorschaubild = (url?: string) => bildInGroesse(url, [300], true);
