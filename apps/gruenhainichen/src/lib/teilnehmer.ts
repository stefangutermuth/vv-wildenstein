/**
 * Teilnehmerkarte aus einer Terminbeschreibung.
 *
 * Die Redaktion pflegt Teilnehmer in WordPress als Liste in der Beschreibung:
 *   <li><strong>Name</strong>, Straße Hausnummer[, Ort]<br>Angebot</li>
 * Hier werden diese Einträge erkannt, zur Build-Zeit verortet und fortlaufend
 * nummeriert. Die Nummern stehen dann in der Liste und auf der Karte.
 * Einträge ohne Adresse bleiben unverändert und ohne Nummer.
 */
import { geocode } from './geocode';

export interface TeilnehmerMarker {
  lat: number;
  lon: number;
  /** Laufende Nummern der Teilnehmer an dieser Stelle (mehrere bei gleicher Adresse). */
  nummern: number[];
  namen: string[];
  adresse: string;
}

export interface TeilnehmerProgramm {
  /** Beschreibung mit Nummern vor den verorteten Einträgen. */
  html: string;
  markers: TeilnehmerMarker[];
}

// Mit Postleitzahl, sonst landet z. B. „Börnichen“ im gleichnamigen Ortsteil von Oederan.
const ORTE: Record<string, string> = {
  'Grünhainichen': '09579 Grünhainichen',
  'Borstendorf':   '09579 Borstendorf',
  'Waldkirchen':   '09579 Waldkirchen',
  'Börnichen':     '09437 Börnichen',
};
const LI_RE = /<li>\s*<strong>([\s\S]*?)<\/strong>\s*,\s*([^<]+?)\s*(?=<br\s*\/?>|<\/li>)/g;
const HAUSNUMMER_RE = /\s\d+\s?[a-z]?$/i;

function decode(s: string): string {
  return s.replace(/&amp;/g, '&').replace(/&#8211;|&ndash;/g, '-').replace(/<[^>]+>/g, '').trim();
}

function geoQuery(adresse: string): string {
  const teile = adresse.split(',').map((t) => t.trim());
  const strasse = teile[0].replace(/([Ss])tr\.(?=\s|$)/, '$1traße');
  const ort = Object.keys(ORTE).find((o) => teile.slice(1).some((t) => t.includes(o))) ?? 'Grünhainichen';
  return `${strasse}, ${ORTE[ort]}`;
}

export async function buildTeilnehmerProgramm(html: string): Promise<TeilnehmerProgramm> {
  const treffer = [...html.matchAll(LI_RE)].filter((m) => HAUSNUMMER_RE.test(m[2].split(',')[0].trim()));
  // Unter drei Adressen lohnt keine Karte: dann bleibt die Beschreibung, wie sie ist.
  if (treffer.length < 3) return { html, markers: [] };

  const markers: TeilnehmerMarker[] = [];
  const nummerProIndex = new Map<number, number>();
  let nr = 0;
  for (const m of treffer) {
    const adresse = decode(m[2]);
    const punkt = await geocode(geoQuery(adresse));
    if (!punkt) continue;
    nr += 1;
    nummerProIndex.set(m.index!, nr);
    const name = decode(m[1]);
    // Gleiche Adresse (auf etwa 10 m): ein gemeinsamer Punkt statt zwei übereinander.
    const da = markers.find((k) => Math.abs(k.lat - punkt.lat) < 0.0001 && Math.abs(k.lon - punkt.lon) < 0.0001);
    if (da) {
      da.nummern.push(nr);
      da.namen.push(name);
    } else {
      markers.push({ lat: punkt.lat, lon: punkt.lon, nummern: [nr], namen: [name], adresse });
    }
  }

  let out = '';
  let pos = 0;
  for (const [index, n] of [...nummerProIndex.entries()].sort((a, b) => a[0] - b[0])) {
    out += html.slice(pos, index) + `<li class="grh-teilnehmer" data-nr="${n}"><span class="grh-teilnehmer__nr" aria-hidden="true">${n}</span>`;
    pos = index + '<li>'.length;
  }
  out += html.slice(pos);
  return { html: out, markers };
}
