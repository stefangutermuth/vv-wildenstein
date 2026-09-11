/**
 * WordPress-REST-Adapter für den Verwaltungsverband Wildenstein.
 *
 * Strategie (Verbandssicht = Klammer über alle Mitgliedsgemeinden):
 *   - Lädt die neuesten Posts der zentralen Installation
 *   - Filtert NICHT auf einen Ortsteil (im Gegensatz zum Grünhainichen-Frontend)
 *   - Mappt WP-Term-Slugs auf das interne Verbands-Schema (Bekanntmachung,
 *     Veranstaltung, Sperrung, Ausschreibung)
 *   - Ergänzt Featured-Image aus der embedded `wp:featuredmedia`
 *
 * REST-Base wird über PUBLIC_WP_API_BASE überschrieben (wichtig, sobald das
 * zentrale WordPress auf eine eigene Subdomain umzieht). Default = Live.
 */

import type { NewsItem, NewsCategory, EventItem } from './cms';
import { zerlegeOeffnungszeiten, type Zeitspanne } from './oeffnungszeiten';

const WP_BASE =
  (import.meta.env.PUBLIC_WP_API_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_WP_API_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/wp/v2';

const WP_AUTH_USER =
  (typeof process !== 'undefined' ? process.env.WP_AUTH_USER : undefined) ??
  (import.meta.env.WP_AUTH_USER as string | undefined) ??
  '';
const WP_AUTH_PASS =
  (typeof process !== 'undefined' ? process.env.WP_AUTH_PASS : undefined) ??
  (import.meta.env.WP_AUTH_PASS as string | undefined) ??
  '';

function buildAuthHeader(): Record<string, string> {
  if (!WP_AUTH_USER || !WP_AUTH_PASS) return {};
  const token = Buffer.from(`${WP_AUTH_USER}:${WP_AUTH_PASS}`).toString('base64');
  return { Authorization: `Basic ${token}` };
}

/** Mapping WP-Term-Slug → Verbands-NewsCategory. Erste Übereinstimmung gewinnt. */
const CATEGORY_RULES: Array<{ match: RegExp; cat: NewsCategory }> = [
  { match: /^sperrung/i,                          cat: 'sperrung'      },
  { match: /ausschreibung/i,                      cat: 'ausschreibung' },
  { match: /^(kultur|tourismus|veranstaltung)/i,  cat: 'veranstaltung' },
];

interface WPPost {
  id: number;
  date: string;
  slug: string;
  link: string;
  title:   { rendered: string };
  excerpt: { rendered: string };
  content: { rendered: string };
  categories: number[];
  featured_media: number;
  sticky?: boolean;
  _embedded?: {
    'wp:featuredmedia'?: Array<{
      source_url?: string;
      mime_type?: string;
      media_details?: {
        width?: number;
        height?: number;
        sizes?: Record<string, { source_url: string; width: number }>;
      };
      alt_text?: string;
    }>;
    'wp:term'?: Array<Array<{ id: number; slug: string; name: string; taxonomy: string }>>;
  };
}

/**
 * Prozent-kodierte Slugs (z. B. „%c2%a7" = §) brechen Astros statisches
 * [slug]-Routing. Früher wurden solche Beiträge übersprungen — dabei fielen
 * amtliche Bekanntmachungen zum Flächennutzungsplan unter den Tisch.
 * Jetzt wird der Slug entschärft: dekodieren, Sonderzeichen entfernen,
 * Bindestriche normalisieren. Der Beitrag bleibt damit erhalten.
 */
export function entschaerfeSlug(slug: string): string {
  if (!slug.includes('%')) return slug;
  let klar = slug;
  try {
    klar = decodeURIComponent(slug);
  } catch {
    klar = slug.replace(/%[0-9a-f]{2}/gi, '');
  }
  const sauber = klar
    .toLowerCase()
    .replace(/[äÄ]/g, 'ae').replace(/[öÖ]/g, 'oe').replace(/[üÜ]/g, 'ue').replace(/ß/g, 'ss')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
  return sauber || slug.replace(/%/g, '');
}

export interface Sprungmarke {
  id: string;
  titel: string;
  ebene: number;
}

/**
 * Versieht die Zwischenüberschriften eines Inhalts mit Sprungmarken und gibt
 * sie als Liste zurück. Grundlage für das Themen-Menü auf langen Amtsseiten
 * (Einwohnermeldeamt: 48.000 Zeichen, 31 Überschriften) — ohne das muss man
 * durch die ganze Seite scrollen, um „Personalausweis" zu finden.
 *
 * Nur h2 wird aufgenommen: h3/h4 sind dort Unterpunkte einzelner Leistungen
 * und würden das Menü unbrauchbar lang machen.
 */
export function baueSprungmarken(html: string): { html: string; marken: Sprungmarke[] } {
  if (!html) return { html, marken: [] };
  const marken: Sprungmarke[] = [];
  const vergeben = new Set<string>();

  const out = html.replace(
    /<h2([^>]*)>([\s\S]*?)<\/h2>/gi,
    (treffer, attr: string, inhalt: string) => {
      const titel = decodeEntities(inhalt.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ')).trim();
      if (!titel) return treffer;

      // Vorhandene ID übernehmen, sonst aus dem Titel bilden
      const vorhanden = /\bid=["']([^"']+)["']/i.exec(attr)?.[1];
      let id =
        vorhanden ||
        titel
          .toLowerCase()
          .replace(/[äÄ]/g, 'ae').replace(/[öÖ]/g, 'oe').replace(/[üÜ]/g, 'ue').replace(/ß/g, 'ss')
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-|-$/g, '')
          .slice(0, 60);
      if (!id) id = `abschnitt-${marken.length + 1}`;
      // Doppelte Überschriften (z. B. zweimal „Gebühren") auseinanderhalten
      let eindeutig = id;
      let n = 2;
      while (vergeben.has(eindeutig)) eindeutig = `${id}-${n++}`;
      vergeben.add(eindeutig);

      marken.push({ id: eindeutig, titel, ebene: 2 });
      const attrOhneId = attr.replace(/\s*\bid=["'][^"']*["']/i, '');
      return `<h2${attrOhneId} id="${eindeutig}" tabindex="-1">${inhalt}</h2>`;
    },
  );
  return { html: out, marken };
}

const PLACEHOLDER_PATTERNS = [/platzhalter/i, /placeholder/i, /beitrag_platzhalter/i];
function isPlaceholderUrl(url: string): boolean {
  return PLACEHOLDER_PATTERNS.some((p) => p.test(url));
}

/**
 * Alle Einträge eines Endpunkts über beliebig viele Seiten holen (per_page=100).
 * Wichtig, damit Detailseiten-Bau und Link-Umschreibung denselben Umfang sehen
 * (sonst zeigen umgeschriebene /neuigkeiten/{slug}-Links auf ungebaute Seiten).
 */
async function fetchAllPages<T>(restBase: string, params: Record<string, string> = {}): Promise<T[]> {
  const out: T[] = [];
  for (let page = 1; page <= 25; page++) {
    const url = new URL(`${WP_BASE}/${restBase}`);
    url.searchParams.set('per_page', '100');
    url.searchParams.set('page', String(page));
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
    });
    if (!res.ok) {
      if (page > 1) break; // keine weitere Seite mehr
      throw new Error(`WP REST ${restBase} ${res.status} ${res.statusText}`);
    }
    const batch = (await res.json()) as T[];
    out.push(...batch);
    if (batch.length < 100) break;
  }
  return out;
}

export async function fetchWordPressNews(): Promise<NewsItem[]> {
  const posts = (await fetchAllPages<WPPost>('posts', {
    _embed: 'wp:featuredmedia,wp:term',
    orderby: 'date',
    order: 'desc',
  })).map((p) => (p.slug.includes('%') ? { ...p, slug: entschaerfeSlug(p.slug) } : p));
  const postSlugs = await ensurePostSlugs();
  const downloadKarte = await ensureDownloadKarte();
  return posts
    .map((p) => mapWPPostToNewsItem(p, postSlugs, downloadKarte))
    .filter((n): n is NewsItem => n !== null);
}

function mapWPPostToNewsItem(
  p: WPPost,
  postSlugs: Set<string>,
  downloadKarte: Map<string, string>,
): NewsItem | null {
  const termSlugs = collectTermSlugs(p);
  const category = pickCategory(termSlugs);
  /* Erst umschreiben, dann das Vorschaubild herausziehen: Sonst stammt es aus
     dem Rohinhalt und behaelt Adressen der aufgeloesten Multisite — drei
     Vorschaubilder in der Neuigkeitenliste zeigten auf
     http://gruenhainichen.com/…, das dort 404 liefert und auf einer
     https-Seite ohnehin blockiert wuerde. */
  const inhalt = rewriteContentUrls(p.content?.rendered ?? '', postSlugs, downloadKarte);
  const image = pickFeaturedImage(p) ?? pickInlineImage(inhalt);

  return {
    slug: p.slug,
    title: decodeEntities(p.title.rendered),
    date: new Date(p.date),
    category,
    image,
    excerpt: stripHtml(p.excerpt.rendered).trim(),
    featured: p.sticky ?? false,
    // Interne Detailseite statt Link auf die alte WP-Ansicht
    href: `/neuigkeiten/${p.slug}`,
    contentHtml: inhalt,
  };
}

function collectTermSlugs(p: WPPost): Set<string> {
  const out = new Set<string>();
  for (const grp of p._embedded?.['wp:term'] ?? []) {
    for (const t of grp) out.add(t.slug);
  }
  return out;
}

function pickCategory(slugs: Set<string>): NewsCategory {
  for (const s of slugs) {
    for (const rule of CATEGORY_RULES) {
      if (rule.match.test(s)) return rule.cat;
    }
  }
  return 'bekanntmachung';
}

function pickFeaturedImage(p: WPPost): string | undefined {
  const media = p._embedded?.['wp:featuredmedia']?.[0];
  if (!media) return undefined;
  if (media.mime_type && !media.mime_type.startsWith('image/')) return undefined;
  const sizes = media.media_details?.sizes ?? {};
  const candidate =
    sizes['medium_large']?.source_url ??
    sizes['large']?.source_url ??
    sizes['medium']?.source_url ??
    media.source_url;
  if (!candidate || isPlaceholderUrl(candidate)) return undefined;
  return candidate;
}

function pickInlineImage(html: string): string | undefined {
  if (!html) return undefined;
  const regex = /<img[^>]+src=["']([^"']+)["'][^>]*>/gi;
  let m: RegExpExecArray | null;
  while ((m = regex.exec(html)) !== null) {
    if (!isPlaceholderUrl(m[1])) return m[1];
  }
  return undefined;
}

function stripHtml(html: string): string {
  // Tags entfernen, HTML-Entities dekodieren (Auszüge/Teaser zeigten sonst
  // Roh-Codes wie &#8222; / &amp;), Whitespace normalisieren.
  const text = decodeEntities(html.replace(/<[^>]*>/g, ' '));
  return text.replace(/\s+/g, ' ').trim();
}

/**
 * Beitrags-Slugs (für das Link-Mapping /{slug}/ → /neuigkeiten/{slug}).
 * Einmal pro Build geladen, von allen Fetchern geteilt.
 */
let postSlugsPromise: Promise<Set<string>> | null = null;
function ensurePostSlugs(): Promise<Set<string>> {
  if (!postSlugsPromise) {
    postSlugsPromise = (async () => {
      try {
        // Denselben (vollständigen) Umfang wie fetchWordPressNews — nur so
        // zeigen umgeschriebene Links immer auf tatsächlich gebaute Seiten.
        const posts = await fetchAllPages<{ slug: string }>('posts', { _fields: 'slug' });
        return new Set(posts.map((p) => entschaerfeSlug(p.slug)));
      } catch {
        return new Set<string>();
      }
    })();
  }
  return postSlugsPromise;
}

/** Links auf Seiten, die wir bewusst nicht generieren → sinnvolle Ziele. */
const LINK_REMAP: Record<string, string> = {
  'maengel-melder': 'https://melder.vv-wildenstein.com',
  'cookie-policy-eu': '/datenschutzerklaerung',
  'lebendigen-adventskalender-2': '/lebendigen-adventskalender',
  '5-creative-2': '/',
  'test-formular': '/',
};

/**
 * URLs im WP-Content umschreiben:
 *  1) /wp-content (Bilder, PDFs) → immer absolut auf den aktuellen WP-Host
 *     (Day-X-sicher: folgt PUBLIC_WP_API_BASE automatisch).
 *  2) Alle anderen internen Links → RELATIV, damit Besucher auf der neuen
 *     Seite bleiben statt zur alten Live-Site zu springen. Beitrags-Links
 *     ({slug} in postSlugs) landen auf unserer Detailseite /neuigkeiten/….
 */
/**
 * Bild-Basisname ohne WP-Größen-Suffix (-1000x750, -scaled) — für Dedup.
 */
function imageBaseName(src: string): string {
  // Voller Pfad (ohne Host/Query) minus Größen-Suffix — so kollidieren
  // gleichnamige Bilder aus verschiedenen /uploads/JJJJ/MM/-Ordnern NICHT.
  return src
    .replace(/^https?:\/\/[^/]+/i, '')
    .replace(/[?#].*$/, '')
    .replace(/-\d+x\d+(\.\w+)$/i, '$1')
    .replace(/-scaled(\.\w+)$/i, '$1')
    .toLowerCase();
}

/**
 * Impreza/WPBakery-Galerien (RoyalSlider) für die statische Auslieferung
 * aufräumen: Jeder Slide liefert im `<a class="rsImg" href>` das große
 * Originalbild samt Maßen — daraus bauen wir schlanke, SCHARFE <figure>-Bilder
 * statt der 150×150-Thumbnails. Anschließend werden über den GESAMTEN Inhalt
 * doppelte Bilder (gleiche Basisdatei) entfernt — im Original stehen die
 * Galerie-Aufmacher oft zusätzlich als Einzelbild im Text.
 */
function normalizeGalleries(html: string): string {
  // 1) RoyalSlider-Slides → saubere große Bilder
  let out = html.replace(/<div class="rsContent">[\s\S]*?<\/div>/g, (slide) => {
    const href = slide.match(/<a[^>]*class="rsImg"[^>]*href="([^"]+)"/i);
    if (!href) return '';
    const w = slide.match(/data-rsw="(\d+)"/i);
    const h = slide.match(/data-rsh="(\d+)"/i);
    const dim = w && h ? ` width="${w[1]}" height="${h[1]}"` : '';
    return `<figure class="vv-gal-item"><img class="vv-gal-img" src="${href[1]}"${dim} loading="lazy" decoding="async" alt=""></figure>`;
  });

  // 2) Duplikate über den gesamten Inhalt entfernen (erste Instanz gewinnt)
  const seen = new Set<string>();
  out = out.replace(/<img\b[^>]*>/gi, (tag) => {
    const src = tag.match(/\bsrc="([^"]+)"/i);
    if (!src) return tag;
    if (/wpdm|file-type-icons/i.test(src[1])) return tag; // Datei-Icons behalten
    const base = imageBaseName(src[1]);
    if (seen.has(base)) return ''; // Duplikat raus
    seen.add(base);
    return tag;
  });
  // 3) Galerie-Wrapper, deren Bild dedupliziert wurde, komplett entfernen
  out = out.replace(/<figure class="vv-gal-item">\s*<\/figure>/g, '');
  return out;
}

/**
 * Leere Baukasten-Spalten entfernen.
 *
 * Im Backend dienen schmale Spalten (meist vc_col-sm-3) haeufig nur als
 * Einrueckung und enthalten nichts. Ohne die Gestaltung des Baukastens
 * beanspruchen sie hier aber genauso viel Platz wie die Textspalte daneben —
 * auf /verband wurde der Text dadurch in eine schmale Saeule am rechten Rand
 * gequetscht. 34 Seiten waren betroffen.
 *
 * Als "leer" gilt eine Spalte nur ohne Text UND ohne alles, was etwas zeigen
 * koennte: Bild, Verweis, Tabelle, Einbettung, Hintergrundbild. Im Zweifel
 * bleibt sie stehen — eine zu viel ist harmloser als ein geloeschter Inhalt.
 */
function entferneLeereSpalten(html: string): string {
  let out = html;

  // Mehrere Durchlaeufe: wird eine innere Spalte entfernt, kann die aeussere
  // dadurch selbst leer werden.
  for (let runde = 0; runde < 3; runde++) {
    const vorher = out;
    let von = 0;

    for (let schutz = 0; schutz < 500; schutz++) {
      const treffer = /<div\b[^>]*\bvc_col-sm-\d+\b[^>]*>/i.exec(out.slice(von));
      if (!treffer) break;
      const start = von + treffer.index;
      const inhaltAb = start + treffer[0].length;

      // <div>-Tiefe zaehlen, bis der Block balanciert ist.
      const tag = /<\/?div\b[^>]*>/gi;
      tag.lastIndex = start;
      let tiefe = 0;
      let ende = -1;
      let t: RegExpExecArray | null;
      while ((t = tag.exec(out)) !== null) {
        tiefe += t[0].startsWith('</') ? -1 : 1;
        if (tiefe === 0) {
          ende = t.index + t[0].length;
          break;
        }
      }
      if (ende < 0) break; // unbalanciert — Finger weg

      const innen = out.slice(inhaltAb, ende);
      const ohneText = innen.replace(/<[^>]+>/g, '').replace(/&nbsp;|\s/g, '') === '';
      const ohneInhalt = !/<(img|iframe|video|audio|svg|input|table|hr|a)\b/i.test(innen);
      const ohneBild = !/background-image\s*:/i.test(innen);

      if (ohneText && ohneInhalt && ohneBild) {
        out = out.slice(0, start) + out.slice(ende);
        // von bleibt stehen: an derselben Stelle weitersuchen
      } else {
        von = inhaltAb; // in die Spalte hinein — verschachtelte Spalten pruefen
      }
    }

    if (out === vorher) break;
  }

  return out;
}

/**
 * Zuordnung /download/<kuerzel>/ → echte Datei-Adresse.
 *
 * Der Download-Manager legt fuer jede Datei eine eigene WordPress-Seite an.
 * Die bauen wir nicht — 65 Verweise auf Formulare und Satzungen liefen
 * dadurch ins Leere, allein 14 auf der Seite mit den Kita-Formularen. Der
 * mu-Plugin-Endpunkt vvw/v1/downloads-alle kennt zu jeder dieser Seiten die
 * Datei selbst; darauf zeigen die Verweise jetzt direkt.
 */
let downloadKartePromise: Promise<Map<string, string>> | null = null;
function ensureDownloadKarte(): Promise<Map<string, string>> {
  if (!downloadKartePromise) downloadKartePromise = ladeDownloadKarte();
  return downloadKartePromise;
}

async function ladeDownloadKarte(): Promise<Map<string, string>> {
  const karte = new Map<string, string>();
  const basis = WP_BASE.replace(/\/wp\/v2\/?$/, '/vvw/v1');
  const abbruch = new AbortController();
  const wecker = setTimeout(() => abbruch.abort(), 20_000);
  try {
    const res = await fetch(`${basis}/downloads-alle`, {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
      signal: abbruch.signal,
    });
    if (!res.ok) throw new Error(`vvw/v1/downloads-alle ${res.status}`);
    const daten = (await res.json()) as {
      gruppen?: Array<{ dateien?: Array<{ url?: string; seite?: string }> }>;
    };
    for (const g of daten.gruppen ?? []) {
      for (const f of g.dateien ?? []) {
        const kuerzel = (f.seite ?? '').match(/\/download\/([^/]+)\/?$/)?.[1];
        if (kuerzel && f.url) karte.set(decodeURIComponent(kuerzel), f.url);
      }
    }
  } catch (err) {
    console.warn('[verband] Download-Zuordnung nicht abrufbar:', err);
  } finally {
    clearTimeout(wecker);
  }
  return karte;
}

/**
 * Bedienelemente entfernen, die im statischen Bau nichts tun.
 *
 * Impreza legt Filterformulare (w-filter) und das vw-events-Plugin eine
 * Filterleiste in den Seiteninhalt. Beides braucht JavaScript und Endpunkte,
 * die es hier nicht gibt: Auf /leben-freizeit/gesundheit stand eine Liste
 * aller Gewerbekategorien zum Anklicken, ohne dass ein Klick etwas bewirkte,
 * auf /leben-freizeit/veranstaltungen eine Leiste mit "Alle | Heute | Diese
 * Woche" samt Monatsauswahl. Bedienelemente ohne Wirkung sind schlimmer als
 * keine.
 */
function entferneToteBedienelemente(html: string): string {
  let out = html;
  // <form class="w-filter …"> … </form>
  out = out.replace(/<form\b[^>]*\bw-filter\b[\s\S]*?<\/form>/gi, '');
  // Filterleiste des Veranstaltungs-Plugins
  for (let schutz = 0; schutz < 20; schutz++) {
    const start = /<div\b[^>]*\bvw-events-filterbar\b[^>]*>/i.exec(out);
    if (!start) break;
    const tag = /<\/?div\b[^>]*>/gi;
    tag.lastIndex = start.index;
    let tiefe = 0;
    let ende = -1;
    let t: RegExpExecArray | null;
    while ((t = tag.exec(out)) !== null) {
      tiefe += t[0].startsWith('</') ? -1 : 1;
      if (tiefe === 0) {
        ende = t.index + t[0].length;
        break;
      }
    }
    if (ende < 0) break;
    out = out.slice(0, start.index) + out.slice(ende);
  }
  return out;
}

function rewriteContentUrls(
  html: string,
  postSlugs: Set<string>,
  downloadKarte: Map<string, string> = new Map(),
): string {
  const wpHost = WP_BASE.replace(/\/wp-json.*$/, '');
  let out = entferneToteBedienelemente(normalizeGalleries(html));
  // Nicht aufgelöste WPBakery-Shortcodes, die als Roh-Text durchrutschen:
  //  - [vc_raw_html]…base64…[/vc_raw_html] / [vc_raw_js] (meist Redirect-/Script-Stubs)
  //  - verwaiste [vc_*]/[/vc_*]-Tags
  out = out.replace(/\[vc_raw_(?:html|js)\][\s\S]*?\[\/vc_raw_(?:html|js)\]/gi, '');
  out = out.replace(/\[\/?vc_[a-z_]*[^\]]*\]/gi, '');

  /* Kurzcodes abgeschalteter Erweiterungen standen als Roh-Text auf der Seite
     — für Besucher lesbar. Achtung: Die Redaktion hat typografische
     Anführungszeichen („ “ ″), deshalb [^\]] statt [^"\]]. */
  // Facebook-Einbettungen laufen im statischen Build nicht — ersatzlos raus.
  out = out.replace(/\[custom-facebook-feed[^\]]*\]/gi, '');

  /* Akkordeon-Kurzcode: Der Titel ist Inhalt, kein Beiwerk. Auf
     /lebendigen-adventskalender stecken darin die 24 Gastgeber
     ("Kindergarten Borstel Borstendorf", "CDU Ortsverband") — 27.000 Zeichen
     Text ohne eine einzige Ueberschrift. Als h3 gibt es der Seite Gliederung
     und ein Themenmenue. Ein h3 mitten im Absatz schliesst dieser im Browser
     sauber ab. */
  out = out.replace(/\[ultimate_exp_section\b([^\]]*)\]/gi, (_treffer, attr: string) => {
    const roh = attr.match(/\btitle\s*=\s*(?:&#\d+;|["'„“”])([\s\S]*?)(?:&#\d+;|["'„“”])/i)?.[1] ?? '';
    const titel = decodeEntities(roh.replace(/<[^>]*>/g, '')).trim();
    // h2, damit das Themenmenue sie aufgreift — es wertet nur h2 aus.
    return titel ? `<h2>${titel}</h2>` : '';
  });
  out = out.replace(/\[\/ultimate_exp_section\]/gi, '');
  /* Sicherheitsnetz fuer Kurzcodes abgeschalteter Erweiterungen, die als Text
     auf der Seite landen. Bedingung: ein Unterstrich im Namen — den haben alle
     Plugin-Kurzcodes ([dt_portfolio_slider], [vc_empty_space]), gewoehnlicher
     Text in eckigen Klammern ("[siehe unten]") dagegen nicht.
     Ausgenommen [dt_blog_posts_small]: Daraus wird spaeter eine echte
     Beitragsliste. Ohne die Ausnahme fiel /vergabeausschreibungen wieder auf
     die Leermeldung zurueck. */
  out = out.replace(/\[\/?([a-z][a-z0-9]*_[a-z0-9_]*)(?:\s[^\]]*)?\]/gi, (treffer, name: string) =>
    /^dt_blog_posts_small$/i.test(name) ? treffer : '',
  );

  // Contact Form 7: ohne WordPress kein Formular. Statt des Kurzcodes ein Weg,
  // der funktioniert — dieselbe Adresse, die auch auf /wirtschaft steht.
  out = out.replace(
    /\[contact-form-7[^\]]*\]/gi,
    '<p class="vv-hinweis">Das Online-Formular steht hier nicht zur Verfügung. ' +
      'Schreiben Sie uns bitte an <a href="mailto:info@vv-wildenstein.com">info@vv-wildenstein.com</a> ' +
      '— wir nehmen Ihre Angaben auf.</p>',
  );
  // Skripte/Stylesheets aus dem WP-Inhalt: laufen im statischen Build ohne die
  // Plugin-Abhängigkeiten nicht und würden als Roh-Text auf der Seite landen
  // (z. B. der jQuery-Dateibaum des Download-Managers).
  out = out.replace(/<script\b[\s\S]*?<\/script>/gi, '');
  out = out.replace(/<link\b[^>]*rel=["']stylesheet["'][^>]*>/gi, '');
  /* Auch <style>-Bloecke: Impreza legt zu jedem Portalgitter eigene Regeln in
     den Inhalt, teils mit !important (".usg_post_title_1{font-size:1rem
     !important}"). Die Gitter ersetzen wir durch eigene Darstellungen — die
     Regeln kaempfen dann gegen unser Stylesheet. Betraf /sperrungen,
     /verband/bauleitplanung und /leben-freizeit/gesundheit. */
  out = out.replace(/<style\b[\s\S]*?<\/style>/gi, '');
  out = out.replace(/<noscript\b[\s\S]*?<\/noscript>/gi, '');
  // Die echte Seiten-h1 liefert der Header — h1 im WP-Body → h2 (keine doppelte h1)
  out = out.replace(/<(\/?)h1(\s|>)/gi, '<$1h2$2');
  out = out.replace(/https?:\/\/(?:www\.)?vv-wildenstein\.com\/wp-content/g, `${wpHost}/wp-content`);
  /* Bilder und Dateien aus der aufgeloesten Multisite: Sie lagen unter
     gruenhainichen.com/wp-content/uploads/sites/2/…, liegen aber auf dem
     zentralen Server. Unter der alten Adresse antwortet heute die neue
     statische Seite mit 404 — und weil die Verweise auf http lauten, haette
     ein Browser sie auf einer https-Seite ohnehin blockiert. Betraf neun
     eingebettete Bilder und eine PDF-Datei. */
  out = out.replace(
    /https?:\/\/(?:www\.)?(?:gruenhainichen\.com|boernichen\.de)\/wp-content/g,
    `${wpHost}/wp-content`,
  );
  out = out.replace(
    /href="https?:\/\/(?:www\.)?vv-wildenstein\.com(\/[^"]*)?"/g,
    (match, rawPath?: string) => {
      const path = rawPath ?? '/';
      // wp-content/wp-json/wp-login/wp-admin niemals relativieren
      if (/^\/wp-/.test(path)) return match;
      /* Alter Permalink-Präfix /blog/… aus einer früheren WordPress-Struktur.
         /blog/amter/bauamt-liegenschaften ist heute /amter/bauamt-liegenschaften. */
      const ohneBlog = path.replace(/^\/blog(?=\/)/, '');

      // Download-Manager-Seiten gibt es hier nicht → direkt auf die Datei
      const dl = path.match(/^\/download\/([^/?#]+)\/?$/i)?.[1];
      if (dl) {
        const datei = downloadKarte.get(decodeURIComponent(dl)) ?? downloadKarte.get(dl);
        if (datei) return `href="${datei}"`;
      }
      // erster Pfad-Teil ohne Slashes/Anker/Query
      const first = ohneBlog.replace(/^\//, '').split(/[/?#]/)[0];
      if (first && LINK_REMAP[first] !== undefined) return `href="${LINK_REMAP[first]}"`;

      /* Beitrags-Permalinks (/{slug}/) → interne News-Detailseite.
         Sonderzeichen machen dabei Ärger: Im Verweis steht „…-nach-§-3-abs-2-…"
         ausgeschrieben, WordPress liefert den Slug prozentkodiert, und gebaut
         wird er entschärft als „…-nach-3-abs-2-…". Deshalb beide Formen prüfen
         — der Umweg über encodeURIComponent bringt das ausgeschriebene § in
         die Form, die entschaerfeSlug erwartet. Auf /verband/bauleitplanung
         liefen zwei von sechs Bekanntmachungen deshalb ins Leere. */
      const alsSlug = postSlugs.has(first) ? first : entschaerfeSlug(encodeURIComponent(first));
      if (alsSlug && postSlugs.has(alsSlug)) return `href="/neuigkeiten/${alsSlug}"`;

      // Prozent-kodierte Pfade, die zu keiner gebauten Seite führen: absolut
      // lassen, statt auf einen Pfad zu zeigen, den es hier nicht gibt.
      if (path.includes('%')) return match;
      return `href="${ohneBlog}"`;
    },
  );
  /* Verweise auf Download-Seiten, die schon relativ im Inhalt stehen (ohne
     Domain) — die Regel oben greift nur bei absoluten Adressen. */
  out = out.replace(/href="\/download\/([^"/?#]+)\/?"/gi, (match, kuerzel: string) => {
    const datei = downloadKarte.get(decodeURIComponent(kuerzel)) ?? downloadKarte.get(kuerzel);
    return datei ? `href="${datei}"` : match;
  });

  // Zum Schluss: Erst jetzt — nach dem Entfernen von Skripten, Stylesheets
  // und Kurzcode-Resten — steht fest, welche Spalte wirklich leer ist.
  return entferneLeereSpalten(out);
}

/* ----------------------------------------------------------------
 * Seiten — die kompletten Inhaltsseiten des Verbands.
 * Quelle der Wahrheit bleibt WordPress: Redaktion pflegt im Backend,
 * jeder Build zieht den aktuellen Stand (Auto-Deploy via Webhook/Zeitplan).
 * ---------------------------------------------------------------- */

/** Kontaktdaten aus den Impreza-Custom-Feldern (mu-Plugin vv-rest-profilfelder). */
export interface VvKontakt {
  fuehrende_person?: string;
  strasse_hausnummer?: string;
  plz_ort?: string;
  telefon?: string;
  email?: string;
  website?: string;
  /** nur bei Vereinen: Mitgliederzahl */
  mitglieder?: string;
  /** Ämter: Funktion des Ansprechpartners (z. B. „Sachbearbeiterin") */
  funktion?: string;
  /** Ämter: mehrzeilige Anschrift (statt Straße/PLZ getrennt) */
  anschrift?: string;
  /** Sprech-/Öffnungszeiten als Redaktions-Freitext */
  oeffnungszeiten?: string;
  fax?: string;
  mobil?: string;
  email2?: string;
  informationen?: string;
  sonstiges?: string;
}

export interface VvGalleryImage {
  url: string;
  full: string;
  alt: string;
}

export interface WPPageItem {
  id: number;
  slug: string;
  /** Hierarchischer Pfad wie im Original (URL-Parität für den Day-X-Umzug) */
  path: string;
  title: string;
  contentHtml: string;
  breadcrumb: Array<{ title: string; path: string }>;
  /** WP-Eltern-ID (0 = keine) — für die Kind-Seiten-Kachelgitter der Hubs */
  parentId: number;
  /** True, wenn die Seite ein Impreza-`us_grid`-Portalgitter enthielt
   *  (das wir entfernt haben) → Signal, ein natives Kachelgitter zu rendern. */
  hadHubGrid: boolean;
  /** Kacheln, die im entfernten Grid standen (Titel + Ziel) — Fallback-Quelle */
  gridTiles?: HubTile[];
  /** Kontaktdaten (Ämter/Profile/Vereine/Tourismus) — bei diesen CPTs steht der
   *  eigentliche Inhalt in Custom-Feldern, nicht im post_content. */
  kontakt?: VvKontakt;
  /** Zusatzbilder aus „Erweiterte Einstellungen" */
  gallery?: VvGalleryImage[];
  /** Beitragsbild */
  image?: string;
  /** Beitragsbild ist ein breites Banner (Logo) — nicht beschneiden */
  imageBreit?: boolean;
}

/**
 * Impreza-`us_grid`-Portalgitter aus dem Content entfernen.
 * Diese dynamischen Gitter überstehen den statischen REST-Schnappschuss nicht:
 * Die Abfrage fällt oft auf einen generischen „letzte Beiträge"-Satz zurück
 * (Amtsblatt-PDFs statt Tourismus) und ohne Impreza-CSS werden bildlose
 * Kacheln zu riesigen Leerkästen. Wir ersetzen sie durch native Kachelgitter
 * (siehe VvHubGrid), die aus den echten Daten gespeist werden.
 * Entfernt den kompletten, balancierten <div class="…us_grid…">-Block.
 */
/**
 * Kacheln aus einem gerenderten Impreza-Grid lesen (Titel, Ziel, Bild).
 * Damit geht der Inhalt der Portalgitter NICHT verloren: Was das alte Grid
 * anzeigte, rendern wir als natives Kachelgitter nach.
 */
function extractGridTiles(gridHtml: string): HubTile[] {
  const tiles: HubTile[] = [];
  const seen = new Set<string>();
  for (const m of gridHtml.matchAll(/<article\b[^>]*class="[^"]*w-grid-item[^"]*"[\s\S]*?<\/article>/gi)) {
    const item = m[0];
    // Titel steht je nach Layout in <h2 …post_title> mit Link ODER in einem
    // schlichten <div class="…post_title"> ganz ohne Link (dann über die ID).
    const titleMatch =
      item.match(/<(?:h\d|div)[^>]*class="[^"]*post_title[^"]*"[^>]*>([\s\S]*?)<\/(?:h\d|div)>/i);
    if (!titleMatch) continue;
    const title = decodeEntities(stripHtml(titleMatch[1]));
    if (!title) continue;

    // Ziel: eigener Titel-Link, sonst Bild-Link — Taxonomie-Links (…/gemeindeteil/…)
    // taugen nicht als Ziel, dann bleibt die Auflösung über sourceId.
    const hrefRaw =
      titleMatch[1].match(/<a[^>]*href="([^"]+)"/i)?.[1] ??
      item.match(/class="[^"]*post_image[^"]*"[\s\S]*?<a[^>]*href="([^"]+)"/i)?.[1] ??
      '';
    const href = hrefRaw.replace(/^https?:\/\/(?:www\.)?vv-wildenstein\.com/i, '').trim();
    const brauchbar =
      href !== '' &&
      !/^\/?$/.test(href) &&
      !/\/(download|gemeindeteil|category|tag|anliegen)\//i.test(href) &&
      !/^(https?:)?\/\//i.test(href);

    const sourceId = Number(item.match(/\bdata-id="(\d+)"/i)?.[1] ?? 0) || undefined;
    if (!brauchbar && !sourceId) continue;

    const key = title.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    const img = item.match(/class="[^"]*post_image[^"]*"[\s\S]*?<img[^>]+src="([^"]+)"/i);
    tiles.push({
      title,
      href: brauchbar ? href : '',
      image: img ? img[1] : undefined,
      sourceId,
    });
  }
  return tiles;
}

/**
 * Kachel-Verweise auf denselben Stand bringen wie die Verweise im Fliesstext.
 *
 * extractGridTiles liest die Original-Permalinks aus dem WP-Schnappschuss
 * ("/bundesweiter-warntag-.../"). Beitraege liegen hier aber unter
 * /neuigkeiten/<slug>. Im Fliesstext macht rewriteContentUrls das schon; die
 * Kacheln liefen daran vorbei — auf /verband/neuigkeiten zeigten dadurch 29
 * von 29 Kacheln ins Leere.
 */
function kachelZieleUmschreiben(tiles: HubTile[], postSlugs: Set<string>): HubTile[] {
  return tiles.map((t) => {
    if (!t.href) return t;
    const erstes = t.href.replace(/^\//, '').split('/')[0];
    if (erstes && postSlugs.has(erstes)) return { ...t, href: `/neuigkeiten/${erstes}` };
    return t;
  });
}

function stripUsGrids(html: string): { html: string; had: boolean; tiles: HubTile[] } {
  let out = html;
  let had = false;
  const tiles: HubTile[] = [];
  for (let guard = 0; guard < 50; guard++) {
    const start = /<div\b[^>]*\bus_grid\b[^>]*>/i.exec(out);
    if (!start) break;
    had = true;
    // Ab dem us_grid-Start die <div>-Tiefe zählen, bis der Block balanciert ist.
    const tag = /<\/?div\b[^>]*>/gi;
    tag.lastIndex = start.index;
    let depth = 0;
    let end = -1;
    let m: RegExpExecArray | null;
    while ((m = tag.exec(out)) !== null) {
      depth += m[0][1] === '/' ? -1 : 1;
      if (depth === 0) {
        end = m.index + m[0].length;
        break;
      }
    }
    if (end === -1) break; // unbalanciert → abbrechen, Rest unangetastet lassen
    tiles.push(...extractGridTiles(out.slice(start.index, end)));
    out = out.slice(0, start.index) + out.slice(end);
  }
  // Über mehrere Grids einer Seite hinweg deduplizieren
  const seen = new Set<string>();
  const uniq = tiles.filter((t) => {
    const k = t.title.toLowerCase();
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });
  return { html: out, had, tiles: uniq };
}

interface WPPageRaw {
  id: number;
  slug: string;
  parent: number;
  title: { rendered: string };
  content: { rendered: string };
}

/** Seiten, die im neuen Frontend NICHT generiert werden. */
const EXCLUDED_PAGE_SLUGS = new Set([
  '5-creative-2',                    // alte Impreza-Startseite (haben eine eigene)
  'verwaltungsverband-wildenstein-2', // Entwurfs-Kopie der Startseite
  'test-formular',                   // Testseite
  'lebendigen-adventskalender-2',    // "VORSCHAU"-Duplikat
  'cookie-policy-eu',                // Complianz-Seite (statische Site setzt keine Cookies)
  'maengel-melder',                  // ersetzt durch melder.vv-wildenstein.com
]);

export async function fetchWordPressPages(): Promise<WPPageItem[]> {
  const url = new URL(`${WP_BASE}/pages`);
  url.searchParams.set('per_page', '100');
  url.searchParams.set('_fields', 'id,slug,parent,title,content');

  const res = await fetch(url.toString(), {
    headers: { Accept: 'application/json', ...buildAuthHeader() },
  });
  if (!res.ok) {
    // Bewusst hart scheitern: lieber Build-Abbruch (alter Stand bleibt live)
    // als ein Deployment ohne Inhaltsseiten.
    throw new Error(`WP REST pages ${res.status} ${res.statusText}`);
  }
  const raw = (await res.json()) as WPPageRaw[];
  const postSlugs = await ensurePostSlugs();
  const downloadKarte = await ensureDownloadKarte();
  const byId = new Map(raw.map((p) => [p.id, p]));

  function chain(p: WPPageRaw): WPPageRaw[] {
    const out: WPPageRaw[] = [p];
    let cur = p;
    while (cur.parent) {
      const parent = byId.get(cur.parent);
      if (!parent) break;
      out.unshift(parent);
      cur = parent;
    }
    return out;
  }

  return raw
    .filter((p) => !EXCLUDED_PAGE_SLUGS.has(p.slug))
    .map((p) => {
      const parents = chain(p);
      const path = '/' + parents.map((x) => x.slug).join('/');
      const stripped = stripUsGrids(p.content.rendered);
      return {
        id: p.id,
        slug: p.slug,
        path,
        parentId: p.parent,
        hadHubGrid: stripped.had,
        gridTiles: kachelZieleUmschreiben(stripped.tiles, postSlugs),
        title: decodeEntities(stripHtml(p.title.rendered).trim()),
        contentHtml: rewriteContentUrls(stripped.html, postSlugs, downloadKarte),
        breadcrumb: parents.slice(0, -1).map((x) => ({
          title: decodeEntities(stripHtml(x.title.rendered).trim()),
          path: '/' + chain(x).map((y) => y.slug).join('/'),
        })),
      };
    });
}

/* ----------------------------------------------------------------
 * Custom Post Types — Ämter, Tourismus-Einträge, Vereine, Profile.
 * Flache Permalinks: /{prefix}/{slug}/ (wie im Original-WordPress).
 * ---------------------------------------------------------------- */

const CPT_SOURCES: Array<{
  restBase: string;
  pathPrefix: string;
  crumb: { title: string; path: string };
}> = [
  { restBase: 'amter',     pathPrefix: 'amter',     crumb: { title: 'Verwaltung',       path: '/verwaltung' } },
  { restBase: 'tourismus', pathPrefix: 'tourismus', crumb: { title: 'Tourismus',        path: '/tourismus_uebersicht' } },
  { restBase: 'verein',    pathPrefix: 'verein',    crumb: { title: 'Leben & Freizeit', path: '/leben-freizeit' } },
  { restBase: 'profile',   pathPrefix: 'profile',   crumb: { title: 'Wirtschaft',       path: '/wirtschaft' } },
];

/**
 * Die CPT-Seiten werden inzwischen von mehreren Seiten gebraucht (Detailrouten
 * und die vier Übersichten). Ergebnis einmal pro Build merken, statt die
 * REST-API mehrfach abzufragen.
 */
let cptPagesPromise: Promise<WPPageItem[]> | null = null;
export function fetchWordPressCptPages(): Promise<WPPageItem[]> {
  if (!cptPagesPromise) cptPagesPromise = ladeCptPages();
  return cptPagesPromise;
}

async function ladeCptPages(): Promise<WPPageItem[]> {
  const out: WPPageItem[] = [];
  const postSlugs = await ensurePostSlugs();
  const downloadKarte = await ensureDownloadKarte();
  for (const src of CPT_SOURCES) {
    // Bis zu 2 Seiten à 100 — deckt alle aktuellen Bestände (max 60)
    for (let pageNo = 1; pageNo <= 2; pageNo++) {
      const url = new URL(`${WP_BASE}/${src.restBase}`);
      url.searchParams.set('per_page', '100');
      url.searchParams.set('page', String(pageNo));
      // vv_kontakt/vv_gallery liefert das mu-Plugin vv-rest-profilfelder. Bei
      // Profilen, Vereinen und Tourismus-Einträgen steht der eigentliche Inhalt
      // (Ansprechpartner, Adresse, Telefon …) NUR dort — post_content ist leer.
      url.searchParams.set('_embed', 'wp:featuredmedia');
      const res = await fetch(url.toString(), {
        headers: { Accept: 'application/json', ...buildAuthHeader() },
      });
      if (!res.ok) {
        if (pageNo > 1) break; // keine weitere Seite vorhanden
        throw new Error(`WP REST ${src.restBase} ${res.status} ${res.statusText}`);
      }
      const raw = (await res.json()) as Array<
        WPPageRaw & { vv_kontakt?: VvKontakt; vv_gallery?: VvGalleryImage[] } & WPCptEmbed
      >;
      for (const p of raw) {
        const stripped = stripUsGrids(p.content?.rendered ?? '');
        const kontakt = p.vv_kontakt
          ? (Object.fromEntries(
              Object.entries(p.vv_kontakt).filter(([, v]) => typeof v === 'string' && v.trim() !== ''),
            ) as VvKontakt)
          : undefined;
        out.push({
          id: p.id,
          slug: p.slug,
          path: `/${src.pathPrefix}/${p.slug}`,
          parentId: 0,
          hadHubGrid: stripped.had,
          gridTiles: kachelZieleUmschreiben(stripped.tiles, postSlugs),
          title: decodeEntities(stripHtml(p.title.rendered).trim()),
          contentHtml: rewriteContentUrls(stripped.html, postSlugs, downloadKarte),
          breadcrumb: [src.crumb],
          kontakt: kontakt && Object.keys(kontakt).length ? kontakt : undefined,
          gallery: Array.isArray(p.vv_gallery) && p.vv_gallery.length ? p.vv_gallery : undefined,
          image: cptImage(p),
          imageBreit: cptImageIstBreit(p),
        });
      }
      if (raw.length < 100) break;
    }
  }
  return out;
}

/* ----------------------------------------------------------------
 * Hub-Kacheln — echte Datenquellen für die Übersichtsseiten, die
 * die entfernten Impreza-`us_grid`-Portalgitter ersetzen.
 * ---------------------------------------------------------------- */

export interface HubTile {
  title: string;
  href: string;
  image?: string;
  /** Tourismus-Kategorien (tourismus_kat-Slugs) — nur bei Tourismus-Einträgen */
  kats?: string[];
  /** Anzeigenamen der Kategorien (z. B. „Handwerk") — für gruppierte Listen */
  katNamen?: string[];
  /** WP-Post-ID (aus `data-id` eines Grid-Items) — um das Ziel aufzulösen,
   *  wenn die Kachel im Original keinen eigenen Link hatte. */
  sourceId?: number;
  /** Redaktionsfelder für Listen-Darstellungen (Ämter, Gewerbe): Ohne sie
   *  müsste man jede Kachel anklicken, um Ansprechpartner oder Telefonnummer
   *  zu sehen. */
  kontakt?: VvKontakt;
}

interface WPCptEmbed {
  slug: string;
  title: { rendered: string };
  _embedded?: {
    'wp:featuredmedia'?: Array<{
      source_url?: string;
      mime_type?: string;
      media_details?: {
        width?: number;
        height?: number;
        sizes?: Record<string, { source_url: string }>;
      };
    }>;
    'wp:term'?: Array<Array<{ slug: string; name?: string; taxonomy: string }>>;
  };
}

/**
 * Ist das Beitragsbild ein breites Banner (Firmenlogo) statt eines Fotos?
 *
 * Das Logo der GRUENPERGA Papier GmbH ist 900 x 194 Pixel. Als Kopfbild ueber
 * die volle Inhaltsbreite gezogen und auf 380 Pixel Hoehe beschnitten sah es
 * aus wie ein Fehler. Ab etwa 2,2:1 wird ein Bild deshalb nicht beschnitten,
 * sondern klein und mittig gesetzt.
 */
function cptImageIstBreit(item: WPCptEmbed): boolean {
  const md = item._embedded?.['wp:featuredmedia']?.[0]?.media_details;
  const b = md?.width ?? 0;
  const h = md?.height ?? 0;
  return b > 0 && h > 0 && b / h > 2.2;
}

function cptImage(item: WPCptEmbed): string | undefined {
  const media = item._embedded?.['wp:featuredmedia']?.[0];
  if (!media || !media.source_url) return undefined;
  if (media.mime_type && !media.mime_type.startsWith('image/')) return undefined;
  const sizes = media.media_details?.sizes ?? {};
  const url =
    sizes['medium_large']?.source_url ??
    sizes['large']?.source_url ??
    sizes['medium']?.source_url ??
    media.source_url;
  if (isPlaceholderUrl(url)) return undefined;
  const wpHost = WP_BASE.replace(/\/wp-json.*$/, '');
  return url.replace(/https?:\/\/(?:www\.)?vv-wildenstein\.com/g, wpHost);
}

async function fetchCptTiles(
  restBase: string,
  pathPrefix: string,
  /** Taxonomie, deren Begriffe mitgelesen werden (Tourismus-Filter, Branchenliste) */
  taxonomie?: string,
): Promise<HubTile[]> {
  const url = new URL(`${WP_BASE}/${restBase}`);
  url.searchParams.set('per_page', '100');
  url.searchParams.set('_embed', taxonomie ? 'wp:featuredmedia,wp:term' : 'wp:featuredmedia');
  const res = await fetch(url.toString(), {
    headers: { Accept: 'application/json', ...buildAuthHeader() },
  });
  if (!res.ok) return [];
  const raw = (await res.json()) as Array<WPCptEmbed & { vv_kontakt?: VvKontakt }>;
  const tiles = raw.map((item) => {
    const begriffe = taxonomie
      ? (item._embedded?.['wp:term'] ?? []).flat().filter((t) => t.taxonomy === taxonomie)
      : [];
    const kats = taxonomie ? begriffe.map((t) => t.slug) : undefined;
    const katNamen = taxonomie ? begriffe.map((t) => decodeEntities(t.name ?? t.slug)) : undefined;
    // Leere Felder aussortieren, damit die Liste nicht mit Nichts rechnet
    const kontakt = item.vv_kontakt
      ? (Object.fromEntries(
          Object.entries(item.vv_kontakt).filter(([, v]) => typeof v === 'string' && v.trim() !== ''),
        ) as VvKontakt)
      : undefined;
    return {
      title: decodeEntities(stripHtml(item.title.rendered).trim()),
      href: `/${pathPrefix}/${item.slug}`,
      image: cptImage(item),
      kats,
      katNamen: katNamen && katNamen.length ? katNamen : undefined,
      kontakt: kontakt && Object.keys(kontakt).length ? kontakt : undefined,
    } as HubTile;
  });
  // Nach Titel deduplizieren (WP hat „…-2"-Dubletten) — Variante MIT Bild gewinnt.
  const byTitle = new Map<string, HubTile>();
  for (const t of tiles) {
    const key = t.title.toLowerCase();
    const existing = byTitle.get(key);
    if (!existing || (!existing.image && t.image)) byTitle.set(key, t);
  }
  return [...byTitle.values()];
}

export interface HubSources {
  amter: HubTile[];
  profile: HubTile[];
  tourismus: HubTile[];
}

/** Einmal pro Build alle CPT-Kachelquellen für die Hub-Seiten laden. */
export async function fetchHubSources(): Promise<HubSources> {
  const [amter, profile, tourismus] = await Promise.all([
    fetchCptTiles('amter', 'amter'),
    fetchCptTiles('profile', 'profile', 'profilkategorie'),
    fetchCptTiles('tourismus', 'tourismus', 'tourismus_kat'),
  ]);
  return { amter, profile, tourismus };
}

/* ----------------------------------------------------------------
 * Events — vw-events Plugin (Namespace vw-events/v1)
 * ---------------------------------------------------------------- */

const VW_EVENTS_BASE =
  (import.meta.env.PUBLIC_VW_EVENTS_BASE as string | undefined) ??
  (typeof process !== 'undefined' ? process.env.PUBLIC_VW_EVENTS_BASE : undefined) ??
  'https://vv-wildenstein.com/wp-json/vw-events/v1';

interface VWEvent {
  id: number;
  slug: string;
  title: string;
  description_html: string;
  start: string | null;
  end: string | null;
  all_day: boolean;
  location: { name: string; address: string };
  organizer: { name: string };
  url: string;
  image: { url: string; alt: string } | null;
  standort: string[];
  category: string[];
  permalink: string;
}

export interface VvStelle {
  id: number;
  slug: string;
  titel: string;
  beschreibungHtml: string;
  arbeitgeber?: string;
  ort?: string;
  umfang?: string;
  ab?: string;
  frist?: string;
  /** Läuft ab am (JJJJ-MM-TT) — danach wird die Anzeige nicht mehr gezeigt */
  bis?: string;
  email?: string;
  telefon?: string;
  website?: string;
  /** Fertiger Aushang als PDF oder Bild, wie die Betriebe ihn schicken */
  datei?: { url: string; typ: string; name: string };
  /** Vorschaubild (Beitragsbild) */
  bild?: string;
  /** Auf welchen Websites die Anzeige erscheinen soll */
  orte: string[];
  art: string[];
}

/**
 * Stellenanzeigen aus dem Redaktionssystem.
 *
 * Abgelaufene Anzeigen werden hier herausgefiltert — das ist der Kern der
 * Umstellung: Vorher lagen die Aushänge als Bild-Kurzcodes in einer Seite,
 * und eine abgelaufene Anzeige fiel erst auf, wenn sich jemand beschwerte.
 *
 * @param website  'verband' | 'gruenhainichen' | 'boernichen' — es werden nur
 *                 Anzeigen zurückgegeben, die für diese Website gedacht sind
 *                 ('verband-weit' erscheint überall).
 */
export async function fetchWordPressStellen(
  website: 'verband' | 'gruenhainichen' | 'boernichen' = 'verband',
): Promise<VvStelle[]> {
  const url = new URL(`${WP_BASE}/stellenanzeigen`);
  url.searchParams.set('per_page', '100');
  url.searchParams.set('_embed', 'wp:featuredmedia');
  let res: Response;
  try {
    res = await fetch(url.toString(), {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
    });
  } catch {
    return [];
  }
  if (!res.ok) return [];

  const heute = new Date().toISOString().slice(0, 10);
  const raw = (await res.json()) as Array<
    WPPageRaw & WPCptEmbed & { vvw_stelle?: Record<string, unknown> }
  >;

  return raw
    .map((p) => {
      const s = (p.vvw_stelle ?? {}) as Record<string, any>;
      const txt = (v: unknown) => {
        const t = typeof v === 'string' ? v.trim() : '';
        return t === '' ? undefined : t;
      };
      return {
        id: p.id,
        slug: p.slug,
        titel: decodeEntities(stripHtml(p.title.rendered).trim()),
        beschreibungHtml: p.content?.rendered ?? '',
        arbeitgeber: txt(s.arbeitgeber),
        ort: txt(s.ort),
        umfang: txt(s.umfang),
        ab: txt(s.ab),
        frist: txt(s.frist),
        bis: txt(s.bis),
        email: txt(s.email),
        telefon: txt(s.telefon),
        website: txt(s.website),
        datei: s.datei?.url ? s.datei : undefined,
        bild: cptImage(p as WPCptEmbed),
        orte: Array.isArray(s.orte) ? s.orte : [],
        art: Array.isArray(s.art) ? s.art : [],
      } as VvStelle;
    })
    // Abgelaufene fliegen raus — der eigentliche Zweck der Umstellung
    .filter((st) => !st.bis || st.bis >= heute)
    // Nur Anzeigen für diese Website
    .filter((st) => st.orte.length === 0 || st.orte.includes('verband-weit') || st.orte.includes(website))
    // Nächste Bewerbungsfrist zuerst, Anzeigen ohne Frist danach
    .sort((a, b) => {
      if (a.frist && b.frist) return a.frist.localeCompare(b.frist);
      if (a.frist) return -1;
      if (b.frist) return 1;
      return a.titel.localeCompare(b.titel, 'de');
    });
}

export async function fetchWordPressEvents(): Promise<EventItem[]> {
  const url = new URL(`${VW_EVENTS_BASE}/events`);
  url.searchParams.set('per_page', '100');
  const fromDate = new Date();
  fromDate.setDate(fromDate.getDate() - 14);
  url.searchParams.set('from', fromDate.toISOString().slice(0, 19));

  const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    throw new Error(`vw-events ${res.status} ${res.statusText} (${url.pathname})`);
  }
  const events = (await res.json()) as VWEvent[];
  const postSlugs = await ensurePostSlugs();
  const downloadKarte = await ensureDownloadKarte();

  const now = new Date();
  return events
    .map((ev) => mapVWEvent(ev, postSlugs, downloadKarte))
    .filter((e): e is EventItem => e !== null)
    .filter((e) => (e.endDate ?? e.startDate).valueOf() >= now.valueOf())
    .sort((a, b) => a.startDate.valueOf() - b.startDate.valueOf());
}

function mapVWEvent(
  ev: VWEvent,
  postSlugs: Set<string>,
  downloadKarte: Map<string, string>,
): EventItem | null {
  if (!ev.start) return null;
  const startDate = new Date(ev.start);
  if (Number.isNaN(startDate.valueOf())) return null;
  const endDate = ev.end ? new Date(ev.end) : undefined;

  return {
    slug: ev.slug,
    title: decodeEntities(ev.title),
    startDate,
    endDate: endDate && !Number.isNaN(endDate.valueOf()) ? endDate : undefined,
    location: ev.location?.name || ev.location?.address || '',
    teaser: stripHtml(ev.description_html).trim().slice(0, 180),
    featured: false,
    image: ev.image?.url,
    // Interne Detailseite (gleicher Pfad wie das Original-Permalink)
    href: `/veranstaltungen/${ev.slug}`,
    contentHtml: rewriteContentUrls(ev.description_html ?? '', postSlugs, downloadKarte),
    organizer: ev.organizer?.name || undefined,
    allDay: ev.all_day || undefined,
  };
}

function decodeEntities(html: string): string {
  return html
    // numerische Entities generisch (deckt „ – — ‚ ' " … usw. ab)
    .replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)))
    .replace(/&#x([0-9a-fA-F]+);/g, (_, code) => String.fromCodePoint(parseInt(code, 16)))
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&hellip;/g, '…')
    .replace(/&nbsp;/g, ' ');
}

/**
 * Sprechzeiten des Rathauses für den Seitenfuß.
 *
 * Steht auf der Verwaltungsseite (WP-Seite "verwaltung") und wird von dort
 * geholt, statt im Fuß ein zweites Mal getippt zu werden — die getippte
 * Fassung war zuletzt an drei Stellen veraltet. Ergebnis einmal pro Build
 * merken: der Fuß steckt im BaseLayout und liefe sonst pro Seite neu.
 *
 * Schlägt der Abruf fehl, kommt eine leere Liste zurück und der Fuß verweist
 * auf /verwaltung. Lieber keine Zeit als eine falsche.
 */
let rathausZeitenPromise: Promise<Zeitspanne[]> | null = null;
export function fetchRathausZeiten(): Promise<Zeitspanne[]> {
  if (!rathausZeitenPromise) rathausZeitenPromise = ladeRathausZeiten();
  return rathausZeitenPromise;
}

async function ladeRathausZeiten(): Promise<Zeitspanne[]> {
  try {
    const url = new URL(`${WP_BASE}/pages`);
    url.searchParams.set('slug', 'verwaltung');
    url.searchParams.set('_fields', 'content');
    // Mit Zeitlimit: der Fuß steckt in jeder Seite. Ohne Abbruch hing ein
    // Build schon einmal über eine Stunde an einer nicht antwortenden REST-API.
    const abbruch = new AbortController();
    const wecker = setTimeout(() => abbruch.abort(), 15_000);
    let res: Response;
    try {
      res = await fetch(url.toString(), {
        headers: { Accept: 'application/json', ...buildAuthHeader() },
        signal: abbruch.signal,
      });
    } finally {
      clearTimeout(wecker);
    }
    if (!res.ok) throw new Error(`WP REST pages?slug=verwaltung ${res.status}`);
    const raw = (await res.json()) as Array<{ content?: { rendered?: string } }>;
    const html = raw[0]?.content?.rendered ?? '';
    return zerlegeOeffnungszeiten(html).zeiten;
  } catch (err) {
    console.warn('[verband] Sprechzeiten für den Fuß nicht abrufbar:', err);
    return [];
  }
}

/* ============================================================
 * Amtsblatt
 * ============================================================ */

export interface AmtsblattAusgabe {
  id: number;
  titel: string;
  /** Erscheinungsdatum (ISO, ohne Zeit) — für die Zeile unter dem Titel. */
  datum: string;
  /** Jahr der AUSGABE, nicht des Erscheinens. */
  jahr: number;
  /** Monat der Ausgabe, 1–12. Bestimmt die Monatsmarke. */
  monat: number;
  pdfUrl: string;
  /** Dateigröße in Bytes, 0 wenn unbekannt. */
  groesse: number;
}

interface WPAmtsblattRaw {
  id: number;
  date: string;
  title: { rendered: string };
  excerpt?: { rendered: string };
  vv_amtsblatt?: {
    pdfUrl?: string | null;
    groesse?: number;
    veroeffentlicht?: string | null;
    ausgabeMonat?: number | null;
    ausgabeJahr?: number | null;
  };
}

let amtsblattPromise: Promise<AmtsblattAusgabe[]> | null = null;
export function fetchAmtsblaetter(): Promise<AmtsblattAusgabe[]> {
  if (!amtsblattPromise) amtsblattPromise = ladeAmtsblaetter();
  return amtsblattPromise;
}

async function ladeAmtsblaetter(): Promise<AmtsblattAusgabe[]> {
  const roh: WPAmtsblattRaw[] = [];

  // 71 Ausgaben heute; zwei Seiten à 100 reichen weit in die Zukunft.
  for (let seite = 1; seite <= 2; seite++) {
    const url = new URL(`${WP_BASE}/amtsblatt_download`);
    url.searchParams.set('per_page', '100');
    url.searchParams.set('page', String(seite));
    url.searchParams.set('_fields', 'id,date,title,excerpt,vv_amtsblatt');

    const abbruch = new AbortController();
    const wecker = setTimeout(() => abbruch.abort(), 20_000);
    let res: Response;
    try {
      res = await fetch(url.toString(), {
        headers: { Accept: 'application/json', ...buildAuthHeader() },
        signal: abbruch.signal,
      });
    } finally {
      clearTimeout(wecker);
    }
    if (!res.ok) {
      if (seite > 1) break; // keine weitere Seite vorhanden
      // Bewusst hart scheitern: das Amtsblatt trägt amtliche Bekanntmachungen.
      // Eine still leere Liste (so stand die Seite bisher da) ist schlimmer als
      // ein Build-Abbruch — dann bleibt wenigstens der alte Stand online.
      throw new Error(`WP REST amtsblatt_download ${res.status} ${res.statusText}`);
    }
    const teil = (await res.json()) as WPAmtsblattRaw[];
    roh.push(...teil);
    if (teil.length < 100) break;
  }

  return roh
    .map((p) => {
      const feld = p.vv_amtsblatt;
      const titel = decodeEntities(p.title?.rendered ?? '');

      /* Einordnung nach der AUSGABE, nicht nach dem Erscheinen: Das Amtsblatt
         08/2026 kam am 31. Juli heraus und stünde sonst unter „Jul". Die
         Nummer steht im Titel und wird auch von dort gelesen — das Feld kommt
         aus einem mu-Plugin und fehlt, wenn ein Build auf einem älteren
         Zwischenspeicher läuft. */
      const nr = titel.match(/(\d{1,2})\s*\/\s*(\d{4})/);
      const erschienen = feld?.veroeffentlicht || p.date || '';
      const alsDatum = erschienen ? new Date(erschienen) : null;

      const monat = feld?.ausgabeMonat ?? (nr ? Number(nr[1]) : alsDatum ? alsDatum.getMonth() + 1 : 0);
      const jahr = feld?.ausgabeJahr ?? (nr ? Number(nr[2]) : alsDatum ? alsDatum.getFullYear() : 0);

      /* Die PDF-Adresse reicht vv-rest-amtsblatt.php nach; wp/v2 gibt sie nicht
         heraus. Der Auszug bleibt als Rückfall, falls eine Ausgabe sie nur
         dort stehen hat. */
      const ausAuszug = (p.excerpt?.rendered ?? '').match(/href="([^"]+\.pdf)"/i)?.[1];
      const pdfUrl = feld?.pdfUrl || ausAuszug || '';

      return {
        id: p.id,
        titel,
        datum: erschienen.slice(0, 10),
        jahr,
        monat,
        pdfUrl,
        groesse: feld?.groesse ?? 0,
        /* „Anzeigenpreise" und „Terminplan" liegen im selben Inhaltstyp, sind
           aber keine Ausgaben und gehören nicht in die Jahresliste. */
        _istAusgabe: feld?.ausgabeMonat != null || nr != null,
      };
    })
    .filter((a) => a._istAusgabe && a.pdfUrl)
    .map(({ _istAusgabe, ...a }) => a)
    .sort((a, b) => (b.jahr !== a.jahr ? b.jahr - a.jahr : b.monat - a.monat));
}

/* ============================================================
 * Gremien und ihre Mitglieder
 * ============================================================ */

export interface GremiumMitglied {
  name: string;
  /** Weitere Gremien derselben Person — z. B. „Bürgermeister". */
  rollen: string[];
}

export interface Gremium {
  id: number;
  name: string;
  slug: string;
  mitglieder: GremiumMitglied[];
}

/**
 * Personen und Gremien aus dem Inhaltstyp `personen`.
 *
 * Die Verbandsversammlungs-Seite zeigte diese Menschen bisher als
 * Bild-Kacheln aus einem Impreza-Portalgitter: 38 Kacheln mit leerer
 * Bildfläche, alle vier Abschnitte der Seite zu einem Haufen verschmolzen,
 * und JEDER der 38 Verweise zeigte auf eine Seite, die es nicht gibt
 * (/personen/… wird hier nicht gebaut). Zu den Personen sind ohnehin nur
 * Name und Gremium hinterlegt — kein Foto, keine Funktion, kein Kontakt.
 * Deshalb jetzt als schlichte Liste aus den echten Daten.
 */
let gremienPromise: Promise<Gremium[]> | null = null;
export function fetchGremien(): Promise<Gremium[]> {
  if (!gremienPromise) gremienPromise = ladeGremien();
  return gremienPromise;
}

/** Rollen, die neben der Mitgliedschaft als Zusatz erscheinen, statt als eigener Abschnitt. */
const ROLLEN_SLUGS = new Set(['buergermeister']);

async function ladeGremien(): Promise<Gremium[]> {
  const hole = async (pfad: string) => {
    const abbruch = new AbortController();
    const wecker = setTimeout(() => abbruch.abort(), 20_000);
    try {
      const res = await fetch(`${WP_BASE}/${pfad}`, {
        headers: { Accept: 'application/json', ...buildAuthHeader() },
        signal: abbruch.signal,
      });
      if (!res.ok) throw new Error(`WP REST ${pfad} ${res.status} ${res.statusText}`);
      return await res.json();
    } finally {
      clearTimeout(wecker);
    }
  };

  const [terme, personen] = await Promise.all([
    hole('gremien?per_page=100&_fields=id,name,slug') as Promise<
      Array<{ id: number; name: string; slug: string }>
    >,
    hole('personen?per_page=100&_fields=id,title,gremien') as Promise<
      Array<{ id: number; title: { rendered: string }; gremien?: number[] }>
    >,
  ]);

  const termById = new Map(terme.map((t) => [t.id, t]));

  /** Nach Nachnamen sortieren — „Dr. Nico Richter" gehört unter R, nicht unter D. */
  const nachname = (n: string) =>
    n.replace(/^(Dr\.|Prof\.|Dipl\.-\w+\.?)\s+/i, '').split(/\s+/).pop() ?? n;

  const out: Gremium[] = [];
  for (const t of terme) {
    if (ROLLEN_SLUGS.has(t.slug)) continue;

    const mitglieder = personen
      .filter((p) => (p.gremien ?? []).includes(t.id))
      .map((p) => ({
        name: decodeEntities(p.title.rendered),
        rollen: (p.gremien ?? [])
          .filter((g) => g !== t.id && ROLLEN_SLUGS.has(termById.get(g)?.slug ?? ''))
          .map((g) => termById.get(g)!.name),
      }))
      .sort((a, b) => nachname(a.name).localeCompare(nachname(b.name), 'de'));

    if (mitglieder.length > 0) out.push({ id: t.id, name: t.name, slug: t.slug, mitglieder });
  }

  /* Reihenfolge wie im Backend gepflegt: Verbandsversammlung zuerst, dann die
     beiden Gemeinderäte. */
  const rang = ['verbandsversammlung', 'gemeinderat-boernichen', 'gemeinderat-gruenhainichen'];
  return out.sort((a, b) => {
    const ra = rang.indexOf(a.slug);
    const rb = rang.indexOf(b.slug);
    return (ra < 0 ? 99 : ra) - (rb < 0 ? 99 : rb);
  });
}

/* ============================================================
 * Beitragslisten-Kurzcode [dt_blog_posts_small]
 * ============================================================ */

/**
 * Löst `[dt_blog_posts_small category="…" number="…"]` in eine echte Liste auf.
 *
 * Der Kurzcode stammt aus einer abgeschalteten Erweiterung und stand als
 * Roh-Text auf drei Seiten — zwei davon (/stellenausschreibungen,
 * /vergabeausschreibungen) hängen im Hauptmenü. Besucher lasen dort
 * `[dt_blog_posts_small category=„stellenausschreibungen"]`.
 *
 * Typografische Anführungszeichen sind Absicht: die Redaktion schreibt
 * category=„…" mit „ und ″, nicht mit geraden Anführungszeichen.
 */
/**
 * Beitragsliste für eine Seite, die im Redaktionssystem leer ist, deren
 * Kürzel aber einer Beitragskategorie entspricht.
 *
 * /stellenanzeigen ist im WordPress leer — auch auf der alten Seite stand dort
 * nur die Überschrift. Die Kategorie „Stellenanzeigen" hat aber Beiträge.
 * Liefert '' wenn es weder Kategorie noch Beiträge gibt; dann bleibt der
 * bisherige Leer-Hinweis stehen.
 */
export async function beitragslisteFuerKuerzel(kuerzel: string): Promise<string> {
  if (!/^[a-z0-9-]{3,}$/.test(kuerzel)) return '';
  const beitraege = await ladeKategorieBeitraege(kuerzel, 20);
  return beitraege.length ? baueBeitragsliste(beitraege) : '';
}

export async function loeseBeitragslisten(html: string): Promise<string> {
  if (!/\[dt_blog_posts_small/i.test(html)) return html;

  const treffer = [...html.matchAll(/\[dt_blog_posts_small([^\]]*)\]/gi)];
  let out = html;

  for (const m of treffer) {
    /* Die Anführungszeichen stehen im Inhalt als HTML-Entity (&#8220;), nicht
       als Zeichen — erst entschärfen, dann lesen. Ohne das blieb die Kategorie
       leer und der Kurzcode wurde ersatzlos entfernt: /stellenausschreibungen
       stand danach als leere Seite da. */
    const attr = m[1].replace(/&#\d+;|&#x[0-9a-f]+;|&quot;/gi, '"');
    const kat = attr.match(/category\s*=\s*["'„“”]?\s*([a-z0-9_-]+)/i)?.[1];
    const anzahl = Number(attr.match(/number\s*=\s*["'„“”]?\s*(\d+)/i)?.[1] ?? 10);
    if (!kat) {
      out = out.replace(m[0], '');
      continue;
    }
    const beitraege = await ladeKategorieBeitraege(kat, Math.min(anzahl, 20));
    out = out.replace(m[0], baueBeitragsliste(beitraege));
  }
  return out;
}

interface KatBeitrag {
  titel: string;
  pfad: string;
  datum: string;
  auszug: string;
}

const katCache = new Map<string, Promise<KatBeitrag[]>>();

function ladeKategorieBeitraege(slug: string, anzahl: number): Promise<KatBeitrag[]> {
  const schluessel = `${slug}:${anzahl}`;
  let p = katCache.get(schluessel);
  if (!p) {
    p = holeKategorieBeitraege(slug, anzahl);
    katCache.set(schluessel, p);
  }
  return p;
}

async function holeKategorieBeitraege(slug: string, anzahl: number): Promise<KatBeitrag[]> {
  const abbruch = new AbortController();
  const wecker = setTimeout(() => abbruch.abort(), 20_000);
  try {
    const katUrl = new URL(`${WP_BASE}/categories`);
    katUrl.searchParams.set('slug', slug);
    katUrl.searchParams.set('_fields', 'id');
    const katRes = await fetch(katUrl.toString(), {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
      signal: abbruch.signal,
    });
    if (!katRes.ok) return [];
    const kats = (await katRes.json()) as Array<{ id: number }>;
    if (!kats.length) return [];

    const url = new URL(`${WP_BASE}/posts`);
    url.searchParams.set('categories', String(kats[0].id));
    url.searchParams.set('per_page', String(anzahl));
    url.searchParams.set('_fields', 'slug,title,date,excerpt');
    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
      signal: abbruch.signal,
    });
    if (!res.ok) return [];
    const roh = (await res.json()) as Array<{
      slug: string;
      title: { rendered: string };
      date: string;
      excerpt?: { rendered: string };
    }>;
    return roh.map((b) => ({
      titel: decodeEntities(stripHtml(b.title.rendered).trim()),
      pfad: `/neuigkeiten/${entschaerfeSlug(b.slug)}`,
      datum: b.date?.slice(0, 10) ?? '',
      auszug: decodeEntities(stripHtml(b.excerpt?.rendered ?? '').trim()).slice(0, 180),
    }));
  } catch (err) {
    console.warn(`[verband] Beitragsliste "${slug}" nicht abrufbar:`, err);
    return [];
  } finally {
    clearTimeout(wecker);
  }
}

function baueBeitragsliste(beitraege: KatBeitrag[]): string {
  if (beitraege.length === 0) {
    return '<p class="vv-hinweis">Derzeit liegen hierzu keine Einträge vor.</p>';
  }
  const fmt = new Intl.DateTimeFormat('de-DE', {
    day: '2-digit', month: 'long', year: 'numeric', timeZone: 'Europe/Berlin',
  });
  const zeilen = beitraege
    .map((b) => {
      const datum = b.datum ? fmt.format(new Date(b.datum)) : '';
      return (
        `<li><a href="${b.pfad}">` +
        (datum ? `<time datetime="${b.datum}">${datum}</time>` : '') +
        `<strong>${b.titel}</strong>` +
        (b.auszug ? `<span>${b.auszug}…</span>` : '') +
        `</a></li>`
      );
    })
    .join('');
  return `<ul class="vv-beitragsliste">${zeilen}</ul>`;
}

/* ================================================================
   Foerderbescheide (Seite /verband/ausschreibungen)
   ================================================================ */

export interface Foerdermassnahme {
  titel: string;
  gesamt?: string;   // Gesamtausgaben, z. B. „32.644,52 Euro"
  satz?: string;     // Foerdersatz, z. B. „60"
  betrag?: string;   // Foerderbetrag, z. B. „19.586,71 Euro"
  textHtml: string;  // Beschreibung ohne die beiden Geldsaetze
}

export interface Foerderblock {
  logos: { src: string; alt: string }[];
  /* Abschnitte desselben Foerderprogramms teilen ein Logoband. Die Gruppe
     sagt der Anzeige, wo das Band wiederholt wuerde — dort bleibt es weg. */
  logoGruppe: number;
  grundlage?: string;              // „Folgende … wurde bewilligt:"
  massnahmen: Foerdermassnahme[];
  anmerkungHtml?: string;
}

export interface Foerderuebersicht {
  bloecke: Foerderblock[];
  hinweise: string[];
}

/**
 * Alternativtexte fuer die Foerderlogos.
 *
 * Im WordPress stehen alle fuenf ohne alt-Attribut — fuer einen Screenreader
 * war der halbe Seitenkopf dadurch stumm. Zuordnung ueber den Dateinamen,
 * die Texte stammen von den Logos selbst.
 */
const FOERDER_LOGOS: { muster: RegExp; alt: string }[] = [
  { muster: /Logo-EU_NEU/i,   alt: 'Kofinanziert von der Europäischen Union' },
  { muster: /SMUL_LO_EPLR/i,  alt: 'EPLR – Entwicklungsprogramm für den ländlichen Raum im Freistaat Sachsen 2014–2020, Europäischer Landwirtschaftsfonds für die Entwicklung des ländlichen Raums' },
  { muster: /LEADER-\d+x\d+/, alt: 'Verein zur Entwicklung der Erzgebirgsregion Flöha- und Zschopautal e. V.' },
  { muster: /Leader/i,        alt: 'LEADER' },
];

function logoAlt(src: string): string {
  const name = src.split('/').pop() ?? '';
  return FOERDER_LOGOS.find((l) => l.muster.test(name))?.alt ?? '';
}

/** Äußere Auszeichnungs-Hüllen abschälen, die WPBakery um ganze Absätze legt. */
function schaeleHuellen(html: string): string {
  let s = html.trim();
  for (let i = 0; i < 6; i++) {
    const m = /^<(span|b|strong|i|em|u)\b[^>]*>([\s\S]*)<\/\1>$/i.exec(s);
    if (!m) break;
    // Nur abschälen, wenn die Hülle wirklich den ganzen Absatz umfasst.
    if (/<\/(span|b|strong|i|em|u)>/i.test(m[2]) && m[2].split(`</${m[1]}>`).length > 1) {
      // Mehrere gleichnamige Enden: Hülle ist nicht eindeutig — Finger weg.
      const tag = new RegExp(`</?${m[1]}\\b[^>]*>`, 'gi');
      let tiefe = 0;
      let heil = true;
      let t: RegExpExecArray | null;
      const ganz = s;
      while ((t = tag.exec(ganz)) !== null) {
        tiefe += t[0].startsWith('</') ? -1 : 1;
        if (tiefe === 0 && t.index + t[0].length < ganz.length) { heil = false; break; }
      }
      if (!heil) break;
    }
    s = m[2].trim();
  }
  return s;
}

const nurText = (html: string) =>
  decodeEntities(html.replace(/<[^>]+>/g, '')).replace(/\s+/g, ' ').trim();

/**
 * Liest die Foerderbescheide aus dem WordPress-Absatzsalat in eine Struktur.
 *
 * Die Seite kam als einziger Textblock: fuenf Logos ohne Alternativtext, die
 * Rechtsgrundlagen als fettes Kursiv mitten im Fliesstext, jede Massnahme ein
 * Absatz, der mit fettem Titel beginnt und die beiden Geldbetraege im Satz
 * versteckt. Zwoelf Bewilligungen sahen dadurch aus wie eine Textwueste.
 *
 * Erkannt wird ueber die Form der Absaetze, nicht ueber ihren Wortlaut:
 *   - Absatz nur mit Bildern        → Logoband, beginnt einen neuen Block
 *   - fett ueber den ganzen Absatz  → Rechtsgrundlage des Blocks
 *   - fetter Anfang + Resttext      → einzelne Massnahme
 *   - „Anmerkung:"                  → Fussnote des Blocks
 *   - schlichter Satz               → Hinweis am Seitenende
 *
 * Gibt null zurueck, sobald die Seite nicht diesem Muster entspricht. Dann
 * bleibt der gewohnte Fliesstext stehen — eine Umstellung im Backend kann die
 * Seite so nicht leer werden lassen.
 */
export function leseFoerderbloecke(html: string): Foerderuebersicht | null {
  const absaetze = [...html.matchAll(/<p\b[^>]*>([\s\S]*?)<\/p>/gi)].map((m) => m[1]);
  if (absaetze.length < 4) return null;

  const bloecke: Foerderblock[] = [];
  const hinweise: string[] = [];
  let aktuell: Foerderblock | null = null;
  let gruppe = 0;
  const neuerBlock = (eigeneGruppe = true) => {
    if (eigeneGruppe) gruppe++;
    aktuell = { logos: [], logoGruppe: gruppe, massnahmen: [] };
    bloecke.push(aktuell);
    return aktuell;
  };

  for (const roh of absaetze) {
    const bilder = [...roh.matchAll(/<img\b[^>]*\bsrc="([^"]+)"[^>]*>/gi)].map((m) => m[1]);
    const text = nurText(roh);

    // Leerabsatz (&nbsp;-Platzhalter aus dem Baukasten)
    if (!text && bilder.length === 0) continue;

    // Logoband: beginnt einen neuen Foerderabschnitt
    if (!text && bilder.length > 0) {
      const b = neuerBlock();
      b.logos = bilder.map((src) => ({ src, alt: logoAlt(src) }));
      continue;
    }

    const kern = schaeleHuellen(roh);
    const fett = /^<(b|strong)\b[^>]*>([\s\S]*?)<\/\1>/i.exec(kern);
    const rest = fett ? kern.slice(fett[0].length) : '';
    const restText = nurText(rest);

    /* Rechtsgrundlage. Erkannt am Doppelpunkt am Ende, nicht an der
       Fett-Auszeichnung: diese Absaetze sind im Baukasten komplett in
       <b><strong><i><em> gehuellt, und genau die Huelle schaelt
       schaeleHuellen() ab. Eine Massnahme endet nie auf einem Doppelpunkt. */
    if (/:\s*$/.test(text) && text.length > 60) {
      const b = aktuell ?? neuerBlock();
      if (b.grundlage && b.massnahmen.length > 0) {
        // Zweite Rechtsgrundlage ohne eigenes Logoband → eigener Abschnitt
        // im selben Foerderprogramm.
        const n = neuerBlock(false);
        n.logos = [...b.logos];
        n.grundlage = text;
      } else {
        b.grundlage = text;
      }
      continue;
    }

    // Fussnote des Abschnitts
    if (/^Anmerkung\b/i.test(text)) {
      const b = aktuell ?? neuerBlock();
      b.anmerkungHtml = kern.replace(/^<em><u>\s*Anmerkung:\s*<\/u><\/em>\s*/i, '').trim();
      continue;
    }

    // Fetter Titel + Beschreibung → Massnahme
    if (fett && restText.length >= 20) {
      const titel = nurText(fett[2]).replace(/[,\s]+$/, '');
      const gesamt = /Gesamtausgaben\s+in\s+Höhe\s+von\s+([\d.,]+)\s*Euro/i.exec(restText)?.[1];
      const foerder = /Fördersatz\s+von\s+(\d+)\s*%\s*beträgt\s+([\d.,]+)\s*Euro/i.exec(restText);
      // Die beiden Geldsaetze stehen oben als Kennzahlen — im Text sind sie
      // dann doppelt und verdecken, worum es bei der Massnahme eigentlich geht.
      const textHtml = rest
        .replace(
          /^\s*(?:<[^>]+>\s*)*mit\s+Gesamtausgaben\s+in\s+Höhe\s+von\s+[\d.,]+\s*Euro\.\s*Der\s+Fördersatz\s+von\s+\d+\s*%\s*beträgt\s+[\d.,]+\s*Euro\.\s*/i,
          '',
        )
        .trim();
      (aktuell ?? neuerBlock()).massnahmen.push({
        titel,
        gesamt: gesamt ? `${gesamt} Euro` : undefined,
        satz: foerder?.[1],
        betrag: foerder ? `${foerder[2]} Euro` : undefined,
        textHtml: textHtml || rest.trim(),
      });
      continue;
    }

    // Schlichter Satz ohne Auszeichnung → Hinweis unter die Seite
    if (text.length > 0 && text.length < 200) {
      hinweise.push(text);
      continue;
    }

    // Etwas Unerwartetes — Struktur nicht verlaesslich, Fliesstext behalten.
    return null;
  }

  const massnahmen = bloecke.reduce((n, b) => n + b.massnahmen.length, 0);
  if (massnahmen < 2) return null;

  /* Freistehende Logos ohne eigenen Inhalt: am Seitenende hing das
     LEADER-Zeichen allein unter dem letzten Satz. Es gehoert zum Programm des
     letzten Abschnitts — also an dessen ganze Logo-Gruppe, nicht nur an den
     letzten Absatz. */
  for (let i = bloecke.length - 1; i > 0; i--) {
    const b = bloecke[i];
    if (b.massnahmen.length > 0 || b.grundlage || b.logos.length === 0) continue;
    const ziel = bloecke.slice(0, i).reverse().find((v) => v.massnahmen.length > 0);
    if (ziel) {
      for (const v of bloecke.filter((x) => x.logoGruppe === ziel.logoGruppe)) {
        for (const l of b.logos) {
          if (!v.logos.some((x) => x.alt === l.alt)) v.logos.push(l);
        }
      }
    }
    bloecke.splice(i, 1);
  }

  return { bloecke: bloecke.filter((b) => b.massnahmen.length > 0), hinweise };
}

export interface Vergabeverfahren {
  titel: string;
  datum?: string;
  textHtml: string;
}

let vergabenPromise: Promise<Vergabeverfahren[]> | null = null;

/**
 * Laufende Vergabeverfahren aus dem CPT „ausschreibungen".
 *
 * Auf der alten Seite stand ueber den Foerderbescheiden ein Impreza-Gitter
 * genau dieses Inhaltstyps (mit no_items_action="hide_grid", deshalb war dort
 * nichts zu sehen: veroeffentlicht ist derzeit kein einziger Eintrag). Ohne
 * diesen Abruf wuerde eine kuenftige Vergabe auf der neuen Seite still
 * fehlen — sie erscheint jetzt oben, sobald die Verwaltung eine einstellt.
 */
export function fetchAusschreibungen(): Promise<Vergabeverfahren[]> {
  if (!vergabenPromise) vergabenPromise = holeAusschreibungen();
  return vergabenPromise;
}

async function holeAusschreibungen(): Promise<Vergabeverfahren[]> {
  const abbruch = new AbortController();
  const wecker = setTimeout(() => abbruch.abort(), 20_000);
  try {
    const url = new URL(`${WP_BASE}/ausschreibungen`);
    url.searchParams.set('per_page', '20');
    url.searchParams.set('orderby', 'date');
    url.searchParams.set('order', 'desc');
    url.searchParams.set('_fields', 'title,date,content,excerpt');
    const res = await fetch(url.toString(), {
      headers: { Accept: 'application/json', ...buildAuthHeader() },
      signal: abbruch.signal,
    });
    if (!res.ok) return [];
    const rohe = (await res.json()) as Array<{
      title?: { rendered?: string };
      date?: string;
      content?: { rendered?: string };
      excerpt?: { rendered?: string };
    }>;
    return rohe.map((r) => ({
      titel: decodeEntities(r.title?.rendered ?? '').trim(),
      datum: r.date,
      textHtml: (r.content?.rendered ?? r.excerpt?.rendered ?? '').trim(),
    })).filter((v) => v.titel);
  } catch (err) {
    console.warn('[verband] Vergabeverfahren nicht abrufbar:', err);
    return [];
  } finally {
    clearTimeout(wecker);
  }
}
