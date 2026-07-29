import { defineConfig } from 'astro/config';

// Live-Ziel ist die Apex-Domain. Bis zum Day-X wird auf der Staging-Subdomain
// 2026.vv-wildenstein.com gebaut/getestet (PUBLIC_STAGING=true → noindex).
export default defineConfig({
  site: 'https://vv-wildenstein.com',
  build: {
    inlineStylesheets: 'auto',
  },
  vite: {
    css: {
      devSourcemap: true,
    },
  },
});
