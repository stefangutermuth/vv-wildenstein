/**
 * Live-Suche im Browser. Wird vom Suchfeld in der Kopfzeile und vom
 * schwebenden Menü gemeinsam benutzt; der Index (/suche-index.json) wird
 * nur einmal geladen, egal welches Feld zuerst benutzt wird.
 *
 * Jedes eingegebene Wort muss im Titel, Zusatz oder Anriss vorkommen;
 * Treffer im Titelanfang und Seiten aus der Navigation stehen weiter oben.
 */

export interface Eintrag {
  t: string;
  u: string;
  k: string;
  s?: string;
  x?: string;
  n?: string;
}

const ART: Record<string, string> = {
  seite: 'Seite', neuigkeit: 'Neuigkeit', termin: 'Termin', ausflug: 'Ausflugsziel',
  verein: 'Verein', gewerbe: 'Gewerbe', kita: 'Kita',
};

const norm = (t: string) => t.toLowerCase()
  .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
  .normalize('NFD').replace(/[̀-ͯ]/g, '');

let index: Eintrag[] | null = null;
let laden: Promise<Eintrag[]> | null = null;

export function ladeIndex(): Promise<Eintrag[]> {
  if (!laden) {
    laden = fetch('/suche-index.json')
      .then((r) => (r.ok ? r.json() : []))
      .then((d: Eintrag[]) => {
        index = d.map((e) => ({ ...e, n: norm(e.t + ' ' + (e.s ?? '') + ' ' + (e.x ?? '')) }));
        return index;
      })
      .catch(() => (index = []));
  }
  return laden;
}

export async function suchen(frage: string, max = 8): Promise<Eintrag[]> {
  const daten = index ?? (await ladeIndex());
  const woerter = norm(frage).split(/\s+/).filter(Boolean);
  if (woerter.length === 0) return [];
  return daten
    .map((e) => {
      if (!woerter.every((w) => (e.n ?? '').includes(w))) return null;
      const titel = norm(e.t);
      let punkte = 0;
      if (titel.startsWith(woerter[0])) punkte += 3;
      if (woerter.every((w) => titel.includes(w))) punkte += 2;
      if (e.k === 'seite') punkte += 1;
      return { e, punkte };
    })
    .filter((x): x is { e: Eintrag; punkte: number } => x !== null)
    .sort((a, b) => b.punkte - a.punkte)
    .slice(0, max)
    .map((x) => x.e);
}

const esc = (t: string) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;');

export interface SucheOptionen {
  input: HTMLInputElement;
  liste: HTMLElement;
  /** Wird ausgeblendet, sobald es Treffer gibt (z. B. ein Hinweistext) */
  hinweis?: HTMLElement | null;
  /** Eindeutiger Präfix für die Treffer-IDs, wenn es mehrere Felder gibt */
  prefix?: string;
}

/** Verdrahtet ein Eingabefeld mit einer Trefferliste. */
export function initSuche({ input, liste, hinweis, prefix = 'grh-treffer' }: SucheOptionen): void {
  let auswahl = -1;
  let warte: number | undefined;

  const zeige = (treffer: Eintrag[], frage: string) => {
    auswahl = -1;
    input.removeAttribute('aria-activedescendant');
    if (!frage) {
      liste.setAttribute('hidden', '');
      liste.innerHTML = '';
      hinweis?.removeAttribute('hidden');
      return;
    }
    hinweis?.setAttribute('hidden', '');
    liste.removeAttribute('hidden');
    if (treffer.length === 0) {
      liste.innerHTML = '<li class="grh-suche__leer">Nichts gefunden. Versuchen Sie ein anderes Wort.</li>';
      return;
    }
    liste.innerHTML = treffer.map((e, i) => {
      const extern = /^https?:/.test(e.u);
      return `<li role="option" id="${prefix}-${i}"><a class="grh-suche__result" href="${esc(e.u)}"${extern ? ' target="_blank" rel="noopener"' : ''}>`
        + `<span class="grh-suche__kind">${ART[e.k] ?? esc(e.k)}</span>`
        + `<span class="grh-suche__title">${esc(e.t)}</span>`
        + (e.s ? `<span class="grh-suche__sub">${esc(e.s)}</span>` : '')
        + `</a></li>`;
    }).join('');
  };

  const lauf = async () => {
    const frage = input.value.trim();
    if (frage.length < 2) { zeige([], ''); return; }
    const treffer = await suchen(frage);
    // Nur anzeigen, wenn die Eingabe inzwischen nicht weitergegangen ist
    if (input.value.trim() === frage) zeige(treffer, frage);
  };

  input.addEventListener('input', () => {
    window.clearTimeout(warte);
    warte = window.setTimeout(lauf, 120);
  });
  input.addEventListener('focus', () => { ladeIndex(); });
  input.addEventListener('keydown', (e) => {
    const links = Array.from(liste.querySelectorAll<HTMLAnchorElement>('a.grh-suche__result'));
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (links.length === 0) return;
      e.preventDefault();
      auswahl = e.key === 'ArrowDown' ? Math.min(auswahl + 1, links.length - 1) : Math.max(auswahl - 1, 0);
      links.forEach((l, i) => l.setAttribute('aria-selected', String(i === auswahl)));
      links[auswahl].scrollIntoView({ block: 'nearest' });
      input.setAttribute('aria-activedescendant', `${prefix}-${auswahl}`);
    } else if (e.key === 'Enter') {
      const ziel = links[auswahl >= 0 ? auswahl : 0];
      if (ziel) { e.preventDefault(); ziel.click(); }
    }
  });
}
