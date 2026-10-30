// Rebuilds the precompiled frontend into a temp directory and compares it with the committed
// dist/. Exits 1 when they differ, i.e. dist/ is stale or the build is not reproducible.
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const frontend = resolve(fileURLToPath(import.meta.url), '../..');
const committed = resolve(frontend, '../dist');
const fresh = mkdtempSync(join(tmpdir(), 'brume-dist-'));
const diagnosticDir = process.env.BRUME_DIST_DIAGNOSTIC_DIR;
const maxReportedProblems = 20;

function workflowMessage(message) {
  return message.replaceAll('%', '%25').replaceAll('\r', '%0D').replaceAll('\n', '%0A');
}

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
    const reported = problems.slice(0, maxReportedProblems);
    const remaining = problems.length - reported.length;
    console.error(`dist/ does not match a fresh build (${problems.length} differing files):\n  ${reported.join('\n  ')}`);
    if (remaining) console.error(`  ... and ${remaining} more differing files`);
    if (process.env.GITHUB_ACTIONS === 'true') {
      for (const problem of reported) {
        console.error(`::error title=frontend dist mismatch::${workflowMessage(problem)}`);
      }
      if (remaining) console.error(`::error title=frontend dist mismatch::${remaining} more differing files; download the fresh dist artifact`);
    }
    if (diagnosticDir) {
      mkdirSync(diagnosticDir, { recursive: true });
      cpSync(fresh, join(diagnosticDir, 'fresh-dist'), { recursive: true });
      console.error(`Fresh build saved to ${diagnosticDir}`);
    }
    console.error('Run `pnpm --dir frontend build` and commit dist/.');
    process.exitCode = 1;
  } else {
    console.log(`dist/ matches a fresh build (${expected.length} files).`);
  }
} finally {
  rmSync(fresh, { recursive: true, force: true });
}
