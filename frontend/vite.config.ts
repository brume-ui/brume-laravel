import { readFileSync, realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig, type Plugin } from 'vite';
import { resolveImportsFromFrontend } from './vite/resolve-from-frontend.ts';
import { canonicalManifestPlugin } from './vite/canonical-manifest.mjs';

// Builds the precompiled frontend shipped in dist/. It compiles the same stubs/frontend/app.tsx
// that `brume:install --frontend` copies into projects, with the empty component registry, so
// precompiled mode is exactly the --frontend path (spec §4.1.1).
const frontend = dirname(fileURLToPath(import.meta.url));
const packageRoot = resolve(frontend, '..');
const stubs = resolve(packageRoot, 'stubs/frontend');

/**
 * The fonts are SIL OFL 1.1, which requires the license to travel with the font files. Vite copies
 * the woff2 files but not the license texts next to them, so emit those into dist/ as well.
 */
function fontLicenses(): Plugin {
  const brumeReact = realpathSync(resolve(frontend, 'node_modules/@brume-ui/brume-react'));
  const nunito = dirname(
    createRequire(resolve(brumeReact, 'package.json')).resolve('@fontsource-variable/nunito/package.json'),
  );
  const licenses = {
    'licenses/jf-openhuninn.txt': resolve(brumeReact, 'dist/fonts/LICENSE-jf-openhuninn.txt'),
    'licenses/nunito.txt': resolve(nunito, 'LICENSE'),
  };
  return {
    name: 'brume:font-licenses',
    apply: 'build',
    generateBundle() {
      for (const [fileName, path] of Object.entries(licenses)) {
        this.emitFile({ type: 'asset', fileName, source: readFileSync(path) });
      }
    },
  };
}

export default defineConfig({
  // Root is the package root so the manifest key is `stubs/frontend/app.tsx` (read by Brume\Support\Assets).
  root: packageRoot,
  // Relative, so CSS font urls resolve next to the CSS wherever asset() puts it (ASSET_URL, CDN, subpath).
  base: './',
  publicDir: false,
  envDir: frontend,
  define: {
    'import.meta.env.VITE_BRUME_MODE': JSON.stringify('precompiled'),
  },
  // Same Tailwind setup as the --frontend stub (stubs/frontend/vite.brume.config.ts).
  plugins: [resolveImportsFromFrontend(stubs), react(), tailwindcss(), fontLicenses(), canonicalManifestPlugin()],
  build: {
    outDir: resolve(packageRoot, 'dist'),
    emptyOutDir: true,
    manifest: true,
    // One bundle on purpose: menu icons are resolved by name, so the whole lucide set is included.
    chunkSizeWarningLimit: 1600,
    rolldownOptions: {
      input: 'stubs/frontend/app.tsx',
    },
  },
});
