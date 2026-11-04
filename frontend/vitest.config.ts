import { defineConfig } from 'vitest/config';

// Pure workbench modules only (node environment, no DOM); components are checked in a real browser.
export default defineConfig({
  test: {
    environment: 'node',
    root: import.meta.dirname,
    include: ['../workbench/resources/js/**/*.test.ts'],
  },
});
