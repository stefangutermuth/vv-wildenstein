/**
 * Suchindex für die Live-Suche im schwebenden Menü.
 *
 * Wird beim Bau einmal als /suche-index.json abgelegt und im Browser erst
 * geladen, wenn jemand das Suchfeld benutzt. Enthält alle Seiten aus der
 * Navigation sowie Neuigkeiten, Termine, Ausflugsziele, Vereine, Gewerbe
 * und Kitas aus dem Redaktionssystem. Kein Server, keine Fremdanbieter.
 */
import type { APIRoute } from 'astro';
import { navigation, type NavItem } from '../lib/navigation';
import { getEvents, getNews } from '../lib/cms';
import { getKitaProfile, getProfile, getTourism, getVereine } from '../lib/cms-cpt';

export interface SuchEintrag {
  /** Titel */
  t: string;
  /** URL, relativ oder absolut */
  u: string;
  /** Art: seite, neuigkeit, termin, ausflug, verein, gewerbe, kita */
  k: string;
  /** Zusatz: Ortsteil, Datum oder kurzer Anriss */
  s?: string;
  /** Nur für die Suche, nicht sichtbar: Anrisstext */
  x?: string;
}

const ortsteilName: Record<string, string> = {
  borstendorf: 'Borstendorf',
  gruenhainichen: 'Grünhainichen',
  waldkirchen: 'Waldkirchen',
};

const fmtDatum = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

/* Stichworte, unter denen man eine Seite sucht, ohne ihren Menünamen zu
   kennen. Nur für die Suche, nicht sichtbar. */
const stichworte: Record<string, string> = {
  '/tourismus/wandern':        'Schachwanderweg Zschopautalweg Touren Wanderwege Eurorando',
  '/tourismus/baden':          'Freibad Schwimmbad Sommer Badesee',
  '/tourismus/museum':         'Volkskunst Holzkunst Wendt Kühn Engel Museum',
  '/tourismus/gastronomie':    'Essen Gasthof Restaurant Café Einkehr',
  '/tourismus/unterkuenfte':   'Übernachten Pension Ferienwohnung Hotel',
  '/tourismus/radfahren':      'Fahrrad Radweg Touren',
  '/gemeinde/amtsblatt':       'Mitteilungsblatt PDF Ausgabe Bekanntmachung',
  '/gemeinde/buergermeister':  'Robert Arnold Sprechstunde Termin',
  '/gemeinde/gemeinderat':     'Sitzung Beschluss Räte Mitglieder',
  '/gemeinde/verwaltung':      'Rathaus Verwaltungsverband Wildenstein Öffnungszeiten Sprechzeiten Ämter',
  '/gemeinde/feuerwehren':     'Feuerwehr Brandschutz Notruf',
  '/gemeinde/bauleitplanung':  'Bebauungsplan Flächennutzungsplan Bauen',
  '/leben/kita':               'Kindergarten Kindertagesstätte Hort Krippe Betreuung',
  '/leben/grundschule':        'Schule Kinder Unterricht',
  '/leben/heiraten':           'Standesamt Hochzeit Trauung Ehe',
  '/leben/buecherei':          'Bibliothek Bücher Ausleihe',
  '/leben/einkaufen':          'Hofladen Bäcker Fleischer Geschäfte',
  '/leben/gesundheit':         'Arzt Ärzte Apotheke Zahnarzt Pflege Physiotherapie',
  '/neuigkeiten/sperrungen':   'Straßensperrung Baustelle Umleitung Verkehr',
  '/gewerbe/stellenausschreibungen': 'Jobs Stellenangebote Arbeit Bewerbung',
  '/veranstaltungen':          'Termine Kalender Feste Events',
  '/kontakt':                  'Telefon E-Mail Adresse Anfahrt Öffnungszeiten',
};

function seiten(items: NavItem[], pfad: string[] = []): SuchEintrag[] {
  return items.flatMap((n) => {
    const eigene: SuchEintrag = { t: n.label, u: n.href, k: 'seite', s: pfad.join(' · ') || undefined, x: stichworte[n.href] };
    return [eigene, ...(n.children ? seiten(n.children, [...pfad, n.label]) : [])];
  });
}

function kurz(text: string | undefined, n = 90): string | undefined {
  if (!text) return undefined;
  const t = text.replace(/\s+/g, ' ').trim();
  return t.length > n ? t.slice(0, n - 1).trimEnd() + '…' : t || undefined;
}

export const GET: APIRoute = async () => {
  const sicher = async <T,>(f: () => Promise<T[]>): Promise<T[]> => {
    try { return await f(); } catch { return []; }
  };

  const [news, events, tourism, vereine, profile, kitas] = await Promise.all([
    sicher(() => getNews()),
    sicher(() => getEvents()),
    sicher(() => getTourism()),
    sicher(() => getVereine()),
    sicher(() => getProfile()),
    sicher(() => getKitaProfile()),
  ]);

  const kitaSlugs = new Set(kitas.map((k) => k.slug));

  const eintraege: SuchEintrag[] = [
    ...seiten(navigation),
    { t: 'Kontakt', u: '/kontakt', k: 'seite', x: stichworte['/kontakt'] },
    { t: 'Impressum', u: '/impressum', k: 'seite' },
    { t: 'Datenschutz', u: '/datenschutz', k: 'seite' },
    { t: 'Barrierefreiheit', u: '/barrierefreiheit', k: 'seite' },
    { t: 'Mängelmelder', u: 'https://melder.vv-wildenstein.com/', k: 'seite', s: 'Schaden melden' },
    ...news.map((n) => ({ t: n.title, u: n.href, k: 'neuigkeit', s: fmtDatum.format(n.date), x: kurz(n.excerpt, 200) })),
    ...events.map((e) => ({
      t: e.title,
      u: `/veranstaltungen/${e.slug}`,
      k: 'termin',
      s: [fmtDatum.format(e.startDate), e.location].filter(Boolean).join(' · '),
      x: kurz(e.teaser, 200),
    })),
    ...tourism.map((t) => ({
      t: t.title,
      u: `/tourismus/eintrag/${t.slug}`,
      k: 'ausflug',
      s: t.ortsteil ? ortsteilName[t.ortsteil] : undefined,
      x: kurz(t.excerpt, 200),
    })),
    ...vereine.map((v) => ({ t: v.title, u: `/vereine/${v.slug}`, k: 'verein', s: v.ortsteil ? ortsteilName[v.ortsteil] : undefined })),
    ...profile
      .filter((p) => !kitaSlugs.has(p.slug))
      .map((p) => ({ t: p.title, u: `/gewerbe/${p.slug}`, k: 'gewerbe', s: [p.kategorien[0]?.replace(/-/g, ' '), p.ortsteil ? ortsteilName[p.ortsteil] : ''].filter(Boolean).join(' · ') || undefined })),
    ...kitas.map((k) => ({ t: k.title, u: `/leben/kita/${k.slug}`, k: 'kita', s: k.ortsteil ? ortsteilName[k.ortsteil] : undefined })),
  ];

  // Doppelte URLs raus (Navigation nennt manche Seite zweimal)
  const gesehen = new Set<string>();
  const einmalig = eintraege.filter((e) => {
    const key = e.u.replace(/\/$/, '');
    if (gesehen.has(key)) return false;
    gesehen.add(key);
    return true;
  });

  return new Response(JSON.stringify(einmalig), {
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
};
