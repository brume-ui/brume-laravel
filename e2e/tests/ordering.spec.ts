import { expect, test, type Locator, type Page } from '@playwright/test';
import { axeViolations, login, useScheme } from './support';

// Drag-to-order acceptance: 首頁橫幅 (orderable list, 45 seeded banners, 20 per page), 網站選單
// (tree, maxDepth 2) and 分類排序 (tree over the product categories). Real endpoints throughout.
// The screenshot checks run first, on the seeded data (banners 41-45 have no sort value yet).
// Every flow below puts back what it moved: banners through a reset to the seeded id order,
// the trees through 復原 or the opposite move. The tree groups also reset to the seed first.
test.beforeEach(async ({ page }) => {
  await login(page);
});

const BANNERS = '/admin/home-banners';
const TOAST = '已更新順序';
const DISABLED_REASON = '請先清除排序／篩選，或使用「調整順序」';
const bannerTitle = (id: number): string => {
  const campaigns = [
    '雙 11 購物節', '週年慶全館 85 折', '新會員首購禮', '秋冬新品上市', '滿額免運專案',
    '會員日雙倍點數', '聖誕禮物提案', '跨年倒數特賣', '春季換季大會', '母親節感恩回饋',
    '夏日清涼特輯', '開學季精選', '黑色星期五', '年終出清', '品牌聯名限定',
  ];
  return `${campaigns[(id - 1) % 15]}｜${['主視覺', '手機版', '側邊推薦'][Math.floor((id - 1) / 15)]}`;
};

const handle = (scope: Page | Locator, name: string) =>
  scope.getByRole('button', { name: `拖曳排序：${name}`, exact: true });
const titles = (page: Page) => page.getByRole('table').locator('tbody tr').getByRole('link').allInnerTexts();
const reorderResponse = (page: Page, path: string) =>
  page.waitForResponse((r) => r.url().endsWith(`${path}/_brume/reorder`) && r.request().method() === 'POST');

/** Puts every banner back in id order (the seed's visible order), through the same endpoint the UI uses. */
async function resetBanners(page: Page): Promise<void> {
  const cookies = await page.context().cookies();
  const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  const response = await page.request.post(`${BANNERS}/_brume/reorder`, {
    headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    data: { ids: Array.from({ length: 45 }, (_, i) => i + 1) },
  });
  expect(response.status()).toBe(200);
}

/** Posts one tree move ({ id, parent, after? }) through the same endpoint the UI uses. */
async function postMove(page: Page, path: string, move: Record<string, number | null>): Promise<void> {
  const cookies = await page.context().cookies();
  const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  const response = await page.request.post(`${path}/_brume/reorder`, {
    headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    data: move,
  });
  expect(response.status()).toBe(200);
}

/** Puts 網站選單 back to the seed (ids 1-5 are the roots, 6-18 their children in id order). */
async function resetNavMenus(page: Page): Promise<void> {
  const children: Array<[number, number]> = [
    [1, 6], [1, 7], [1, 8], [2, 9], [2, 10], [2, 11], [3, 12], [3, 13], [3, 14], [4, 15], [4, 16], [5, 17], [5, 18],
  ];
  // Roots first (each goes last, so they end up 1..5), then every child last under its parent.
  for (let id = 1; id <= 5; id++) await postMove(page, '/admin/nav-menus', { id, parent: null });
  for (const [parent, id] of children) await postMove(page, '/admin/nav-menus', { id, parent });
}

/** Puts the category tree back to the seed: 服飾 (1), 家居 (2), and 外套 (3) under 服飾. */
// Assumes the seed has no category rows other than ids 1-3, so moving those three restores it all.
async function resetCategories(page: Page): Promise<void> {
  await postMove(page, '/admin/product-category-tree', { id: 1, parent: null });
  await postMove(page, '/admin/product-category-tree', { id: 2, parent: null });
  await postMove(page, '/admin/product-category-tree', { id: 3, parent: 1 });
}

/** Keyboard move: focus the handle, pick up, press the keys, drop. */
async function keyboardMove(page: Page, name: string, keys: string[], scope: Page | Locator = page) {
  const grip = handle(scope, name);
  await grip.focus();
  await page.keyboard.press('Space');
  // dnd-kit's keyboard sensor listens from the next tick on; one key per frame-ish step.
  for (const key of keys) {
    await page.waitForTimeout(120);
    await page.keyboard.press(key);
  }
  await page.waitForTimeout(120);
  await page.keyboard.press('Space');
}

/** Real pointer drag in small steps, so dnd-kit passes its activation distance. */
async function pointerDrag(page: Page, from: Locator, to: Locator, dx = 0, dy = 0) {
  const a = (await from.boundingBox())!;
  const b = (await to.boundingBox())!;
  const sx = a.x + a.width / 2;
  const sy = a.y + a.height / 2;
  const tx = b.x + b.width / 2 + dx;
  const ty = b.y + b.height / 2 + dy;
  await page.mouse.move(sx, sy);
  await page.waitForTimeout(100);
  await page.mouse.down();
  for (let i = 1; i <= 15; i++) {
    await page.mouse.move(sx + ((tx - sx) * i) / 15, sy + ((ty - sy) * i) / 15);
    await page.waitForTimeout(25);
  }
  await page.waitForTimeout(150);
  await page.mouse.up();
}

type Node = { level: number; name: string };
/** The tree's nodes in display order, with their level. */
async function tree(page: Page): Promise<Node[]> {
  return page.locator('main li[aria-level]').evaluateAll((els) =>
    els.map((el) => ({ level: Number(el.getAttribute('aria-level')), name: el.querySelector('a')?.textContent ?? '' })),
  );
}
const parentOf = (nodes: Node[], name: string): string | null => {
  const index = nodes.findIndex((n) => n.name === name);
  for (let i = index - 1; i >= 0; i--) if (nodes[i].level < nodes[index].level) return nodes[i].name;
  return null;
};
const SEEDED_MENU: Node[] = [
  ['關於我們', ['品牌故事', '團隊介紹', '聯絡我們']],
  ['商品專區', ['新品上市', '熱銷排行', '限時特價']],
  ['會員服務', ['會員登入', '訂單查詢', '常見問題']],
  ['最新消息', ['活動公告', '媒體報導']],
  ['門市據點', ['台北門市', '舊版門市地圖']],
].flatMap(([root, kids]) => [
  { level: 1, name: root as string },
  ...(kids as string[]).map((name) => ({ level: 2, name })),
]);

function pageErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning') errors.push(m.text());
  });
  return errors;
}

// Screenshots and axe first, on the seeded data.
for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 390]) {
    for (const [name, path, heading] of [
      ['banners', BANNERS, '首頁橫幅'],
      ['banners-dialog', BANNERS, '首頁橫幅'],
      ['menus', '/admin/nav-menus', '網站選單'],
    ] as const) {
      test(`ordering ${name} ${scheme} ${width}: axe, geometry, screenshot`, async ({ page }) => {
        const errors = pageErrors(page);
        await useScheme(page, scheme);
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.getByRole('heading', { name: heading, exact: true }).first()).toBeVisible();
        if (name === 'banners-dialog') {
          await page.getByRole('button', { name: '調整順序', exact: true }).click();
          await expect(page.getByRole('dialog', { name: '調整順序' })).toBeVisible();
          await expect(page.getByRole('dialog').getByRole('listitem')).toHaveCount(45);
        } else if (name === 'banners') {
          await expect(handle(page, bannerTitle(1))).toBeVisible();
        } else {
          await expect(handle(page, '品牌故事')).toBeVisible();
        }
        await page.evaluate(() => document.fonts.ready);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        expect(await axeViolations(page)).toEqual([]);
        expect(errors).toEqual([]);
        await expect(page).toHaveScreenshot(`ordering-${name}-${scheme}-${width}.png`, { animations: 'disabled' });
      });
    }
  }
}

test.describe('banners: a paged list in order by dragging', () => {
  test.beforeEach(async ({ page }) => {
    await resetBanners(page);
  });
  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage({ baseURL: test.info().project.use.baseURL });
    await login(page);
    await resetBanners(page);
    await page.close();
  });

  test('a mouse drag of the first row below the third survives a reload', async ({ page }) => {
    await page.goto(BANNERS);
    await expect(handle(page, bannerTitle(1))).toBeVisible();
    const saved = reorderResponse(page, BANNERS);
    await pointerDrag(page, handle(page, bannerTitle(1)), handle(page, bannerTitle(3)), 0, 12);
    expect((await saved).status()).toBe(200);
    await expect(page.getByText(TOAST)).toBeVisible();
    expect((await titles(page)).slice(0, 4)).toEqual([2, 3, 1, 4].map(bannerTitle));

    await page.reload();
    await expect(handle(page, bannerTitle(1))).toBeVisible();
    expect((await titles(page)).slice(0, 4)).toEqual([2, 3, 1, 4].map(bannerTitle));
  });

  test('keyboard: pick up, move down twice, drop; Esc cancels', async ({ page }) => {
    await page.goto(BANNERS);
    await expect(handle(page, bannerTitle(1))).toBeVisible();

    // Esc puts it back without a request.
    const sent: string[] = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().endsWith('/_brume/reorder')) sent.push(r.url());
    });
    const grip = handle(page, bannerTitle(1));
    await grip.focus();
    await page.keyboard.press('Space');
    await page.waitForTimeout(120);
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(120);
    await page.keyboard.press('Escape');
    expect((await titles(page)).slice(0, 3)).toEqual([1, 2, 3].map(bannerTitle));
    expect(sent).toEqual([]);

    const saved = reorderResponse(page, BANNERS);
    await keyboardMove(page, bannerTitle(1), ['ArrowDown', 'ArrowDown']);
    expect((await saved).status()).toBe(200);
    await expect(page.getByText(TOAST)).toBeVisible();
    expect((await titles(page)).slice(0, 3)).toEqual([2, 3, 1].map(bannerTitle));
    await page.reload();
    expect((await titles(page)).slice(0, 3)).toEqual([2, 3, 1].map(bannerTitle));
  });

  test('復原 puts the moved row back', async ({ page }) => {
    await page.goto(BANNERS);
    await expect(handle(page, bannerTitle(1))).toBeVisible();
    await keyboardMove(page, bannerTitle(1), ['ArrowDown']);
    await expect(page.getByText(TOAST)).toBeVisible();
    expect((await titles(page)).slice(0, 2)).toEqual([2, 1].map(bannerTitle));

    const undone = reorderResponse(page, BANNERS);
    await page.getByRole('button', { name: '復原', exact: true }).click();
    expect((await undone).status()).toBe(200);
    await expect.poll(async () => (await titles(page)).slice(0, 3)).toEqual([1, 2, 3].map(bannerTitle));
    await page.reload();
    expect((await titles(page)).slice(0, 3)).toEqual([1, 2, 3].map(bannerTitle));
  });

  test('a filter or search disables the handles and says why', async ({ page }) => {
    await page.goto(BANNERS);
    await page.getByRole('button', { name: '篩選', exact: true }).click();
    await page.getByRole('combobox', { name: '上架狀態' }).click();
    await page.getByRole('option', { name: '已下架' }).click();
    await page.getByRole('button', { name: '套用篩選' }).click();
    await expect(page).toHaveURL(/filter/);
    const grip = page.getByRole('button', { name: /^拖曳排序：/ }).first();
    await expect(grip).toHaveAttribute('aria-disabled', 'true');
    await grip.hover();
    await expect(page.getByRole('tooltip').filter({ hasText: DISABLED_REASON })).toBeVisible();

    // A disabled handle starts nothing, even from the keyboard.
    const sent: string[] = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().endsWith('/_brume/reorder')) sent.push(r.url());
    });
    const before = await titles(page);
    await grip.focus();
    await page.keyboard.press('Space');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Space');
    expect(await titles(page)).toEqual(before);
    expect(sent).toEqual([]);

    // The dialog is the way to order a narrowed list.
    await expect(page.getByRole('button', { name: '調整順序', exact: true })).toBeEnabled();

    // The same with a search.
    await page.goto(`${BANNERS}?q=${encodeURIComponent('雙 11')}`);
    await expect(page.getByRole('button', { name: /^拖曳排序：/ }).first()).toHaveAttribute('aria-disabled', 'true');
  });

  test('調整順序: a banner from page 3 put on top is the first row of page 1', async ({ page }) => {
    await page.goto(`${BANNERS}?page=3`);
    const last = bannerTitle(45);
    await expect(page.getByRole('table').getByRole('link', { name: last, exact: true })).toBeVisible();
    await page.getByRole('button', { name: '調整順序', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '調整順序' });
    await expect(dialog.getByRole('listitem')).toHaveCount(45);
    await expect(dialog.getByRole('button', { name: '儲存', exact: true })).toBeDisabled();
    await dialog.getByRole('button', { name: `置頂：${last}`, exact: true }).click();
    await expect(dialog.getByRole('listitem').first()).toContainText(last);

    // Cancel changes nothing.
    await dialog.getByRole('button', { name: '取消', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('table').getByRole('link', { name: last, exact: true })).toBeVisible();

    await page.getByRole('button', { name: '調整順序', exact: true }).click();
    await dialog.getByRole('button', { name: `置頂：${last}`, exact: true }).click();
    const saved = reorderResponse(page, BANNERS);
    await dialog.getByRole('button', { name: '儲存', exact: true }).click();
    expect((await saved).status()).toBe(200);
    await expect(dialog).toHaveCount(0);

    await page.goto(BANNERS);
    const first = await titles(page);
    expect(first[0]).toBe(last);
    expect(first[1]).toBe(bannerTitle(1));
    // The others kept their order, and the first page now ends one banner earlier.
    expect(first).toHaveLength(20);
    expect(first[19]).toBe(bannerTitle(19));
  });
});

test.describe('網站選單: a two-level tree', () => {
  // Start from the seeded tree even if an earlier test stopped halfway.
  test.beforeEach(async ({ page }) => {
    await resetNavMenus(page);
  });

  test('the seeded tree: handles, toggles, 全部收合 / 全部展開', async ({ page }) => {
    await page.goto('/admin/nav-menus');
    await expect(handle(page, '品牌故事')).toBeVisible();
    expect(await tree(page)).toEqual(SEEDED_MENU);
    await page.getByRole('button', { name: '全部收合', exact: true }).click();
    expect((await tree(page)).map((n) => n.level)).toEqual([1, 1, 1, 1, 1]);
    await page.getByRole('button', { name: '全部展開', exact: true }).click();
    expect(await tree(page)).toEqual(SEEDED_MENU);
    await page.getByRole('button', { name: '關於我們 的子項目', exact: true }).click();
    expect((await tree(page)).map((n) => n.name)).not.toContain('品牌故事');
    await page.getByRole('button', { name: '關於我們 的子項目', exact: true }).click();
    expect(await tree(page)).toEqual(SEEDED_MENU);
  });

  test('a child dragged under another root is saved; 復原 puts it back', async ({ page }) => {
    await page.goto('/admin/nav-menus');
    await expect(handle(page, '聯絡我們')).toBeVisible();
    expect(parentOf(await tree(page), '聯絡我們')).toBe('關於我們');

    const saved = reorderResponse(page, '/admin/nav-menus');
    await pointerDrag(page, handle(page, '聯絡我們'), handle(page, '商品專區'));
    expect((await saved).status()).toBe(200);
    await expect(page.getByText(TOAST)).toBeVisible();
    const moved = await tree(page);
    expect(parentOf(moved, '聯絡我們')).toBe('商品專區');
    expect(moved.find((n) => n.name === '聯絡我們')!.level).toBe(2);
    expect(moved.filter((n) => n.level === 1).map((n) => n.name)).toEqual(['關於我們', '商品專區', '會員服務', '最新消息', '門市據點']);

    // A second page sees the saved tree.
    const other = await page.context().newPage();
    await other.goto('/admin/nav-menus');
    await expect(handle(other, '聯絡我們')).toBeVisible();
    expect(await tree(other)).toEqual(moved);
    await other.close();

    const undone = reorderResponse(page, '/admin/nav-menus');
    await page.getByRole('button', { name: '復原', exact: true }).click();
    expect((await undone).status()).toBe(200);
    await expect.poll(() => tree(page)).toEqual(SEEDED_MENU);
    await page.reload();
    expect(await tree(page)).toEqual(SEEDED_MENU);
  });

  test('nothing nests deeper than maxDepth: keyboard and pointer', async ({ page }) => {
    await page.goto('/admin/nav-menus');
    await expect(handle(page, '團隊介紹')).toBeVisible();
    const sent: string[] = [];
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().endsWith('/_brume/reorder')) sent.push(r.url());
    });

    // A child cannot go further in (level 3), and neither can a root that has children.
    for (const name of ['團隊介紹', '商品專區']) {
      await handle(page, name).focus();
      await page.keyboard.press('Space');
      await page.keyboard.press('ArrowRight');
      await expect(page.getByText('無法再往內移，最多 2 層')).toBeAttached();
      await page.keyboard.press('Space');
    }
    expect(await tree(page)).toEqual(SEEDED_MENU);
    expect(sent).toEqual([]);

    // Pointer: a root with children pushed to the right of a child is never put at level 3,
    // and since no legal drop target is left, nothing is saved.
    await pointerDrag(page, handle(page, '關於我們'), handle(page, '熱銷排行'), 80, 4);
    await page.waitForTimeout(500);
    expect(sent).toEqual([]);
    await expect(page.getByText(TOAST)).toHaveCount(0);
    const after = await tree(page);
    expect(Math.max(...after.map((n) => n.level))).toBe(2);
    for (const child of ['品牌故事', '團隊介紹', '聯絡我們']) expect(parentOf(after, child)).toBe('關於我們');
    expect(after).toEqual(SEEDED_MENU);
    await page.reload();
    expect(await tree(page)).toEqual(SEEDED_MENU);
  });

  test('a node offers 新增子項 and the create page has the parent filled in', async ({ page }) => {
    await page.goto('/admin/nav-menus');
    await page.getByRole('button', { name: '商品專區 的更多操作', exact: true }).click();
    await page.getByRole('menuitem', { name: '新增子項', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/nav-menus\/create/);
    await expect(page.getByRole('combobox', { name: '上層選單' })).toHaveValue('商品專區');
  });
});

test.describe('分類排序: the category tree and the 商品分類 list', () => {
  test.beforeEach(async ({ page }) => {
    await resetCategories(page);
  });

  test('moving 外套 under 家居 updates the 商品分類 list; moving it back restores it', async ({ page }) => {
    const rowsLeft = async (names: string[]) => {
      const out: Record<string, number> = {};
      for (const name of names) {
        const box = await page.getByRole('table').getByRole('link', { name, exact: true }).boundingBox();
        out[name] = box!.x;
      }
      return out;
    };
    const listOrder = async () => {
      await page.goto('/admin/product-categories');
      await expect(page.getByRole('table').getByRole('link', { name: '外套', exact: true })).toBeVisible();
      return (await titles(page)).filter((t) => ['服飾', '外套', '家居'].includes(t));
    };

    expect(await listOrder()).toEqual(['服飾', '外套', '家居']);
    const before = await rowsLeft(['服飾', '外套', '家居']);
    expect(before['外套']).toBeGreaterThan(before['服飾']);
    expect(before['家居']).toBe(before['服飾']);

    await page.goto('/admin/product-category-tree');
    await expect(handle(page, '外套')).toBeVisible();
    expect(parentOf(await tree(page), '外套')).toBe('服飾');
    const saved = reorderResponse(page, '/admin/product-category-tree');
    await keyboardMove(page, '外套', ['ArrowDown']);
    expect((await saved).status()).toBe(200);
    expect(parentOf(await tree(page), '外套')).toBe('家居');

    expect(await listOrder()).toEqual(['服飾', '家居', '外套']);
    const after = await rowsLeft(['服飾', '外套', '家居']);
    expect(after['外套']).toBeGreaterThan(after['家居']);

    // And back.
    await page.goto('/admin/product-category-tree');
    const back = reorderResponse(page, '/admin/product-category-tree');
    await keyboardMove(page, '外套', ['ArrowUp']);
    expect((await back).status()).toBe(200);
    expect(parentOf(await tree(page), '外套')).toBe('服飾');
    expect(await listOrder()).toEqual(['服飾', '外套', '家居']);
  });
});
