import { expect, test, type Page } from "@playwright/test";
import { axeViolations, login, useScheme } from "./support";

// Dedicated published-package acceptance project after forms; every case owns its seeded composition ID.
// Resource options/save/upload/cell endpoints are real, with no AJAX mocks.
test.beforeEach(async ({ page }) => {
  await login(page);
});

async function edit(page: Page, id: number) {
  await page.goto(`/admin/compositions/${id}/edit`);
  await expect(page.getByRole("textbox", { name: "名稱", exact: true })).toHaveValue(
    `關聯組合 ${id}`,
  );
}
async function save(page: Page, id: number) {
  await page.getByRole("combobox", { name: "儲存後", exact: true }).click();
  await page.getByRole("option", { name: "繼續編輯", exact: true }).click();
  const response = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname === `/admin/compositions/${id}` &&
      response.request().method() === "PUT",
  );
  await page.getByRole("button", { name: "儲存", exact: true }).click();
  return response;
}

for (const [schemeIndex, scheme] of (["light", "dark"] as const).entries()) {
  for (const [widthIndex, width] of [1440, 390].entries()) {
    test(`composition custom fields, JSON, axe and geometry ${scheme} ${width}`, async ({
      page,
    }) => {
      const errors: string[] = [];
      page.on("pageerror", (error) => errors.push(error.message));
      page.on("console", (message) => {
        if (message.type() === "error" || message.type() === "warning")
          errors.push(message.text());
      });
      await useScheme(page, scheme);
      await page.setViewportSize({ width, height: 900 });
      const id = 1 + schemeIndex * 2 + widthIndex;
      await edit(page, id);
      await expect(page.getByRole("textbox", { name: "配送說明", exact: true })).toHaveCount(0);
      await page.getByRole("textbox", { name: "地址", exact: true }).fill(`瀏覽器地址 ${id}`);
      await page
        .getByRole("textbox", { name: "備註內容", exact: true })
        .first()
        .fill(`JSON 值 ${id}`);
      expect((await save(page, id)).status()).toBe(200);
      await page.reload();
      await expect(page.getByRole("textbox", { name: "地址", exact: true })).toHaveValue(
        `瀏覽器地址 ${id}`,
      );
      await expect(
        page.getByRole("textbox", { name: "備註內容", exact: true }).first(),
      ).toHaveValue(`JSON 值 ${id}`);
      expect(await page.evaluate(() => window.innerWidth)).toBe(width);
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
      ).toBeLessThanOrEqual(width);
      expect(await axeViolations(page)).toEqual([]);
      expect(errors).toEqual([]);
      await page.evaluate(() => window.scrollTo(0, 0));
      await expect(
        page.getByRole("heading", { name: "編輯關聯組合", exact: true }),
      ).toBeVisible();
      await expect(page).toHaveScreenshot(`composition-${scheme}-${width}.png`, {
        animations: "disabled",
      });
    });
  }
}

test("nested picker paging/cancel, server extras 422 and upload tokens follow their stable row", async ({
  page,
}) => {
  await edit(page, 5);
  const picker = page.getByRole("button", { name: /^贈品 ·/ });
  await picker.click();
  const quantity = page.getByRole("textbox", { name: "贈品數量", exact: true });
  await quantity.fill("4");
  await page.getByRole("button", { name: "下一頁", exact: true }).click();
  await expect(page.getByRole("checkbox", { name: /商品 21/ })).toBeVisible();
  await page.getByRole("button", { name: "取消", exact: true }).click();
  await picker.click();
  await expect(quantity).toHaveValue("2");
  await quantity.fill("9");
  await page.getByRole("button", { name: "套用", exact: true }).click();
  expect((await save(page, 5)).status()).toBe(422);
  await expect(quantity).toHaveValue("9");
  await expect(quantity).toHaveAttribute("aria-invalid", "true");
  await expect(page.getByText("贈品數量不可超過 5。").first()).toBeVisible();
  await quantity.fill("4");
  // Picker extra upload sends ancestorSources and exact verified context through normal endpoint.
  await page.evaluate(() => {
    const original = window.fetch;
    window.fetch = (input, init) => {
      if (init?.body instanceof FormData) {
        sessionStorage.setItem(
          "composition-upload-fields",
          JSON.stringify(
            Object.fromEntries(
              [...init.body.entries()].filter(([, value]) => typeof value === "string"),
            ),
          ),
        );
      }
      return original(input, init);
    };
  });
  const upload = page.waitForResponse(
    (response) =>
      response.url().includes("/_brume/upload") && response.request().method() === "POST",
  );
  await page
    .getByRole("group", { name: "贈品附件", exact: true })
    .locator("input[type=file]")
    .setInputFiles({
      name: "gift.txt",
      mimeType: "text/plain",
      buffer: Buffer.from("gift source context"),
    });
  const uploaded = await upload;
  expect(uploaded.status()).toBe(200);
  const multipart = JSON.parse(
    await page.evaluate(() => sessionStorage.getItem("composition-upload-fields") ?? "{}"),
  );
  expect(JSON.parse(multipart.ancestorSources)).toEqual(
    expect.arrayContaining([expect.objectContaining({ path: "tiers.*.thresholds.*.gifts" })]),
  );
  expect(JSON.parse(multipart.rowContext)).toHaveLength(3);
  await expect(page.getByText("gift.txt", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "套用", exact: true }).click();
  expect((await save(page, 5)).status()).toBe(200);
  await page.reload();
  await picker.click();
  await expect(quantity).toHaveValue("4");
  const fresh = await page.request.get("/admin/compositions/5/edit", {
    headers: { "X-Brume-Form": "1" },
  });
  expect(fresh.status()).toBe(200);
  const stored = (await fresh.json()).page.values.tiers[0].thresholds[0].gifts[0].attachment;
  expect(stored.path).toMatch(/^brume\/brume\.compositions\/.+\.txt$/);
  expect(stored.token).toBeUndefined();
  await expect(page.getByText(stored.path, { exact: true })).toBeVisible();
});

test("Grid single/multiple picker filters use ID queries and custom inline editor round trips", async ({
  page,
}) => {
  await page.goto("/admin/compositions");
  await page.getByRole("button", { name: "篩選", exact: true }).click();
  const single = page.getByRole("group", { name: "單選商品", exact: true });
  await single.getByRole("button", { name: /單選商品/ }).click();
  await single.getByRole("radio", { name: /商品 01$/ }).check();
  await single.getByRole("button", { name: "套用", exact: true }).click();
  const multiple = page.getByRole("group", { name: "多選商品", exact: true });
  await multiple.getByRole("button", { name: /多選商品/ }).click();
  await multiple.getByRole("checkbox", { name: /商品 01$/ }).check();
  await multiple.getByRole("checkbox", { name: /商品 02$/ }).check();
  await multiple.getByRole("button", { name: "套用", exact: true }).click();
  await page.getByRole("button", { name: "套用篩選", exact: true }).click();
  await expect(page).toHaveURL(/filter/);
  const query = new URL(page.url()).searchParams;
  expect(query.get("filter[owner]")).toBe("1");
  expect(
    [...query.entries()]
      .filter(([key]) => /^filter\[owners\]\[\d*\]$/.test(key))
      .map(([, value]) => value),
  ).toEqual(["1", "2"]);
  await page.goto("/admin/compositions");
  const row = page
    .locator("tbody tr")
    .filter({ has: page.getByRole("link", { name: "關聯組合 6", exact: true }) });
  const addressCell = row.getByRole("cell").filter({ hasText: "中山南路1號" });
  await expect(
    addressCell.getByText("100, 臺北市, 中正區, 中山南路1號", { exact: true }),
  ).toBeVisible();
  await expect(addressCell).not.toContainText("{");
  const editAddress = row.getByRole("button", { name: /編輯.*地址/ });
  await expect(editAddress).not.toHaveAttribute("aria-label", /\{/);
  await editAddress.click();
  await page.getByRole("textbox", { name: "地址", exact: true }).fill("取消的行內地址");
  await page.getByRole("button", { name: "取消", exact: true }).click();
  await expect(
    addressCell.getByText("100, 臺北市, 中正區, 中山南路1號", { exact: true }),
  ).toBeVisible();
  await expect(addressCell).not.toContainText("{");
  await editAddress.click();
  await page.getByRole("textbox", { name: "地址", exact: true }).fill("行內編輯地址");
  const changed = page.waitForResponse((response) => response.request().method() === "PATCH");
  await page.getByRole("button", { name: "儲存", exact: true }).click();
  expect((await changed).status()).toBe(200);
  const savedAddress = "100, 臺北市, 中正區, 行內編輯地址";
  await expect(row.getByText(savedAddress, { exact: true })).toBeVisible();
  await expect(row).not.toContainText("{");
  await page.reload();
  await expect(row.getByText(savedAddress, { exact: true })).toBeVisible();
  await expect(row).not.toContainText("{");
});

test("custom address labels follow the public locale in all three languages", async ({
  page,
}) => {
  await edit(page, 8);
  for (const name of ["郵遞區號", "縣市", "鄉鎮市區", "地址"])
    await expect(page.getByRole("textbox", { name, exact: true })).toBeVisible();
  await page.getByRole("button", { name: "語言", exact: true }).click();
  await page.getByRole("menuitemradio", { name: "English", exact: true }).click();
  for (const name of ["Zip code", "County / city", "District", "Street address"])
    await expect(page.getByRole("textbox", { name, exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Language", exact: true }).click();
  await page.getByRole("menuitemradio", { name: "简体中文", exact: true }).click();
  for (const name of ["邮政编码", "县市", "乡镇市区", "地址"])
    await expect(page.getByRole("textbox", { name, exact: true })).toBeVisible();
});

async function storedGifts(page: Page, id: number) {
  const response = await page.request.get(`/admin/compositions/${id}/edit`, {
    headers: { "X-Brume-Form": "1" },
  });
  expect(response.status()).toBe(200);
  return (await response.json()).page.values.tiers[0].thresholds;
}

function thresholds(page: Page) {
  return page.getByRole("list", { name: "門檻", exact: true }).locator(":scope > li");
}

test("persistent gift branches validate active fields and reload inactive stored scalars and product relations", async ({ page }) => {
  await edit(page, 9);
  const before = await storedGifts(page, 9);
  const first = thresholds(page).nth(0);
  const second = thresholds(page).nth(1);
  await first.getByRole("radio", { name: "優惠券", exact: true }).check();
  await first.getByRole("textbox", { name: "優惠券代碼", exact: true }).fill("COUPON-9");
  await first.getByRole("textbox", { name: "優惠券金額", exact: true }).fill("25.50");
  await first.getByLabel("優惠券到期時間", { exact: true }).fill("2030-12-31T18:00");
  await second.getByRole("radio", { name: "點數", exact: true }).check();
  await second.getByRole("textbox", { name: "贈送點數", exact: true }).fill("125");
  await second.getByRole("textbox", { name: "點數備註", exact: true }).fill("活動點數");
  // A→B→A preserves draft values before save, with independent row conditions.
  await first.getByRole("radio", { name: "商品", exact: true }).check();
  await expect(second.getByRole("textbox", { name: "贈送點數", exact: true })).toHaveValue("125");
  await first.getByRole("radio", { name: "優惠券", exact: true }).check();
  await expect(first.getByRole("textbox", { name: "優惠券代碼", exact: true })).toHaveValue("COUPON-9");
  expect((await save(page, 9)).status()).toBe(200);
  await page.reload();
  await expect(first.getByRole("radio", { name: "優惠券", exact: true })).toBeChecked();
  await expect(second.getByRole("radio", { name: "點數", exact: true })).toBeChecked();
  const accepted = await storedGifts(page, 9);
  expect(accepted[0].gifts).toEqual(before[0].gifts);
  expect(accepted[0].coupon_amount).toBe("25.50");
  expect(accepted[1].coupon_code).toBe("DEMO-9");
  expect(accepted[1].points).toBe(125);
  // Hidden unsaved coupon text must not replace the stored coupon when product is active.
  await first.getByRole("textbox", { name: "優惠券代碼", exact: true }).fill("HIDDEN-UNSAVED");
  await first.getByRole("radio", { name: "商品", exact: true }).check();
  expect((await save(page, 9)).status()).toBe(200);
  await page.reload();
  await first.getByRole("radio", { name: "優惠券", exact: true }).check();
  await expect(first.getByRole("textbox", { name: "優惠券代碼", exact: true })).toHaveValue("COUPON-9");
  await first.getByRole("textbox", { name: "優惠券代碼", exact: true }).fill("");
  // Client validation blocks empty active coupon fields before a request.
  await page.getByRole("button", { name: "儲存", exact: true }).click();
  await expect(first.getByRole("textbox", { name: "優惠券代碼", exact: true })).toHaveAttribute("aria-invalid", "true");
  await first.getByRole("textbox", { name: "優惠券代碼", exact: true }).fill("CORRECTED-9");
  expect((await save(page, 9)).status()).toBe(200);
  await page.reload();
  await expect(first.getByRole("textbox", { name: "優惠券代碼", exact: true })).toHaveValue("CORRECTED-9");
});

test("persistent gift threshold sorting preserves IDs and a newly added owner chooses its type explicitly", async ({ page }) => {
  await edit(page, 10);
  const before = await storedGifts(page, 10);
  await thresholds(page).nth(0).getByRole("radio", { name: "點數", exact: true }).check();
  await thresholds(page).nth(0).getByRole("textbox", { name: "贈送點數", exact: true }).fill("77");
  await page.getByRole("button", { name: "下移 門檻 1", exact: true }).click();
  await expect(thresholds(page).nth(0).getByRole("radio", { name: "優惠券", exact: true })).toBeChecked();
  await expect(thresholds(page).nth(1).getByRole("textbox", { name: "贈送點數", exact: true })).toHaveValue("77");
  expect((await save(page, 10)).status()).toBe(200);
  await page.reload();
  const sorted = await storedGifts(page, 10);
  expect(sorted.map((row: { _brume: { id: string } }) => row._brume.id)).toEqual([before[1]._brume.id, before[0]._brume.id]);
  expect(sorted[1].points).toBe(77);
  expect(sorted[1].gifts).toEqual(before[0].gifts);
  await page.getByRole("button", { name: "新增 門檻", exact: true }).click();
  const added = thresholds(page).nth(2);
  // Published alpha14 creates an empty row draft; defaultValue is not an initializer.
  await expect(added.getByRole("radio", { name: "商品", exact: true })).not.toBeChecked();
  await added.getByRole("textbox", { name: "門檻名稱", exact: true }).fill("新增點數門檻");
  await added.getByRole("textbox", { name: "門檻金額", exact: true }).fill("300.00");
  await added.getByRole("radio", { name: "點數", exact: true }).check();
  await added.getByRole("textbox", { name: "贈送點數", exact: true }).fill("55");
  expect((await save(page, 10)).status()).toBe(200);
  await page.reload();
  await expect(thresholds(page).nth(2).getByRole("radio", { name: "點數", exact: true })).toBeChecked();
  await expect(thresholds(page).nth(2).getByRole("textbox", { name: "贈送點數", exact: true })).toHaveValue("55");
  const fresh = await storedGifts(page, 10);
  expect(fresh[2]._brume.id).not.toBe(before[0]._brume.id);
  expect(fresh[2]._brume.id).not.toBe(before[1]._brume.id);
  expect(fresh[2].gifts).toEqual([]);
});
