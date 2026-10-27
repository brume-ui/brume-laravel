import { expect, test } from '@playwright/test';
import { login } from './support';

test.beforeEach(async ({ page }) => {
  await login(page);
});

test('the same flash message twice shows a toast both times', async ({ page }) => {
  const toaster = page.locator('[data-brume-toaster]');

  await page.goto('/admin/users');
  for (let i = 0; i < 2; i++) {
    await page.goto('/admin/_demo/toast');
    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(toaster.getByRole('status').filter({ hasText: '已儲存' })).toHaveCount(1);
  }

  // Two different flash ids were recorded, not one id replayed.
  const seen = await page.evaluate(() => JSON.parse(sessionStorage.getItem('brume.seen-flash') ?? '[]'));
  expect(new Set(seen).size).toBe(2);
});

test('an unregistered component on the dashboard explains how to fix it', async ({ page }) => {
  const alert = page.getByRole('alert').filter({ hasText: 'demo-chart' });
  await expect(alert).toBeVisible();
  await expect(alert).toContainText('--frontend');
});
