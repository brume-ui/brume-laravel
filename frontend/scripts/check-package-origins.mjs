// The E2E and dist gates must exercise the published packages named by this lockfile,
// never a neighboring source checkout linked into node_modules.
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const frontend = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const dependencies = JSON.parse(readFileSync(resolve(frontend, 'package.json'), 'utf8')).dependencies;
const modules = realpathSync(resolve(frontend, 'node_modules')) + sep;
const lock = readFileSync(resolve(frontend, 'pnpm-lock.yaml'), 'utf8');

for (const name of ['tokens', 'schema', 'react', 'renderer', 'inertia']) {
  const packageName = `@brume-ui/brume-${name}`;
  const expectedVersion = dependencies[packageName];
  const installed = realpathSync(resolve(frontend, 'node_modules', packageName));
  const actualVersion = JSON.parse(readFileSync(resolve(installed, 'package.json'), 'utf8')).version;
  const locked = lock.includes(`  '${packageName}@${expectedVersion}':\n    resolution: {integrity: sha512-`);
  if (actualVersion !== expectedVersion || !installed.startsWith(modules) || !locked) {
    throw new Error(`${packageName} must be installed at ${expectedVersion} inside frontend/node_modules with registry integrity in the lockfile; found ${actualVersion} at ${installed}`);
  }
  console.log(`${packageName}@${actualVersion}: ${installed}`);
}
