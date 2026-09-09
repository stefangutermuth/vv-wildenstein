/**
 * Prüft, ob jedes im WordPress gepflegte Redaktionsfeld (vv_kontakt) auch auf
 * der gebauten Seite steht. Aufruf:  node tools/feld-audit.mjs [verband|grh]
 *
 * Hintergrund: Die Angaben der CPTs (Ämter, Vereine, Profile, Tourismus) stehen
 * nicht im Fließtext, sondern in ACF-Feldern. Fällt eines aus dem Mapping,
 * verschwindet es lautlos von der Website — genau das war mit „offnungszeiten"
 * und den Ämter-Kontakten passiert.
 */
import fs from 'node:fs';
import path from 'node:path';

const WP = 'https://vv-wildenstein.com/wp-json/wp/v2';
const ZIEL = process.argv[2] ?? 'verband';
const DIST = ZIEL === 'grh' ? 'apps/gruenhainichen/dist' : 'apps/verband/dist';
const ROUTEN =
  ZIEL === 'grh'
    ? { tourismus: (s) => [`tourismus/eintrag/${s}`], verein: (s) => [`vereine/${s}`],
        profile: (s) => [`gewerbe/${s}`, `leben/kita/${s}`] }
    : { amter: (s) => [`amter/${s}`], tourismus: (s) => [`tourismus/${s}`],
        verein: (s) => [`verein/${s}`], profile: (s) => [`profile/${s}`] };

const norm = (s) => (s || '').replace(/\s+/g, ' ').replace(/[‐-―]/g, '-').trim().toLowerCase();

function seitentext(datei) {
  if (!fs.existsSync(datei)) return null;
  const h = fs.readFileSync(datei, 'utf8');
  const m = h.match(/<main[^>]*>([\s\S]*?)<\/main>/i);
  return norm((m ? m[1] : h).replace(/<[^>]+>/g, ' ').replace(/&[a-z#0-9]+;/gi, ' '));
}

/** Suchbare Kernbestandteile — Schema und Schluss-Slash weg (Links werden gekürzt gezeigt). */
function kern(wert) {
  const roh = String(wert).replace(/^https?:\/\//i, '').replace(/\/$/, '');
  const zeilen = roh.split(/\r?\n/).map((z) => z.trim()).filter((z) => z.length > 3);
  const teile = [];
  if (zeilen[0]) teile.push(zeilen[0]);
  const tel = roh.match(/\d[\d\s/().-]{5,}\d/g);
  if (tel) teile.push(...tel.slice(0, 2));
  return teile.slice(0, 3);
}

async function alle(typ) {
  const out = [];
  for (let p = 1; p <= 5; p++) {
    const r = await fetch(`${WP}/${typ}?per_page=100&page=${p}&status=publish`);
    if (!r.ok) break;
    const b = await r.json();
    out.push(...b);
    if (b.length < 100) break;
  }
  return out;
}

let geprueft = 0, ok = 0, ohneSeite = 0;
const luecken = [];
for (const [typ, routen] of Object.entries(ROUTEN)) {
  for (const it of await alle(typ)) {
    const gefuellt = Object.entries(it.vv_kontakt || {}).filter(([, v]) => String(v || '').trim());
    if (!gefuellt.length) continue;
    let txt = null;
    for (const r of routen(it.slug)) {
      txt = seitentext(path.join(DIST, r, 'index.html'));
      if (txt !== null) break;
    }
    if (txt === null) { ohneSeite++; continue; }
    geprueft++;
    for (const [feld, wert] of gefuellt) {
      const teile = kern(wert);
      if (!teile.length || teile.some((t) => txt.includes(norm(t)))) ok++;
      else luecken.push({ typ, slug: it.slug, feld, wert: String(wert).replace(/\s+/g, ' ').slice(0, 60) });
    }
  }
}

console.log(`# FELD-AUDIT (${ZIEL}) — ${geprueft} Einträge geprüft, ${ohneSeite} dort nicht gelistet`);
console.log(`  Felder korrekt: ${ok} | auffällig: ${luecken.length}`);
if (luecken.length) {
  console.log('\n  Hinweis: Treffer mit & " oder Links sind meist Artefakte dieses Skripts —');
  console.log('  vor dem Melden einzeln gegenprüfen.\n');
  for (const l of luecken.slice(0, 20)) console.log(`   [${l.typ}] ${l.slug} → ${l.feld}: "${l.wert}"`);
}
