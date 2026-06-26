import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://melder.vv-wildenstein.com',
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
