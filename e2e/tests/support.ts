import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

export const SESSION_COOKIE = 'brume_workbench_session';

export const ADMIN = { username: 'admin', password: 'admin1234' };

export async function login(page: Page, options: { remember?: boolean } = {}): Promise<void> {
  await page.goto('/admin/login');
  await page.getByRole('textbox', { name: '帳號' }).fill(ADMIN.username);
  await page.getByRole('textbox', { name: '密碼', exact: true }).fill(ADMIN.password);
  if (options.remember) await page.getByLabel('記住我').check();
  await page.getByRole('button', { name: '登入' }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.locator('[data-shell-mount]')).toBeVisible();
}

/** Pins the color scheme the way the navbar toggle stores it, before any page script runs. */
export async function useScheme(page: Page, scheme: 'light' | 'dark'): Promise<void> {
  await page.addInitScript((value) => {
    localStorage.setItem('brume.color-scheme', value);
  }, scheme);
}

/** axe on the settled page, as { url, id, help, nodes } per violation. */
export async function axeViolations(page: Page): Promise<unknown[]> {
  // Inertia's progress bar (NProgress, `.bar[role="bar"]`) stays in the DOM until the visit
  // finishes. Audit the settled page, not the transient indicator from @inertiajs/react.
  await expect(page.locator('#nprogress')).toHaveCount(0);
  // Brume's enter animations (fades, row staggers) pass through low-contrast frames; audit
  // the settled colors once every finite animation has finished.
  await page.evaluate(() =>
    Promise.all(
      document
        .getAnimations()
        .filter((a) => a.effect?.getComputedTiming().endTime !== Number.POSITIVE_INFINITY)
        .map((a) => a.finished.catch(() => undefined)),
    ),
  );
  const { violations } = await new AxeBuilder({ page }).analyze();
  return violations.map((v) => ({
    url: new URL(page.url()).pathname,
    id: v.id,
    help: v.help,
    nodes: v.nodes.map((n) => `${n.target.join(' ')} — ${n.failureSummary}`),
  }));
}
