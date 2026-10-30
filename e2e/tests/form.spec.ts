import { expect, test, type Locator, type Page } from '@playwright/test';
import { axeViolations, login, useScheme } from './support';

// Runs in the forms project after all seeded-count suites. Every write uses real Resource endpoints.
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGUlEQVQokWPkK5nAQApgIkn1qIZRDUNKAwB4twEycPdKNAAAAABJRU5ErkJggg==',
  'base64',
);
const image = (name: string) => ({ name, mimeType: 'image/png', buffer: png });

async function after(page: Page, value: string) {
  await page.getByRole('combobox', { name: '儲存後', exact: true }).click();
  await page.getByRole('option', { name: value, exact: true }).click();
}
async function save(page: Page) {
  await page.getByRole('button', { name: '儲存', exact: true }).click();
}
async function fillProduct(page: Page, sku: string) {
  await page.getByRole('textbox', { name: '品名', exact: true }).fill('表單驗收新品');
  await page.getByRole('textbox', { name: '貨號', exact: true }).fill(sku);
  await page.getByRole('textbox', { name: '標語', exact: true }).fill('每天都用得到');
}
async function assertErrorInsideDialog(input: Locator) {
  await expect(input).toBeFocused();
  const metrics = await input.evaluate((element) => {
    const body = element.closest('[data-dialog-body]');
    const field = element.closest('[data-field-layout]');
    const error = (element.getAttribute('aria-describedby') ?? '')
      .split(' ')
      .map((id) => document.getElementById(id))
      .find((node) => node?.getAttribute('role') === 'alert' || node?.id.endsWith('-error'));
    if (!body || !field || !error) throw new Error('Missing dialog error geometry');
    const rect = (node: Element) => {
      const r = node.getBoundingClientRect();
      return { top: r.top, bottom: r.bottom };
    };
    return {
      body: rect(body),
      field: rect(field),
      input: rect(element),
      error: rect(error),
    };
  });
  for (const region of [metrics.field, metrics.input, metrics.error]) {
    expect(region.top).toBeGreaterThanOrEqual(metrics.body.top);
    expect(region.bottom).toBeLessThanOrEqual(metrics.body.bottom);
  }
}
async function geometry(page: Page) {
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    ),
  ).toBeLessThanOrEqual(0);
  for (const name of ['取消', '儲存']) {
    const button = page.getByRole('button', { name, exact: true });
    const bounds = await button.evaluate((el) => {
      const text = el.querySelector('span') ?? el;
      const a = el.getBoundingClientRect(),
        b = text.getBoundingClientRect();
      return {
        left: b.left - a.left,
        right: a.right - b.right,
        textHeight: b.height,
      };
    });
    expect(bounds.left).toBeGreaterThanOrEqual(0);
    expect(bounds.right).toBeGreaterThanOrEqual(0);
    expect(bounds.textHeight).toBeLessThanOrEqual(24);
  }
}
async function gallery(page: Page) {
  await page
    .getByRole('group', { name: '相簿', exact: true })
    .locator('input[type=file]')
    .setInputFiles([image('first.png'), image('second.png')]);
  await expect(page.getByText('上傳中…', { exact: true })).toHaveCount(0);
  await expect(page.locator('li[data-upload-key]')).toHaveCount(2);
  await page.getByRole('button', { name: '下移 first.png', exact: true }).click();
  await expect(page.locator('li[data-upload-key]').first()).toContainText('second.png');
  await page
    .locator('li[data-upload-key]')
    .first()
    .getByRole('textbox', { name: '連結', exact: true })
    .fill('https://example.test/second');
}

const runtimeErrors = new WeakMap<Page, string[]>();
test.beforeEach(async ({ page }) => {
  const errors: string[] = [];
  runtimeErrors.set(page, errors);
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    // The unique-SKU case deliberately exercises this real 422. Preserve app errors and all
    // other transport failures; Chromium also emits its own resource diagnostic for this one.
    if (
      message.type() === 'error' &&
      /^Failed to load resource:.*422/.test(message.text()) &&
      new URL(message.location().url).pathname === '/admin/grid-showcase'
    )
      return;
    if (message.type() === 'error' || message.type() === 'warning') errors.push(message.text());
  });
  await login(page);
});
test.afterEach(async ({ page }) => {
  expect(runtimeErrors.get(page)).toEqual([]);
});

test('order modal validates locally, continues creating and preserves the shell', async ({
  page,
}) => {
  await page.goto('/admin/orders');
  const mount = await page.locator('[data-shell-mount]').getAttribute('data-shell-mount');
  let writes = 0;
  page.on('request', (request) => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/admin/orders')
      writes++;
  });
  await page.getByRole('button', { name: '新增訂單', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: '新增訂單', exact: true });
  await expect(dialog).toBeVisible();
  await expect(page).toHaveURL(/\/admin\/orders$/);
  await expect(page.locator('[data-shell-mount]')).toHaveAttribute('data-shell-mount', mount!);
  await page.keyboard.press('Meta+Enter');
  await expect(dialog.getByRole('textbox', { name: '客戶', exact: true })).toHaveAttribute(
    'aria-invalid',
    'true',
  );
  await assertErrorInsideDialog(dialog.getByRole('textbox', { name: '客戶', exact: true }));
  expect(writes).toBe(0);
  await dialog.getByRole('textbox', { name: '客戶', exact: true }).fill('驗收連續新增客戶');
  await after(page, '繼續新增');
  await save(page);
  await expect(page.getByText('已新增訂單', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('textbox', { name: '客戶', exact: true })).toHaveValue('');
  await dialog.getByRole('button', { name: '取消', exact: true }).click();
  await expect(page.locator('tbody tr').first()).toContainText('驗收連續新增客戶');
  expect(writes).toBe(1);
});

test('order edit keyboard navigation, dirty confirmation, cancel action and quick create persist', async ({
  page,
}) => {
  await page.goto('/admin/orders');
  const numbers = await page.locator('tbody tr td').filter({ hasText: /^BR-/ }).allTextContents();
  const mount = await page.locator('[data-shell-mount]').getAttribute('data-shell-mount');
  await page
    .locator('tbody tr')
    .first()
    .getByRole('link', { name: numbers[0]!.trim(), exact: true })
    .click();
  const dialog = page.getByRole('dialog', { name: /編輯訂單/ });
  await expect(dialog).toBeVisible();
  await expect(page).toHaveURL(/\/admin\/orders$/);
  await expect(page.locator('[data-shell-mount]')).toHaveAttribute('data-shell-mount', mount!);
  const nextResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/edit') && response.request().headers()['x-brume-form'] === '1',
  );
  await page.keyboard.press('Alt+ArrowDown');
  const nextForm = (await (await nextResponse).json()).page;
  expect(nextForm.record.label).toBe(numbers[1]!.trim());
  await expect(dialog.getByRole('textbox', { name: '客戶', exact: true })).toHaveValue(
    nextForm.values.customer,
  );
  const customer = dialog.getByRole('textbox', { name: '客戶', exact: true });
  await customer.fill('驗收已修改客戶');
  await page.keyboard.press('Escape');
  await expect(page.getByRole('alertdialog')).toBeVisible();
  await page.getByRole('alertdialog').getByRole('button', { name: '取消', exact: true }).click();
  await after(page, '回到列表');
  await save(page);
  await expect(dialog).toHaveCount(0);
  await expect(page.locator('tbody tr').nth(1)).toContainText('驗收已修改客戶');
  await page.getByRole('button', { name: '快速新增', exact: true }).click();
  const quick = page.getByRole('textbox', { name: '客戶', exact: true });
  await quick.fill('驗收快速客戶');
  await quick.press('Enter');
  await expect(page.locator('tbody tr').first()).toContainText('驗收快速客戶');
  const row = page.locator('tbody tr').first();
  await row.getByRole('button', { name: /更多操作/ }).click();
  await page.getByRole('menuitem', { name: '取消訂單', exact: true }).click();
  const action = page.getByRole('dialog', { name: '取消訂單', exact: true });
  await action.getByRole('button', { name: '取消訂單', exact: true }).click();
  await expect(action.getByRole('textbox', { name: '取消原因', exact: true })).toHaveAttribute(
    'aria-invalid',
    'true',
  );
  await action.getByRole('textbox', { name: '取消原因', exact: true }).fill('客戶要求取消');
  await action.getByRole('button', { name: '取消訂單', exact: true }).click();
  await expect(action).toHaveCount(0);
  await expect(page.locator('tbody tr').first()).toContainText('已取消');
});

test('showcase page uploads, sorting, extras, unique errors and all new inline editors persist', async ({
  page,
}) => {
  await page.goto('/admin/grid-showcase');
  const existing = await page
    .locator('tbody tr')
    .first()
    .getByRole('cell')
    .filter({ hasText: /^SKU-/ })
    .innerText();
  await page.getByRole('button', { name: '新增商品', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/grid-showcase\/create$/);
  const toc = page.getByRole('navigation', { name: '本頁段落' });
  await expect(toc.getByRole('link')).toHaveCount(4);
  await fillProduct(page, existing.trim());
  await gallery(page);
  await page
    .getByRole('group', { name: '封面', exact: true })
    .locator('input[type=file]')
    .setInputFiles(image('cover.png'));
  await page
    .getByRole('group', { name: '文件', exact: true })
    .locator('input[type=file]')
    .setInputFiles({
      name: 'readme.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('Workbench acceptance document'),
    });
  await expect(page.getByText('上傳中…', { exact: true })).toHaveCount(0);
  await save(page);
  await expect(toc.getByRole('link', { name: /基本資料.*（有錯誤）/ })).toBeVisible();
  await expect(toc.getByRole('link', { name: '媒體', exact: true })).toBeVisible();
  await expect(page.getByRole('textbox', { name: '貨號', exact: true })).toBeFocused();
  await page.getByRole('textbox', { name: '貨號', exact: true }).fill('TASK22-E2E');
  await after(page, '回到列表');
  await save(page);
  await expect(page).toHaveURL(/\/admin\/grid-showcase$/);
  let row = page.locator('tbody tr').first();
  await row.getByRole('link', { name: '表單驗收新品', exact: true }).click();
  await expect(page).toHaveURL(/\/edit$/);
  const uploads = page.locator('li[data-upload-key]');
  await expect(uploads).toHaveCount(4);
  const galleryItems = page
    .getByRole('group', { name: '相簿', exact: true })
    .locator('li[data-upload-key]');
  const paths = await galleryItems
    .locator('a')
    .evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href')));
  expect(paths).toHaveLength(2);
  expect(paths[0]).not.toBe(paths[1]);
  await expect(
    galleryItems.first().getByRole('textbox', { name: '連結', exact: true }),
  ).toHaveValue('https://example.test/second');
  for (const url of paths) expect((await page.request.get(url!)).status()).toBe(200);
  await page.getByRole('textbox', { name: '標語', exact: true }).fill('未儲存修改');
  await page.getByRole('link', { name: '訂單', exact: true }).click();
  await expect(page.getByRole('alertdialog')).toBeVisible();
  await page
    .getByRole('alertdialog')
    .getByRole('button', { name: '放棄變更', exact: true })
    .click();
  await expect(page).toHaveURL(/\/admin\/orders$/);
  await page.goto('/admin/grid-showcase?q=TASK22-E2E');
  row = page.locator('tbody tr').first();
  await row.getByRole('button', { name: /^編輯售價（NT\$）：/ }).click();
  const price = row.getByRole('textbox', { name: '售價（NT$）', exact: true });
  await price.fill('1280.5');
  await price.press('Enter');
  await expect(row).toContainText('1,280.50');
  await row.getByRole('button', { name: /^編輯上市日：/ }).click();
  const date = row.getByLabel('上市日', { exact: true });
  await date.fill('2026-11-02');
  await date.press('Enter');
  await expect(row).toContainText('2026-11-02');
  await row.getByRole('button', { name: /^編輯標籤：/ }).click();
  const tags = page.getByRole('combobox', { name: '標籤', exact: true });
  await tags.fill('驗收標籤');
  await tags.press('Enter');
  await tags.press('Enter');
  await expect(page.getByRole('combobox', { name: '標籤', exact: true })).toHaveCount(0);
  await page.reload();
  await expect(page.locator('tbody tr').first()).toContainText('驗收標籤');
});

test('featured product name and sku render as readonly text', async ({ page }) => {
  // Seeder intentionally marks id 56 featured, independent of Faker's draws.
  await page.goto('/admin/grid-showcase/56/edit');
  await expect(page.getByRole('heading', { name: /編輯商品/ })).toBeVisible();
  await expect(page.getByRole('textbox', { name: '品名', exact: true })).toHaveCount(0);
  await expect(page.getByRole('textbox', { name: '貨號', exact: true })).toHaveCount(0);
  await expect(page.getByText('SKU-', { exact: false }).first()).toBeVisible();
});

for (const viewport of [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
]) {
  test.describe(`${viewport.name} form acceptance`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });
    for (const scheme of ['light', 'dark'] as const) {
      test(`modal and page geometry, axe, errors and uploads ${scheme}`, async ({ page }) => {
        test.setTimeout(90_000);
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await useScheme(page, scheme);
        await page.goto('/admin/orders');
        await page.getByRole('button', { name: '新增訂單', exact: true }).click();
        await page.keyboard.press('Meta+Enter');
        await assertErrorInsideDialog(
          page.getByRole('dialog').getByRole('textbox', { name: '客戶', exact: true }),
        );
        await geometry(page);
        expect(await axeViolations(page)).toEqual([]);
        await expect(page).toHaveScreenshot(`order-modal-errors-${scheme}-${viewport.name}.png`);
        await page.getByRole('dialog').getByRole('button', { name: '取消', exact: true }).click();
        await page.goto('/admin/grid-showcase/create');
        await save(page);
        await expect(page.getByRole('textbox', { name: '品名', exact: true })).toBeFocused();
        await geometry(page);
        for (const name of ['上市日', '期間（起）', '期間（迄）']) {
          const native = page.getByLabel(name, { exact: true });
          const bounds = await native.evaluate((el) => ({
            width: el.getBoundingClientRect().width,
            scroll: el.scrollWidth,
            client: el.clientWidth,
          }));
          expect(bounds.width).toBeGreaterThanOrEqual(140);
          expect(bounds.scroll).toBeLessThanOrEqual(bounds.client);
        }
        expect(await axeViolations(page)).toEqual([]);
        await expect(page).toHaveScreenshot(`showcase-errors-${scheme}-${viewport.name}.png`, {
          fullPage: true,
        });
        await gallery(page);
        await page.getByRole('heading', { name: '媒體', exact: true }).scrollIntoViewIfNeeded();
        await geometry(page);
        expect(await axeViolations(page)).toEqual([]);
        await expect(page).toHaveScreenshot(`showcase-uploads-${scheme}-${viewport.name}.png`, {
          fullPage: true,
        });
        expect(errors).toEqual([]);
      });
    }
  });
}
