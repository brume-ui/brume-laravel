import { defineConfig, devices } from '@playwright/test';

const PORT = 8100;

export default defineConfig({
  testDir: './tests',
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // One seeded database and the single-process PHP server: keep concurrency low.
  workers: 2,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    locale: 'zh-TW',
    timezoneId: 'Asia/Taipei',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    // Publishes dist/, migrates and seeds a fresh database, then serves the workbench.
    command: './serve.sh',
    url: `http://127.0.0.1:${PORT}/admin/login`,
    // Never reuse: a server left running from `composer serve` has a different database and
    // php.ini, and the suite depends on the fresh seed and on display_errors being on.
    reuseExistingServer: false,
    timeout: 120_000,
    stdout: 'ignore',
    stderr: 'pipe',
  },
});
