import { realpathSync } from 'node:fs';
import { resolve } from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig, type Plugin } from 'vite';
import { resolveImportsFromFrontend } from './vite/resolve-from-frontend.ts';

// Builds the workbench's own frontend (workbench/resources/js/brume), the way a project installed
// with `brume:install --frontend` builds resources/js/brume with vite.brume.config.ts. The output
// goes where Laravel's Vite helper looks in vite mode: public/build-brume of the testbench
// skeleton that `testbench serve` serves. dist/ (the precompiled frontend) is not touched.
const frontend = import.meta.dirname;
const packageRoot = resolve(frontend, '..');
const workbench = resolve(packageRoot, 'workbench');
const sources = resolve(workbench, 'resources/js');
const publicDir = resolve(packageRoot, 'vendor/orchestra/testbench-core/laravel/public');

/**
 * The workbench components use Lucide icons. lucide-react is a dependency of brume-react, not of
 * frontend/package.json (which installed projects receive), so resolve it from brume-react: the
 * same copy the shell already bundles for menu icons.
 */
function lucideFromBrumeReact(): Plugin {
  const brumeReact = realpathSync(resolve(frontend, 'node_modules/@brume-ui/brume-react'));
  return {
    name: 'brume:workbench-lucide',
    enforce: 'pre',
    resolveId(source, importer, options) {
      if (source !== 'lucide-react' || !importer?.startsWith(sources)) return null;
      return this.resolve(source, resolve(brumeReact, 'package.json'), { ...options, skipSelf: true });
    },
  };
}

export default defineConfig({
  // Root is the workbench, so the manifest key is `resources/js/brume/app.tsx`, the entry
  // Brume\Support\Assets asks Laravel's Vite helper for.
  root: workbench,
  base: '/build-brume/',
  publicDir: false,
  envDir: frontend,
  plugins: [lucideFromBrumeReact(), resolveImportsFromFrontend(sources), react(), tailwindcss()],
  build: {
    outDir: resolve(publicDir, 'build-brume'),
    emptyOutDir: true,
    manifest: 'manifest.json',
    chunkSizeWarningLimit: 1600,
    rolldownOptions: {
      input: 'resources/js/brume/app.tsx',
    },
  },
});
