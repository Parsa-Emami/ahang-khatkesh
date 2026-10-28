import { expect, test, type Page } from "@playwright/test";
async function login(page: Page, device: string, owner = false, recovery = 1) {
    await page.goto("/");
    await page
        .getByLabel("ایمیل", { exact: true })
        .fill(`${owner ? "owner" : "listener"}-${device}@example.test`);
    await page
        .getByLabel("رمز عبور", { exact: true })
        .fill(process.env.AHANG_E2E_PASSWORD!);
    await page.getByRole("button", { name: "ورود به خط‌کش" }).click();
    if (owner) {
        await page
            .getByRole("button", { name: "استفاده از کد بازیابی", exact: true })
            .click();
        await page
            .getByLabel("کد بازیابی", { exact: true })
            .fill(`browser-recovery-${recovery}`);
        const challenge = page.waitForResponse(
            (response) =>
                response.url().endsWith("/api/v1/login/challenge") &&
                response.request().method() === "POST",
        );
        await page.getByRole("button", { name: "ورود به خط‌کش" }).click();
        expect((await challenge).status()).toBe(200);
    }
    await expect(
        page.getByRole("button", { name: "نمایش صف پخش", exact: true }),
    ).toBeVisible({ timeout: 15000 });
}
async function sessionHeaders(page: Page) {
    const { data } = await (await page.request.get("/api/v1/session")).json();
    return {
        "X-CSRF-TOKEN": data.csrf_token,
        "X-Session-Context": `${data.user.id}:${data.user.access_version}`,
        Accept: "application/json",
    };
}
test("listener cannot mutate catalog with valid CSRF and real middleware rejects missing CSRF", async ({
    page,
}, info) => {
    await login(page, info.project.name);
    const missing = await page.request.post("/api/v1/admin/playlists", {
        data: { name: "Blocked", mood: "calm" },
        headers: { Accept: "application/json" },
    });
    expect(missing.status()).toBe(419);
    const forbidden = await page.request.post("/api/v1/admin/playlists", {
        data: { name: "Blocked", mood: "calm" },
        headers: await sessionHeaders(page),
    });
    expect(forbidden.status()).toBe(403);
    await expect(
        page.getByRole("heading", { name: "پشت صحنهٔ خط‌کش" }),
    ).toHaveCount(0);
});
test("real audio supports play next queue edits and timed stop without changing the playlist", async ({
    page,
}, info) => {
    await page.clock.install();
    await login(page, info.project.name);
    const playlists = await (
        await page.request.get("/api/v1/playlists")
    ).json();
    const id = playlists.data.find(
        (p: { tracks_count: number }) => p.tracks_count >= 3,
    ).id;
    await page.goto(`/playlists/${id}`);
    const original = await (
        await page.request.get(`/api/v1/playlists/${id}`)
    ).json();
    await page.getByRole("button", { name: "پخش همه", exact: true }).click();
    await expect(
        page
            .locator(".player-bar")
            .getByRole("button", { name: "توقف پخش", exact: true }),
    ).toBeVisible();
    await expect
        .poll(async () =>
            Number(
                await page
                    .locator('input[aria-label="موقعیت پخش"]')
                    .inputValue(),
            ),
        )
        .toBeGreaterThan(0);
    const target = original.data.tracks[2];
    await page
        .getByRole("button", { name: `پخش بعدی ${target.title}`, exact: true })
        .click();
    await page
        .getByRole("button", { name: "نمایش صف پخش", exact: true })
        .click();
    const dialog = page.getByRole("dialog", { name: "صف پخش شما" });
    await expect(dialog.locator(".queue-row").nth(1)).toContainText(
        target.title,
    );
    await dialog
        .getByRole("button", {
            name: `برداشتن ${target.title} از صف`,
            exact: true,
        })
        .click();
    await expect(dialog.locator(".queue-row")).toHaveCount(
        original.data.tracks.length - 1,
    );
    await dialog
        .getByRole("button", { name: "بستن پنجره", exact: true })
        .click();
    await page.getByRole("button", { name: "تایمر خواب", exact: true }).click();
    await page.getByRole("button", { name: "۵ دقیقه", exact: true }).click();
    for (const control of await page.locator(".player-tools button").all()) {
        const bounds = await control.boundingBox();
        expect(bounds).not.toBeNull();
        expect(bounds!.x).toBeGreaterThanOrEqual(0);
        expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(
            page.viewportSize()!.width,
        );
    }
    await page.clock.fastForward(300001);
    await expect(
        page
            .locator(".player-bar")
            .getByRole("button", { name: "پخش موسیقی", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("button", { name: "تایمر خواب", exact: true }),
    ).toHaveAttribute("aria-pressed", "false");
    const after = await (
        await page.request.get(`/api/v1/playlists/${id}`)
    ).json();
    expect(after.data.tracks.map((track: { id: number }) => track.id)).toEqual(
        original.data.tracks.map((track: { id: number }) => track.id),
    );
    await page.screenshot({
        path: `reports/player-${info.project.name}.png`,
        fullPage: true,
    });
});
test("dirty playlist order survives remote edits and reconnect until explicitly discarded", async ({
    page,
}, info) => {
    await login(
        page,
        info.project.name,
        true,
        info.title.startsWith("dirty") ? 11 : 21,
    );
    const playlists = await (
        await page.request.get("/api/v1/playlists")
    ).json();
    const playlist = playlists.data.find(
        (p: { tracks_count: number }) => p.tracks_count >= 3,
    );
    const original = (
        await (
            await page.request.get(`/api/v1/playlists/${playlist.id}`)
        ).json()
    ).data;
    await page.goto("/admin?tab=library");
    await page
        .getByRole("button", {
            name: `ترتیب آهنگ‌های ${playlist.name}`,
            exact: true,
        })
        .click();
    const dialog = page.getByRole("dialog", {
        name: `آهنگ‌های ${playlist.name}`,
        exact: true,
    });
    await expect(dialog.locator(".arrange-list li")).toHaveCount(
        original.tracks.length,
    );
    await dialog
        .getByRole("button", {
            name: `پایین بردن ${original.tracks[0].title}`,
            exact: true,
        })
        .click();
    await expect(dialog.locator(".arrange-list li").first()).toContainText(
        original.tracks[1].title,
    );
    const ids = original.tracks
        .map((track: { id: number }) => track.id)
        .reverse();
    const changed = await page.request.put(
        `/api/v1/admin/playlists/${playlist.id}/tracks`,
        {
            data: { revision: original.revision, track_ids: ids },
            headers: await sessionHeaders(page),
        },
    );
    expect(changed.status()).toBe(200);
    await page.evaluate(() => {
        window.dispatchEvent(new Event("offline"));
        window.dispatchEvent(new Event("online"));
    });
    await expect(dialog.getByRole("alert")).toContainText(
        "نسخهٔ تازه‌ای در سرور وجود دارد",
    );
    await expect(dialog.locator(".arrange-list li").first()).toContainText(
        original.tracks[1].title,
    );
    await expect(
        dialog.getByRole("button", { name: "ذخیرهٔ ترتیب و آهنگ‌ها" }),
    ).toBeDisabled();
    await dialog
        .getByRole("button", {
            name: "کنار گذاشتن این ترتیب و بارگذاری نسخهٔ تازه",
        })
        .click();
    await expect(dialog.locator(".arrange-list li").first()).toContainText(
        original.tracks.at(-1).title,
    );
    await expect(
        dialog.getByRole("button", { name: "ذخیرهٔ ترتیب و آهنگ‌ها" }),
    ).toBeEnabled();
});
test("super administrator can trash and restore catalog from the admin panel", async ({
    page,
}, info) => {
    await login(
        page,
        info.project.name,
        true,
        info.title.startsWith("dirty") ? 11 : 21,
    );
    const tracks = await (
        await page.request.get("/api/v1/admin/tracks")
    ).json();
    const track = tracks.data[0];
    await page.goto("/admin?tab=catalog");
    await page.getByRole("button", { name: "آهنگ‌ها", exact: true }).click();
    await page
        .getByRole("button", {
            name: `انتقال ${track.title} به سطل بازیافت`,
            exact: true,
        })
        .click();
    const confirm = page.getByRole("dialog", {
        name: `حذف ${track.title}`,
        exact: true,
    });
    await confirm
        .getByRole("button", { name: "انتقال به سطل بازیافت", exact: true })
        .click();
    await expect(confirm).toHaveCount(0);
    await page.goto("/admin?tab=trash");
    await page
        .getByRole("button", { name: `بازیابی ${track.title}`, exact: true })
        .click();
    await expect(
        page.getByRole("button", {
            name: `بازیابی ${track.title}`,
            exact: true,
        }),
    ).toHaveCount(0);
    const after = await (await page.request.get("/api/v1/admin/tracks")).json();
    expect(
        after.data.some((item: { id: number }) => item.id === track.id),
    ).toBe(true);
    await page.screenshot({
        path: `reports/admin-${info.project.name}.png`,
        fullPage: true,
    });
});
test("super administrator reserves storage before uploading audio through the admin UI", async ({
    page,
}, info) => {
    await login(page, info.project.name, true, 31);
    await page.goto("/admin?tab=imports");
    await expect(
        page.getByLabel("فضای کتابخانه", { exact: true }),
    ).toBeVisible();
    const samples = Buffer.alloc(3200),
        wave = Buffer.alloc(44 + samples.length);
    samples.writeInt16LE(info.project.name === "mobile" ? 1 : 2, 0);
    wave.write("RIFF", 0);
    wave.writeUInt32LE(36 + samples.length, 4);
    wave.write("WAVEfmt ", 8);
    wave.writeUInt32LE(16, 16);
    wave.writeUInt16LE(1, 20);
    wave.writeUInt16LE(1, 22);
    wave.writeUInt32LE(16000, 24);
    wave.writeUInt32LE(32000, 28);
    wave.writeUInt16LE(2, 32);
    wave.writeUInt16LE(16, 34);
    wave.write("data", 36);
    wave.writeUInt32LE(samples.length, 40);
    samples.copy(wave, 44);
    const writes: string[] = [];
    page.on("request", (request) => {
        if (
            request.method() === "POST" &&
            (request.url().endsWith("/admin/storage-reservations") ||
                request.url().endsWith("/admin/media"))
        )
            writes.push(request.url());
    });
    const reservation = page.waitForResponse(
        (response) =>
            response.url().endsWith("/admin/storage-reservations") &&
            response.request().method() === "POST",
    );
    const upload = page.waitForResponse(
        (response) =>
            response.url().endsWith("/admin/media") &&
            response.request().method() === "POST",
    );
    await page
        .getByLabel("بارگذاری فایل‌های صوتی", { exact: true })
        .setInputFiles({
            name: "phase-seven.wav",
            mimeType: "audio/wav",
            buffer: wave,
        });
    expect((await reservation).status()).toBe(201);
    const result = await upload;
    expect(result.status()).toBe(201);
    expect((await result.json()).data.original_name).toBe("phase-seven.wav");
    expect(writes).toHaveLength(2);
    expect(writes[0]).toMatch(/storage-reservations$/);
    expect(writes[1]).toMatch(/admin\/media$/);
    await expect(page.getByLabel("عنوان آهنگ ۱", { exact: true })).toHaveValue(
        "phase-seven",
    );
    const status = await (
        await page.request.get("/api/v1/admin/storage")
    ).json();
    expect(status.data.reserved_bytes).toBe(0);
    await page.screenshot({
        path: `reports/storage-${info.project.name}.png`,
        fullPage: true,
    });
});

test("FLAC conversion and quality picker preserve the draft and playback position", async ({
    page,
}, info) => {
    await login(page, info.project.name, true, 32);
    await page.goto("/admin?tab=imports");
    const title = `F08 ${info.project.name}`;
    await page.getByLabel("نام پلی‌لیست", { exact: true }).fill(title);
    const upload = page.waitForResponse(
        (response) =>
            response.url().endsWith("/admin/media") &&
            response.request().method() === "POST",
    );
    await page
        .getByLabel("بارگذاری فایل‌های صوتی", { exact: true })
        .setInputFiles(`reports/f08-${info.project.name}.flac`);
    const uploaded = await upload;
    expect(uploaded.status()).toBe(201);
    const source = (await uploaded.json()).data;
    expect(source.codec).toBe("flac");
    expect(source.conversion.id).toBeTruthy();
    await expect(
        page.getByLabel("عنوان آهنگ ۱", { exact: true }),
    ).toBeVisible();
    await page
        .getByLabel("عنوان آهنگ ۱", { exact: true })
        .fill(`Quality ${info.project.name}`);
    await page
        .getByLabel("هنرمند آهنگ ۱", { exact: true })
        .fill("Synthetic artist");
    await page.getByRole("button", { name: "تبدیل صوت", exact: false }).click();
    await expect(
        page
            .getByText("بررسی فریم CBR و decode کامل موفق · اصل FLAC محفوظ")
            .first(),
    ).toBeVisible({ timeout: 20000 });
    await expect
        .poll(
            async () =>
                (
                    await (
                        await page.request.get(
                            `/api/v1/admin/media-conversions/${source.conversion.id}`,
                        )
                    ).json()
                ).data.status,
            { timeout: 20000 },
        )
        .toBe("ready");
    const conversion = await (
        await page.request.get(
            `/api/v1/admin/media-conversions/${source.conversion.id}`,
        )
    ).json();
    expect(conversion.data.status).toBe("ready");
    expect(conversion.data.progress_percent).toBe(100);
    expect(conversion.data.report.bitrate_bps).toBe(320000);
    expect(conversion.data.report.full_decode).toBe(true);
    const ownConversion = page.getByRole("article", {
        name: `تبدیل f08-${info.project.name}.flac`,
        exact: true,
    });
    await expect(
        ownConversion.getByText("آمادهٔ پخش", { exact: true }),
    ).toBeVisible({ timeout: 20000 });
    await ownConversion.scrollIntoViewIfNeeded();
    for (const direction of ["rtl", "ltr"])
        for (const theme of ["light", "dark"]) {
            await page.evaluate(
                ({ direction, theme }) => {
                    document.documentElement.dir = direction;
                    document.documentElement.dataset.theme = theme;
                },
                { direction, theme },
            );
            await expect(
                page.getByRole("heading", {
                    name: "اصل محفوظ، نسخهٔ سبک‌تر برای پخش",
                }),
            ).toBeVisible();
            expect(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <= innerWidth + 1,
                ),
            ).toBe(true);
            await page.screenshot({
                path: `reports/conversion-${info.project.name}-${direction}-${theme}.png`,
                fullPage: true,
            });
        }
    await page.evaluate(() => {
        document.documentElement.dir = "rtl";
        document.documentElement.dataset.theme = "light";
    });
    await page
        .getByRole("button", { name: "ورود موسیقی", exact: false })
        .click();
    await expect(page.getByLabel("نام پلی‌لیست", { exact: true })).toHaveValue(
        title,
    );
    await expect(page.getByLabel("عنوان آهنگ ۱", { exact: true })).toHaveValue(
        `Quality ${info.project.name}`,
    );
    await page
        .getByRole("button", { name: "بررسی و پیش‌نمایش", exact: false })
        .click();
    const commit = page.getByRole("button", {
        name: "تأیید و ساخت پلی‌لیست",
        exact: true,
    });
    await expect(commit).toBeEnabled({ timeout: 10000 });
    await commit.click();
    await page
        .getByRole("link", { name: "دیدن پلی‌لیست", exact: false })
        .click({ timeout: 15000 });
    await page
        .getByRole("button", {
            name: `پخش Quality ${info.project.name}`,
            exact: true,
        })
        .click();
    for (const control of await page.locator(".player-tools button").all()) {
        const bounds = await control.boundingBox();
        expect(bounds!.x).toBeGreaterThanOrEqual(0);
        expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(
            page.viewportSize()!.width,
        );
    }
    await page
        .getByRole("button", { name: "انتخاب کیفیت پخش", exact: true })
        .click();
    const picker = page.getByRole("combobox", {
        name: "کیفیت پخش",
        exact: true,
    });
    await expect(picker).toBeVisible();
    await expect(
        page.getByLabel("موقعیت پخش", { exact: true }),
    ).not.toHaveValue("0");
    const position = Number(
        await page.getByLabel("موقعیت پخش", { exact: true }).inputValue(),
    );
    await picker.selectOption("original");
    await page
        .getByRole("dialog", { name: "کیفیت پخش", exact: true })
        .getByRole("button", { name: "بستن پنجره", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "توقف پخش", exact: true }),
    ).toBeVisible();
    await expect
        .poll(async () =>
            Number(
                await page
                    .getByLabel("موقعیت پخش", { exact: true })
                    .inputValue(),
            ),
        )
        .toBeGreaterThanOrEqual(position);
    await page
        .getByRole("button", { name: "انتخاب کیفیت پخش", exact: true })
        .click();
    await picker.selectOption("mp3-320");
    await page
        .getByRole("dialog", { name: "کیفیت پخش", exact: true })
        .getByRole("button", { name: "بستن پنجره", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "توقف پخش", exact: true }),
    ).toBeVisible();
});
