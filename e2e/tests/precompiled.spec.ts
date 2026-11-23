import { expect, test } from '@playwright/test';
import { login } from './support';

// The committed dist/ (precompiled mode, as `brume:install` without --frontend serves it) in a real
// browser. Its page prints only the entry's tags; the rich text editor is a lazy chunk the entry
// imports relative to itself, with its own stylesheet. Runs against e2e/serve-precompiled.sh (the
// `precompiled` project's baseURL), which shares the database with the main server.

test('precompiled dist: the lazy editor chunk and its CSS load, the editor is themed and shows the seed', async ({ page, baseURL }) => {
  const origin = new URL(baseURL!).origin;
  const failed: string[] = [];
  const editorFiles: string[] = [];
  await page.route(/^https?:\/\/([a-z0-9-]+\.)*(youtube\.com|youtube-nocookie\.com)\//, (route) =>
    route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html lang="en"><title>stub</title></html>' }),
  );
  // Same-origin requests only: the stubbed YouTube frame can be cancelled when Jodit re-renders it.
  page.on('requestfailed', (r) => {
    if (new URL(r.url()).origin === origin) failed.push(`failed ${r.url()}`);
  });
  page.on('response', (r) => {
    const url = new URL(r.url());
    if (r.status() >= 400) failed.push(`${r.status()} ${r.url()}`);
    if (url.origin === origin && url.pathname.includes('/jodit-editor-'))
      editorFiles.push(`${r.status()} ${url.pathname} ${r.headers()['content-type'] ?? ''}`);
  });

  await login(page);
  const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  const reset = await page.request.post('/admin/_demo/rich-text/reset', {
    headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(reset.status()).toBe(200);

  await page.goto('/admin/products/1/edit');
  await expect(page.getByRole('heading', { name: '編輯商品', exact: true })).toBeVisible();

  // The page runs the precompiled entry, not the workbench's Vite build.
  const entry = await page.evaluate(() => [...document.querySelectorAll('script[type="module"][src]')].map((s) => new URL((s as HTMLScriptElement).src).pathname));
  expect(entry.some((p) => /^\/vendor\/brume\/assets\/app-[\w-]+\.js$/.test(p))).toBe(true);
  expect(entry.some((p) => p.includes('build-brume'))).toBe(false);

  const root = page.locator('.br-rich-text').filter({ has: page.locator('iframe[title="商品說明"]') });
  const iframe = root.locator('iframe.jodit-wysiwyg_iframe');
  await expect(iframe).toHaveCount(1, { timeout: 20_000 });
  await expect.poll(() => iframe.evaluate((f: HTMLIFrameElement) => f.contentDocument?.documentElement?.getAttribute('lang')), { timeout: 20_000 }).toBe('zh-TW');

  // The lazy JS and CSS came from the published dist/ with the right types.
  expect(editorFiles.some((f) => /^200 \/vendor\/brume\/assets\/jodit-editor-[\w-]+\.js (text|application)\/javascript/.test(f))).toBe(true);
  expect(editorFiles.some((f) => /^200 \/vendor\/brume\/assets\/jodit-editor-[\w-]+\.css text\/css/.test(f))).toBe(true);
  expect(await page.locator('link[rel="stylesheet"][href*="jodit-editor-"]').count()).toBeGreaterThan(0);

  // Themed toolbar: Jodit's and Brume's editor CSS applied (unstyled buttons would be plain inline buttons).
  await expect(root.locator('.jodit-container')).toHaveClass(/jodit_theme_brume/);
  const bold = root.locator('.jodit-toolbar-button_bold .jodit-toolbar-button__button');
  await expect(bold).toBeVisible();
  const style = await bold.evaluate((el) => {
    const s = getComputedStyle(el);
    const box = el.getBoundingClientRect();
    return { display: s.display, cursor: s.cursor, height: box.height, icon: el.querySelector('svg')?.getBoundingClientRect().width ?? 0 };
  });
  expect(style.display).toMatch(/flex/);
  expect(style.cursor).toBe('pointer');
  expect(style.height).toBeGreaterThanOrEqual(24);
  expect(style.height).toBeLessThanOrEqual(48);
  expect(style.icon).toBeGreaterThan(8);

  // The editing iframe shows the seeded sample, styled by the storefront stylesheet.
  const frame = await (await iframe.elementHandle())!.contentFrame();
  await expect(frame!.locator('h2')).toHaveText('霧島選物｜冬季保養組');
  await expect(frame!.locator('table.specTable')).toHaveCount(1);
  await expect.poll(() => frame!.evaluate(() => getComputedStyle(document.querySelector('.font_lv2')!).fontSize)).toBe('26px');

  expect(failed).toEqual([]);
});
