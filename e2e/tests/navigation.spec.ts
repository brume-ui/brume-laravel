import { expect, test } from '@playwright/test';
import { login } from './support';

test.beforeEach(async ({ page }) => {
  await login(page);
});

test('the shell stays mounted when navigating from the dashboard to 管理員', async ({ page }) => {
  const shell = page.locator('[data-shell-mount]');
  const mountId = await shell.getAttribute('data-shell-mount');
  expect(mountId).toBeTruthy();

  await page.getByRole('button', { name: '系統', exact: true }).click();
  await page.getByRole('link', { name: '管理員' }).click();

  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.getByRole('heading', { name: '管理員' })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();
  await expect(shell).toHaveAttribute('data-shell-mount', mountId as string);
});

test('pagination writes ?page=2, keeps the scroll position and Back returns to page 1', async ({ page }) => {
  // Short viewport so the 10-row page scrolls.
  await page.setViewportSize({ width: 1280, height: 480 });
  await page.goto('/admin/users');
  await expect(page.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();

  const pageTwo = page.getByRole('link', { name: '第 2 頁' });
  await pageTwo.scrollIntoViewIfNeeded();
  const scrollY = await page.evaluate(() => window.scrollY);
  expect(scrollY).toBeGreaterThan(0);

  await pageTwo.click();
  await expect(page).toHaveURL(/\/admin\/users\?page=2$/);
  await expect(page.getByRole('link', { name: '第 2 頁' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('cell', { name: 'admin', exact: true })).toHaveCount(0);
  expect(await page.evaluate(() => window.scrollY)).toBe(scrollY);

  await page.goBack();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();
});

// Guards output printed BEFORE Laravel's HandleExceptions takes over (autoloaded files, config
// files, compile-time deprecations): with display_errors=1 (e2e/php.ini) PHP writes those straight
// into the response. Request-time deprecations after boot are logged by Laravel, never printed, so
// they cannot break this. The PDO::MYSQL_ATTR_SSL_CA case (spec §11) is a host config file and is
// covered by brume:install's check, not by the workbench.
test('partial visit returns clean JSON', async ({ page }) => {
  await page.goto('/admin/users');
  const version = await page.evaluate(() => {
    const el = document.querySelector('[data-page]');
    return JSON.parse(el?.getAttribute('data-page') ?? '{}').version ?? '';
  });

  const response = await page.request.get('/admin/users?page=2', {
    headers: { 'X-Inertia': 'true', 'X-Inertia-Version': version, Accept: 'text/html, application/xhtml+xml' },
  });
  const body = await response.text();

  expect(body, 'PHP printed a message into the response').not.toMatch(/(Deprecated|Warning|Notice)(<\/b>)?:/);
  expect(body.startsWith('{'), `body does not start with JSON: ${body.slice(0, 200)}`).toBe(true);
  const json = JSON.parse(body);
  expect(response.status()).toBe(200);
  expect(response.headers()['x-inertia']).toBe('true');
  expect(json.component).toBe('brume/page');
  expect(json.props.envelope.page.pagination).toMatchObject({ page: 2, perPage: 10, total: 36, lastPage: 4 });
});
