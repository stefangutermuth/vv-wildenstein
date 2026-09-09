import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Live-Ziel ist die Apex-Domain. Bis zum Day-X wird auf der Staging-Subdomain
// 2026.vv-wildenstein.com gebaut/getestet (PUBLIC_STAGING=true → noindex).
// Auf der Staging-Domain wäre eine Sitemap kontraproduktiv (die Seiten tragen
// dort noindex) — sie entsteht deshalb nur für den echten Live-Build.
const istStaging =
  process.env.PUBLIC_STAGING === 'true' || process.env.PUBLIC_STAGING === '1';

export default defineConfig({
  site: 'https://vv-wildenstein.com',
  integrations: istStaging
    ? []
    : [
        sitemap({
          // Seiten ohne Suchwert bzw. reine Formularstrecken auslassen.
          filter: (page) =>
            !page.includes('/404') &&
            !page.includes('/veranstaltung-einreichen'),
          changefreq: 'weekly',
          lastmod: new Date(),
        }),
      ],
  build: {
    inlineStylesheets: 'auto',
  },
  vite: {
    css: {
      devSourcemap: true,
    },
  },
});
