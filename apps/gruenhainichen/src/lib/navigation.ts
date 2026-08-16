export type NavItem = {
  label: string;
  href: string;
  description?: string;
  children?: NavItem[];
  /** Inhalt noch nicht final geklärt — UI zeigt ein rotes Ausrufezeichen. */
  wip?: boolean;
};

/**
 * Master-Navigation der Website. Wird von GrhHeader (klassische Inline-Nav)
 * und GrhMegaMenu (Vollbild-Popup auf der Variante) gemeinsam benutzt.
 *
 * `wip: true` markiert Seiten, deren Inhalt noch nicht final entschieden ist —
 * sie existieren als Platzhalter, das MegaMenu zeigt ein rotes ❗.
 */
export const navigation: NavItem[] = [
  {
    label: 'Gemeinde',
    href: '/gemeinde',
    children: [
      {
        label: 'Ortsteile',
        href: '/gemeinde/ortsteile',
        children: [
          { label: 'Ortsteil Borstendorf',    href: '/gemeinde/ortsteile/borstendorf' },
          { label: 'Ortsteil Grünhainichen',  href: '/gemeinde/ortsteile/gruenhainichen' },
          { label: 'Ortsteil Waldkirchen',    href: '/gemeinde/ortsteile/waldkirchen' },
        ],
      },
      { label: 'Geschichte',          href: '/gemeinde/geschichte' },
      { label: 'Bürgermeister',       href: '/gemeinde/buergermeister' },
      { label: 'Verwaltung',          href: '/gemeinde/verwaltung' },
      { label: 'Gemeinderat',         href: '/gemeinde/gemeinderat' },
      { label: 'Amtsblatt',           href: '/gemeinde/amtsblatt' },
      { label: 'Feuerwehren',         href: '/gemeinde/feuerwehren' },
      { label: 'Bauleitplanung',      href: '/gemeinde/bauleitplanung' },

      /* Vorübergehend herausgenommen, bis die Abläufe mit der Verwaltung
         stehen (Stand 16.08.2026):
           • „Räume buchen" mit Belegungsplan
           • „Beitrag einreichen" unter Amtsblatt
         Die Seiten liegen weiterhin im Repo, aber mit Unterstrich davor
         (src/pages/gemeinde/_raeume/ und _einreichen.astro) — so baut Astro
         sie nicht mit. Zum Wiedereinschalten: Unterstriche entfernen, diese
         Einträge zurückholen und die beiden Weiterleitungen in
         public/.htaccess löschen. */
    ],
  },
  {
    label: 'Neuigkeiten',
    href: '/neuigkeiten',
    children: [
      { label: 'Alle Neuigkeiten', href: '/neuigkeiten' },
      { label: 'Sperrungen',       href: '/neuigkeiten/sperrungen' },
    ],
  },
  {
    label: 'Tourismus',
    href: '/tourismus',
    children: [
      { label: 'Sehenswürdigkeiten', href: '/tourismus/sehenswuerdigkeiten' },
      { label: 'Museum',             href: '/tourismus/museum' },
      { label: 'Baden',              href: '/tourismus/baden' },
      { label: 'Gastronomie',        href: '/tourismus/gastronomie' },
      { label: 'Wandern',            href: '/tourismus/wandern' },
      { label: 'Radfahren',          href: '/tourismus/radfahren' },
      { label: 'Unterkünfte',        href: '/tourismus/unterkuenfte' },
    ],
  },
  {
    label: 'Gewerbe',
    href: '/gewerbe',
    children: [
      { label: 'Übersicht',              href: '/gewerbe' },
      { label: 'Stellenausschreibungen', href: '/gewerbe/stellenausschreibungen', wip: true },
    ],
  },
  { label: 'Vereine', href: '/vereine' },
  {
    label: 'Leben',
    href: '/leben',
    children: [
      { label: 'Gesundheit',                 href: '/leben/gesundheit' },
      { label: 'Kindertagesstätte / Hort',   href: '/leben/kita' },
      { label: 'Grundschule',                href: '/leben/grundschule' },
      { label: 'Einkaufen',                  href: '/leben/einkaufen' },
      { label: 'Heiraten',                   href: '/leben/heiraten' },
      { label: 'Freizeit',                   href: '/vereine' },
      { label: 'Gemeinde Bücherei',          href: '/leben/buecherei' },
      { label: 'Kirche',                     href: '/leben/kirche' },
      { label: 'Lebendiger Adventskalender', href: '/leben/lebendiger-adventskalender' },
    ],
  },
  {
    label: 'Veranstaltungen',
    href: '/veranstaltungen',
    children: [
      { label: 'Alle Veranstaltungen', href: '/veranstaltungen' },
      {
        label: 'Heimatfest',
        href: '/veranstaltungen/heimatfest',
        children: [
          { label: 'Rückblick / Bilder',  href: '/veranstaltungen/heimatfest/rueckblick' },
          { label: 'Jubiläumsprodukte',   href: '/veranstaltungen/heimatfest/jubilaeumsprodukte' },
        ],
      },
      { label: 'EURORANDO 2026', href: '/veranstaltungen/eurorando-2026' },
    ],
  },
];
