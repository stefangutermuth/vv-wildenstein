#!/usr/bin/env node
/**
 * Prüft fest im Quelltext eingetragene Kontaktdaten gegen das Redaktionssystem.
 *
 * Warum es das gibt
 * -----------------
 * Am 17.08.2026 wechselte der Verwaltungsverband die Leitung der Kita
 * „Mäuseburg" von Frau Wolf auf Frau Richter. Die Detailseite zog automatisch
 * nach — die Kachel auf der Übersichtsseite trug den Namen fest im Quelltext
 * und zeigte weiter den alten. Auf einer Seite standen zwei verschiedene
 * Namen für dieselbe Einrichtung.
 *
 * Aufgefallen ist das nur, weil eine Mitarbeiterin nachgefragt hat. Kein
 * Fehler, keine Meldung, kein Hinweis — die Seite hätte den falschen Namen
 * beliebig lange still weitergetragen.
 *
 * Dieses Skript ersetzt die Aufmerksamkeit eines Menschen: Es hält jeden fest
 * eingetragenen Wert gegen das Redaktionssystem und meldet, sobald etwas
 * auseinanderläuft.
 *
 * Aufruf:  npm run pruefe:daten
 *          npm run pruefe:daten -- --streng     (Rückgabewert 1 bei Abweichung)
 */

import { readFile } from 'node:fs/promises';
import path from 'node:path';

const API = 'https://vv-wildenstein.com/wp-json/wp/v2';
const WURZEL = path.resolve(import.meta.dirname, '..', 'apps', 'gruenhainichen');

/** Dateien mit fest eingetragenen Einrichtungen. */
const SEITEN = [
  'src/pages/leben/einkaufen.astro',
  'src/pages/leben/gesundheit.astro',
  'src/pages/leben/kirche.astro',
  'src/pages/leben/kita.astro',
];

/**
 * Dieselbe Einrichtung heißt an beiden Orten unterschiedlich.
 * Ohne diese Zuordnung meldete das Skript sie dauerhaft als „nicht gefunden"
 * — und solche Dauerwarnungen liest nach zwei Wochen niemand mehr.
 */
const ALIASE = {
  'Zahnarztpraxis Anke Nüßler': 'Anke Nüßler',
  'Praxis Ines Holler': 'Ines Holler',
  'Ev.-Luth. Kirche Borstendorf': 'Evangel.-luther. Kirche Borstendorf',
  'Ev.-Luth. Kirchgemeinde Grünhainichen': 'Evangelisch-Lutherischen Kirchgemeinde Grünhainichen',
  'Raiffeisen BHG Waldkirchen e.G.': 'Raiffeisen Bezugs- und Handelsgenossenschaft Waldkirchen/Erzgeb. e.G.',
  'Hobler — Figuren mit Herz': 'Hobler – Figuren mit Herz aus Grünhainichen',
  'Apotheke Grünhainichen': 'Apotheke Grünhainichen, Apotheker Andreas Enger e. K.',
  'Kita „Borstel"': 'Kita „Borstel“ Integrative Kindertageseinrichtung Borstendorf einschließlich Hort',
};

const norm = (s) =>
  (s ?? '')
    .toLowerCase()
    .replaceAll('ä', 'a').replaceAll('ö', 'o').replaceAll('ü', 'u').replaceAll('ß', 'ss')
    .replace(/[^a-z0-9]/g, '');

/** Nur die Ziffern vergleichen — „037294 / 1541" und „037294/ 15 41" sind dieselbe Nummer. */
const ziffern = (s) => (s ?? '').replace(/\D/g, '');

const entities = (s) =>
  (s ?? '')
    .replace(/&#(\d+);/g, (_, c) => String.fromCodePoint(+c))
    .replace(/&#x([0-9a-f]+);/gi, (_, c) => String.fromCodePoint(parseInt(c, 16)))
    .replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&nbsp;/g, ' ')
    .replace(/&(?:bdquo|ldquo|rdquo|lsquo|rsquo);/g, '"');

async function ladeProfile() {
  const alle = [];
  for (let seite = 1; seite <= 5; seite++) {
    const r = await fetch(`${API}/profile?per_page=100&page=${seite}&_fields=slug,title,vv_kontakt`, {
      headers: { Accept: 'application/json' },
    });
    if (!r.ok) break;
    const teil = await r.json();
    alle.push(...teil);
    if (teil.length < 100) break;
  }
  const nach = new Map();
  for (const p of alle) {
    nach.set(norm(entities(p.title.rendered)), {
      titel: entities(p.title.rendered),
      slug: p.slug,
      kontakt: p.vv_kontakt ?? {},
    });
  }
  return nach;
}

/**
 * Wert eines Feldes lesen — bis zum passenden Schlusszeichen, nicht bis zum
 * nächstbesten. Namen wie `'Kita „Borstel"'` enthalten typografische
 * Anführungszeichen; ein einfaches [^'"]+ bricht dort mittendrin ab und der
 * Abgleich mit dem Redaktionssystem scheitert an einem halben Namen.
 */
function feld(block, schluessel) {
  const m = block.match(new RegExp(`(?:${schluessel}):\\s*(['"])((?:\\\\.|(?!\\1).)*)\\1`, 's'));
  return m ? m[2].replace(/\\(['"])/g, '$1') : '';
}

/** Objektliterale mit `name:` aus einer Astro-Datei holen. */
function lieseEintraege(quelltext) {
  const bloecke = quelltext.match(/\{[^{}]*?name:\s*['"][^{}]*?\}/gs) ?? [];
  return bloecke.map((b) => ({
    name: feld(b, 'name'),
    telefon: feld(b, 'phone|telefon'),
    email: feld(b, 'email'),
    leitung: feld(b, 'leitung'),
  }));
}

const abweichungen = [];
const ohneProfil = [];
let geprueft = 0;

const profile = await ladeProfile();
if (profile.size === 0) {
  // Kein Grund, den Bau scheitern zu lassen — aber sagen muss man es.
  console.log('⚠  Redaktionssystem nicht erreichbar — Prüfung übersprungen.');
  process.exit(0);
}

console.log(`Profile im Redaktionssystem: ${profile.size}\n`);

for (const datei of SEITEN) {
  const quelltext = await readFile(path.join(WURZEL, datei), 'utf8');
  const eintraege = lieseEintraege(quelltext);
  let auffaellig = 0;

  for (const e of eintraege) {
    if (!e.telefon && !e.email && !e.leitung) continue; // reine Gestaltungsobjekte
    geprueft++;

    const gesucht = ALIASE[e.name] ?? e.name;
    const p = profile.get(norm(gesucht));
    if (!p) {
      ohneProfil.push({ datei, name: e.name });
      continue;
    }

    const k = p.kontakt;
    const pruefe = (feld, unser, deren, vergleich = (a, b) => a.trim().toLowerCase() === b.trim().toLowerCase()) => {
      if (!unser || !deren) return;
      if (!vergleich(unser, deren)) {
        abweichungen.push({ datei, name: e.name, feld, unser, deren, slug: p.slug });
        auffaellig++;
      }
    };

    pruefe('Telefon', e.telefon, k.telefon, (a, b) => ziffern(a) === ziffern(b));
    pruefe('E-Mail', e.email, k.email);
    // Im Redaktionssystem steht oft „Leiterin: Frau Richter" — die Anrede weg.
    pruefe('Leitung', e.leitung, (k.fuehrende_person ?? '').replace(/^\s*(Leiterin|Leiter|Leitung)\s*:\s*/i, ''));
  }

  const zeichen = auffaellig ? '⚠' : '✓';
  console.log(`${zeichen} ${datei.padEnd(34)} ${eintraege.length} Einträge`);
}

console.log('');

if (abweichungen.length > 0) {
  console.log('═'.repeat(74));
  console.log('ABWEICHUNGEN — Quelltext und Redaktionssystem widersprechen sich');
  console.log('═'.repeat(74));
  for (const a of abweichungen) {
    console.log(`\n  ${a.name}   (${a.datei})`);
    console.log(`    ${a.feld}`);
    console.log(`      auf unserer Seite:      ${a.unser}`);
    console.log(`      im Redaktionssystem:    ${a.deren}`);
    console.log(`      https://vv-wildenstein.com/profile/${a.slug}/`);
  }
  console.log('');
}

if (ohneProfil.length > 0) {
  console.log('Ohne Gegenstück im Redaktionssystem (nur zur Kenntnis):');
  for (const o of ohneProfil) console.log(`  · ${o.name}  (${o.datei})`);
  console.log('  → Falls es dort doch existiert, gehört der Name in die ALIASE-Liste dieses Skripts.\n');
}

console.log('─'.repeat(74));
console.log(`Geprüft: ${geprueft} Einträge · Abweichungen: ${abweichungen.length} · ohne Profil: ${ohneProfil.length}`);

// Für die Anzeige in GitHub Actions
if (process.env.GITHUB_STEP_SUMMARY) {
  const { appendFile } = await import('node:fs/promises');
  const zeilen = [
    '## Abgleich fest eingetragener Kontaktdaten',
    '',
    `Geprüft: **${geprueft}** · Abweichungen: **${abweichungen.length}** · ohne Profil: ${ohneProfil.length}`,
    '',
  ];
  if (abweichungen.length) {
    zeilen.push('| Einrichtung | Feld | Auf unserer Seite | Im Redaktionssystem |', '|---|---|---|---|');
    for (const a of abweichungen) zeilen.push(`| ${a.name} | ${a.feld} | ${a.unser} | ${a.deren} |`);
  } else {
    zeilen.push('Keine Abweichungen gefunden.');
  }
  await appendFile(process.env.GITHUB_STEP_SUMMARY, zeilen.join('\n') + '\n');
}

// Sichtbare Warnung im Aktionsprotokoll
for (const a of abweichungen) {
  console.log(`::warning file=apps/gruenhainichen/${a.datei}::${a.name}: ${a.feld} steht als „${a.unser}", im Redaktionssystem als „${a.deren}"`);
}

process.exit(process.argv.includes('--streng') && abweichungen.length > 0 ? 1 : 0);
