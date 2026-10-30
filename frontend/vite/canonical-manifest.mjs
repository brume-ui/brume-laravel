import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const pnpmPrefix = 'frontend/node_modules/.pnpm/';

function logicalSourcePath(source) {
  if (!source.startsWith(pnpmPrefix)) return source;

  const nested = source.slice(pnpmPrefix.length);
  const separator = '/node_modules/';
  const index = nested.indexOf(separator);
  if (index < 1 || index + separator.length === nested.length) {
    throw new Error(`Invalid pnpm manifest source path: ${source}`);
  }

  return `frontend/node_modules/${nested.slice(index + separator.length)}`;
}

/** Keep Vite's source keys independent of npm's and pnpm's physical package layout. */
export function canonicalManifest(manifest) {
  const entries = Object.entries(manifest).map(([source, value]) => {
    const key = logicalSourcePath(source);
    const item = { ...value };
    if (item.src) item.src = logicalSourcePath(item.src);
    for (const field of ['imports', 'dynamicImports']) {
      if (item[field]) item[field] = item[field].map(logicalSourcePath);
    }
    return [key, item];
  });

  entries.sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0);
  const result = Object.fromEntries(entries);
  if (Object.keys(result).length !== entries.length) {
    throw new Error('Manifest source paths collide after pnpm normalization');
  }
  return result;
}

export function canonicalManifestPlugin() {
  return {
    name: 'brume:canonical-manifest',
    apply: 'build',
    writeBundle(options) {
      if (!options.dir) throw new Error('Vite build output directory is missing');
      const path = join(options.dir, '.vite/manifest.json');
      const manifest = JSON.parse(readFileSync(path, 'utf8'));
      writeFileSync(path, JSON.stringify(canonicalManifest(manifest), null, 2));
    },
  };
}
