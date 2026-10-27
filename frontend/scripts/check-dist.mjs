// Rebuilds the precompiled frontend into a temp directory and compares it with the committed
// dist/. Exits 1 when they differ, i.e. dist/ is stale or the build is not reproducible.
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const frontend = resolve(fileURLToPath(import.meta.url), '../..');
const committed = resolve(frontend, '../dist');
const fresh = mkdtempSync(join(tmpdir(), 'brume-dist-'));

function listFiles(dir) {
  if (!existsSync(dir)) return [];
  return readdirSync(dir, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => relative(dir, join(entry.parentPath, entry.name)))
    .sort();
}

try {
  await build({
    configFile: join(frontend, 'vite.config.ts'),
    logLevel: 'warn',
    build: { outDir: fresh, emptyOutDir: true },
  });

  const expected = listFiles(fresh);
  const actual = listFiles(committed);
  const problems = [
    ...expected.filter((f) => !actual.includes(f)).map((f) => `missing in dist/: ${f}`),
    ...actual.filter((f) => !expected.includes(f)).map((f) => `extra in dist/: ${f}`),
    ...expected
      .filter((f) => actual.includes(f))
      .filter((f) => !readFileSync(join(fresh, f)).equals(readFileSync(join(committed, f))))
      .map((f) => `content differs: ${f}`),
  ];

  if (problems.length > 0) {
    console.error(`dist/ does not match a fresh build:\n  ${problems.join('\n  ')}`);
    console.error('Run `pnpm --dir frontend build` and commit dist/.');
    process.exitCode = 1;
  } else {
    console.log(`dist/ matches a fresh build (${expected.length} files).`);
  }
} finally {
  rmSync(fresh, { recursive: true, force: true });
}
