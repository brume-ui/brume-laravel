import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// Brume's own Vite build, separate from the project's vite.config so the two never clash.
// Brume\Support\Assets reads public/brume.hot (dev) and public/build-brume (build).
export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/brume/app.tsx'],
      hotFile: 'public/brume.hot',
      buildDirectory: 'build-brume',
      refresh: true,
    }),
    react(),
  ],
});
