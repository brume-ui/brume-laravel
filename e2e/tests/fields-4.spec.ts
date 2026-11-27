import { expect, test, type Locator, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, useScheme } from './support';

// Small input fields (ip, rate, slider, range, icon, time, timeRange, month, year, timezone, button,
// idField) on Grid 示範 items, and the captcha on its own 驗證碼示範 form. Items 1-3 keep their seed
// (screenshots read item 1); items 30 and 31 are written here and every test sets what it checks, so
// reruns are idempotent. The workbench captcha alphabet is a single character: the answer is 7777.
// Linux baselines are generated in CI.

const ANSWER = '7777';

test.beforeEach(async ({ page }) => {
  await login(page);
  page.on('dialog', (dialog) => dialog.accept());
});

async function openItem(page: Page, id: number) {
  await page.goto(`/admin/grid-showcase/${id}/edit`);
  await expect(page.getByRole('heading', { name: '編輯商品', exact: true })).toBeVisible();
  await expect(page.getByRole('textbox', { name: '伺服器 IP', exact: true })).toBeVisible();
}
function save(page: Page, path: string, method = 'PUT') {
  const response = page.waitForResponse((r) => new URL(r.url()).pathname === path && r.request().method() === method);
  return page.getByRole('button', { name: '儲存', exact: true }).click().then(() => response);
}
const box = (page: Page, name: string) => page.getByRole('textbox', { name, exact: true });
const fieldOf = (control: Locator) => control.locator('xpath=ancestor::*[@data-field-layout][1]');
const section = (page: Page) => page.getByRole('region', { name: '其他欄位', exact: true });

async function csrfHeaders(page: Page) {
  const cookies = await page.context().cookies();
  const token = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  return { 'X-XSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
}
function trackWrites(page: Page) {
  const writes: string[] = [];
  page.on('request', (r) => {
    if (['PUT', 'POST'].includes(r.method())) writes.push(`${r.method()} ${new URL(r.url()).pathname}`);
  });
  return writes;
}

async function pickOption(page: Page, combobox: string, option: string, search?: string) {
  await page.getByRole('combobox', { name: combobox, exact: true }).click();
  if (search) await page.keyboard.type(search);
  await page.getByRole('option', { name: option, exact: true }).click();
}

test('every small field is saved on an item and reloaded', async ({ page }) => {
  await openItem(page, 30);
  await box(page, '伺服器 IP').fill('2001:db8::8a2e:370:7334');
  await box(page, '折扣率 %').fill('12.5');
  const slider = page.getByRole('slider', { name: '音量', exact: true });
  await slider.focus();
  await page.keyboard.press('Home');
  for (let i = 0; i < 4; i++) await page.keyboard.press('ArrowRight');
  await expect(slider).toHaveValue('20');
  await box(page, '重量範圍（kg）（起）').fill('1.5');
  await box(page, '重量範圍（kg）（迄）').fill('3');
  await page.getByRole('button', { name: '圖示：選擇圖示' }).click();
  const dialog = page.getByRole('dialog', { name: '圖示', exact: true });
  await dialog.getByRole('searchbox', { name: '搜尋圖示' }).fill('coffee');
  await dialog.getByRole('group', { name: '圖示清單' }).getByRole('button', { name: 'coffee', exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(box(page, '圖示')).toHaveValue('coffee');
  // An overnight range is fine for a time range.
  await box(page, '營業時間（起）').fill('22:00');
  await box(page, '營業時間（迄）').fill('06:00');
  await box(page, '提醒時間').fill('07:45');
  await pickOption(page, '生日月份', '七月');
  await box(page, '創立年份').fill('1999');
  await pickOption(page, '時區', 'Asia/Tokyo', 'Tokyo');
  expect((await save(page, '/admin/grid-showcase/30')).ok()).toBe(true);

  await openItem(page, 30);
  await expect(box(page, '伺服器 IP')).toHaveValue('2001:db8::8a2e:370:7334');
  await expect(box(page, '折扣率 %')).toHaveValue('12.50');
  await expect(page.getByRole('slider', { name: '音量', exact: true })).toHaveValue('20');
  await expect(box(page, '重量範圍（kg）（起）')).toHaveValue('1.50');
  await expect(box(page, '重量範圍（kg）（迄）')).toHaveValue('3.00');
  await expect(box(page, '圖示')).toHaveValue('coffee');
  await expect(box(page, '營業時間（起）')).toHaveValue('22:00');
  await expect(box(page, '營業時間（迄）')).toHaveValue('06:00');
  await expect(box(page, '提醒時間')).toHaveValue('07:45');
  await expect(page.getByRole('combobox', { name: '生日月份', exact: true })).toHaveValue('七月');
  await expect(box(page, '創立年份')).toHaveValue('1999');
  await expect(page.getByRole('combobox', { name: '時區', exact: true })).toHaveValue('Asia/Tokyo');
});

test('the 圖示 picker reaches an icon by keyboard and hands focus back', async ({ page }) => {
  await openItem(page, 30);
  const trigger = page.getByRole('button', { name: '圖示：選擇圖示' });
  await trigger.click();
  const dialog = page.getByRole('dialog', { name: '圖示', exact: true });
  const list = dialog.getByRole('group', { name: '圖示清單' });
  // The unfiltered list is capped, with a hint to narrow the search.
  expect(await list.getByRole('button').count()).toBeLessThanOrEqual(120);
  await expect(dialog.getByText('僅顯示前 120 個圖示，請縮小搜尋範圍。')).toBeVisible();
  await dialog.getByRole('searchbox', { name: '搜尋圖示' }).fill('lamp-desk');
  await expect(list.getByRole('button', { name: 'lamp-desk', exact: true })).toBeVisible();
  await list.getByRole('button', { name: 'lamp-desk', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  await expect(box(page, '圖示')).toHaveValue('lamp-desk');
  // A name lucide does not have is kept: it loads and saves with the fallback icon.
  await box(page, '圖示').fill('no-such-icon');
  expect((await save(page, '/admin/grid-showcase/30')).ok()).toBe(true);
  await openItem(page, 30);
  await expect(box(page, '圖示')).toHaveValue('no-such-icon');
});

// The time inputs are native: a malformed time cannot be typed, so the server tests cover it.
// A range whose start is above its end is reported on the end bound.
const clientCases: { label: string; name: string; value: string; message: RegExp; flagged?: string }[] = [
  { label: 'ip', name: '伺服器 IP', value: '256.1.1.1', message: /伺服器 IP/ },
  { label: 'rate above max', name: '折扣率 %', value: '101', message: /折扣率/ },
  { label: 'range order', name: '重量範圍（kg）（起）', value: '9', message: /重量範圍/, flagged: '重量範圍（kg）（迄）' },
  { label: 'icon', name: '圖示', value: 'Not Valid', message: /圖示/ },
  { label: 'year above max', name: '創立年份', value: '3000', message: /創立年份/ },
];

for (const c of clientCases) {
  test(`client refuses a bad ${c.label} before anything is sent`, async ({ page }) => {
    await openItem(page, 31);
    const writes = trackWrites(page);
    const control = box(page, c.name);
    if (c.label === 'range order') await box(page, '重量範圍（kg）（迄）').fill('2');
    await control.fill(c.value);
    await page.getByRole('button', { name: '儲存', exact: true }).click();
    const flagged = c.flagged ? box(page, c.flagged) : control;
    await expect(flagged).toHaveAttribute('aria-invalid', 'true');
    await expect(fieldOf(flagged).getByText(c.message).first()).toBeVisible();
    expect(writes).toEqual([]);
  });
}

test('the server refuses direct writes of every bad small value with 422', async ({ page }) => {
  const headers = await csrfHeaders(page);
  const put = (values: object) => page.request.put('/admin/grid-showcase/31', { headers, data: { values } });
  const response = await put({
    server_ip: '256.1.1.1',
    discount_rate: '101',
    volume: 7,
    weight: { start: '5', end: '1' },
    icon: 'Bad Name',
    reminder_at: '24:00',
    birth_month: 13,
    founded_year: 3000,
    timezone: 'Mars/Base',
  });
  expect(response.status()).toBe(422);
  const { errors } = await response.json();
  for (const key of ['server_ip', 'discount_rate', 'volume', 'icon', 'reminder_at', 'birth_month', 'founded_year', 'timezone']) {
    expect(Object.keys(errors), key).toContain(key);
  }
  expect(Object.keys(errors).some((key) => key.startsWith('weight'))).toBe(true);
  // 35 is off the step-5 grid by a hair, 0.35-style values and text too.
  for (const volume of [36, -5, 105, 'abc']) {
    expect((await put({ volume })).status(), String(volume)).toBe(422);
  }
  // Nothing was written by the refusals.
  await openItem(page, 31);
  await expect(box(page, '伺服器 IP')).not.toHaveValue('256.1.1.1');
});

test('the 查看商品列表 button is a link to the item list', async ({ page }) => {
  await openItem(page, 1);
  const link = page.getByRole('link', { name: '查看商品列表', exact: true });
  await expect(link).toHaveAttribute('href', /^\/admin\/grid-showcase\?q=SKU-/);
  await link.click();
  await expect(page).toHaveURL(/\/admin\/grid-showcase\?q=SKU-/);
  // On create the closure gets no record and links to the plain list.
  await page.goto('/admin/grid-showcase/create');
  await expect(page.getByRole('link', { name: '查看商品列表', exact: true })).toHaveAttribute('href', '/admin/grid-showcase');
});

test('the id shows on edit and is left out while the record is new', async ({ page }) => {
  await openItem(page, 31);
  const basics = page.getByRole('region', { name: '基本資料', exact: true });
  await expect(basics.getByText('ID', { exact: true })).toBeVisible();
  await expect(basics.getByText('31', { exact: true })).toBeVisible();
  await page.goto('/admin/grid-showcase/create');
  await expect(page.getByRole('heading', { name: '新增商品', exact: true })).toBeVisible();
  await expect(page.getByRole('region', { name: '基本資料', exact: true }).getByText('ID', { exact: true })).toHaveCount(0);
});

test('the other-fields section passes axe on a seeded item', async ({ page }) => {
  await openItem(page, 1);
  await expect(page.getByRole('slider', { name: '音量', exact: true })).toHaveValue('35');
  // The section only: the read-only 品名 / 貨號 of a featured item carry aria-required, an existing finding outside this batch.
  await expect(page.locator('#nprogress')).toHaveCount(0);
  await section(page).evaluate((el) => el.setAttribute('data-axe-target', ''));
  const { violations } = await new AxeBuilder({ page }).include('[data-axe-target]').analyze();
  expect(violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(' ')) }))).toEqual([]);
});

test.describe('captcha', () => {
  async function openForm(page: Page) {
    await page.goto('/admin/captcha-demos/create');
    await expect(page.getByRole('heading', { name: '新增驗證碼示範', exact: true })).toBeVisible();
    const image = page.getByRole('img', { name: '驗證圖片' });
    await expect(image).toBeVisible();
    // Loaded, not just attached.
    await expect.poll(() => image.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);
    return image;
  }

  test('a wrong answer is refused and the image refreshes; the right one saves', async ({ page }) => {
    const message = `留言 ${Date.now()}`;
    const image = await openForm(page);
    const before = await image.getAttribute('src');
    await box(page, '留言').fill(message);
    const answer = box(page, '驗證碼');
    await answer.fill('0000');
    const refused = await save(page, '/admin/captcha-demos', 'POST');
    expect(refused.status()).toBe(422);
    expect(Object.keys((await refused.json()).errors)).toEqual(['__captcha__']);
    await expect(fieldOf(answer).getByRole('alert').or(fieldOf(answer).getByText(/驗證碼/).last()).first()).toBeVisible();
    // The image reloaded (cache-buster on src) and the typed answer was cleared.
    await expect(image).not.toHaveAttribute('src', before ?? '');
    await expect.poll(() => image.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);
    await expect(answer).toHaveValue('');
    await expect(box(page, '留言')).toHaveValue(message);

    await answer.fill(ANSWER);
    const saved = await save(page, '/admin/captcha-demos', 'POST');
    expect(saved.ok()).toBe(true);
    await expect(page).toHaveURL(/\/admin\/captcha-demos$/);
    await expect(page.getByText(message, { exact: true })).toBeVisible();
  });

  test('a direct POST without or with a wrong answer is refused on the captcha only', async ({ page }) => {
    const headers = await csrfHeaders(page);
    for (const values of [{ message: '無答案' }, { message: '錯誤答案', __captcha__: '0000' }]) {
      const response = await page.request.post('/admin/captcha-demos', { headers, data: { values } });
      expect(response.status()).toBe(422);
      expect(Object.keys((await response.json()).errors)).toEqual(['__captcha__']);
    }
    await page.goto('/admin/captcha-demos');
    await expect(page.getByText('無答案', { exact: true })).toHaveCount(0);
    await expect(page.getByText('錯誤答案', { exact: true })).toHaveCount(0);
  });

  test('the main item form needs no captcha', async ({ page }) => {
    await openItem(page, 31);
    await expect(page.getByRole('img', { name: '驗證圖片' })).toHaveCount(0);
  });
});

for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 390]) {
    test(`other fields section ${scheme} ${width}: screenshot`, async ({ page }) => {
      await useScheme(page, scheme);
      await page.setViewportSize({ width, height: 900 });
      await openItem(page, 1);
      await expect(page.getByRole('slider', { name: '音量', exact: true })).toHaveValue('35');
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      await page.evaluate(() => document.fonts.ready);
      await section(page).scrollIntoViewIfNeeded();
      await expect(section(page)).toHaveScreenshot(`fields-4-other-fields-${scheme}-${width}.png`, { animations: 'disabled' });
    });
  }
}
