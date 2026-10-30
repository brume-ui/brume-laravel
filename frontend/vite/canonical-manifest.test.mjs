import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { canonicalManifest, canonicalManifestPlugin } from './canonical-manifest.mjs';

// Source paths taken from the npm-built alpha.12 manifest and the earlier pnpm-built manifest.
const logical = 'frontend/node_modules/@brume-ui/brume-react/dist/fonts/jf-openhuninn-00.woff2';
const physical = 'frontend/node_modules/.pnpm/@brume-ui+brume-react@0.1.0-alpha.11_react-dom@19.3.1_react@19.3.1__react@19.3.1/node_modules/@brume-ui/brume-react/dist/fonts/jf-openhuninn-00.woff2';
const entry = 'stubs/frontend/app.tsx';

test('npm and pnpm source paths produce the same complete manifest', () => {
  const asset = { file: 'assets/jf-openhuninn-00-example.woff2' };
  const app = { file: 'assets/app-example.js', isEntry: true, css: ['assets/app-example.css'], assets: [asset.file] };
  const npm = { [logical]: { ...asset, src: logical }, [entry]: { ...app, src: entry } };
  const pnpm = { [physical]: { ...asset, src: physical }, [entry]: { ...app, src: entry } };

  assert.deepEqual(canonicalManifest(pnpm), canonicalManifest(npm));
  assert.deepEqual(canonicalManifest(npm), npm);
});

test('normalization rejects collisions instead of losing manifest entries', () => {
  assert.throws(() => canonicalManifest({ [logical]: { file: 'a' }, [physical]: { file: 'b' } }), /collide/);
});

test('the Vite build hook writes a canonical manifest into its output directory', () => {
  const outDir = mkdtempSync(join(tmpdir(), 'brume-manifest-'));
  try {
    mkdirSync(join(outDir, '.vite'));
    const path = join(outDir, '.vite/manifest.json');
    writeFileSync(path, JSON.stringify({ [physical]: { file: 'assets/font.woff2', src: physical } }));

    canonicalManifestPlugin().writeBundle({ dir: outDir });

    assert.deepEqual(JSON.parse(readFileSync(path, 'utf8')), {
      [logical]: { file: 'assets/font.woff2', src: logical },
    });
  } finally {
    rmSync(outDir, { recursive: true, force: true });
  }
});
