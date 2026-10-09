/**
 * Teilnehmerkarte aus einer Terminbeschreibung.
 *
 * Die Redaktion pflegt Teilnehmer in WordPress als Liste in der Beschreibung:
 *   <li><strong>Name</strong>, Straße Hausnummer[, Ort]<br>Angebot</li>
 * Hier werden diese Einträge erkannt, zur Build-Zeit verortet und fortlaufend
 * nummeriert. Die Nummern stehen dann in der Liste und auf der Karte.
 * Einträge ohne Adresse bleiben unverändert und ohne Nummer.
 *
 * Besonderheiten stehen als Attribut am <li> (in WordPress in der HTML-Ansicht):
 *   data-nr="A"
 *       eigenes Zeichen statt der laufenden Nummer, zählt nicht mit
 *   data-zeichen="haltestelle wc essen"
 *       am Teilnehmer: Dort gibt es auch eine Shuttle-Haltestelle, ein WC, etwas zu
 *       essen. Die Zeichen stehen in der Liste und auf der Karte neben der Nummer.
 *   data-karte="parken|haltestelle|essen|wc" data-lat="50.76492" data-lon="13.14472"
 *       weiterer Punkt für die Karte, ohne Adresssuche:
 *       <li data-karte="parken" data-lat="…" data-lon="…" data-shuttle="ja"><strong>P1 Grundschule</strong>, mit Shuttle</li>
 *       Bei Parkplätzen wird ein Kürzel am Namensanfang („P1“) zum Zeichen,
 *       data-shuttle="ja" kennzeichnet einen Parkplatz mit Shuttle-Haltestelle.
 *       Ohne data-lat und data-lon steht nur das Zeichen in der Liste, etwa bei
 *       „Kaffee und Kuchen bei Teilnehmer 4“, der schon auf der Karte ist.
 */
import { geocode } from './geocode';
import { PUNKT_ARTEN, zeichenHtml, zeichenReihe, type KartenPunkt, type PunktArt } from './kartenzeichen';

export interface TeilnehmerMarker {
  lat: number;
  lon: number;
  /** Zeichen der Teilnehmer an dieser Stelle: laufende Nummer oder eigenes Zeichen (mehrere bei gleicher Adresse). */
  zeichen: string[];
  namen: string[];
  adresse: string;
  /** Was es an dieser Stelle außerdem gibt (data-zeichen), in der Reihenfolge der Karte. */
  dazu: PunktArt[];
}

export interface TeilnehmerProgramm {
  /** Beschreibung mit Nummern und Zeichen vor den verorteten Einträgen. */
  html: string;
  markers: TeilnehmerMarker[];
  /** Parkplätze, Haltestellen, Essen, WC. */
  punkte: KartenPunkt[];
}

// Mit Postleitzahl, sonst landet z. B. „Börnichen“ im gleichnamigen Ortsteil von Oederan.
const ORTE: Record<string, string> = {
  'Grünhainichen': '09579 Grünhainichen',
  'Borstendorf':   '09579 Borstendorf',
  'Waldkirchen':   '09579 Waldkirchen',
  'Börnichen':     '09437 Börnichen',
};
const LI_RE = /<li\b([^>]*)>\s*<strong>([\s\S]*?)<\/strong>\s*,\s*([^<]+?)\s*(?=<br\s*\/?>|<\/li>)/g;
const PUNKT_RE = /<li\b([^>]*\bdata-karte=[^>]*)>\s*<strong>([\s\S]*?)<\/strong>([^<]*)/g;
const HAUSNUMMER_RE = /\s\d+\s?[a-z]?$/i;

function decode(s: string): string {
  return s.replace(/&amp;/g, '&').replace(/&#8211;|&ndash;/g, '-').replace(/<[^>]+>/g, '').trim();
}

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function attribut(tag: string, name: string): string {
  return tag.match(new RegExp(`\\b${name}=["']([^"']*)["']`))?.[1]?.trim() ?? '';
}

function geoQuery(adresse: string): string {
  const teile = adresse.split(',').map((t) => t.trim());
  const strasse = teile[0].replace(/([Ss])tr\.(?=\s|$)/, '$1traße');
  const ort = Object.keys(ORTE).find((o) => teile.slice(1).some((t) => t.includes(o))) ?? 'Grünhainichen';
  return `${strasse}, ${ORTE[ort]}`;
}

export async function buildTeilnehmerProgramm(html: string): Promise<TeilnehmerProgramm> {
  const treffer = [...html.matchAll(LI_RE)].filter(
    (m) => !/\bdata-karte=/.test(m[1]) && HAUSNUMMER_RE.test(m[3].split(',')[0].trim()),
  );
  // Unter drei Adressen lohnt keine Karte: dann bleibt die Beschreibung, wie sie ist.
  if (treffer.length < 3) return { html, markers: [], punkte: [] };

  // Stellen, an denen ein Stück der Beschreibung ersetzt wird.
  const ersetzen: { von: number; bis: number; text: string }[] = [];

  const markers: TeilnehmerMarker[] = [];
  let nr = 0;
  for (const m of treffer) {
    const adresse = decode(m[3]);
    const punkt = await geocode(geoQuery(adresse));
    if (!punkt) continue;
    const zeichen = attribut(m[1], 'data-nr') || String(++nr);
    ersetzen.push({
      von: m.index!,
      bis: m.index! + m[0].indexOf('>') + 1,
      text: `<li class="grh-teilnehmer" data-nr="${esc(zeichen)}"><span class="grh-teilnehmer__nr" aria-hidden="true">${esc(zeichen)}</span>`,
    });
    const name = decode(m[2]);
    const dazu = PUNKT_ARTEN.filter((art) => attribut(m[1], 'data-zeichen').split(/[\s,]+/).includes(art));
    if (dazu.length > 0) {
      // Die Zeichen stehen hinter der Adresse, vor dem Angebot.
      const ende = m.index! + m[0].length;
      ersetzen.push({ von: ende, bis: ende, text: ` <span class="grh-teilnehmer__zeichen">${zeichenReihe(dazu)}</span>` });
    }
    // Gleiche Adresse (auf etwa 10 m): ein gemeinsamer Punkt statt zwei übereinander.
    const da = markers.find((k) => Math.abs(k.lat - punkt.lat) < 0.0001 && Math.abs(k.lon - punkt.lon) < 0.0001);
    if (da) {
      da.zeichen.push(zeichen);
      da.namen.push(name);
      da.dazu = PUNKT_ARTEN.filter((art) => da.dazu.includes(art) || dazu.includes(art));
    } else {
      markers.push({ lat: punkt.lat, lon: punkt.lon, zeichen: [zeichen], namen: [name], adresse, dazu });
    }
  }

  const punkte: KartenPunkt[] = [];
  for (const m of html.matchAll(PUNKT_RE)) {
    const art = attribut(m[1], 'data-karte') as PunktArt;
    const lat = parseFloat(attribut(m[1], 'data-lat').replace(',', '.'));
    const lon = parseFloat(attribut(m[1], 'data-lon').replace(',', '.'));
    if (!PUNKT_ARTEN.includes(art)) continue;
    // „P1 Grundschule“: Das Kürzel wird zum Zeichen, der Rest bleibt der Name.
    const kuerzel = art === 'parken' ? m[2].match(/^\s*(P\d*)\s+([\s\S]+)$/) : null;
    const nameHtml = kuerzel ? kuerzel[2] : m[2];
    const p: KartenPunkt = {
      art,
      lat,
      lon,
      zeichen: art === 'parken' ? (kuerzel?.[1] ?? 'P') : art === 'haltestelle' ? 'H' : art === 'wc' ? 'WC' : '',
      name: decode(nameHtml),
      zusatz: decode(m[3]).replace(/^[\s,·]+/, ''),
      shuttle: /^(ja|1|true)$/i.test(attribut(m[1], 'data-shuttle')),
    };
    // Ohne Koordinaten bekommt der Eintrag nur sein Zeichen in der Liste.
    if (!Number.isNaN(lat) && !Number.isNaN(lon)) punkte.push(p);
    ersetzen.push({
      von: m.index!,
      bis: m.index! + m[0].length - m[3].length,
      text: `<li class="grh-kartenpunkt">${zeichenHtml(p)}<strong>${nameHtml}</strong>`,
    });
  }

  let out = '';
  let pos = 0;
  for (const e of ersetzen.sort((a, b) => a.von - b.von)) {
    out += html.slice(pos, e.von) + e.text;
    pos = e.bis;
  }
  out += html.slice(pos);
  return { html: out, markers, punkte };
}
