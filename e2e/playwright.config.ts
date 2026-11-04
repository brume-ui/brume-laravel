import { defineConfig, devices } from "@playwright/test";

// E2E_PORT lets the suite run next to a `composer serve` already listening on 8100.
const PORT = Number(process.env.E2E_PORT ?? 8100);

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
      testIgnore: ["grid.spec.ts", "form.spec.ts", "composition.spec.ts", "polish.spec.ts", "catalog.spec.ts", "promotions.spec.ts"],
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
  ],
  webServer: {
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
});
