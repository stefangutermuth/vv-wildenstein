import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://melder2026.vv-wildenstein.com',
  devToolbar: { enabled: false },
  build: {
    inlineStylesheets: 'auto',
  },
  vite: {
    css: {
      devSourcemap: true,
    },
  },
});
