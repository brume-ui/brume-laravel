import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { login, useScheme } from './support';

async function axeViolations(page: Page): Promise<unknown[]> {
  // Inertia's progress bar (NProgress, `.bar[role="bar"]`) stays in the DOM until the visit
  // finishes. Audit the settled page, not the transient indicator from @inertiajs/react.
  await expect(page.locator('#nprogress')).toHaveCount(0);
  const { violations } = await new AxeBuilder({ page }).analyze();
  return violations.map((v) => ({
    url: new URL(page.url()).pathname,
    id: v.id,
    help: v.help,
    nodes: v.nodes.map((n) => `${n.target.join(' ')} — ${n.failureSummary}`),
  }));
}

test('no light flash on reload in dark mode', async ({ page }) => {
  await login(page);
  await page.getByRole('button', { name: '色彩模式' }).click();
  await page.getByRole('menuitemradio', { name: '深色' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

  // Captured at DOMContentLoaded of the reload, before React mounts the shell.
  await page.addInitScript(() => {
    document.addEventListener('DOMContentLoaded', () => {
      const root = getComputedStyle(document.documentElement);
      (window as unknown as { __dcl: unknown }).__dcl = {
        theme: document.documentElement.dataset.theme ?? null,
        canvas: root.getPropertyValue('--br-surface-canvas').trim(),
        body: getComputedStyle(document.body).backgroundColor,
      };
    });
  });
  await page.reload();

  const dcl = await page.evaluate(() => (window as unknown as { __dcl: Record<string, unknown> }).__dcl);
  expect(dcl.theme).toBe('dark');
  // brume-tokens dark surface-canvas (#0f151c in 0.1.0-alpha.0).
  expect(dcl.canvas).toBe('#0f151c');
  expect(dcl.body).toBe('rgb(15, 21, 28)');
});

test('switching the language to English translates the menu', async ({ page }) => {
  await login(page);
  await page.getByRole('button', { name: '語言' }).click();
  await page.getByRole('menuitemradio', { name: 'English' }).click();

  const nav = page.getByRole('navigation', { name: 'Main navigation' });
  await expect(nav.getByRole('link', { name: 'Dashboard' })).toBeVisible();
  await expect(nav.getByRole('button', { name: 'System' })).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});

test('the collapsed sidebar stays collapsed after a reload', async ({ page }) => {
  await login(page);
  await page.getByRole('button', { name: '收合側欄' }).click();
  await expect(page.locator('aside[data-collapsed]')).toBeVisible();

  await page.reload();
  await expect(page.locator('aside[data-collapsed]')).toBeVisible();
  await expect(page.getByRole('button', { name: '展開側欄' })).toBeVisible();
});

for (const scheme of ['light', 'dark'] as const) {
  test(`axe finds no violations in ${scheme} mode`, async ({ page }) => {
    await useScheme(page, scheme);
    await login(page);
    await expect(page.locator('html')).toHaveAttribute('data-theme', scheme);
    const violations = await axeViolations(page);

    await page.goto('/admin/users');
    await expect(page.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();
    violations.push(...(await axeViolations(page)));

    expect(violations).toEqual([]);
  });
}
