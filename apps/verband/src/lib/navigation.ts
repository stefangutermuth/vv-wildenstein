export type NavItem = {
  label: string;
  href: string;
  description?: string;
  external?: boolean;
  children?: NavItem[];
};

/**
 * Master-Navigation des Verbands-Frontends. Struktur bewusst nah am bisherigen
 * Impreza-Auftritt: Verband/Verwaltung (Ämter), Verbandsversammlung, Service.
 * Wird von VvHeader (Desktop-Inline + Mobile-Panel) genutzt.
 *
 * Viele Ziele zeigen aktuell auf Platzhalter-Pfade (#) bzw. die Live-WP-Seiten —
 * in der Skeleton-Phase geht es zunächst um Startseite + Struktur.
 */
/**
 * WICHTIG: Alle Ziele sind ECHTE Seitenpfade aus dem zentralen WordPress
 * (gleiche URLs wie die Original-Website → keine kaputten Links beim Umzug).
 * Die Seiten selbst generiert src/pages/[...slug].astro beim Build.
 */
export const navigation: NavItem[] = [
  {
    label: 'Verband',
    href: '/verband',
    children: [
      { label: 'Der Verwaltungsverband',      href: '/verband' },
      { label: 'Verbandsversammlung',         href: '/verband/verbandsversammlung' },
      { label: 'Rechtssachen / Satzungen',    href: '/verband/rechtssachen-satzungen' },
      { label: 'Schiedsstelle',               href: '/verband/schiedsstelle' },
      { label: 'Bauleitplanung',              href: '/verband/bauleitplanung' },
      { label: 'Feuerwehren',                 href: '/verband/feuerwehren' },
      { label: 'Ausschreibungen',             href: '/verband/ausschreibungen' },
      { label: 'Amtsblatt',                   href: '/amtsblatt' },
      { label: 'Wahlen',                      href: '/wahlen' },
    ],
  },
  {
    label: 'Verwaltung',
    href: '/verwaltung',
    children: [
      { label: 'Öffnungszeiten & Ämter',      href: '/verwaltung' },
      { label: 'Dienstleistungen',            href: '/dienstleistungen' },
      { label: 'Heiraten',                    href: '/heiraten' },
      { label: 'Kindertagesstätten / Hort',   href: '/kindertagesstaetten' },
      { label: 'Kita-Formulare',              href: '/formulare-der-kindertageseinrichtungen-gruenhainichen' },
      { label: 'Downloads',                   href: '/downloads' },
      { label: 'Datenschutzbeauftragte',      href: '/datenschutzbeauftragte' },
    ],
  },
  {
    label: 'Tourismus',
    href: '/tourismus_uebersicht',
    children: [
      { label: 'Übersicht',           href: '/tourismus_uebersicht' },
      { label: 'Sehenswürdigkeiten',  href: '/tourismus_uebersicht/tourismus_uebersicht' },
      { label: 'Museum',              href: '/tourismus_uebersicht/museum' },
      { label: 'Gastronomie',         href: '/tourismus_uebersicht/gastronomie' },
      { label: 'Unterkünfte',         href: '/tourismus_uebersicht/unterkuenfte' },
      { label: 'Aktivitäten',         href: '/tourismus_uebersicht/aktivitaeten' },
      { label: 'Holzkunst',           href: '/holzkunst' },
      { label: 'Freibad',             href: '/leben-freizeit/freibad' },
    ],
  },
  {
    label: 'Wirtschaft',
    href: '/wirtschaft',
    children: [
      { label: 'Überblick & Unternehmen', href: '/wirtschaft' },
      { label: 'Stellenanzeigen',         href: '/stellenanzeigen' },
      { label: 'Stellenausschreibungen',  href: '/stellenausschreibungen' },
      { label: 'Vergabe / Ausschreibungen', href: '/vergabeausschreibungen' },
      { label: 'Gewerbeeintrag melden',   href: '/informationen/gewerbeeintrag' },
    ],
  },
  {
    label: 'Leben & Freizeit',
    href: '/leben-freizeit',
    children: [
      { label: 'Übersicht',            href: '/leben-freizeit' },
      { label: 'Familien',             href: '/leben-freizeit/familien' },
      { label: 'Kindergarten',         href: '/leben-freizeit/kindergarten' },
      { label: 'Schule',               href: '/leben-freizeit/schule' },
      { label: 'Jugend',               href: '/leben-freizeit/jugend' },
      { label: 'Senioren',             href: '/leben-freizeit/senioren' },
      { label: 'Gesundheit',           href: '/leben-freizeit/gesundheit' },
      { label: 'Kirchen',              href: '/kirchen' },
      { label: 'Einkaufen',            href: '/leben-freizeit/einkaufen' },
      { label: 'Bauen / Wohnen',       href: '/leben-freizeit/bauen-wohnen' },
      { label: 'Freizeit & Kultur',    href: '/leben-freizeit/freizeit' },
      { label: 'Freibad',              href: '/leben-freizeit/freibad' },
      { label: 'Veranstaltungen',      href: '/veranstaltungen' },
      { label: 'Adventskalender',      href: '/lebendigen-adventskalender' },
    ],
  },
  {
    label: 'Projekte',
    href: '/projekte',
  },
];

