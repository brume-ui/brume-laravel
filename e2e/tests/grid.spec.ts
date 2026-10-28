import { expect, type Page, test } from '@playwright/test';
import { axeViolations, login, useScheme } from './support';

// 訂單 and Grid 示範 on the phase-2 Grid. These tests change the seeded orders (ship, delete), so
// this file runs in its own Playwright project after every other spec (playwright.config.ts), and
// its tests run in order in one worker: the ones that change data come after the ones that read it.
test.describe.configure({ mode: 'serial' });

const toastWith = (page: Page, text: string | RegExp) =>
  page.locator('[data-brume-toaster]').getByText(text);

const rows = (page: Page) => page.locator('tbody tr');

/** The amounts in the 金額 column of the visible rows, as numbers. */
async function amounts(page: Page): Promise<number[]> {
  const header = await page.locator('thead th').allTextContents();
  const index = header.findIndex((text) => text.startsWith('金額'));
  const cells = await rows(page).locator(`td:nth-child(${index + 1})`).allTextContents();
  return cells.map((text) => Number(text.replace(/[^\d]/g, '')));
}

test.beforeEach(async ({ page }) => {
  await login(page);
});

test.describe('訂單', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/orders');
    await expect(rows(page)).toHaveCount(15);
  });

  test('the 金額 header sorts ascending through a partial reload', async ({ page }) => {
    const shell = page.locator('[data-shell-mount]');
    const mountId = await shell.getAttribute('data-shell-mount');
    const header = page.getByRole('columnheader', { name: /^金額/ });
    await expect(header).toHaveAttribute('aria-sort', 'none');

    const visit = page.waitForRequest((request) => request.url().includes('sort=amount'));
    await header.getByRole('button').click();
    expect((await visit).headers()['x-inertia-partial-data']).toBe('envelope');

    await expect(page).toHaveURL(/[?&]sort=amount(&|$)/);
    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    const shown = await amounts(page);
    expect(shown).toEqual([...shown].sort((a, b) => a - b));
    // The smallest of all 60 seeded orders (DatabaseSeeder's fixed seed), not only of this page.
    await expect(rows(page).first()).toContainText('BR-20260903-042');
    expect(shown[0]).toBe(120);
    await expect(shell).toHaveAttribute('data-shell-mount', mountId as string);
  });

  test('quick search by order number leaves one row; clearing it brings back 15', async ({ page }) => {
    const search = page.getByRole('searchbox', { name: '搜尋' });
    await search.fill('BR-20261027-060');
    await search.press('Enter');
    await expect(page).toHaveURL(/[?&]q=BR-20261027-060/);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText('BR-20261027-060');

    await search.fill('');
    await search.press('Enter');
    await expect(page).not.toHaveURL(/[?&]q=/);
    await expect(rows(page)).toHaveCount(15);
  });

  test('the filter panel applies 已付款 as a chip; its ✕ removes it', async ({ page }) => {
    await page.getByRole('button', { name: '篩選', exact: true }).click();
    await page.getByRole('combobox', { name: '狀態' }).click();
    await page.getByRole('option', { name: '已付款' }).click();
    await page.getByRole('button', { name: '套用篩選' }).click();

    const chips = page.getByRole('group', { name: '已套用的篩選' });
    await expect(chips).toContainText('已付款');
    await expect(page).toHaveURL(/filter%5Bstatus%5D=paid|filter\[status\]=paid/);
    await expect(rows(page).first()).toContainText('已付款');

    await chips.getByRole('button', { name: '移除篩選：狀態' }).click();
    await expect(chips).toBeHidden();
    await expect(rows(page)).toHaveCount(15);
  });

  test('每頁 50 筆 shows 50 rows', async ({ page }) => {
    await page.getByRole('combobox', { name: '每頁筆數' }).click();
    await page.getByRole('option', { name: '每頁 50 筆' }).click();
    await expect(page).toHaveURL(/[?&]per_page=50/);
    await expect(rows(page)).toHaveCount(50);
  });

  test('hiding 客戶 sticks after a reload', async ({ page }) => {
    await expect(page.getByRole('columnheader', { name: '客戶' })).toBeVisible();
    await page.getByRole('button', { name: '顯示欄位' }).click();
    await page.getByRole('checkbox', { name: '客戶' }).uncheck();
    await expect(page.getByRole('columnheader', { name: '客戶' })).toHaveCount(0);

    await page.reload();
    await expect(rows(page)).toHaveCount(15);
    await expect(page.getByRole('columnheader', { name: '客戶' })).toHaveCount(0);
  });

  test('an empty result offers 清除所有篩選, which brings the rows back', async ({ page }) => {
    await page.goto('/admin/orders?q=no-such-order');
    await expect(page.getByText('沒有符合條件的資料')).toBeVisible();
    await page.getByRole('button', { name: '清除所有篩選' }).last().click();
    await expect(page).not.toHaveURL(/[?&]q=/);
    await expect(rows(page)).toHaveCount(15);
  });

  test('a 422 on inline status edit shows next to the cell', async ({ page }) => {
    await page.route('**/admin/orders/_brume/cells/**', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: '狀態的值無效。',
          errors: { status: ['狀態的值無效。'] },
        }),
      }),
    );
    const row = rows(page).first();
    await row.getByRole('button', { name: /^編輯狀態：/ }).click();
    await page.getByRole('dialog', { name: '狀態' }).getByRole('radio', { name: '已取消' }).click();
    await expect(row.getByRole('alert')).toHaveText('狀態的值無效。');
  });

  test('匯出 Excel downloads an .xlsx', async ({ page }) => {
    await page.getByRole('button', { name: '匯出 Excel' }).click();
    const download = page.waitForEvent('download');
    await page.getByRole('menuitem', { name: '符合條件的全部資料' }).click();
    expect((await download).suggestedFilename()).toMatch(/\.xlsx$/);
  });

  test('標記出貨 on a paid row toasts and the row becomes 已出貨', async ({ page }) => {
    const row = rows(page).filter({ has: page.getByRole('button', { name: '標記出貨' }) }).first();
    const number = (await row.locator('td a').first().textContent())?.trim() ?? '';
    expect(number).toMatch(/^BR-/);
    await row.getByRole('button', { name: '標記出貨' }).click();
    await expect(toastWith(page, `${number} 已標記出貨`)).toBeVisible();
    const same = rows(page).filter({ hasText: number });
    await expect(same).toContainText('已出貨');
    await expect(same.getByRole('button', { name: '標記出貨' })).toHaveCount(0);
  });

  test('batch 標記出貨 on two rows clears the selection', async ({ page }) => {
    await page.getByRole('checkbox', { name: /^選取 BR-/ }).nth(0).check();
    await page.getByRole('checkbox', { name: /^選取 BR-/ }).nth(1).check();
    const bar = page.getByRole('region', { name: '批次操作' });
    await expect(bar).toContainText('已選取 2 筆');
    await bar.getByRole('button', { name: '標記出貨' }).click();
    const confirm = page.getByRole('alertdialog', { name: '將 2 筆訂單標記為已出貨？' });
    await confirm.getByRole('button', { name: '標記出貨' }).click();
    await expect(toastWith(page, /已標記出貨/)).toBeVisible();
    await expect(bar).toBeHidden();
    await expect(page.getByRole('checkbox', { name: /^選取 BR-/, checked: true })).toHaveCount(0);
  });

  test('right-click → 刪除 asks first, then deletes the row', async ({ page }) => {
    const row = rows(page).nth(2);
    const number = (await row.locator('td a').first().textContent())?.trim() ?? '';
    await row.click({ button: 'right' });
    const menu = page.getByRole('menu');
    await expect(menu.getByRole('menuitem', { name: /^顯示訂單編號/ })).toBeVisible();
    await menu.getByRole('menuitem', { name: /^刪除/ }).click();

    const confirm = page.getByRole('alertdialog', { name: '刪除這筆資料？' });
    await expect(confirm).toContainText('刪除後無法復原。');
    await confirm.getByRole('button', { name: '刪除' }).click();
    await expect(toastWith(page, '已刪除 1 筆')).toBeVisible();
    await expect(rows(page).filter({ hasText: number })).toHaveCount(0);
  });
});

test('an unknown admin path answers 404 with the not-found page', async ({ page }) => {
  const response = await page.goto('/admin/nope');
  expect(response?.status()).toBe(404);
  await expect(page.getByRole('heading', { name: '找不到這個頁面' })).toBeVisible();
});

test.describe('Grid 示範', () => {
  test('lists 60 seeded items, 20 per page, with displays and editors', async ({ page }) => {
    await page.getByRole('link', { name: 'Grid 示範' }).click();
    await expect(page).toHaveURL(/\/admin\/grid-showcase$/);
    await expect(page.getByRole('heading', { name: 'Grid 示範', level: 1 })).toBeVisible();
    await expect(page.getByRole('link', { name: '第 3 頁' })).toBeVisible();
    await expect(page.getByRole('link', { name: '第 4 頁' })).toHaveCount(0);

    const table = page.getByRole('table', { name: 'Grid 示範' });
    await expect(table.getByRole('button', { name: /^複製貨號/ }).first()).toBeVisible();
    await expect(table.getByRole('switch', { name: /上架/ }).first()).toBeVisible();
    await expect(table.getByRole('button', { name: /QR Code/ }).first()).toBeVisible();
    await expect(table.getByRole('img').first()).toBeVisible();
  });

  test('an inline edit that breaks a rule shows the Chinese validation message', async ({ page }) => {
    await page.goto('/admin/grid-showcase');
    const row = page.locator('tbody tr').first();
    await row.getByRole('button', { name: /^編輯標語：/ }).click();
    const input = row.getByRole('textbox', { name: '標語' });
    await input.fill('');
    await input.press('Enter');
    await expect(row.getByRole('alert')).toHaveText('請填寫標語。');
  });

  for (const scheme of ['light', 'dark'] as const) {
    test(`axe finds no violations in ${scheme} mode`, async ({ page }) => {
      await useScheme(page, scheme);
      await page.goto('/admin/grid-showcase');
      await expect(page.locator('html')).toHaveAttribute('data-theme', scheme);
      await expect(page.getByRole('heading', { name: 'Grid 示範', level: 1 })).toBeVisible();
      await expect(page.getByRole('link', { name: '第 3 頁' })).toBeVisible();
      expect(await axeViolations(page)).toEqual([]);
    });
  }
});
