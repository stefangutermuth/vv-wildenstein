import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://boernichen.de',
  build: {
    inlineStylesheets: 'auto',
  },
  vite: {
    css: {
      devSourcemap: true,
    },
  },
});
