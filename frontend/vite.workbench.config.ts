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
 * Packages the workbench components share with the shell resolve to the shell's own copies:
 * lucide-react (a dependency of brume-react, not of frontend/package.json, which installed
 * projects receive), React (one copy, or hooks fail) and @inertiajs/react (one router). Installed
 * from npm these are the same copies frontend/ has; with brume-ui linked (see the README) the
 * shell's come from brume-ui's node_modules, and a second React would break every hook.
 */
function sharedWithShell(): Plugin {
  const from = (pkg: string) => resolve(realpathSync(resolve(frontend, 'node_modules', pkg)), 'package.json');
  const owners: Array<[RegExp, string]> = [
    [/^lucide-react$/, from('@brume-ui/brume-react')],
    [/^react(?:-dom)?(?:\/.*)?$/, from('@brume-ui/brume-react')],
    [/^@inertiajs\/react$/, from('@brume-ui/brume-inertia')],
  ];
  return {
    name: 'brume:workbench-shared-with-shell',
    enforce: 'pre',
    resolveId(source, importer, options) {
      if (!importer?.startsWith(sources)) return null;
      const owner = owners.find(([pattern]) => pattern.test(source))?.[1];
      return owner ? this.resolve(source, owner, { ...options, skipSelf: true }) : null;
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
  plugins: [sharedWithShell(), resolveImportsFromFrontend(sources), react(), tailwindcss()],
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
