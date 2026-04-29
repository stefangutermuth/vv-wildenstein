# Component-Mapping Astro → WPBakery / Impreza

Jede Astro-Komponente bekommt in der späteren WordPress-Multisite ein 1:1-Pendant.
Konvention: Astro-Component-Name → WPBakery-Shortcode mit `grh_`-Prefix in snake_case.
ACF-Feldgruppen-Slugs spiegeln die Astro-Props.

## Globale Komponenten

| Astro                        | WPBakery / Impreza                                  | ACF-Felder                                                                            |
|------------------------------|------------------------------------------------------|---------------------------------------------------------------------------------------|
| `<GrhHeader />`              | `[grh_header]` (registered theme element)            | `topbar_phone`, `topbar_office_hours_link`, `nav_items` (Repeater)                    |
| `<GrhFooter />`              | `[grh_footer]` (registered theme element)            | `address`, `phone`, `email`, `mayor_hours`, `office_hours`, `legal_links` (Repeater)  |
| `<GrhWappen />`              | Wiederverwendbarer Block / SVG-Asset `wappen.svg`    | nur `variant` (color/mono-light/mono-dark)                                            |
| `<GrhButton />`              | WPBakery-Custom-Element `grh_button`                 | `variant`, `size`, `label`, `href`, `aria_label`                                      |
| `<GrhBadge />`               | Inline-Shortcode `[grh_badge tone="forest"]…[/]`     | `tone`, `size`                                                                        |
| `<GrhIcon />`                | Inline-Shortcode `[grh_icon name="angel11"]`         | `name`, `size`                                                                        |

## Hero & Sektionen

| Astro                          | WPBakery-Element                                          | ACF-Felder                                                                                                |
|--------------------------------|------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------|
| `<GrhHero />`                  | `[grh_hero]` (Container-Element, wraps row)               | `season` (select), `title`, `subline`, `bg_image_id`, `featured_id` (relationship)                        |
| `<GrhSchwibbogenSvg />`        | wird im Hero-Element automatisch via `season` gerendert   | —                                                                                                          |
| `<GrhFeaturedSlot />`          | Reusable Block "Aktueller Schwerpunkt" (CPT `grh_featured`)| `eyebrow`, `title`, `subtitle`, `ctas` (Repeater: label, href, variant)                                   |
| `<GrhSection />`               | WPBakery-Row mit `grh-section`-Wrapper-Class              | `tone`, `pattern`, `padding`, `align`, `eyebrow`, `title`, `subtitle`                                     |
| `<GrhDualPortal />`            | `[grh_dual_portal]`                                       | `left_portal` / `right_portal` Group-Felder mit Quicklink-Repeatern                                       |
| `<GrhTraditionShowcase />`     | `[grh_tradition topic="wendt-kuehn"]`                     | `topic` (select), `eyebrow`, `title`, `body`, `facts` (Repeater), `ctas` (Repeater), `reverse` (boolean)  |
| `<GrhTourismGrid />`           | `[grh_tourism_grid]`                                      | `tiles` (Repeater: icon, label, sub, href)                                                                |

## Cards (CPT-Templates)

| Astro                  | WPBakery-Output                                | Quelle (CPT)        | ACF-Felder spiegeln Schema in `src/content/config.ts` |
|------------------------|------------------------------------------------|---------------------|--------------------------------------------------------|
| `<GrhNewsCard />`      | `single-grh_news.php` + Shortcode `[grh_news_card]`     | `grh_news`   | `title`, `date`, `category`, `ortsteil`, `image`, `excerpt`, `featured` |
| `<GrhEventCard />`     | The-Events-Calendar Template-Override `grh-event-card.php` oder `[grh_event_card]` | `grh_event` (oder TEC) | `title`, `start_date`, `end_date`, `location`, `ortsteil`, `featured`, `teaser`, `image`, `cta_url`, `cta_label` |
| `<GrhOrtsteilCard />`  | `[grh_ortsteil_card slug="borstendorf"]`       | `grh_ortsteil` (3 fixe Posts) | `name`, `slug`, `tagline`, `description`, `image`, `order` |

## Migrations-Strategie

1. **Network-Activated-Plugin "grh-design-tokens"** liefert `tokens.css` als globales CSS (`wp_enqueue_style` mit `media="all"`). Damit erben alle Subsites identische Variablen.
2. **WPBakery-Custom-Elements** werden in einem zweiten Plugin "grh-blocks" als PHP-Classes registriert (`vc_map(...)`). Jedes Element rendert das gleiche HTML wie die Astro-Komponente und nutzt dieselben BEM-Klassen.
3. **CSS-Klassen-Vertrag:** Alle Selektoren tragen das `grh-`-Prefix (z. B. `.grh-news-card__title`). Astro-Scoping fällt in WordPress weg, deshalb existieren bereits BEM-Klassen als doppeltes Sicherheitsnetz.
4. **JavaScript:** Der Reveal-Observer (BaseLayout), Sticky-Header und Schwibbogen-Trigger werden als zwei kleine Scripts ausgeliefert (`grh-ui.js`, `grh-hero.js`) und mit `wp_enqueue_script` eingebunden — keine Build-Tools nötig, alles Vanilla.
5. **Saison-Schaltung:** `src/lib/seasons.ts` wird in WordPress als ACF-Option-Page „Aktuelle Saison" abgebildet. Das Hero-Element liest die Option statt die JS-Funktion.
