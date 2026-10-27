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
