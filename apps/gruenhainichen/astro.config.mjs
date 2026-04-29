import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://www.gruenhainichen.com',
  build: {
    inlineStylesheets: 'auto',
  },
  vite: {
    css: {
      devSourcemap: true,
    },
  },
});
