import { resolve } from 'node:path';
import type { Plugin } from 'vite';

const frontend = resolve(import.meta.dirname, '..');

/**
 * Source files outside frontend/ (stubs/frontend, the workbench's resources/js) would never reach
 * frontend/node_modules through Node resolution. Resolve their bare imports as if they were
 * imported from frontend/.
 * Tailwind resolves CSS @import itself (relative to the CSS file), so bare CSS imports in those
 * files are rewritten to absolute paths before Tailwind sees them; must run before tailwindcss().
 */
export function resolveImportsFromFrontend(dir: string): Plugin {
  const fromFrontend = resolve(frontend, 'vite.config.ts');
  const isBare = (source: string) => !source.startsWith('.') && !source.startsWith('/');
  return {
    name: 'brume:resolve-imports-from-frontend',
    enforce: 'pre',
    async resolveId(source, importer, options) {
      if (!importer?.startsWith(dir) || !isBare(source)) {
        return null;
      }
      return this.resolve(source, fromFrontend, { ...options, skipSelf: true });
    },
    async transform(code, id) {
      if (!id.startsWith(dir) || !id.endsWith('.css')) {
        return null;
      }
      let out = code;
      for (const [, source] of code.matchAll(/@import\s+["']([^"']+)["']/g)) {
        if (!isBare(source)) continue;
        const resolved = await this.resolve(source, fromFrontend, { skipSelf: true });
        if (!resolved) this.error(`Cannot resolve ${source} from frontend/`);
        out = out.replace(`"${source}"`, JSON.stringify(resolved.id));
      }
      return out;
    },
  };
}
