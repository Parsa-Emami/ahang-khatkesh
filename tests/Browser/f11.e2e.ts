import { expect, test, type Page } from "@playwright/test";
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
    if (await menu.isVisible()) await menu.click();
    await page.getByRole("link", { name, exact: true }).click();
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
    await page.locator(".header-actions button").last().click();
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
test("ListeningPrivacyTest native audio consent private broadcast export erase and cross-user query cache isolation", async ({
    page,
}, info) => {
    test.setTimeout(120000);
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
    await consent.check();
    await expect(consent).toBeEnabled();
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
    await consent.uncheck();
    await expect(consent).toBeEnabled();
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
    await mkdir("reports/f11-privacy", { recursive: true });
    await page.screenshot({
        path: `reports/f11-privacy/${info.project.name}-history.png`,
    });
});
test("ExplainableMixTest curated reasons liked signals opt-out virtual playback and no catalog writes", async ({
    page,
}, info) => {
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
    await recommendations.uncheck();
    await expect(recommendations).toBeEnabled();
    await navigate(page, "میکس برای تو");
    await expect(
        page.getByText("پیشنهاد موسیقی را خاموش کرده‌ای.", { exact: true }),
    ).toBeVisible();
    await expect(page.locator(".listening-row")).toHaveCount(0);
    await expect(
        page.getByRole("button", { name: "پخش میکس", exact: true }),
    ).toBeDisabled();
    await mkdir("reports/f11-privacy", { recursive: true });
    await page.screenshot({
        path: `reports/f11-privacy/${info.project.name}-opt-out.png`,
    });
});
