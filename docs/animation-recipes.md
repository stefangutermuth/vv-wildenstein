# Animation Recipes

Architektur-Entscheidung: **Kein GSAP im Erstwurf.** Die Hero-Animationen sind so konzipiert, dass sie mit reinen CSS-Keyframes + IntersectionObserver auskommen. Vorteile: ~0 KB JS-Overhead, perfekte WordPress-Migrierbarkeit, keine Lizenzfragen für die spätere Multisite. GSAP kann jederzeit nachgerüstet werden, falls komplexe Choreografien (z. B. Scroll-Scrub) gewünscht werden.

## 1. Schwibbogen-Lichter entzünden

**Ort:** `src/components/hero/GrhHero.astro` (Trigger), `src/components/hero/GrhSchwibbogenSvg.astro` (Markup), `src/styles/animations.css` (Keyframes)

**Trigger:** IntersectionObserver, Threshold 0.4. Sobald der Hero zu 40 % im Viewport ist, bekommt der Wrapper `data-grh-schwibbogen` die Klasse `is-ignited`.

**Animation:** Stagger über 11 `.grh-light`-Elemente via `nth-child(n)` und `animation-delay` (150 ms Versatz). Jedes Licht durchläuft `grh-light-ignite` (600 ms, einmalig) und anschließend `grh-light-pulse` (4 s, infinite, ease-in-out).

**Reduced Motion:** Bei `prefers-reduced-motion: reduce` werden alle Lichter sofort eingeblendet (`opacity: 1`), Animationen deaktiviert.

```js
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (reduce) {
  schwibbogen.classList.add('is-ignited');
} else {
  const io = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) {
        schwibbogen.classList.add('is-ignited');
        io.disconnect();
        break;
      }
    }
  }, { threshold: 0.4 });
  io.observe(schwibbogen);
}
```

**WordPress-Migration:** 1:1 in `grh-hero.js` übernehmen, mit `wp_enqueue_script` registrieren. Die Klassen-Hooks (`.grh-schwibbogen`, `.grh-light`) bleiben identisch.

---

## 2. Wanderpfad-Linie (Sommer-Modus)

**Trigger:** Page-Load (CSS-Animation startet automatisch).

**Animation:** SVG-`<path>` mit `stroke-dasharray="6 12"` und `stroke-dashoffset: 600`. Keyframe `grh-path-draw` setzt offset auf 0 in 4 s.

**Reduced Motion:** Path wird direkt vollständig gezeichnet.

---

## 3. Parallax Berge

**Trigger:** Scroll-Event (mit `requestAnimationFrame`-throttling).

**Logik:** Zwei `<svg>` mit `data-parallax="0.15"` bzw. `0.3`. Pro Scroll-Tick wird `transform: translate3d(0, scrollY * speed, 0)` gesetzt.

**Reduced Motion:** Komplett deaktiviert (Listener wird nicht registriert).

```js
const layers = document.querySelectorAll('[data-parallax]');
let ticking = false;
const update = () => {
  layers.forEach((el) => {
    const speed = parseFloat(el.dataset.parallax ?? '0.15');
    el.style.transform = `translate3d(0, ${scrollY * speed}px, 0)`;
  });
  ticking = false;
};
window.addEventListener('scroll', () => {
  if (!ticking) { requestAnimationFrame(update); ticking = true; }
}, { passive: true });
```

---

## 4. Reveal-on-Scroll (Sektionen, Cards, Headlines)

**Ort:** `src/layouts/BaseLayout.astro` (globaler Observer), Klasse `.grh-reveal` zur Markierung.

**Animation:** `opacity 0 → 1` und `translateY(24px) → 0` über 700 ms ease-out, ausgelöst beim Eintritt in den Viewport (Threshold 0.12, Root-Margin `-10%` unten).

**Stagger-Effekt:** Wenn mehrere Cards in einem Container sind, schichtet sich der Eindruck automatisch über die unterschiedlichen IntersectionObserver-Trigger-Zeiten. Falls expliziter Stagger gewünscht ist, lässt sich das durch `transition-delay: calc(var(--i) * 80ms)` an einzelnen Elementen lösen.

---

## 5. Schwebende Engel & Sterne (Advent-Modus)

**Trigger:** CSS-only — keine JavaScript-Logik.

**Klassen:** `.grh-float-slow` (12 s), `.grh-float-mid` (9 s), `.grh-float-fast` (7 s) für vertikale Sinusbewegung. `.grh-twinkle` (3.5 s) für Sterne-Pulsation.

---

## 6. Mikro-Interaktionen

| Element                      | Verhalten                                                |
|------------------------------|----------------------------------------------------------|
| Buttons (alle Varianten)     | Hover: `translateY(-1px) scale(1.02)`, Shadow-Aufhellung |
| Cards (News, Event, Ortsteil)| Hover: `translateY(-3px..-6px)`, Shadow-Verstärkung       |
| Bilder in Cards              | Hover: `scale(1.04)`, 600 ms                              |
| Compact-News                 | Hover: `translateX(4px)`                                  |
| Tourism-Tile-Pfeile          | Hover: `translateX(4px)`                                  |
| Nav-Links                    | Hover: Slide-in-Underline von links via `right` Property  |

Alle Mikro-Interaktionen mit `transition` Token (`var(--grh-dur-fast)`, `var(--grh-ease-out)`).

---

## Optionaler GSAP-Pfad (falls später gewünscht)

Falls die Schwibbogen-Animation `scrub`-fähig (an die Scroll-Position gekoppelt) sein soll oder der Schachbrett-Trenner Feld-für-Feld animiert eingefärbt werden soll, GSAP `Core` + `ScrollTrigger` lazy laden:

```js
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
if (!reduce) {
  const { gsap }       = await import('gsap');
  const { ScrollTrigger } = await import('gsap/ScrollTrigger');
  gsap.registerPlugin(ScrollTrigger);

  gsap.to('.grh-light', {
    opacity: 1,
    duration: 0.3,
    stagger: 0.15,
    scrollTrigger: { trigger: '.grh-hero', start: 'top top', end: '+=400', scrub: 1 },
  });
}
```

Aber wirklich erst bei konkretem Bedarf.
