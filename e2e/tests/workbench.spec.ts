import { expect, test } from '@playwright/test';
import { login } from './support';

// The workbench runs in --frontend (vite) mode with its own registered components
// (workbench/resources/js/brume/components.ts).

test.beforeEach(async ({ page }) => {
  await login(page);
});

test('the dashboard renders the registered demo-chart, not a missing-component alert', async ({ page }) => {
  const chart = page.locator('[data-demo-chart]');
  await expect(chart.getByRole('heading', { name: '近 6 個月營收' })).toBeVisible();
  await expect(chart.getByRole('img', { name: /^近 6 個月營收：5 月 NT\$ / })).toBeVisible();
  await expect(page.getByRole('heading', { name: '訂單總數' })).toBeVisible();
  await expect(page.getByRole('alert')).toHaveCount(0);
});

test('訂單 lists 60 seeded orders, newest first, 15 per page', async ({ page }) => {
  await page.getByRole('link', { name: '訂單' }).click();
  await expect(page).toHaveURL(/\/admin\/orders$/);
  await expect(page.getByRole('heading', { name: '訂單', level: 1 })).toBeVisible();

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
  for (const section of ['按鈕', '表單欄位', '通知', '彈窗與確認', '資料表與分頁', '卡片、空狀態與骨架', '頁首與麵包屑']) {
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
});
