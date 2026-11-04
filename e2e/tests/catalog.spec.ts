import { expect, test, type Page } from '@playwright/test';
import { axeViolations, login, useScheme } from './support';

// Catalog acceptance (products and categories). Every test that writes owns an `E2E-CAT-…`
// identifier or a category it creates itself; seeded rows are read or restored.
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGUlEQVQokWPkK5nAQApgIkn1qIZRDUNKAwB4twEycPdKNAAAAABJRU5ErkJggg==',
  'base64',
);
const image = (name: string) => ({ name, mimeType: 'image/png', buffer: png });

test.beforeEach(async ({ page }) => {
  await login(page);
});

// Page checks first: they capture the seeded data, before the tests below add records.
for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 390]) {
    for (const [name, path, heading] of [
      ['products', '/admin/products', '商品'],
      ['product-create', '/admin/products/create', '新增商品'],
      ['product-edit', '/admin/products/64/edit', '編輯商品'],
      ['categories', '/admin/product-categories', '商品分類'],
      ['category-edit', '/admin/product-categories/1/edit', '編輯商品分類'],
    ] as const) {
      test(`catalog ${name} ${scheme} ${width}: axe, geometry, screenshot`, async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (m) => {
          if (m.type() === 'error' || m.type() === 'warning') errors.push(m.text());
        });
        await useScheme(page, scheme);
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.getByRole('heading', { name: heading, exact: true }).first()).toBeVisible();
        await page.evaluate(() => document.fonts.ready);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        expect(await axeViolations(page)).toEqual([]);
        expect(errors).toEqual([]);
        await expect(page).toHaveScreenshot(`catalog-${name}-${scheme}-${width}.png`, {
          animations: 'disabled',
        });
      });
    }
  }
}

async function continueEditing(page: Page) {
  await page.getByRole('combobox', { name: '儲存後', exact: true }).click();
  await page.getByRole('option', { name: '繼續編輯', exact: true }).click();
}
function saveResponse(page: Page, method: 'PUT' | 'POST', pattern: RegExp) {
  return page.waitForResponse(
    (response) =>
      pattern.test(new URL(response.url()).pathname) && response.request().method() === method,
  );
}
const field = (page: Page, name: string) => page.getByRole('textbox', { name, exact: true });
const rows = (page: Page) => page.locator('tbody tr');

async function createProduct(page: Page, identifier: string, name: string) {
  await page.goto('/admin/products/create');
  await field(page, '貨號').fill(identifier);
  await field(page, '商品名稱').fill(name);
  await continueEditing(page);
  const response = saveResponse(page, 'POST', /^\/admin\/products$/);
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBeLessThan(300);
  await expect(page).toHaveURL(/\/admin\/products\/\d+\/edit/);
  return Number(new URL(page.url()).pathname.split('/')[3]);
}

test('product list: search, filter, bundle labels, identifier link, toggle, batch, export and hidden column', async ({
  page,
}) => {
  await createProduct(page, 'E2E-CAT-GRID', 'E2E 列表商品');
  await page.goto('/admin/products');

  // Search narrows to the one row and clears again.
  const search = page.getByRole('searchbox');
  await search.fill('E2E-CAT-GRID');
  await search.press('Enter');
  await expect(page).toHaveURL(/[?&]q=E2E-CAT-GRID/);
  await expect(rows(page)).toHaveCount(1);
  const row = rows(page).first();
  await expect(row).toContainText('單品');

  // The identifier column links to the edit page.
  const link = row.getByRole('link', { name: 'E2E-CAT-GRID', exact: true });
  await expect(link).toHaveAttribute('href', /\/admin\/products\/\d+\/edit$/);

  // Toggle publish inline.
  const published = row.getByRole('switch', { name: /上架/ });
  await expect(published).not.toBeChecked();
  const patch = page.waitForResponse((r) => r.request().method() === 'PATCH');
  await published.click();
  expect((await patch).status()).toBe(200);
  await expect(published).toBeChecked();

  // Batch unpublish through the bar.
  await row.getByRole('checkbox', { name: /^選取/ }).check();
  const bar = page.getByRole('region', { name: '批次操作' });
  await expect(bar).toContainText('已選取 1 筆');
  await bar.getByRole('button', { name: '下架', exact: true }).click();
  await page
    .getByRole('alertdialog', { name: '將 1 件商品下架？' })
    .getByRole('button', { name: '下架', exact: true })
    .click();
  await expect(rows(page).first().getByRole('switch', { name: /上架/ })).not.toBeChecked();

  // Bundle label: the seeded 組合 product, everything else 單品.
  await search.fill('DEMO-064');
  await search.press('Enter');
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first()).toContainText('組合');
  await expect(rows(page).first()).not.toContainText('單品');

  // Filter panel: 商品類型 組合商品 leaves only bundles.
  await page.goto('/admin/products');
  await page.getByRole('button', { name: '篩選', exact: true }).click();
  await page.getByRole('combobox', { name: '商品類型' }).click();
  await page.getByRole('option', { name: '組合商品' }).click();
  await page.getByRole('button', { name: '套用篩選' }).click();
  await expect(page).toHaveURL(/filter/);
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first()).toContainText('DEMO-064');

  // Export downloads the workbook.
  await page.goto('/admin/products');
  await page.getByRole('button', { name: '匯出 Excel' }).click();
  const download = page.waitForEvent('download');
  await page.getByRole('menuitem', { name: '符合條件的全部資料' }).click();
  expect((await download).suggestedFilename()).toMatch(/products.*\.xlsx$/);

  // Hide a column; it stays hidden after a reload.
  await expect(page.getByRole('columnheader', { name: '庫存' })).toBeVisible();
  await page.getByRole('button', { name: '顯示欄位' }).click();
  await page.getByRole('checkbox', { name: '庫存' }).uncheck();
  await expect(page.getByRole('columnheader', { name: '庫存' })).toHaveCount(0);
  await page.reload();
  await expect(rows(page).first()).toBeVisible();
  await expect(page.getByRole('columnheader', { name: '庫存' })).toHaveCount(0);
});

test('create a product with reordered images, preorder fields and bundle components, then reload', async ({
  page,
}) => {
  await page.goto('/admin/products/create');
  await field(page, '貨號').fill('E2E-CAT-NEW');
  await field(page, '商品名稱').fill('E2E 新品');
  await field(page, '價格（NT$）').fill('1200');
  await field(page, '庫存').fill('9');

  await page
    .getByRole('group', { name: '相簿', exact: true })
    .locator('input[type=file]')
    .setInputFiles([image('one.png'), image('two.png')]);
  await expect(page.getByText('上傳中…', { exact: true })).toHaveCount(0);
  await expect(page.locator('li[data-upload-key]')).toHaveCount(2);
  await page.getByRole('button', { name: '下移 one.png', exact: true }).click();
  await expect(page.locator('li[data-upload-key]').first()).toContainText('two.png');
  await page
    .locator('li[data-upload-key]')
    .first()
    .getByRole('textbox', { name: '連結', exact: true })
    .fill('https://example.test/two');

  // Preorder fields appear only for a preorder.
  await expect(field(page, '預購說明')).toHaveCount(0);
  await page.getByRole('switch', { name: '預購商品', exact: true }).click();
  await page.getByLabel('預計出貨時間', { exact: true }).fill('2026-12-15T10:00');
  await field(page, '預購說明').fill('12 月中旬出貨');

  // Components with quantities.
  await expect(page.getByRole('button', { name: /^組合內容 ·/ })).toHaveCount(0);
  await page.getByRole('switch', { name: '組合商品', exact: true }).click();
  await page.getByRole('button', { name: /^組合內容 ·/ }).click();
  const panel = page.getByRole('region', { name: '組合內容', exact: true });
  await panel.getByRole('checkbox', { name: /示範商品 002/ }).check();
  await panel.getByRole('checkbox', { name: /示範商品 001/ }).check();
  const quantities = panel.getByRole('textbox', { name: '數量', exact: true });
  await expect(quantities).toHaveCount(2);
  await quantities.nth(0).fill('3');
  await quantities.nth(1).fill('2');
  await panel.getByRole('button', { name: '套用', exact: true }).click();

  await continueEditing(page);
  const response = saveResponse(page, 'POST', /^\/admin\/products$/);
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBe(200);
  await expect(page).toHaveURL(/\/admin\/products\/\d+\/edit/);
  await page.reload();

  await expect(field(page, '貨號')).toHaveValue('E2E-CAT-NEW');
  // The reordered first image carries the link typed on it.
  await expect(page.locator('li[data-upload-key]')).toHaveCount(2);
  await expect(
    page.locator('li[data-upload-key]').first().getByRole('textbox', { name: '連結', exact: true }),
  ).toHaveValue('https://example.test/two');
  await expect(
    page.locator('li[data-upload-key]').nth(1).getByRole('textbox', { name: '連結', exact: true }),
  ).toHaveValue('');
  await expect(page.getByRole('switch', { name: '預購商品', exact: true })).toBeChecked();
  await expect(field(page, '預購說明')).toHaveValue('12 月中旬出貨');
  await expect(page.getByLabel('預計出貨時間', { exact: true })).toHaveValue('2026-12-15T10:00');
  const summary = page.getByRole('list', { name: '組合內容', exact: true });
  await expect(summary.getByRole('listitem')).toHaveCount(2);
  await expect(summary.getByRole('listitem').first()).toContainText('示範商品 002');
  await expect(summary.getByRole('listitem').first()).toContainText('3');
  await expect(summary.getByRole('listitem').nth(1)).toContainText('示範商品 001');
});

test('ERP product: locked fields are readonly, total limit validates against sold and shows the new remaining', async ({
  page,
}) => {
  // DEMO-003: ERP row with sold_count 3 and total_limit 100.
  await page.goto('/admin/products?q=DEMO-003');
  await rows(page).first().getByRole('link', { name: 'DEMO-003', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/products\/\d+\/edit/);
  // Locked fields are read-only text, not inputs.
  for (const name of ['貨號', '商品名稱', '價格（NT$）', '庫存'])
    await expect(field(page, name)).toHaveCount(0);
  await expect(page.getByRole('region', { name: '基本資料' })).toContainText('DEMO-003');
  await expect(field(page, '條碼')).toBeEditable();

  await continueEditing(page);
  const save = () => page.getByRole('button', { name: '儲存', exact: true }).click();
  const put = () => saveResponse(page, 'PUT', /^\/admin\/products\/\d+$/);

  // An editable field saves.
  await field(page, '條碼').fill('E2E-BARCODE-3');
  let response = put();
  await save();
  expect((await response).status()).toBe(200);
  await expect(page.getByText('已儲存變更', { exact: true })).toBeVisible();
  await page.reload();
  await expect(field(page, '條碼')).toHaveValue('E2E-BARCODE-3');

  // Total below sold shows the field error.
  const total = page.getByRole('spinbutton', { name: '總限購', exact: true }).or(field(page, '總限購'));
  await total.fill('2');
  response = put();
  await save();
  expect((await response).status()).toBe(422);
  await expect(total).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByText(/總限購.*已售出|不可小於/).first()).toBeVisible();

  // A valid total gives remaining = total - sold after reload.
  await total.fill('50');
  response = put();
  await save();
  expect((await response).status()).toBe(200);
  await page.reload();
  await expect(total).toHaveValue('50');
  await expect(page.getByText('剩餘可售', { exact: true })).toBeVisible();
  await expect(page.locator('[data-field-layout]').filter({ hasText: '剩餘可售' })).toContainText('47');
  // Restore.
  await total.fill('100');
  response = put();
  await save();
  expect((await response).status()).toBe(200);
});

function panelOf(page: Page) {
  return page.getByRole('region', { name: '商品', exact: true });
}

test('category: pick across two pages, cancel, reapply, move out of old category and deselect', async ({
  page,
}) => {
  // Own category.
  await page.goto('/admin/product-categories/create');
  await field(page, '分類名稱').fill('E2E 分類');
  await continueEditing(page);
  const created = saveResponse(page, 'POST', /^\/admin\/product-categories$/);
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await created).status()).toBeLessThan(300);
  await expect(page).toHaveURL(/\/admin\/product-categories\/\d+\/edit/);

  const trigger = page.getByRole('button', { name: /^商品 ·/ });
  await trigger.click();
  const panel = panelOf(page);
  await panel.getByRole('checkbox', { name: /示範商品 001/ }).check();
  await panel.getByRole('button', { name: '下一頁', exact: true }).click();
  await panel.getByRole('checkbox', { name: /示範商品 021/ }).check();
  await panel.getByRole('button', { name: '取消', exact: true }).click();
  await expect(trigger).toContainText('0');

  await trigger.click();
  // The panel reopens on the page it was left on.
  await panelOf(page).getByRole('button', { name: '上一頁', exact: true }).click();
  await panelOf(page).getByRole('checkbox', { name: /示範商品 001/ }).check();
  await panelOf(page).getByRole('button', { name: '下一頁', exact: true }).click();
  await panelOf(page).getByRole('checkbox', { name: /示範商品 021/ }).check();
  await panelOf(page).getByRole('button', { name: '套用', exact: true }).click();
  await expect(trigger).toContainText('2');
  let response = saveResponse(page, 'PUT', /^\/admin\/product-categories\/\d+$/);
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBe(200);
  await page.reload();
  await expect(trigger).toContainText('2');

  // Both products now sit in the new category, and DEMO-001 left 家居 (category 2).
  const categoryId = new URL(page.url()).pathname.split('/')[3];
  const inCategory = async (id: string) => {
    await page.goto(`/admin/products?filter[category_id]=${id}&per_page=50`);
    await expect(page.getByRole('searchbox')).toBeVisible();
    return rows(page);
  };
  await expect(await inCategory(categoryId)).toHaveCount(2);
  await expect((await inCategory('2')).filter({ hasText: 'DEMO-001' })).toHaveCount(0);

  // Deselect DEMO-021: it becomes uncategorised and is not deleted.
  await page.goto(`/admin/product-categories/${categoryId}/edit`);
  await trigger.click();
  await panelOf(page).getByRole('button', { name: '移除「示範商品 021」', exact: true }).click();
  await panelOf(page).getByRole('button', { name: '套用', exact: true }).click();
  response = saveResponse(page, 'PUT', /^\/admin\/product-categories\/\d+$/);
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBe(200);
  await expect(await inCategory(categoryId)).toHaveCount(1);
  await page.goto('/admin/products?q=DEMO-021');
  await expect(rows(page)).toHaveCount(1);
});

