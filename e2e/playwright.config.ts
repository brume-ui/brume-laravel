import { defineConfig, devices } from "@playwright/test";

// E2E_PORT lets the suite run next to a `composer serve` already listening on 8100.
const PORT = Number(process.env.E2E_PORT ?? 8100);
// The precompiled check's own server (same database, committed dist/ frontend).
const PRECOMPILED_PORT = Number(process.env.E2E_PRECOMPILED_PORT ?? PORT + 2);

export default defineConfig({
  testDir: "./tests",
  snapshotPathTemplate: process.env.BRUME_UI_INTEGRATION === '1'
    ? './source-snapshots/{testFilePath}/{arg}-{projectName}-{platform}{ext}' : undefined,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // One seeded database and the single-process PHP server: keep concurrency low.
  workers: 2,
  reporter: process.env.CI ? [["github"], ["list"]] : "list",
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    locale: "zh-TW",
    timezoneId: "Asia/Taipei",
    trace: "retain-on-failure",
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
      testIgnore: ["grid.spec.ts", "form.spec.ts", "composition.spec.ts", "polish.spec.ts", "catalog.spec.ts", "promotions.spec.ts", "ordering.spec.ts", "rich-text.spec.ts", "fields-3.spec.ts", "precompiled.spec.ts"],
    },
    // grid.spec.ts ships and deletes seeded orders, which the dashboard and 訂單 checks above
    // count on, so it runs once they are done.
    {
      name: "grid",
      use: { ...devices["Desktop Chrome"] },
      testMatch: "grid.spec.ts",
      dependencies: ["chromium"],
    },
    {
      name: "forms",
      use: { ...devices["Desktop Chrome"] },
      testMatch: "form.spec.ts",
      dependencies: ["grid"],
      retries: 0,
    },
    // Dedicated tables/seed identities; runs after the existing form scenarios.
    {
      name: "composition",
      use: { ...devices["Desktop Chrome"] },
      testMatch: "composition.spec.ts",
      dependencies: ["forms"],
      retries: 0,
      fullyParallel: false,
    },
    { name: 'polish', use: { ...devices['Desktop Chrome'] }, testMatch: 'polish.spec.ts', dependencies: ['composition'], retries: 0 },
    // Product and category acceptance, then promotions; each creates its own prefixed records.
    { name: 'catalog', use: { ...devices['Desktop Chrome'] }, testMatch: 'catalog.spec.ts', dependencies: ['polish'], retries: 0 },
    { name: 'promotions', use: { ...devices['Desktop Chrome'] }, testMatch: 'promotions.spec.ts', dependencies: ['catalog'], retries: 0 },
    // Drag-to-order pages; moves what it reorders back, and runs last so nothing else sees it in flux.
    { name: 'ordering', use: { ...devices['Desktop Chrome'] }, testMatch: 'ordering.spec.ts', dependencies: ['promotions'], retries: 0, fullyParallel: false },
    // Rich text editors on 商品 (DEMO-001/002/003/005/006); every test resets those columns to the seed first.
    { name: 'rich-text', use: { ...devices['Desktop Chrome'] }, testMatch: 'rich-text.spec.ts', dependencies: ['ordering'], retries: 0, fullyParallel: false },
    // Colour, telephone and html block: 商品 label colour (DEMO-008/009 are written, DEMO-002 is read) and Grid 示範 items 20/21.
    { name: 'fields-3', use: { ...devices['Desktop Chrome'] }, testMatch: 'fields-3.spec.ts', dependencies: ['rich-text'], retries: 0, fullyParallel: false },
    // The committed dist/ (precompiled mode) in a real browser: the lazy editor chunk and its CSS.
    { name: 'precompiled', use: { ...devices['Desktop Chrome'], baseURL: `http://127.0.0.1:${PRECOMPILED_PORT}` }, testMatch: 'precompiled.spec.ts', dependencies: ['fields-3'], retries: 0 },
  ],
  webServer: [
    {
      // Builds the workbench frontend, migrates and seeds a fresh database, then serves the workbench.
      command: "./serve.sh",
      url: `http://127.0.0.1:${PORT}/admin/login`,
      // Never reuse: a server left running from `composer serve` has a different database and
      // php.ini, and the suite depends on the fresh seed and on display_errors being on.
      reuseExistingServer: false,
      timeout: 120_000,
      stdout: "ignore",
      stderr: "pipe",
    },
    {
      // Same workbench and database, frontend from the committed dist/ (WORKBENCH_FRONTEND=precompiled).
      command: "./serve-precompiled.sh",
      url: `http://127.0.0.1:${PRECOMPILED_PORT}/admin/login`,
      env: { E2E_PRECOMPILED_PORT: String(PRECOMPILED_PORT) },
      reuseExistingServer: false,
      timeout: 120_000,
      stdout: "ignore",
      stderr: "pipe",
    },
  ],
});
