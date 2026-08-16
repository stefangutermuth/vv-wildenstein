import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://www.gruenhainichen.com',
  integrations: [
    sitemap({
      // Seiten, die für die Suche keinen Wert haben oder Nutzerzustand brauchen.
      filter: (page) =>
        !page.includes('/gemeinde/amtsblatt/einreichen') &&
        !page.includes('/404'),
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
