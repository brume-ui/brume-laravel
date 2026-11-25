import { expect, test, type Page } from '@playwright/test';
import { login, useScheme } from './support';

// Colour, telephone and html block against the real backend. 商品 label colour: DEMO-008 / DEMO-009
// are written here (every test sets the value it checks, so reruns are idempotent), DEMO-002 keeps its
// seeded #1e88e5 for the screenshots. Telephone and the alpha colour use Grid 示範 items 20 and 21.
// Linux baselines are generated in CI.

test.beforeEach(async ({ page }) => {
  await login(page);
  page.on('dialog', (dialog) => dialog.accept());
});

async function openProduct(page: Page, id: number) {
  await page.goto(`/admin/products/${id}/edit`);
  await expect(page.getByRole('heading', { name: '編輯商品', exact: true })).toBeVisible();
}
async function openItem(page: Page, id: number) {
  await page.goto(`/admin/grid-showcase/${id}/edit`);
  await expect(page.getByRole('textbox', { name: '聯絡電話', exact: true })).toBeVisible();
}
async function save(page: Page, path: string) {
  const response = page.waitForResponse((r) => new URL(r.url()).pathname === path && r.request().method() === 'PUT');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  return response;
}

const colorBox = (page: Page) => page.getByRole('textbox', { name: '標籤顏色', exact: true });
const chip = (page: Page) => page.getByRole('button', { name: '標籤顏色：開啟色盤' });

test('a typed #ABC is canonicalised, saved and reloaded as #aabbcc', async ({ page }) => {
  await openProduct(page, 8);
  await colorBox(page).fill('#ABC');
  await page.keyboard.press('Tab');
  await expect(colorBox(page)).toHaveValue('#aabbcc');
  expect((await save(page, '/admin/products/8')).ok()).toBe(true);
  await openProduct(page, 8);
  await expect(colorBox(page)).toHaveValue('#aabbcc');
});

test('a swatch pick is saved and reloaded', async ({ page }) => {
  await openProduct(page, 9);
  await chip(page).click();
  const panel = page.getByRole('dialog', { name: '標籤顏色' });
  await expect(panel).toBeVisible();
  await panel.getByRole('button', { name: '#8e24aa' }).click();
  await expect(colorBox(page)).toHaveValue('#8e24aa');
  await page.keyboard.press('Escape');
  await expect(panel).toBeHidden();
  await expect(chip(page)).toBeFocused();
  expect((await save(page, '/admin/products/9')).ok()).toBe(true);
  await openProduct(page, 9);
  await expect(colorBox(page)).toHaveValue('#8e24aa');
});

test('an invalid colour is refused before anything is stored', async ({ page }) => {
  await openProduct(page, 9);
  await colorBox(page).fill('#12345');
  await page.keyboard.press('Tab');
  await expect(colorBox(page)).toHaveAttribute('aria-invalid', 'true');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: /顏色/ }).first()).toBeVisible();
  await openProduct(page, 9);
  await expect(colorBox(page)).toHaveValue('#8e24aa');
});

test('the html note shows its link, with only whitelisted markup', async ({ page }) => {
  await openProduct(page, 2);
  const block = page.locator('[data-html-layout="block"]');
  await expect(block).toBeVisible();
  const link = block.getByRole('link', { name: '商品分類' });
  await expect(link).toHaveAttribute('href', '/admin/product-categories');
  await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
  // The block is display only: the create page (no record) shows it too.
  await page.goto('/admin/products/create');
  await expect(page.locator('[data-html-layout="block"]').getByRole('link', { name: '商品分類' })).toBeVisible();
});

test('a telephone with an extension is saved; abc is refused', async ({ page }) => {
  await openItem(page, 20);
  const tel = page.getByRole('textbox', { name: '聯絡電話', exact: true });
  await tel.fill('02-2345-6789#123');
  expect((await save(page, '/admin/grid-showcase/20')).ok()).toBe(true);
  await openItem(page, 20);
  await expect(tel).toHaveValue('02-2345-6789#123');

  await tel.fill('abc');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  await expect(tel).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByRole('alert').filter({ hasText: /電話|格式|regex/i }).first()).toBeVisible();
  await openItem(page, 20);
  await expect(tel).toHaveValue('02-2345-6789#123');
});

test('the server refuses a direct POST of a bad colour and tel with 422', async ({ page }) => {
  const cookies = await page.context().cookies();
  const token = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  const response = await page.request.put('/admin/grid-showcase/20', {
    headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    data: { values: { contact_tel: 'abc', meta: { color: 'teal', accent: 'red' } } },
  });
  expect(response.status()).toBe(422);
  const { errors } = await response.json();
  expect(Object.keys(errors)).toEqual(expect.arrayContaining(['contact_tel', 'meta.accent']));
});

test('an alpha colour inside the JSON meta block is saved', async ({ page }) => {
  await openItem(page, 21);
  const accent = page.getByRole('textbox', { name: '強調色', exact: true });
  await accent.fill('#ABCD');
  await page.keyboard.press('Tab');
  await expect(accent).toHaveValue('#aabbccdd');
  expect((await save(page, '/admin/grid-showcase/21')).ok()).toBe(true);
  await openItem(page, 21);
  await expect(accent).toHaveValue('#aabbccdd');
});

for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 390]) {
    test(`label colour field ${scheme} ${width}: screenshot`, async ({ page }) => {
      await useScheme(page, scheme);
      await page.setViewportSize({ width, height: 900 });
      await openProduct(page, 2);
      await expect(colorBox(page)).toHaveValue('#1e88e5');
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      await page.evaluate(() => document.fonts.ready);
      const field = colorBox(page).locator('xpath=ancestor::*[@data-field-layout][1]');
      await field.scrollIntoViewIfNeeded();
      await expect(field).toHaveScreenshot(`fields-3-label-color-${scheme}-${width}.png`, { animations: 'disabled' });
    });
  }
}
