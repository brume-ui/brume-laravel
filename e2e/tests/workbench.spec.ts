import { expect, test } from '@playwright/test';
import { login } from './support';

// The workbench runs in --frontend (vite) mode with its own registered components
// (workbench/resources/js/brume/components.ts).

test.beforeEach(async ({ page }) => {
  await login(page);
});

test('the dashboard renders the stat cards and charts from the seeded orders, no missing-component alert', async ({
  page,
}) => {
  const stats = page.locator('[data-kpi-row] [data-stat-card]');
  await expect(stats).toHaveCount(4);
  for (const [label, hint] of [
    ['營收', '10 月，統計至 10/27'],
    ['訂單數', '10 月，含已取消 2 筆'],
    ['平均客單價', '10 月，不含已取消的訂單'],
    ['新客戶', '10 月第一次下單的客戶'],
  ]) {
    const card = page.getByRole('group', { name: label, exact: true });
    await expect(card).toBeVisible();
    await expect(card).toContainText(hint);
    await expect(card).toContainText('上月');
  }
  // October: 9 orders, 2 cancelled; the other 7 total NT$39,620.
  await expect(page.getByRole('group', { name: '訂單數', exact: true })).toContainText('9');
  await expect(page.getByRole('group', { name: '營收', exact: true })).toContainText('39,620');

  for (const [chart, title] of [
    ['line', '每週營收'],
    ['donut', '訂單狀態'],
    ['bar', '分類營收'],
  ]) {
    await expect(page.locator(`[data-chart-card="${chart}"]`).getByRole('figure', { name: title })).toBeVisible();
  }
  // The donut's data table lists every status with its count over the 60 seeded orders.
  const donut = page.getByRole('figure', { name: '訂單狀態' });
  await expect(donut.getByRole('table')).toContainText('已取消');
  await expect(page.getByRole('heading', { name: '待處理訂單' })).toBeVisible();
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('訂單 lists 60 seeded orders, newest first (the default sort), 15 per page', async ({ page }) => {
  await page.getByRole('link', { name: '訂單' }).click();
  await expect(page).toHaveURL(/\/admin\/orders$/);
  await expect(page.getByRole('heading', { name: '訂單', level: 1 })).toBeVisible();
  // Searchable and sortable now (grid.spec.ts drives them); the default sort is not a header sort.
  await expect(page.getByRole('searchbox', { name: '搜尋' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: /^建立時間/ })).toHaveAttribute('aria-sort', 'none');

  const rows = page.locator('tbody tr');
  await expect(rows).toHaveCount(15);
  await expect(rows.first()).toContainText('BR-20261027-060');
  await expect(rows.first()).toContainText('2026-10-27');
  await expect(page.getByRole('link', { name: '第 4 頁' })).toBeVisible();

  await page.getByRole('link', { name: '第 2 頁' }).click();
  await expect(page).toHaveURL(/\/admin\/orders\?page=2$/);
  await expect(rows).toHaveCount(15);
  await expect(rows.first()).toContainText('-045');

  await page.goto('/admin/orders?page=4');
  await expect(rows).toHaveCount(15);
  await expect(rows.last()).toContainText('-001');
});

test('元件展示 shows the kit and its interactions work', async ({ page }) => {
  await page.getByRole('link', { name: '元件展示' }).click();
  await expect(page).toHaveURL(/\/admin\/components$/);
  const showcase = page.locator('[data-component-showcase]');
  for (const section of ['按鈕', '表單欄位', '通知', '彈窗與確認', '資料表與分頁', '卡片、空狀態與骨架', '頁首與麵包屑', '圖表']) {
    await expect(showcase.getByRole('heading', { name: section, exact: true })).toBeVisible();
  }
  await expect(page.getByRole('navigation', { name: '麵包屑' })).toContainText('元件展示');

  // Field error appears while the username is too short.
  const username = showcase.getByRole('textbox', { name: /帳號/ });
  await username.fill('ab');
  await expect(showcase.getByText('帳號至少需要 3 個字元')).toBeVisible();
  await username.fill('abc');
  await expect(showcase.getByText('帳號至少需要 3 個字元')).toHaveCount(0);

  // All four toast levels.
  const toaster = page.locator('[data-brume-toaster]');
  for (const [button, text] of [
    ['成功', '已儲存訂單 BR-0001。'],
    ['資訊', '有 3 筆新訂單。'],
    ['警告', '庫存剩 2 件，記得補貨。'],
    ['錯誤', '儲存失敗：網路中斷，請稍後再試。'],
  ]) {
    await showcase.getByRole('button', { name: button, exact: true }).click();
    await expect(toaster.getByText(text)).toBeVisible();
  }

  // Dialog opens and closes.
  await showcase.getByRole('button', { name: '編輯客戶' }).click();
  const dialog = page.getByRole('dialog', { name: '編輯客戶' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: '取消' }).click();
  await expect(dialog).toHaveCount(0);

  // Confirm resolves with the user's choice.
  await showcase.getByRole('button', { name: '刪除訂單' }).click();
  const confirm = page.getByRole('alertdialog', { name: '刪除訂單 BR-0001？' });
  await confirm.getByRole('button', { name: '刪除訂單' }).click();
  await expect(showcase.locator('[data-confirm-result]')).toHaveText('已刪除訂單 BR-0001');

  // Client-side DataTable paging.
  await expect(showcase.getByRole('cell', { name: '霧面馬克杯 01' })).toBeVisible();
  await showcase.getByRole('navigation', { name: '商品分頁' }).getByRole('link', { name: '第 2 頁' }).click();
  await expect(showcase.getByRole('cell', { name: '亞麻餐墊 09' })).toBeVisible();
  await expect(showcase.getByRole('cell', { name: '霧面馬克杯 01' })).toHaveCount(0);
  await expect(page).toHaveURL(/\/admin\/components$/);

  // The 圖表 section shows each chart and links to the dashboard.
  for (const title of ['本週每日營收', '各通路訂單數', '付款方式']) {
    await expect(showcase.getByRole('figure', { name: title })).toBeVisible();
  }
  await expect(showcase.getByRole('group', { name: '本週營收' })).toBeVisible();
  await showcase.getByRole('link', { name: '儀表板' }).click();
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.locator('[data-kpi-row]')).toBeVisible();
});

// The shell and the grid toolbar in the workbench's own merged stylesheet (react + renderer +
// inertia styles.css bundled into one file): breakpoint utilities must still win at their width.
test.describe('at 1440×900', () => {
  test.use({ viewport: { width: 1440, height: 900 } });

  test('the shell is in its desktop layout and 訂單 fits without scrolling sideways', async ({ page }) => {
    await page.goto('/admin/orders');
    await expect(page.locator('tbody tr')).toHaveCount(15);
    await expect(page.getByRole('button', { name: '選單', exact: true })).toBeHidden();
    const main = page.locator('#brume-main');
    await expect(main).toHaveCSS('padding-left', '32px');
    // The quick search box keeps its own width, so the filter button shares its line.
    const search = await page.getByRole('searchbox', { name: '搜尋' }).boundingBox();
    const filter = await page.getByRole('button', { name: '篩選', exact: true }).boundingBox();
    expect(search?.width).toBeLessThan(400);
    expect(Math.round(filter?.y ?? 0)).toBe(Math.round(search?.y ?? -1));
    const overflow = await page
      .getByRole('table')
      .locator('xpath=..')
      .evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(overflow).toBe(0);
  });
});

test.describe('at 390×844', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('the shell is in its phone layout', async ({ page }) => {
    await page.goto('/admin/orders');
    await expect(page.getByRole('button', { name: '選單', exact: true })).toBeVisible();
    await expect(page.locator('#brume-main')).toHaveCSS('padding-left', '16px');
    // The quick search takes the whole toolbar line.
    const search = await page.getByRole('searchbox', { name: '搜尋' }).boundingBox();
    expect(search?.width).toBeGreaterThan(300);
  });
});
