import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { axeViolations, login, useScheme } from './support';

test.use({ actionTimeout: 15000 });
const baseline = process.env.POLISH_BASELINE === '1';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGUlEQVQokWPkK5nAQApgIkn1qIZRDUNKAwB4twEycPdKNAAAAABJRU5ErkJggg==', 'base64');
async function capture(page: Page, info: TestInfo, name: string, fullPage = true) {
  await page.evaluate(() => document.fonts.ready);
  if (baseline) await page.screenshot({ path: info.outputPath(`${name}.png`), fullPage, animations: 'disabled' });
  else await expect(page).toHaveScreenshot(`${name}.png`, { fullPage, animations: 'disabled' });
}
async function accessible(page: Page) {
  if (!baseline) {
    expect(await axeViolations(page)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  }
}
for (const scheme of ['light', 'dark'] as const) {
  for (const width of [1440, 390]) {
    test(`polish states ${scheme} ${width}`, async ({ page }, info) => {
      test.setTimeout(120000);
      await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
      await useScheme(page, scheme);
      await login(page);
      await page.goto('/admin/grid-showcase/create');
      await expect(page.getByRole('heading', { name: '新增商品', exact: true })).toBeVisible();
      await accessible(page);
      await capture(page, info, `product-create-${scheme}-${width}`);
      await page.getByRole('button', { name: '儲存', exact: true }).click();
      const name = page.getByRole('textbox', { name: '品名', exact: true });
      await expect(name).toHaveAttribute('aria-invalid', 'true');
      await capture(page, info, `product-errors-${scheme}-${width}`);
      if (!baseline) {
        const link = page.getByRole('alert').getByRole('link').filter({ hasText: '品名' });
        await link.focus();
        await page.keyboard.press('Enter');
        await expect(name).toBeFocused();
        const focus = await name.evaluate(el => ({color:getComputedStyle(el).outlineColor, border:getComputedStyle(el).borderColor, width:getComputedStyle(el).outlineWidth}));
        expect(focus.color).toBe(focus.border);
        expect(focus.width).toBe('2px');
        await page.getByRole('textbox', {name:'秘密備註',exact:true}).scrollIntoViewIfNeeded();
        const last = await page.getByRole('textbox', {name:'秘密備註',exact:true}).boundingBox();
        const footer = await page.locator('[data-form-content] > div').last().boundingBox();
        expect(last!.y + last!.height).toBeLessThanOrEqual(footer!.y);
      }
      await accessible(page);
      let release!: () => void;
      const held = new Promise<void>(resolve => { release = resolve; });
      await page.route('**/grid-showcase/_brume/upload', async route => { await held; await route.continue(); });
      const cover = page.getByRole('group', { name: '封面', exact: true });
      await cover.locator('input[type=file]').setInputFiles({name:'product-preview.png',mimeType:'image/png',buffer:png});
      try {
        await expect(cover.getByRole('progressbar')).toBeVisible();
        await cover.scrollIntoViewIfNeeded();
        await capture(page, info, `product-uploading-${scheme}-${width}`, false);
        await accessible(page);
      } finally {
        // Never leave a paused network route pending during failure/teardown.
        release();
      }
      await expect(cover.getByRole('progressbar')).toHaveCount(0);
      await expect(cover.getByText('product-preview.png', {exact:true})).toBeVisible();
      await capture(page, info, `product-uploaded-${scheme}-${width}`, false);
      await accessible(page);
      await page.unroute('**/grid-showcase/_brume/upload');
      // Each screen gets a new document. Leaving via goto invokes beforeunload, accepted here.
      page.on('dialog', dialog => dialog.accept());
      await page.goto('/admin/compositions/1/edit');
      await expect(page.getByRole('heading', {name:'編輯關聯組合',exact:true})).toBeVisible();
      if (!baseline && width === 1440) {
        const zip = await page.getByRole('textbox',{name:'郵遞區號',exact:true}).boundingBox();
        const county = await page.getByRole('textbox',{name:'縣市',exact:true}).boundingBox();
        const district = await page.getByRole('textbox',{name:'鄉鎮市區',exact:true}).boundingBox();
        expect(Math.abs(zip!.y - county!.y)).toBeLessThan(2);
        expect(Math.abs(zip!.y - district!.y)).toBeLessThan(2);
        const content = await page.locator('[data-form-content]').boundingBox();
        expect(content!.width).toBeLessThanOrEqual(880);
      }
      await accessible(page);
      await capture(page, info, `composition-edit-${scheme}-${width}`);
      // Exclude timestamped rows created by earlier persistence tests from the modal backdrop.
      await page.goto('/admin/orders?q=BR-202609');
      await page.getByRole('button',{name:'新增訂單',exact:true}).click();
      await page.getByRole('dialog').getByRole('button',{name:'儲存',exact:true}).click();
      if (!baseline) {
        await expect(page.getByRole('button',{name:'上一筆',exact:true})).toHaveCount(0);
        await expect(page.getByRole('button',{name:'下一筆',exact:true})).toHaveCount(0);
      }
      await accessible(page);
      await capture(page, info, `order-modal-${scheme}-${width}`, false);
      if (!baseline) {
        await page.getByRole('dialog').getByRole('button', {name:'取消',exact:true}).click();
        await page.goto('/admin/grid-showcase/create');
        for (const [label,value] of [['品名','Focus test'],['貨號','focus-test'],['標語','Test'],['售價（NT$）','100'],['庫存','2'],['評分','4']]) {
          await page.getByRole('textbox',{name:label,exact:true}).fill(value,{timeout:10000});
        }
        await page.route('**/grid-showcase', route => route.request().method() === 'POST'
          ? route.fulfill({status:422,json:{errors:{status:['請重新選擇狀態'],secret_note:['秘密備註無法使用']}}}) : route.continue());
        await page.getByRole('button',{name:'儲存',exact:true}).click();
        const radio = page.getByRole('radio').first();
        await page.keyboard.press('Tab');
        await radio.focus();
        const radioFocus = await radio.evaluate(el => ({outline:getComputedStyle(el).outlineColor,danger:getComputedStyle(el).getPropertyValue('--br-color-danger').trim()}));
        // Compare with another invalid input's computed border; CSS custom colors may be hex.
        const secret = page.getByRole('textbox',{name:'秘密備註',exact:true});
        await expect(secret).toHaveCSS('border-color', radioFocus.outline);
        await expect(radio).toHaveCSS('border-color', radioFocus.outline);
        const lastLink = page.getByRole('alert').getByRole('link').filter({hasText:'秘密備註'});
        await lastLink.focus();
        await page.keyboard.press('Enter');
        await expect(secret).toBeFocused();
        const field = await secret.locator('xpath=ancestor::*[@data-field-layout][1]').boundingBox();
        const footer = await page.locator('[data-form-content] > div').last().boundingBox();
        expect(field!.y).toBeGreaterThanOrEqual(0);
        expect(field!.y+field!.height).toBeLessThanOrEqual(footer!.y);
        await capture(page, info, `product-error-focus-${scheme}-${width}`, false);
        await accessible(page);
        await page.goto('/admin/compositions/1/edit');
        await page.getByRole('textbox',{name:'地址',exact:true}).fill('街道測試');
        await page.route('**/compositions/1', route => ['PATCH','PUT','POST'].includes(route.request().method())
          ? route.fulfill({status:422,json:{errors:{'address.address':['請檢查街道地址']}}}) : route.continue());
        await page.getByRole('button',{name:'儲存',exact:true}).click();
        const addressLink = page.getByRole('alert').getByRole('link',{name:/請檢查街道地址/});
        await addressLink.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('textbox',{name:'地址',exact:true})).toBeFocused();
        await accessible(page);
      }

    });
  }
}
