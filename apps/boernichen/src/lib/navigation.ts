export type NavItem = {
  label: string;
  href: string;
  description?: string;
  children?: NavItem[];
  /** Wenn true: Elternpunkt ist reiner Dropdown-Auslöser, ein Klick navigiert nicht. */
  noLink?: boolean;
};

/**
 * Master-Navigation der Website. Wird von GrhHeader (klassische Inline-Nav)
 * und GrhMegaMenu (Vollbild-Popup) gemeinsam benutzt.
 * Struktur gespiegelt vom Original boernichen.de.
 */
export const navigation: NavItem[] = [
  {
    label: 'Gemeinde',
    href: '/unser-boernichen-erzgeb',
    children: [
      { label: 'Unser Börnichen/Erzgeb.', href: '/unser-boernichen-erzgeb' },
      { label: 'Verwaltung',              href: '/verwaltung' },
      { label: 'Amtsblatt',               href: '/amtsblatt' },
      { label: 'Termine',                 href: '/termine' },
      { label: 'Bauleitplanung',          href: '/bauleitplanung' },
      { label: 'Feuerwehr Börnichen',     href: '/vereine/feuerwehr-boernichen' },
    ],
  },
  {
    label: 'Neuigkeiten',
    href: '/neuigkeiten',
  },
  {
    label: 'Tourismus',
    href: '/tourismus',
  },
  {
    label: 'Leben in Börnichen',
    href: '/leben-in-boernichen',
    noLink: true,
    children: [
      { label: 'Gewerbe',                           href: '/gewerbe' },
      { label: 'Vereine',                           href: '/vereine' },
      { label: 'Evangel.-luther. Kirche Börnichen', href: '/vereine/kirche' },
      { label: 'Grundschule „Im Grünen“',           href: '/vereine/grundschule' },
      { label: 'Kita Wunderland',                   href: '/vereine/kita-wunderland' },
      { label: 'Seniorentreff Börnichen',           href: '/vereine/seniorentreff-boernichen' },
    ],
  },
  {
    label: 'Veranstaltungen',
    href: '/veranstaltungen',
  },
];
