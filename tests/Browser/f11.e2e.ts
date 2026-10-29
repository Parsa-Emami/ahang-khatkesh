import {
    expect,
    test,
    type Page,
    type Locator,
    type TestInfo,
} from "@playwright/test";
import { mkdir, readFile } from "node:fs/promises";
async function login(
    page: Page,
    device: string,
    account = "history",
    initial = true,
) {
    if (initial) await page.goto("/");
    await page
        .getByLabel("ایمیل", { exact: true })
        .fill(`${account}-${device}@example.test`);
    await page
        .getByLabel("رمز عبور", { exact: true })
        .fill(process.env.AHANG_E2E_PASSWORD!);
    await page
        .getByRole("button", { name: "ورود به خط‌کش", exact: true })
        .click();
    await expect(page.locator(".player-bar")).toBeVisible({ timeout: 15000 });
}
async function navigate(page: Page, name: string) {
    const menu = page.getByRole("button", {
        name: "باز کردن منو",
        exact: true,
    });
    const mobile = await menu.isVisible();
    if (mobile) await menu.click();
    const link = page.getByRole("link", { name, exact: true });
    const target = new URL((await link.getAttribute("href"))!, page.url());
    const samePath = target.pathname === new URL(page.url()).pathname;
    await link.click();
    await expect(page).toHaveURL(target.href);
    const close = page.getByRole("button", { name: "بستن منو", exact: true });
    if (mobile && samePath) await close.click();
    if (mobile)
        await expect(page.locator(".sidebar")).not.toHaveClass(/is-open/);
}
async function headers(page: Page) {
    const { data } = await (await page.request.get("/api/v1/session")).json();
    return {
        "X-CSRF-TOKEN": data.csrf_token,
        "X-Session-Context": `${data.user.id}:${data.user.access_version}`,
        Accept: "application/json",
    };
}
async function logout(page: Page) {
    const quick = page.locator(".header-actions button").last();
    if (await quick.isVisible()) await quick.click();
    else {
        const menu = page.getByRole("button", {
            name: "باز کردن منو",
            exact: true,
        });
        if (await menu.isVisible()) await menu.click();
        await page.locator(".sidebar .user-button").click();
    }
    await page
        .getByRole("button", { name: "خروج از حساب", exact: true })
        .click();
    await expect(page.getByLabel("ایمیل", { exact: true })).toBeVisible();
}
async function privacy(page: Page, name: string) {
    const response = page.waitForResponse(
        (r) =>
            r.url().endsWith("/api/v1/listening/settings") &&
            r.request().method() === "PATCH",
    );
    await page
        .locator(".app-header")
        .getByRole("button", { name, exact: true })
        .click();
    expect((await response).status()).toBe(200);
}
async function checkbox(page: Page, control: Locator, checked: boolean) {
    await expect(control).toBeEnabled();
    const response = page.waitForResponse(
        (r) =>
            r.url().endsWith("/api/v1/listening/settings") &&
            r.request().method() === "PATCH",
    );
    await control.click();
    expect((await response).status()).toBe(200);
    if (checked) await expect(control).toBeChecked();
    else await expect(control).not.toBeChecked();
    await expect(control).toBeEnabled();
}
async function capture(page: Page, info: TestInfo, state: string) {
    await mkdir("reports/f11-privacy", { recursive: true });
    await page.screenshot({
        path: `reports/f11-privacy/${info.project.name}-${state}.png`,
        animations: "disabled",
    });
}
test("ListeningPrivacyTest native audio consent private broadcast export erase and cross-user query cache isolation", async ({
    page,
}, info) => {
    test.setTimeout(120000);
    page.setDefaultTimeout(15000);
    await page.addInitScript(() => {
        const Original = window.Audio;
        const created: HTMLAudioElement[] = [];
        Object.assign(window, { __f11Audio: created });
        window.Audio = function (src?: string) {
            const audio = new Original(src);
            created.push(audio);
            return audio;
        } as unknown as typeof Audio;
    });
    await login(page, info.project.name);
    await navigate(page, "تاریخچهٔ شنیدن");
    const consent = page.getByRole("checkbox", {
        name: "با ثبت تاریخچهٔ شنیدن خودم موافقم",
        exact: true,
    });
    await expect(consent).not.toBeChecked();
    const missingCsrf = await page.request.patch("/api/v1/listening/settings", {
        data: { revision: 0, history_enabled: true },
        headers: { Accept: "application/json" },
    });
    expect(missingCsrf.status()).toBe(419);
    await checkbox(page, consent, true);
    const sibling = await page.context().newPage();
    await sibling.goto("/history");
    await privacy(sibling, "شروع حالت خصوصی");
    await expect(
        page
            .locator(".app-header")
            .getByRole("button", { name: "پایان حالت خصوصی", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");
    await sibling.close();
    let grants = 0;
    page.on("request", (request) => {
        if (
            request.url().endsWith("/api/v1/listening/plays") &&
            request.method() === "POST"
        )
            grants++;
    });
    await navigate(page, "میکس برای تو");
    await page.getByRole("button", { name: "پخش میکس", exact: true }).click();
    await expect
        .poll(() =>
            page
                .getByLabel("موقعیت پخش", { exact: true })
                .inputValue()
                .then(Number),
        )
        .toBeGreaterThan(2);
    expect(grants).toBe(0);
    const snapshot = await (await page.request.get("/api/v1/playback")).json();
    expect(snapshot.data.queue).toEqual([]);
    await capture(page, info, "private");
    await privacy(page, "پایان حالت خصوصی");
    await expect.poll(() => grants).toBe(1);
    await expect
        .poll(
            () =>
                page
                    .getByLabel("موقعیت پخش", { exact: true })
                    .inputValue()
                    .then(Number),
            { timeout: 25000 },
        )
        .toBeGreaterThan(18);
    const saved = page.waitForResponse(
        (r) =>
            r.url().endsWith("/api/v1/listening/events") &&
            r.request().method() === "POST",
    );
    await page
        .locator(".player-bar")
        .getByRole("button", { name: "توقف پخش", exact: true })
        .click();
    expect((await saved).status()).toBe(200);
    const audio = await page.evaluate(() => {
        const list = (window as unknown as { __f11Audio: HTMLAudioElement[] })
            .__f11Audio;
        return { count: list.length, time: list[0].currentTime };
    });
    expect(audio.count).toBe(1);
    expect(audio.time).toBeGreaterThan(18);
    await navigate(page, "تاریخچهٔ شنیدن");
    await expect(page.locator(".listening-row")).toHaveCount(1);
    const row = (
        await (await page.request.get("/api/v1/listening/history")).json()
    ).data[0];
    const download = page.waitForEvent("download");
    await page
        .getByRole("button", { name: "دریافت خروجی JSON", exact: true })
        .click();
    const exported = JSON.parse(
        await readFile((await (await download).path())!, "utf8"),
    );
    expect(exported.format).toBe("ahang-listening-v1");
    expect(exported.data).toHaveLength(1);
    expect(exported.data[0].session_pseudonym).toMatch(/^[a-f0-9]{64}$/);
    await capture(page, info, "history");
    await logout(page);
    await login(page, info.project.name, "visual", false);
    await navigate(page, "تاریخچهٔ شنیدن");
    await expect(page.locator(".listening-row")).toHaveCount(0);
    const forbiddenDelete = await page.request.delete(
        `/api/v1/listening/history/${row.id}`,
        { data: { revision: 0 }, headers: await headers(page) },
    );
    expect(forbiddenDelete.status()).toBe(404);
    await logout(page);
    await login(page, info.project.name, "history", false);
    await navigate(page, "تاریخچهٔ شنیدن");
    await expect(page.locator(".listening-row")).toHaveCount(1);
    const eraser = await page.context().newPage();
    await eraser.goto("/history");
    await eraser
        .getByRole("button", { name: "پاک‌کردن کل تاریخچه", exact: true })
        .click();
    await eraser
        .getByRole("button", { name: "تأیید و پاک‌کردن", exact: true })
        .click();
    await expect(page.locator(".listening-row")).toHaveCount(0);
    expect(
        (await (await page.request.get("/api/v1/listening/export")).json())
            .data,
    ).toEqual([]);
    await eraser.close();
    await checkbox(page, consent, false);
    for (const width of [320, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const direction of ["rtl", "ltr"]) {
            await page.evaluate((dir) => {
                document.documentElement.dir = dir;
            }, direction);
            expect(
                await page
                    .locator(".main-content")
                    .evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
            ).toBe(true);
        }
    }
});
test("ExplainableMixTest curated reasons liked signals opt-out virtual playback and no catalog writes", async ({
    page,
}, info) => {
    page.setDefaultTimeout(15000);
    await login(page, info.project.name);
    const before = (await (await page.request.get("/api/v1/playlists")).json())
        .data;
    await navigate(page, "میکس برای تو");
    await expect(page.locator(".listening-row").first()).toBeVisible();
    await page.locator("summary").first().click();
    await expect(page.locator("details[open]")).toContainText(
        "از انتخاب‌های کتابخانه",
    );
    const mix = (await (await page.request.get("/api/v1/listening/mix")).json())
        .data;
    const like = await page.request.put(
        `/api/v1/tracks/${mix[0].track.id}/like`,
        { headers: await headers(page) },
    );
    expect(like.status()).toBe(200);
    await navigate(page, "تاریخچهٔ شنیدن");
    await navigate(page, "میکس برای تو");
    await page.locator("summary").first().click();
    await expect(page.locator("details[open]")).toContainText(
        "خودت این آهنگ را پسندیده‌ای",
    );
    await capture(page, info, "mix");
    await page.getByRole("button", { name: "پخش میکس", exact: true }).click();
    await expect(
        page
            .locator(".player-bar")
            .getByRole("button", { name: "توقف پخش", exact: true }),
    ).toBeVisible();
    const after = (await (await page.request.get("/api/v1/playlists")).json())
        .data;
    expect(after.map((p: { id: number }) => p.id)).toEqual(
        before.map((p: { id: number }) => p.id),
    );
    await navigate(page, "تاریخچهٔ شنیدن");
    const recommendations = page.getByRole("checkbox", {
        name: "پیشنهاد موسیقی برای من فعال باشد",
        exact: true,
    });
    await checkbox(page, recommendations, false);
    await navigate(page, "میکس برای تو");
    await expect(
        page.getByText("پیشنهاد موسیقی را خاموش کرده‌ای.", { exact: true }),
    ).toBeVisible();
    await expect(page.locator(".listening-row")).toHaveCount(0);
    await expect(
        page.getByRole("button", { name: "پخش میکس", exact: true }),
    ).toBeDisabled();
    await capture(page, info, "opt-out");
});
