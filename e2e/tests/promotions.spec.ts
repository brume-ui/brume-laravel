import { expect, test, type Locator, type Page } from '@playwright/test';
import { axeViolations, login, useScheme } from './support';

// Promotion acceptance (滿額贈). Writes go to promotions this file creates ("E2E …"); the seeded
// promotion 1 is only read or submitted invalid. Real endpoints throughout, no AJAX mocks.
test.beforeEach(async ({ page }) => {
  await login(page);
});

// Page checks first: they capture the seeded data, before the tests below add records.
for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 390]) {
    for (const [name, path, heading] of [
      ['list', '/admin/promotions', '滿額贈'],
      ['create', '/admin/promotions/create', '新增滿額贈'],
      ['edit', '/admin/promotions/1/edit', '編輯滿額贈'],
    ] as const) {
      test(`promotions ${name} ${scheme} ${width}: axe, geometry, screenshot`, async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (m) => {
          if (m.type() === 'error' || m.type() === 'warning') errors.push(m.text());
        });
        await useScheme(page, scheme);
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.getByRole('heading', { name: heading, exact: true }).first()).toBeVisible();
        if (name === 'edit') {
          await field(page, '即時試算').fill('1000');
          await expect(page.locator('[id$="-field-calculator-result"]')).toContainText('任選 1 樣：示範商品 011');
        }
        await page.evaluate(() => document.fonts.ready);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        expect(await axeViolations(page)).toEqual([]);
        expect(errors).toEqual([]);
        await expect(page).toHaveScreenshot(`promotions-${name}-${scheme}-${width}.png`, {
          animations: 'disabled',
          fullPage: name === 'edit',
        });
      });
    }
  }
}

const DIRTY = '有未儲存的變更';
const field = (scope: Page | Locator, name: string) => scope.getByRole('textbox', { name, exact: true });
const thresholds = (page: Page) =>
  page.getByRole('list', { name: '消費門檻', exact: true }).locator(':scope > li');
const actionsOf = (threshold: Locator) =>
  threshold.getByRole('list', { name: '贈送方式', exact: true }).locator(':scope > li');

function writes(page: Page): string[] {
  const seen: string[] = [];
  page.on('request', (request) => {
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(request.method()))
      seen.push(`${request.method()} ${new URL(request.url()).pathname}`);
  });
  return seen;
}
function promotionResponse(page: Page, method: 'POST' | 'PUT') {
  return page.waitForResponse(
    (response) =>
      /^\/admin\/promotions(\/\d+)?$/.test(new URL(response.url()).pathname) &&
      response.request().method() === method,
  );
}
async function continueEditing(page: Page) {
  await page.getByRole('combobox', { name: '儲存後', exact: true }).click();
  await page.getByRole('option', { name: '繼續編輯', exact: true }).click();
}
async function pickGifts(action: Locator, pick: (panel: Locator) => Promise<void>) {
  await action.getByRole('button', { name: /^贈品 ·/ }).click();
  const panel = action.getByRole('region', { name: '贈品', exact: true });
  await pick(panel);
  await panel.getByRole('button', { name: '套用', exact: true }).click();
}

test('calculator: below, at and above thresholds; the form never turns dirty and nothing is sent', async ({
  page,
}) => {
  const sent = writes(page);
  await page.goto('/admin/promotions/1/edit');
  await expect(field(page, '活動名稱')).toHaveValue('滿額贈示範');
  const calculator = field(page, '即時試算');
  const result = page.locator('[id$="-field-calculator-result"]');
  const clean = async () => {
    await expect(page.getByText(DIRTY)).toHaveCount(0);
    await expect(page.getByRole('button', { name: '捨棄變更' })).toHaveCount(0);
  };
  await clean();

  await calculator.fill('499.99');
  await expect(result).toContainText('NT$ 499.99 未達任何門檻');
  await clean();

  await calculator.fill('500');
  await expect(result).toContainText('滿 NT$ 500.00');
  await expect(result).toContainText('全部送：示範商品 010');
  await expect(result).not.toContainText('滿 NT$ 1000.00');
  await clean();

  await calculator.fill('999.99');
  await expect(result).toContainText('滿 NT$ 500.00');
  await expect(result).not.toContainText('滿 NT$ 1000.00');

  await calculator.fill('1000');
  // Only the highest tier reached applies; the 500 tier is not added on top.
  await expect(result).toContainText('滿 NT$ 1000.00');
  await expect(result).not.toContainText('滿 NT$ 500.00');
  await expect(result).toContainText('任選 1 樣：示範商品 011、示範商品 012');
  await clean();

  await calculator.fill('12.345');
  await expect(result).toContainText('請輸入有效金額（最多兩位小數）');
  await calculator.fill('');
  await expect(result.getByRole('list')).toHaveCount(0);
  await clean();

  // Positive control: a real field edit does raise the indicator, so the absence above means something.
  await field(page, '活動名稱').fill('滿額贈示範（改）');
  await expect(page.getByText(DIRTY)).toBeVisible();
  expect(sent).toEqual([]);
});

test('a duplicate threshold (500 beside 500.00) is rejected by the client before any request', async ({
  page,
}) => {
  const sent = writes(page);
  await page.goto('/admin/promotions/1/edit');
  const second = field(thresholds(page).nth(1), '消費滿（NT$）');
  await second.fill('500');
  await expect(second).toHaveValue('500');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  await expect(second).toHaveAttribute('aria-invalid', 'true');
  const summary = page.getByRole('alert').first();
  await expect(summary).toContainText('有 2 個欄位需要修正');
  await expect(field(thresholds(page).nth(0), '消費滿（NT$）')).toHaveAttribute('aria-invalid', 'true');
  expect(sent).toEqual([]);
});

test('create a promotion with two thresholds, nested actions and paged gifts; server-only given_limit error focuses its field; correct, reorder, remove', async ({
  page,
}) => {
  await page.goto('/admin/promotions/create');
  await field(page, '活動名稱').fill('E2E 滿額贈');
  await field(page, '活動期間（起）').fill('2026-11-10T00:00');
  await field(page, '活動期間（迄）').fill('2026-11-20T23:59');

  // Threshold 1: all-gifts, picked across two pages.
  await page.getByRole('button', { name: '新增 消費門檻', exact: true }).click();
  const first = thresholds(page).nth(0);
  await field(first, '消費滿（NT$）').fill('300');
  await first.getByRole('button', { name: '新增 贈送方式', exact: true }).click();
  // A new nested row starts with the template default ('all').
  await expect(actionsOf(first).nth(0).getByRole('radio', { name: '全部送', exact: true })).toBeChecked();
  await pickGifts(actionsOf(first).nth(0), async (panel) => {
    await panel.getByRole('checkbox', { name: /示範商品 001/ }).check();
    await panel.getByRole('button', { name: '下一頁', exact: true }).click();
    await panel.getByRole('checkbox', { name: /示範商品 025/ }).check();
  });

  // Threshold 2: choose 1 of 2, the second gift limited with an invalid-on-the-server limit of 0.
  await page.getByRole('button', { name: '新增 消費門檻', exact: true }).click();
  const second = thresholds(page).nth(1);
  await field(second, '消費滿（NT$）').fill('800');
  await second.getByRole('button', { name: '新增 贈送方式', exact: true }).click();
  const action = actionsOf(second).nth(0);
  await action.getByRole('radio', { name: '任選 N 樣', exact: true }).check();
  await field(action, '任選數量').fill('1');
  await pickGifts(action, async (panel) => {
    await panel.getByRole('checkbox', { name: /示範商品 002/ }).check();
    await panel.getByRole('checkbox', { name: /示範商品 003/ }).check();
    await panel.getByRole('switch', { name: '限量', exact: true }).nth(1).click();
    await field(panel, '限量數').fill('0');
  });

  await continueEditing(page);
  let response = promotionResponse(page, 'POST');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBe(422);
  // The nested server error opens its picker and focuses exactly that field.
  const limit = field(actionsOf(thresholds(page).nth(1)).nth(0), '限量數');
  await expect(limit).toBeFocused();
  await expect(limit).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByText('限量數至少為 1').first()).toBeVisible();

  // Correct it and save.
  await limit.fill('5');
  await actionsOf(thresholds(page).nth(1)).nth(0).getByRole('button', { name: '套用', exact: true }).click();
  response = promotionResponse(page, 'POST');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBe(200);
  await expect(page).toHaveURL(/\/admin\/promotions\/\d+\/edit/);
  const id = new URL(page.url()).pathname.split('/')[3];

  // Reopen: both thresholds and their gifts are there.
  await page.reload();
  await expect(thresholds(page)).toHaveCount(2);
  await expect(field(thresholds(page).nth(0), '消費滿（NT$）')).toHaveValue('300.00');
  await expect(
    thresholds(page).nth(0).getByRole('list', { name: '贈品', exact: true }).getByRole('listitem'),
  ).toHaveCount(2);
  await expect(
    thresholds(page).nth(1).getByRole('list', { name: '贈品', exact: true }).getByRole('listitem').nth(1),
  ).toContainText('限量數：5');

  // Reorder thresholds, save, reload.
  await page.getByRole('button', { name: '下移 消費門檻 1', exact: true }).click();
  await expect(field(thresholds(page).nth(0), '消費滿（NT$）')).toHaveValue('800.00');
  response = promotionResponse(page, 'PUT');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBe(200);
  await page.reload();
  await expect(field(thresholds(page).nth(0), '消費滿（NT$）')).toHaveValue('800.00');
  await expect(field(thresholds(page).nth(1), '消費滿（NT$）')).toHaveValue('300.00');

  // Remove the 800 threshold, save, reload.
  await page.getByRole('button', { name: '移除 消費門檻 1', exact: true }).click();
  response = promotionResponse(page, 'PUT');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  expect((await response).status()).toBe(200);
  await page.reload();
  await expect(thresholds(page)).toHaveCount(1);
  await expect(field(thresholds(page).nth(0), '消費滿（NT$）')).toHaveValue('300.00');
  expect(id).toMatch(/^\d+$/);
});

