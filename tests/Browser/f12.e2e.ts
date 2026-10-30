import { expect, test, type Page, type TestInfo } from "@playwright/test";
import { mkdir } from "node:fs/promises";
async function login(page: Page, device: string, owner = false, code = 36) {
    await page.goto("/");
    await page
        .getByLabel("ایمیل", { exact: true })
        .fill(`${owner ? "owner" : "history"}-${device}@example.test`);
    await page
        .getByLabel("رمز عبور", { exact: true })
        .fill(process.env.AHANG_E2E_PASSWORD!);
    await page
        .getByRole("button", { name: "ورود به خط‌کش", exact: true })
        .click();
    if (owner) {
        await page
            .getByRole("button", { name: "استفاده از کد بازیابی", exact: true })
            .click();
        await page
            .getByLabel("کد بازیابی", { exact: true })
            .fill(`browser-recovery-${code}`);
        await page
            .getByRole("button", { name: "ورود به خط‌کش", exact: true })
            .click();
    }
    await expect(page.locator(".player-bar")).toBeVisible({ timeout: 15000 });
}
async function headers(page: Page) {
    const { data } = await (await page.request.get("/api/v1/session")).json();
    return {
        "X-CSRF-TOKEN": data.csrf_token,
        "X-Session-Context": `${data.user.id}:${data.user.access_version}`,
        Accept: "application/json",
    };
}
async function capture(page: Page, info: TestInfo, state: string) {
    const review = page.locator(".catalog-review");
    if (await review.count())
        await review.evaluate((node) => {
            node.scrollTop = 0;
        });
    else if (state.startsWith("member-"))
        await page
            .locator(".proposal-list article")
            .first()
            .scrollIntoViewIfNeeded();
    else if (state === "workflows")
        await page.locator(".workflow-card").first().scrollIntoViewIfNeeded();
    await mkdir("reports/f12-catalog", { recursive: true });
    await page.screenshot({
        path: `reports/f12-catalog/${info.project.name}-${state}.png`,
        animations: "disabled",
    });
}
async function closeReview(page: Page) {
    await page
        .locator(".catalog-review")
        .getByRole("button", { name: "بستن پنجره", exact: true })
        .click();
}
async function commit(page: Page) {
    const response = page.waitForResponse(
        (r) =>
            /\/catalog-changes\/[^/]+\/commit$/.test(r.url()) &&
            r.request().method() === "POST",
    );
    await page
        .getByRole("button", { name: "تأیید و اعمال تغییرات", exact: true })
        .click();
    const result = await response;
    expect(result.status()).toBe(200);
    await expect(
        page.locator(".catalog-review").getByRole("status"),
    ).toHaveText("تغییرات اعمال شد");
    return (await result.json()).data;
}
async function undo(page: Page) {
    await page
        .getByRole("button", { name: "بازگردانی این تغییر", exact: true })
        .click();
    const response = page.waitForResponse(
        (r) =>
            /\/catalog-changes\/[^/]+\/rollback$/.test(r.url()) &&
            r.request().method() === "POST",
    );
    await page
        .getByRole("button", { name: "تأیید بازگردانی", exact: true })
        .click();
    expect((await response).status()).toBe(200);
    await expect(
        page.locator(".catalog-review").getByRole("status"),
    ).toHaveText("تغییرات بازگردانی شد");
}
test("MemberProposalTest real CSRF member submission owner preview draft acceptance undo and rejection", async ({
    page,
    browser,
}, info) => {
    test.setTimeout(90000);
    page.setDefaultTimeout(15000);
    await login(page, info.project.name);
    await page.goto("/requests");
    const missing = await page.request.post("/api/v1/member-proposals", {
        data: {},
        headers: { Accept: "application/json" },
    });
    expect(missing.status()).toBe(419);
    const forbidden = await page.request.post(
        "/api/v1/admin/catalog-changes/preview",
        {
            data: { operation: "metadata", rows: [] },
            headers: await headers(page),
        },
    );
    expect(forbidden.status()).toBe(403);
    await page
        .getByLabel("نام پلی‌لیست", { exact: true })
        .fill(`F12 member ${info.project.name}`);
    await page.locator(".catalog-picker button").first().click();
    const submission = page.waitForResponse(
        (r) =>
            r.url().endsWith("/api/v1/member-proposals") &&
            r.request().method() === "POST",
    );
    await page
        .getByRole("button", { name: "ارسال برای بررسی مالک", exact: true })
        .click();
    const submitted = await submission;
    expect(submitted.status()).toBe(201);
    const proposal = (await submitted.json()).data;
    await expect(page.locator(".proposal-list")).toContainText(
        `F12 member ${info.project.name}`,
    );
    await capture(page, info, "member-pending");
    const ownerContext = await browser.newContext({
        viewport: page.viewportSize(),
        isMobile: info.project.name === "mobile",
        hasTouch: info.project.name === "mobile",
    });
    const owner = await ownerContext.newPage();
    owner.setDefaultTimeout(15000);
    try {
        await login(owner, info.project.name, true);
        await owner.goto("/admin?tab=overview");
        await expect(owner.locator(".workflow-grid")).toContainText(
            "صندوق پیشنهاد اعضا",
        );
        await capture(owner, info, "workflows");
        await owner.goto("/admin?tab=proposals");
        const card = owner
            .locator(".proposal-list article")
            .filter({ hasText: `F12 member ${info.project.name}` });
        await expect(card.getByRole("checkbox")).not.toBeChecked();
        await card
            .getByRole("button", { name: "پیش‌نمایش پذیرش", exact: true })
            .click();
        await expect(owner.locator(".change-comparison")).toContainText(
            "پیش‌نویس",
        );
        await capture(owner, info, "proposal-preview");
        const accepted = await commit(owner);
        const memberResult = await (
            await page.request.get(`/api/v1/member-proposals/${proposal.id}`)
        ).json();
        expect(memberResult.data.status).toBe("accepted");
        expect(memberResult.data.result_playlist_id).toBeNull();
        expect(
            (
                await page.request.get(
                    `/api/v1/playlists/${accepted.result.created_playlist_id}`,
                )
            ).status(),
        ).toBe(404);
        await undo(owner);
        await closeReview(owner);
        await page.reload();
        await page
            .getByRole("combobox", { name: "وضعیت", exact: true })
            .selectOption("rejected");
        await expect(page.locator(".proposal-list")).toContainText(
            "پذیرش این پیشنهاد بازگردانی شده است.",
        );
        await capture(page, info, "member-undone");
        await page
            .getByLabel("نوع پیشنهاد", { exact: true })
            .selectOption("track_metadata");
        await page
            .getByLabel("عنوان پیشنهادی", { exact: true })
            .fill(`F12 suggestion ${info.project.name}`);
        await page.locator(".catalog-picker button").first().click();
        await page
            .getByRole("button", { name: "ارسال برای بررسی مالک", exact: true })
            .click();
        await expect(
            page.getByLabel("عنوان پیشنهادی", { exact: true }),
        ).toHaveValue("");
        await owner.reload();
        const next = owner
            .locator(".proposal-list article")
            .filter({ hasText: `F12 suggestion ${info.project.name}` });
        await next
            .getByRole("button", { name: "رد پیشنهاد", exact: true })
            .click();
        await owner
            .getByLabel("دلیل رد", { exact: true })
            .fill("لطفاً مرجع معتبر عنوان را ارسال کنید.");
        await owner
            .getByRole("button", { name: "تأیید رد پیشنهاد", exact: true })
            .click();
        await expect(owner.getByRole("dialog")).toHaveCount(0);
        await page.reload();
        await page
            .getByRole("combobox", { name: "وضعیت", exact: true })
            .selectOption("rejected");
        await expect(page.locator(".proposal-list")).toContainText(
            "لطفاً مرجع معتبر عنوان را ارسال کنید.",
        );
    } finally {
        await ownerContext.close();
    }
});
test("CatalogBulkChangeTest browser bulk draft conflict merge retained ids and missing source repair", async ({
    page,
}, info) => {
    test.setTimeout(120000);
    page.setDefaultTimeout(15000);
    await login(page, info.project.name, true, 37);
    const tracks = (
        await (
            await page.request.get(
                `/api/v1/admin/tracks?q=F12%20${info.project.name}`,
            )
        ).json()
    ).data;
    const root = tracks.find((t: { title: string }) =>
        t.title.endsWith(" Root"),
    );
    const duplicate = tracks.find((t: { title: string }) =>
        t.title.endsWith(" Duplicate"),
    );
    const missing = tracks.find((t: { title: string }) =>
        t.title.endsWith(" Missing"),
    );
    expect(root).toBeTruthy();
    await page.goto("/admin?tab=catalog&workflow=bulk");
    await page
        .getByLabel("افزودن آهنگ از کاتالوگ", { exact: true })
        .fill(root.title);
    await page.locator(".picker-results button").first().click();
    const selected = page.locator(".catalog-selection > li").first();
    await selected
        .getByLabel("عنوان آهنگ", { exact: true })
        .fill(`${root.title} edited`);
    const refresh = await page.request.patch(
        `/api/v1/admin/tracks/${root.id}`,
        {
            data: {
                revision: root.revision,
                title: `${root.title} remote`,
                primary_release_id: root.primary_release_id,
                credits: root.artists.map(
                    (a: { id: number; role: string }) => ({
                        artist_id: a.id,
                        role: a.role,
                    }),
                ),
            },
            headers: await headers(page),
        },
    );
    expect(refresh.status()).toBe(200);
    await page
        .getByRole("button", { name: "پیش‌نمایش تغییرات", exact: true })
        .click();
    await expect(page.getByRole("alert").first()).toBeVisible();
    await expect(
        selected.getByLabel("عنوان آهنگ", { exact: true }),
    ).toHaveValue(`${root.title} edited`);
    await capture(page, info, "stale-draft");
    await page
        .getByRole("button", { name: "پاک‌کردن انتخاب‌ها", exact: true })
        .click();
    await page
        .getByLabel("افزودن آهنگ از کاتالوگ", { exact: true })
        .fill(`${root.title} remote`);
    await page.locator(".picker-results button").first().click();
    await selected
        .getByLabel("عنوان آهنگ", { exact: true })
        .fill(`${root.title} edited`);
    await page
        .getByRole("button", { name: "پیش‌نمایش تغییرات", exact: true })
        .click();
    await expect(page.locator(".catalog-review")).toBeVisible();
    await commit(page);
    await undo(page);
    await closeReview(page);
    await page.goto("/admin?tab=catalog&workflow=health");
    await page
        .getByLabel("جست‌وجوی آهنگ", { exact: true })
        .fill(`F12 ${info.project.name}`);
    const healthRoot = page.locator(".catalog-health-list article").filter({
        has: page.getByRole("heading", {
            name: `${root.title} remote · #${root.id}`,
            exact: true,
        }),
    });
    await healthRoot
        .getByRole("button", { name: "بررسی ادغام", exact: true })
        .click();
    const dialog = page.getByRole("dialog").first();
    await dialog.getByRole("checkbox").check();
    await dialog
        .getByLabel("هویت ضبط را دوباره وارد کنید", { exact: true })
        .fill(root.recording_identity);
    await dialog
        .getByLabel("مرجع ضبط را دوباره وارد کنید", { exact: true })
        .fill(root.recording_provenance);
    await dialog
        .getByRole("button", { name: "پیش‌نمایش تغییرات", exact: true })
        .click();
    const merged = await commit(page);
    expect(
        merged.summary.some(
            (s: { entity: string }) => s.entity === "recording_merge",
        ),
    ).toBe(true);
    const retained = await page.request.get(
        `/api/v1/tracks/${duplicate.id}/stream`,
        { headers: { Range: "bytes=0-31" } },
    );
    expect(retained.status()).toBe(206);
    await capture(page, info, "merged");
    await undo(page);
    await closeReview(page);
    await dialog
        .getByRole("button", { name: "بستن پنجره", exact: true })
        .click();
    const healthMissing = page
        .locator(".catalog-health-list article")
        .filter({ hasText: missing.title });
    await expect(healthMissing).toContainText("منبع صوتی مفقود");
    await healthMissing
        .getByRole("button", { name: "بررسی ترمیم", exact: true })
        .click();
    await expect(
        page.getByRole("dialog", { name: "ترمیم منبع صوتی", exact: true }),
    ).toBeVisible();
    const wave = Buffer.alloc(44 + 16000 * 4 * 2);
    wave.write("RIFF", 0);
    wave.writeUInt32LE(wave.length - 8, 4);
    wave.write("WAVEfmt ", 8);
    wave.writeUInt32LE(16, 16);
    wave.writeUInt16LE(1, 20);
    wave.writeUInt16LE(1, 22);
    wave.writeUInt32LE(16000, 24);
    wave.writeUInt32LE(32000, 28);
    wave.writeUInt16LE(2, 32);
    wave.writeUInt16LE(16, 34);
    wave.write("data", 36);
    wave.writeUInt32LE(wave.length - 44, 40);
    for (let i = 44; i < wave.length; i += 2)
        wave.writeInt16LE(info.project.name === "desktop" ? 101 : 102, i);
    const uploaded = page.waitForResponse(
        (r) =>
            r.url().endsWith("/api/v1/admin/media") &&
            r.request().method() === "POST",
    );
    await page
        .getByLabel("بارگذاری فایل جایگزین", { exact: true })
        .setInputFiles({
            name: "replacement.wav",
            mimeType: "audio/wav",
            buffer: wave,
        });
    expect((await uploaded).status()).toBe(201);
    await page
        .getByLabel("هویت ضبط را دوباره وارد کنید", { exact: true })
        .fill(missing.recording_identity);
    await page
        .getByLabel("مرجع ضبط را دوباره وارد کنید", { exact: true })
        .fill(missing.recording_provenance);
    await page
        .getByRole("button", { name: "پیش‌نمایش تغییرات", exact: true })
        .click();
    await commit(page);
    expect(
        (
            await page.request.get(`/api/v1/tracks/${missing.id}/stream`, {
                headers: { Range: "bytes=0-31" },
            })
        ).status(),
    ).toBe(206);
    await capture(page, info, "repair");
    await page
        .getByRole("button", { name: "بازگردانی این تغییر", exact: true })
        .click();
    await page
        .getByRole("button", { name: "تأیید بازگردانی", exact: true })
        .click();
    await expect(
        page.locator(".catalog-review").getByRole("alert"),
    ).toBeVisible();
    await expect(
        page.locator(".catalog-review").getByRole("status"),
    ).toHaveText("تغییرات اعمال شد");
    await closeReview(page);
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "بستن پنجره", exact: true })
        .click();
    for (const width of [320, 390, 768, 1440])
        for (const direction of ["rtl", "ltr"]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.evaluate((dir) => {
                document.documentElement.dir = dir;
            }, direction);
            expect(
                await page
                    .locator(".main-content")
                    .evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
            ).toBe(true);
        }
});

test.afterEach(async ({ page }, info) => {
    if (info.status !== info.expectedStatus) {
        console.log(
            "F12_FAILURE_PAGE=" +
                JSON.stringify({
                    url: page.url(),
                    text: (await page.locator("body").innerText()).slice(
                        0,
                        10000,
                    ),
                }),
        );
        await mkdir("reports/f12-failures", { recursive: true });
        await page.screenshot({
            path: `reports/f12-failures/${info.project.name}-${info.line}.png`,
        });
    }
});
