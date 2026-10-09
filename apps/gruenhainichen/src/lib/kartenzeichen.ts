/**
 * Zeichen für weitere Punkte der Teilnehmerkarte: Parkplatz, Shuttle-Haltestelle,
 * Essen und WC. Dasselbe Zeichen steht in der Liste und auf der Karte, deshalb
 * liegt es hier und ohne Node-Abhängigkeiten: Das Modul läuft auch im Browser.
 */

export type PunktArt = 'parken' | 'haltestelle' | 'essen' | 'wc';

export interface KartenPunkt {
  art: PunktArt;
  lat: number;
  lon: number;
  /** Beschriftung des Zeichens: „P1“, „P“, „H“, „WC“. Bei Essen leer (Besteck). */
  zeichen: string;
  name: string;
  /** Text nach dem Namen, z. B. „nur am Sonntag“. */
  zusatz: string;
  /** Parkplatz mit Shuttle-Haltestelle. */
  shuttle: boolean;
}

export const PUNKT_ARTEN: PunktArt[] = ['parken', 'haltestelle', 'wc', 'essen'];

/** Beschriftung, wenn das Zeichen ohne eigenen Punkt steht (an einem Teilnehmer, in der Legende). */
export const ZEICHEN_TEXT: Record<PunktArt, string> = { parken: 'P', haltestelle: 'H', wc: 'WC', essen: '' };

/** Mehrere Zeichen hintereinander, z. B. Haltestelle, WC und Essen an einem Teilnehmer. */
export function zeichenReihe(arten: PunktArt[]): string {
  return arten.map((art) => zeichenHtml({ art, zeichen: ZEICHEN_TEXT[art], shuttle: false })).join('');
}

const BESTECK =
  '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
  '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></svg>';

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/** HTML des Zeichens, für Liste und Karte gleich. */
export function zeichenHtml(p: Pick<KartenPunkt, 'art' | 'zeichen' | 'shuttle'>): string {
  const inhalt = p.art === 'essen' ? BESTECK : esc(p.zeichen);
  const haltestelle = p.art === 'parken' && p.shuttle ? '<span class="grh-kz__h">H</span>' : '';
  return `<span class="grh-kz grh-kz--${p.art}">${inhalt}${haltestelle}</span>`;
}
