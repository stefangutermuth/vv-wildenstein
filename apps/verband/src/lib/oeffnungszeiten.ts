/**
 * Zerlegt die Öffnungszeiten, wie die Redaktion sie im Backend schreibt.
 *
 * Im Backend werden die Spalten mit Leerzeichen ausgerichtet
 * ("Freitag                09:00 Uhr bis 12:00 Uhr"). Im Browser fallen
 * mehrfache Leerzeichen zusammen, die Ausrichtung ginge also verloren. Hier
 * werden die Zeilen an genau dieser Lücke getrennt.
 *
 * Bewusst an EINER Stelle: die Zeiten stehen im Fuß jeder Seite und in der
 * Spalte der Verwaltungsseite. Als sie doppelt gepflegt waren, ist der Fuß
 * unbemerkt veraltet (dort standen zuletzt 14–18 statt 13–18 Uhr, der
 * Donnerstag endete angeblich 16 statt 15.15 Uhr und der Freitag fehlte ganz).
 */
export interface Zeitspanne {
  /** "Dienstag, Donnerstag, Freitag" */
  tage: string;
  /** "09:00 – 12:00 Uhr" */
  zeit: string;
}

export interface Oeffnungszeiten {
  ueberschrift: string;
  zeiten: Zeitspanne[];
  /** Alles, was sich nicht zerlegen ließ — lieber unformatiert als verschwunden. */
  absaetze: string[];
  /** Der reine Text, falls gar nichts erkannt wurde. */
  roh: string;
}

/** Kurzform für enge Spalten: "Dienstag, Donnerstag, Freitag" → "Di, Do, Fr" */
const TAGE_KURZ: Array<[RegExp, string]> = [
  [/Montag/gi, 'Mo'],
  [/Dienstag/gi, 'Di'],
  [/Mittwoch/gi, 'Mi'],
  [/Donnerstag/gi, 'Do'],
  [/Freitag/gi, 'Fr'],
  [/Samstag|Sonnabend/gi, 'Sa'],
  [/Sonntag/gi, 'So'],
];

export function kuerzeTage(tage: string): string {
  let out = tage;
  for (const [lang, kurz] of TAGE_KURZ) out = out.replace(lang, kurz);
  return out.replace(/\s+/g, ' ').trim();
}

/** Eine Tagesangabe muss einen Wochentag enthalten … */
const HAT_TAG = /Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonnabend|Sonntag|täglich/i;
/** … und die Zeitangabe eine Uhrzeit oder ein Schließwort. */
const IST_ZEIT = /\d{1,2}[:.]\d{2}|\d{1,2}\s*Uhr|Schließtag|geschlossen|nach Vereinbarung|Termin/i;

export function zerlegeOeffnungszeiten(html: string): Oeffnungszeiten {
  const roh = html
    // Impreza hängt an die Seite noch Stil- und Skriptblöcke sowie die
    // Ämter-Kacheln an. Die sind tabweise eingerückt und sähen sonst wie
    // ausgerichtete Spalten aus — dann landete "Bauamt/Liegenschaften"
    // zwischen den Sprechzeiten.
    .replace(/<(style|script)[^>]*>[\s\S]*?<\/\1>/gi, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>/gi, '\n\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&#8211;/g, '–')
    .replace(/&#8222;|&#8220;/g, '"');

  const zeiten: Zeitspanne[] = [];
  const absaetze: string[] = [];
  let ueberschrift = '';
  let offen = ''; // Tage, deren Zeit erst in der Folgezeile steht

  for (const z of roh.split('\n').map((s) => s.trimEnd())) {
    const t = z.trim();
    if (!t) continue;

    if (!ueberschrift && /Öffnungszeiten|Rathaus/i.test(t) && t.length < 60 && !/\d{2}:\d{2}/.test(t)) {
      ueberschrift = t;
      continue;
    }

    // Zeile mit Ausrichtungslücke: "Freitag        09:00 Uhr bis 12:00 Uhr"
    // Auf t statt z prüfen, sonst zählt schon die Einrückung als Lücke.
    const paar = /^(.+?)\s{2,}(.+)$/.exec(t);
    if (paar) {
      const tage = ((offen ? offen + ' ' : '') + paar[1]).trim().replace(/,\s*$/, '');
      const zeit = paar[2].trim().replace(/\s*Uhr\s*bis\s*/i, ' – ').replace(/\s+/g, ' ');
      if (HAT_TAG.test(tage) && IST_ZEIT.test(zeit)) {
        offen = '';
        zeiten.push({ tage, zeit });
        continue;
      }
    }

    // Zeile nur mit Tagen ("Dienstag, Donnerstag,") → gehört zur nächsten
    if (/^[A-Za-zÄÖÜäöü,\s]+,$/.test(t) && t.length < 40 && HAT_TAG.test(t)) {
      offen = t;
      continue;
    }

    absaetze.push(t);
  }

  return { ueberschrift, zeiten, absaetze, roh: roh.trim() };
}
