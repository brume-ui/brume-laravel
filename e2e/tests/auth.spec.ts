import { expect, test } from '@playwright/test';
import { ADMIN, login, SESSION_COOKIE } from './support';

test('wrong password shows the error and focuses the username field', async ({ page }) => {
  await page.goto('/admin/login');
  await page.getByRole('textbox', { name: '帳號' }).fill(ADMIN.username);
  await page.getByRole('textbox', { name: '密碼', exact: true }).fill('not-the-password');
  await page.getByRole('button', { name: '登入' }).click();

  await expect(page.getByText('帳號或密碼不正確，請再試一次。')).toBeVisible();
  await expect(page.getByRole('textbox', { name: '帳號' })).toBeFocused();
  await expect(page).toHaveURL(/\/admin\/login$/);
});

test('correct credentials land on the dashboard', async ({ page }) => {
  await login(page);
  await expect(page.getByRole('heading', { name: '儀表板' })).toBeVisible();
});

test('remember me keeps the admin signed in without the session cookie', async ({ page, context }) => {
  await login(page, { remember: true });

  const cookies = await context.cookies();
  expect(cookies.some((c) => c.name.startsWith('remember_brume_'))).toBe(true);
  await context.clearCookies({ name: SESSION_COOKIE });

  await page.reload();
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.getByRole('heading', { name: '儀表板' })).toBeVisible();
});

test('without remember me, losing the session cookie signs the admin out', async ({ page, context }) => {
  await login(page);
  await context.clearCookies({ name: SESSION_COOKIE });

  await page.reload();
  await expect(page).toHaveURL(/\/admin\/login$/);
});

test('logout returns to the login page', async ({ page }) => {
  await login(page);
  await page.getByRole('button', { name: /使用者選單/ }).click();
  await page.getByRole('menuitem', { name: '登出' }).click();

  await expect(page).toHaveURL(/\/admin\/login$/);
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/admin\/login$/);
});
