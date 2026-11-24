import AxeBuilder from '@axe-core/playwright';
import { test as base, expect, type Frame, type Locator, type Page } from '@playwright/test';
import { axeViolations, login, useScheme } from './support';

// Rich text acceptance against the real backend: 商品說明 (editor with the storefront stylesheet and
// image uploads), 常見問題 (JSON repeater rows whose required answers are editors without images) and
// ERP 備註 (read-only). The seeded sample sits on DEMO-001/002/005 (ids 1, 2, 5). Every test starts
// from the seed: the workbench-only /admin/_demo/rich-text/reset route puts the rich text columns back,
// /legacy writes HTML straight into the database and /raw reads what is stored.

const PORT = Number(process.env.E2E_PORT ?? 8100);
const ORIGIN = `http://127.0.0.1:${PORT}`;
// A real 16×16 PNG: the server decodes the header (getimagesize), so the bytes must be an image.
const PNG = [
  ...Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGUlEQVQokWPkK5nAQApgIkn1qIZRDUNKAwB4twEycPdKNAAAAABJRU5ErkJggg==',
    'base64',
  ),
];
// The same PNG claiming 13000 px of width: small enough for the client, refused by the server's
// uploads.max_image_dimension (12000).
const WIDE_PNG = (() => {
  const bytes = [...PNG];
  bytes.splice(16, 4, 0, 0, 0x32, 0xc8);
  return bytes;
})();
const UPLOADED = /^\/uploads\/brume\/brume\.products\/editor\/[0-9a-f-]{36}\.png$/;

// Nothing may leave the origin; the YouTube embed in the sample is answered by a stub.
const test = base.extend<{ sameOrigin: string[] }>({
  sameOrigin: [
    async ({ page }, use) => {
      const external: string[] = [];
      await page.route(/^https?:\/\/([a-z0-9-]+\.)*(youtube\.com|youtube-nocookie\.com)\//, (route) =>
        route.fulfill({
          status: 200,
          contentType: 'text/html',
          body: '<!doctype html><html lang="en"><title>stub</title><body></body></html>',
        }),
      );
      page.on('request', (request) => {
        const url = request.url();
        if (/^(data|blob|about):/.test(url)) return;
        const { origin, hostname } = new URL(url);
        if (origin === ORIGIN) return;
        if (/(^|\.)youtube(-nocookie)?\.com$/.test(hostname) && url.includes('/embed/')) return;
        external.push(url);
      });
      await use(external);
      expect(external, 'requests left the origin').toEqual([]);
    },
    { auto: true },
  ],
});

async function xsrf(page: Page): Promise<Record<string, string>> {
  const cookies = await page.context().cookies();
  const token = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  return { 'X-XSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
}
async function demoPost(page: Page, path: string, data: Record<string, unknown> = {}) {
  const response = await page.request.post(`/admin/_demo/rich-text/${path}`, { headers: await xsrf(page), data });
  expect(response.status()).toBe(200);
}
type Raw = { description: string | null; faqs: { question: string; answer: string | null }[] | null; sample: string; sanitisedSample: string };
async function raw(page: Page, id: number): Promise<Raw> {
  const response = await page.request.get(`/admin/_demo/rich-text/raw/${id}`, { headers: { Accept: 'application/json' } });
  expect(response.status()).toBe(200);
  return response.json();
}

test.beforeEach(async ({ page }) => {
  await login(page);
  await demoPost(page, 'reset');
  // Leaving a page with unsaved edits asks first; the tests leave on purpose.
  page.on('dialog', (dialog) => dialog.accept());
});

/** The editor shell (`.br-rich-text`) whose editing iframe is titled `label`. */
const editor = (page: Page, label: string, index = 0): Locator =>
  page.locator('.br-rich-text').filter({ has: page.locator(`iframe[title="${label}"]`) }).nth(index);
const description = (page: Page) => editor(page, '商品說明');
const answer = (page: Page, row: number) => editor(page, '回答', row);

/** The lazy chunk loaded, Jodit is ready and the component set a valid <html lang> in the editing document. */
async function editorReady(root: Locator) {
  await expect(root.locator('iframe.jodit-wysiwyg_iframe')).toHaveCount(1, { timeout: 15_000 });
  await expect
    .poll(() => root.locator('iframe.jodit-wysiwyg_iframe').evaluate((f: HTMLIFrameElement) => f.contentDocument?.documentElement?.getAttribute('lang')), { timeout: 15_000 })
    .toBe('zh-TW');
}
async function frameOf(root: Locator): Promise<Frame> {
  const frame = await (await root.locator('iframe.jodit-wysiwyg_iframe').elementHandle())?.contentFrame();
  if (!frame) throw new Error('editing iframe has no frame');
  return frame;
}
async function openProduct(page: Page, id: number) {
  await page.goto(`/admin/products/${id}/edit`);
  await expect(page.getByRole('heading', { name: '編輯商品', exact: true })).toBeVisible();
  for (const root of await page.locator('.br-rich-text').all()) await editorReady(root);
}

/** Focus the editing body and put the caret at the end of the first non-empty text inside `selector`. */
async function caretAt(page: Page, root: Locator, selector = 'body') {
  const frame = await frameOf(root);
  await frame.locator('body').focus();
  await frame.evaluate((sel) => {
    const scope = document.querySelector(sel) ?? document.body;
    const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, {
      acceptNode: (n) => (/\S/.test((n.textContent ?? '').replace(/ /g, '')) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP),
    });
    const node = walker.nextNode() ?? scope;
    const range = document.createRange();
    range.setStart(node, node.nodeType === 3 ? (node.textContent ?? '').length : 0);
    range.collapse(true);
    getSelection()?.removeAllRanges();
    getSelection()?.addRange(range);
  }, selector);
  await page.waitForTimeout(50);
}

/** A synthetic paste, as the browser would deliver it, into the editing document. */
async function paste(root: Locator, files: { name: string; type: string; bytes?: number[]; size?: number }[]) {
  const frame = await frameOf(root);
  return frame.evaluate((files) => {
    const dt = new DataTransfer();
    dt.setData('text/plain', 'plain');
    for (const f of files) {
      const body = f.size ? new Uint8Array(f.size) : new Uint8Array(f.bytes ?? []);
      if (f.size && f.bytes) body.set(f.bytes);
      dt.items.add(new File([body], f.name, { type: f.type }));
    }
    const event = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true });
    (document.activeElement ?? document.body).dispatchEvent(event);
    return event.defaultPrevented;
  }, files);
}

async function continueEditing(page: Page) {
  await page.getByRole('combobox', { name: '儲存後', exact: true }).click();
  await page.getByRole('option', { name: '繼續編輯', exact: true }).click();
  // The select hands focus back to its trigger after it closes; wait for that, or the hand-back can
  // land after the test has put the caret in the editor and take the keyboard away from it.
  await expect(page.getByRole('listbox')).toHaveCount(0);
  await expect(page.getByRole('combobox', { name: '儲存後', exact: true })).toBeFocused();
}
async function save(page: Page, id: number, how: 'click' | 'shortcut' = 'click') {
  const response = page.waitForResponse((r) => new URL(r.url()).pathname === `/admin/products/${id}` && r.request().method() === 'PUT');
  if (how === 'click') await page.getByRole('button', { name: '儲存', exact: true }).click();
  else await page.keyboard.press('ControlOrMeta+Enter');
  expect((await response).status()).toBe(200);
  await expect(page.getByText('已儲存變更', { exact: true })).toBeVisible();
}
const imgCount = async (root: Locator) => (await frameOf(root)).locator('img').count();

test('the seeded sample opens in the editor with the storefront stylesheet; the lazy chunk and its CSS load', async ({ page }) => {
  const failed: string[] = [];
  page.on('response', (r) => {
    if (r.status() >= 400) failed.push(`${r.status()} ${r.url()}`);
  });
  const jodit: string[] = [];
  page.on('request', (r) => {
    if (/jodit/i.test(new URL(r.url()).pathname)) jodit.push(new URL(r.url()).pathname);
  });
  await openProduct(page, 1);
  const root = description(page);
  const frame = await frameOf(root);

  // Structure: nested layout, headings, lists, table, images, embed, a link wrapping a block.
  await expect(frame.locator('.rowOuter > .box100.flexBlock > .box50 h2')).toHaveText('霧島選物｜冬季保養組');
  await expect(frame.locator('h3')).toHaveText(['三個步驟，養出好膚況', '使用示範影片']);
  await expect(frame.locator('ul.iconList > li')).toHaveCount(3);
  await expect(frame.locator('ol > li')).toHaveCount(3);
  await expect(frame.locator('ol > li ul > li')).toHaveCount(2);
  await expect(frame.locator('table.specTable td[rowspan="2"]')).toHaveText('容量');
  await expect(frame.locator('table.specTable col')).toHaveCount(3);
  await expect(frame.locator('img')).toHaveCount(7);
  await expect(frame.locator('iframe[src="https://www.youtube.com/embed/demoVideo01"]')).toHaveCount(1);
  await expect(frame.locator('a[href="/storefront/winter-set"] > div.cardBox img')).toHaveCount(1);
  // Every image is a local placeholder that loaded.
  await expect
    .poll(() => frame.evaluate(() => [...document.images].every((i) => i.complete && i.naturalWidth > 0)))
    .toBe(true);

  // contentCss: the storefront classes are styled inside the editing document.
  const styles = await frame.evaluate(() => ({
    sheet: [...document.querySelectorAll('link[rel="stylesheet"]')].some((l) => (l as HTMLLinkElement).href.endsWith('/storefront/content.css')),
    badge: getComputedStyle(document.querySelector('.tagBadge')!).backgroundColor,
    title: getComputedStyle(document.querySelector('.font_lv2')!).fontSize,
    card: getComputedStyle(document.querySelector('.cardBox')!).borderTopColor,
  }));
  expect(styles).toEqual({ sheet: true, badge: 'rgb(181, 69, 47)', title: '26px', card: 'rgb(228, 224, 212)' });

  // The editor chunk and its stylesheet loaded (no 404s), and Jodit's toolbar is themed by them.
  expect(failed).toEqual([]);
  expect(jodit.some((p) => p.endsWith('.js'))).toBe(true);
  const bold = root.locator('.jodit-toolbar-button_bold .jodit-toolbar-button__button');
  await expect(bold).toBeVisible();
  const box = await bold.boundingBox();
  expect(box!.height).toBeGreaterThanOrEqual(24);
  expect(box!.height).toBeLessThanOrEqual(48);
  expect(await root.locator('.jodit-toolbar__box').evaluate((el) => getComputedStyle(el).display)).not.toBe('inline');
  expect(await bold.locator('svg').evaluate((el) => (el as SVGElement).getBoundingClientRect().width)).toBeGreaterThan(8);

  // The FAQ answers are editors too; the ERP note of a non-ERP product is empty.
  await expect(page.locator('.br-rich-text')).toHaveCount(3);
  await expect((await frameOf(answer(page, 0))).locator('strong')).toHaveText('不含香精與色素');
});

test('saving untouched stores the sanitised seed, and a second save changes nothing', async ({ page }) => {
  expect((await raw(page, 1)).description).toBe((await raw(page, 1)).sample);
  await openProduct(page, 1);
  await continueEditing(page);
  await save(page, 1);
  const first = await raw(page, 1);
  expect(first.description).toBe(first.sanitisedSample);
  expect(first.description).not.toBe(first.sample); // &nbsp; → U+00A0 and other renders-the-same rewrites

  await openProduct(page, 1);
  await continueEditing(page);
  await save(page, 1);
  expect((await raw(page, 1)).description).toBe(first.description);
});

test('typing saves, survives a reload, and saving the reloaded value again leaves it unchanged', async ({ page }) => {
  await openProduct(page, 1);
  const root = description(page);
  await caretAt(page, root, 'h2');
  await page.keyboard.type('限定版');
  await continueEditing(page);
  await save(page, 1);
  const saved = (await raw(page, 1)).description ?? '';
  expect(saved).toContain('霧島選物｜冬季保養組限定版</h2>');

  await page.reload();
  await editorReady(description(page));
  await expect((await frameOf(description(page))).locator('h2')).toHaveText('霧島選物｜冬季保養組限定版');
  await continueEditing(page);
  await save(page, 1);
  expect((await raw(page, 1)).description).toBe(saved);
});

test('Meta+Enter inside the editor saves the typed text', async ({ page }) => {
  await openProduct(page, 2);
  const root = description(page);
  await continueEditing(page);
  await caretAt(page, root, 'h2');
  await page.keyboard.type('快捷鍵');
  await save(page, 2, 'shortcut');
  const stored = (await raw(page, 2)).description ?? '';
  expect(stored).toContain('冬季保養組快捷鍵</h2>');
  // The shortcut saved; it did not insert a line break into the heading.
  expect(stored.match(/<h2/g)).toHaveLength(1);
});

test('a pasted image uploads, lands as a stored public URL and survives save and reload', async ({ page }) => {
  await openProduct(page, 2);
  const root = description(page);
  const before = await imgCount(root);
  // Hold the upload so the uploading state is observable.
  let release!: () => void;
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route('**/admin/products/2/_brume/upload', async (route) => {
    await held;
    await route.continue();
  });
  const uploaded = page.waitForResponse((r) => r.url().endsWith('/admin/products/2/_brume/upload'));
  await caretAt(page, root, 'h2');
  await paste(root, [{ name: 'photo.png', type: 'image/png', bytes: PNG }]);
  try {
    await expect(root).toHaveAttribute('data-uploading', '1');
    await expect((await frameOf(root)).locator('[data-br-upload]')).toHaveText('上傳中…');
  } finally {
    release();
  }
  expect((await uploaded).status()).toBe(200);
  await expect(root).not.toHaveAttribute('data-uploading');
  const frame = await frameOf(root);
  await expect(frame.locator('img')).toHaveCount(before + 1);
  const src = await frame.locator('h2 img').getAttribute('src');
  expect(src).toMatch(UPLOADED);
  const file = await page.request.get(src!);
  expect(file.status()).toBe(200);
  expect(file.headers()['content-type']).toMatch(/^image\/png/);

  await continueEditing(page);
  await save(page, 2);
  expect((await raw(page, 2)).description).toContain(`<img src="${src}"`);
  await page.reload();
  await editorReady(description(page));
  await expect((await frameOf(description(page))).locator(`img[src="${src}"]`)).toHaveCount(1);
});

test('a non-image file, an oversize image and an image the server refuses insert nothing', async ({ page }) => {
  await openProduct(page, 2);
  const root = description(page);
  const before = await imgCount(root);
  let uploads = 0;
  page.on('request', (r) => {
    if (r.url().endsWith('/_brume/upload')) uploads++;
  });

  await caretAt(page, root, 'h2');
  await paste(root, [{ name: 'notes.pdf', type: 'application/pdf', bytes: [37, 80, 68, 70] }]);
  await expect(page.getByText(/不支援這種檔案格式/).first()).toBeVisible();

  // Over the field's 2 MB: refused in the browser with the size message, nothing is sent.
  await paste(root, [{ name: 'big.png', type: 'image/png', bytes: PNG, size: 3 * 1024 * 1024 }]);
  await expect(page.getByText(/檔案超過/).first()).toBeVisible();
  expect(uploads).toBe(0);

  // Within the size, but the server refuses it (13000 px wide): its 422 message is shown.
  const refused = page.waitForResponse((r) => r.url().endsWith('/_brume/upload'));
  await paste(root, [{ name: 'wide.png', type: 'image/png', bytes: WIDE_PNG }]);
  expect((await refused).status()).toBe(422);
  await expect(page.getByText(/圖片任一邊超過/).first()).toBeVisible();
  await expect(root).not.toHaveAttribute('data-uploading');

  const frame = await frameOf(root);
  await expect(frame.locator('[data-br-upload]')).toHaveCount(0);
  expect(await imgCount(root)).toBe(before);
});

test('hostile legacy HTML in the database never executes, shows cleaned and saves clean', async ({ page }) => {
  const evil: string[] = [];
  await page.route('**://evil.example/**', (route) => {
    evil.push(route.request().url());
    return route.abort();
  });
  await demoPost(page, 'legacy/6', {
    html: [
      '<p>合法段落一</p>',
      '<img src="x" onerror="window.__xss=1">',
      '<script>window.__xss=1</script>',
      '<p><a href="javascript:window.__xss=1">x</a></p>',
      '<iframe src="https://evil.example/"></iframe>',
      '<p>合法段落二</p>',
    ].join(''),
  });
  expect((await raw(page, 6)).description).toContain('onerror');

  await openProduct(page, 6);
  const root = description(page);
  const frame = await frameOf(root);
  await expect(frame.locator('p').filter({ hasText: /\S/ })).toHaveText(['合法段落一', 'x', '合法段落二']);
  await expect(frame.locator('a')).toHaveCount(1);
  expect(await frame.locator('a').getAttribute('href')).toBeNull();
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => (window as { __xss?: number }).__xss)).toBeUndefined();
  expect(await frame.evaluate(() => (window as { __xss?: number }).__xss)).toBeUndefined();
  const inside = await frame.evaluate(() => document.body.innerHTML);
  for (const bad of ['onerror', '<script', 'javascript:', 'evil.example']) expect(inside).not.toContain(bad);
  expect(evil).toEqual([]);

  await continueEditing(page);
  await save(page, 6);
  const stored = (await raw(page, 6)).description ?? '';
  expect(stored).toContain('合法段落一');
  expect(stored).toContain('合法段落二');
  for (const bad of ['onerror', '<script', 'javascript:', 'evil.example', '<iframe']) expect(stored).not.toContain(bad);
  expect(await page.evaluate(() => (window as { __xss?: number }).__xss)).toBeUndefined();
});

test('an emptied required FAQ answer blocks the save with the standard message', async ({ page }) => {
  await openProduct(page, 1);
  let writes = 0;
  page.on('request', (r) => {
    if (r.method() === 'PUT' && new URL(r.url()).pathname === '/admin/products/1') writes++;
  });
  const root = answer(page, 0);
  await (await frameOf(root)).locator('body').focus();
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.press('Backspace');
  await page.getByRole('button', { name: '儲存', exact: true }).click();
  await expect(page.getByText('請填寫回答。').first()).toBeVisible();
  await expect(root.locator('iframe.jodit-wysiwyg_iframe')).toHaveAttribute('aria-invalid', 'true');
  await page.waitForTimeout(300);
  expect(writes).toBe(0);
  expect((await raw(page, 1)).faqs?.[0].answer).toContain('不含香精與色素');
});

test('FAQ row editors are independent, save cleaned HTML per row and refuse images', async ({ page }) => {
  await openProduct(page, 1);
  const first = answer(page, 0);
  const second = answer(page, 1);
  await caretAt(page, first, 'p');
  await page.keyboard.type('（甲）');
  await expect.poll(async () => (await frameOf(second)).evaluate(() => document.body.innerHTML)).not.toContain('（甲）');

  await caretAt(page, second, 'li');
  await paste(second, [{ name: 'row.png', type: 'image/png', bytes: PNG }]);
  const toast = page.getByRole('status').filter({ hasText: '這個欄位不能貼上或拖放圖片檔。' });
  await expect(toast).toBeVisible();
  expect(await imgCount(second)).toBe(0);
  await toast.getByRole('button', { name: '關閉', exact: true }).click();

  const seeded = (await raw(page, 1)).faqs!;
  await continueEditing(page);
  await save(page, 1);
  const faqs = (await raw(page, 1)).faqs!;
  expect(faqs.map((f) => f.question)).toEqual(['敏感肌可以使用嗎？', '開封後可以放多久？']);
  expect(faqs[0].answer).toBe('<p>可以。配方（甲）<strong>不含香精與色素</strong>，第一次使用建議先在耳後試擦。</p>');
  // The untouched row is stored as the sanitiser cleans it (&nbsp; becomes U+00A0), nothing else.
  expect(faqs[1].answer).toBe(seeded[1].answer!.replaceAll('&nbsp;', ' '));
});

test('the ERP note is a read-only view: sandboxed, no editor, styled by the storefront sheet', async ({ page }) => {
  await page.goto('/admin/products/3/edit');
  await expect(page.getByRole('heading', { name: '編輯商品', exact: true })).toBeVisible();
  const view = page.getByTitle('ERP 備註');
  await expect(view).toBeVisible();
  const sandbox = (await view.getAttribute('sandbox')) ?? '';
  expect(sandbox).toContain('allow-same-origin');
  for (const token of ['allow-scripts', 'allow-forms', 'allow-top-navigation']) expect(sandbox).not.toContain(token);
  const frame = await (await view.elementHandle())!.contentFrame();
  await expect(frame!.locator('strong')).toHaveText('ERP 出貨備註');
  await expect(frame!.locator('li')).toHaveCount(2);
  await expect.poll(() => frame!.evaluate(() => getComputedStyle(document.querySelector('.font_lv5')!).fontSize)).toBe('15px');
  // Only the description is an editor here (DEMO-003 has no FAQ rows); the view loads none.
  await editorReady(description(page));
  await expect(page.locator('.jodit-container')).toHaveCount(1);
  await expect(page.locator('iframe[title="ERP 備註"].jodit-wysiwyg_iframe')).toHaveCount(0);
});

test('the product edit page with the editors has no axe violations', async ({ page }) => {
  test.setTimeout(90_000);
  await openProduct(page, 1);
  expect(await axeViolations(page)).toEqual([]);
  // The ERP view is a sandboxed iframe without scripts: axe cannot run inside it, the element is still
  // checked. Scoped to that field: pre-existing `aria-allowed-attr` on read-only fields; tracked in
  // docs/superpowers/phase-3-status.md.
  await page.goto('/admin/products/5/edit');
  await editorReady(description(page));
  const view = page.locator('[data-field-layout]').filter({ has: page.getByTitle('ERP 備註') });
  await expect(view).toHaveCount(1);
  const { violations } = await new AxeBuilder({ page }).include('[data-field-layout]:has(iframe[title="ERP 備註"])').exclude('iframe[sandbox]').analyze();
  expect(violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target.join(' ')) }))).toEqual([]);
});

// Linux baselines are generated in CI.
for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 390]) {
    test(`rich-text product editors ${scheme} ${width}: geometry, screenshots`, async ({ page }) => {
      const errors: string[] = [];
      page.on('pageerror', (error) => errors.push(error.message));
      await useScheme(page, scheme);
      await page.setViewportSize({ width, height: 900 });
      await openProduct(page, 1);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      const root = description(page);
      const fit = await root.evaluate((el) => {
        const box = el.getBoundingClientRect();
        const buttons = [...el.querySelectorAll('.jodit-toolbar-button')].map((b) => b.getBoundingClientRect());
        return {
          inside: buttons.every((b) => b.left >= box.left - 1 && b.right <= box.right + 1),
          work: el.querySelector('.jodit-workplace')?.getBoundingClientRect().height ?? 0,
        };
      });
      expect(fit.inside).toBe(true);
      expect(fit.work).toBeGreaterThan(300);
      for (const editorRoot of [root, answer(page, 0), answer(page, 1)])
        await (await frameOf(editorRoot)).evaluate(() => {
          (document.activeElement as HTMLElement | null)?.blur();
          getSelection()?.removeAllRanges();
        });
      await page.evaluate(() => document.fonts.ready);
      // Viewport shots with the block at the top, so the editor is seen in the form (the sticky save
      // bar stays at the bottom, below it).
      const atTop = async (target: Locator) => {
        // Below the sticky top bar.
        await target.evaluate((el) => {
          el.scrollIntoView({ block: 'start' });
          window.scrollBy(0, -72);
        });
        await page.waitForTimeout(100);
      };
      await atTop(page.getByRole('region', { name: '商品介紹', exact: true }));
      await expect(page).toHaveScreenshot(`rich-text-description-${scheme}-${width}.png`, { animations: 'disabled' });
      await atTop(page.getByRole('list', { name: '常見問題', exact: true }).getByRole('listitem').first());
      await expect(page).toHaveScreenshot(`rich-text-faq-${scheme}-${width}.png`, { animations: 'disabled' });
      expect(errors).toEqual([]);
    });
  }
}
