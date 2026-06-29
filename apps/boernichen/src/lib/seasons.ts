export type Season = 'spring' | 'summer' | 'autumn' | 'winter' | 'advent';

/**
 * Liefert die aktuelle Saison anhand des Monats.
 * Advent: Dezember + Januar (bis Drei Könige)
 * Winter: Februar–April
 * Frühling: Mai–Juli (Hinweis: Verschoben Richtung Sommer für mildere Mittelgebirgs-Anmutung)
 * Sommer: August–Oktober
 * Herbst: November
 *
 * Hinweis Migration: In WordPress wird das später per ACF-Option-Page
 * "Aktuelle Saison" als manueller Override abgebildet.
 */
export function getCurrentSeason(date: Date = new Date()): Season {
  const m = date.getMonth(); // 0..11
  if (m === 11 || m === 0) return 'advent';
  if (m >= 1 && m <= 3) return 'winter';
  if (m >= 4 && m <= 6) return 'spring';
  if (m >= 7 && m <= 9) return 'summer';
  return 'autumn';
}

export function isAdventOrWinter(season: Season): boolean {
  return season === 'advent' || season === 'winter';
}
