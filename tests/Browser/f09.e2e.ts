import { expect, test, type Page } from "@playwright/test";
async function login(page: Page, device: string, owner = false) {
    await page.goto("/");
    await page
        .getByLabel("ایمیل", { exact: true })
        .fill(`${owner ? "owner" : "listener"}-${device}@example.test`);
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
            .fill("browser-recovery-20");
        await page
            .getByRole("button", { name: "ورود به خط‌کش", exact: true })
            .click();
    }
    await expect(
        page.getByRole("button", { name: "نمایش صف پخش", exact: true }),
    ).toBeVisible();
}
async function headers(page: Page) {
    const { data } = await (await page.request.get("/api/v1/session")).json();
    return {
        "X-CSRF-TOKEN": data.csrf_token,
        "X-Session-Context": `${data.user.id}:${data.user.access_version}`,
        Accept: "application/json",
    };
}
async function resetPreferences(page: Page) {
    const response = await page.request.patch("/api/v1/me", {
        data: {
            locale: "fa-IR",
            direction: "auto",
            motion: "system",
            time_zone: "Asia/Tehran",
            theme: "system",
        },
        headers: await headers(page),
    });
    expect(response.status()).toBe(200);
}
async function selectPreference(page: Page, name: string, value: string) {
    const saved = page.waitForResponse(
        (response) =>
            response.url().endsWith("/api/v1/me") &&
            response.request().method() === "PATCH",
    );
    await page
        .locator(`.dialog-content select[name="${name}"]`)
        .selectOption(value);
    expect((await saved).status()).toBe(200);
}
test("locale direction theme matrix supports reflow system theme reduced motion and translated field errors", async ({
    page,
}, info) => {
    test.setTimeout(120000);
    await page.emulateMedia({ colorScheme: "dark", reducedMotion: "reduce" });
    await login(page, info.project.name);
    await page
        .getByRole("button", { name: "تنظیمات حساب", exact: true })
        .click();
    for (const locale of ["fa-IR", "en-US"]) {
        await selectPreference(page, "locale", locale);
        for (const direction of ["rtl", "ltr"]) {
            await selectPreference(page, "direction", direction);
            for (const theme of ["light", "dark", "system"]) {
                const name =
                    locale === "fa-IR"
                        ? {
                              light: "روشن",
                              dark: "تیره",
                              system: "هماهنگ با دستگاه",
                          }[theme]
                        : {
                              light: "Light",
                              dark: "Dark",
                              system: "Follow device",
                          }[theme];
                const saved = page.waitForResponse(
                    (response) =>
                        response.url().endsWith("/api/v1/me") &&
                        response.request().method() === "PATCH",
                );
                await page.getByRole("button", { name, exact: true }).click();
                expect((await saved).status()).toBe(200);
                await expect(page.locator("html")).toHaveAttribute(
                    "lang",
                    locale,
                );
                await expect(page.locator("html")).toHaveAttribute(
                    "dir",
                    direction,
                );
                await expect(page.locator("html")).toHaveAttribute(
                    "data-theme",
                    theme === "system" ? "dark" : theme,
                );
                for (const width of [320, 390, 768, 1440]) {
                    await page.setViewportSize({ width, height: 1000 });
                    const dialog = page.getByRole("dialog");
                    const box = await dialog.boundingBox();
                    expect(box).not.toBeNull();
                    expect(box!.x).toBeGreaterThanOrEqual(-1);
                    expect(box!.x + box!.width).toBeLessThanOrEqual(width + 1);
                    expect(
                        await dialog.evaluate(
                            (element) =>
                                element.scrollWidth <= element.clientWidth + 1,
                        ),
                    ).toBe(true);
                    expect(
                        await page.evaluate(
                            () =>
                                document.documentElement.scrollWidth <=
                                innerWidth + 1,
                        ),
                    ).toBe(true);
                }
            }
        }
    }
    await page.emulateMedia({ colorScheme: "light" });
    await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.evaluate(() => {
        document.body.style.zoom = "2";
    });
    expect(
        await page
            .getByRole("dialog")
            .evaluate(
                (element) => element.scrollWidth <= element.clientWidth + 1,
            ),
    ).toBe(true);
    await page.evaluate(() => {
        document.body.style.zoom = "";
    });
    await selectPreference(page, "motion", "reduced");
    const animations = await page
        .locator(".dialog-content")
        .evaluate((element) => getComputedStyle(element).animationDuration);
    expect(parseFloat(animations)).toBeLessThanOrEqual(0.001);
    await page
        .getByRole("button", { name: "Save password", exact: true })
        .click();
    await expect(
        page.getByLabel("Current password", { exact: true }),
    ).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByRole("alert").first()).toContainText(
        "This field is required.",
    );
    await page
        .getByRole("button", { name: "Close dialog", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Account settings", exact: true }),
    ).toBeFocused();
    await resetPreferences(page);
});
test("DraftNavigationTest preserves audio and drafts across locale theme dialog close routes back and unload", async ({
    page,
}, info) => {
    test.setTimeout(120000);
    await page.addInitScript(() => {
        const Original = window.Audio;
        const created: HTMLAudioElement[] = [];
        Object.assign(window, { __f09Audio: created });
        window.Audio = function (src?: string) {
            const audio = new Original(src);
            created.push(audio);
            return audio;
        } as unknown as typeof Audio;
    });
    await login(page, info.project.name, true);
    const data = await (await page.request.get("/api/v1/playlists")).json();
    await page.goto(
        `/playlists/${data.data.find((item: { tracks_count: number }) => item.tracks_count > 0).id}`,
    );
    await page.getByRole("button", { name: "پخش همه", exact: true }).click();
    await expect
        .poll(() =>
            page
                .locator('input[aria-label="موقعیت پخش"]')
                .inputValue()
                .then(Number),
        )
        .toBeGreaterThan(0);
    const before = await page.evaluate(() => {
        const list = (window as unknown as { __f09Audio: HTMLAudioElement[] })
            .__f09Audio;
        return { count: list.length, time: list[0].currentTime };
    });
    await page
        .getByRole("button", { name: "تنظیمات حساب", exact: true })
        .click();
    await page.getByLabel("رمز فعلی", { exact: true }).fill("draft-secret");
    await selectPreference(page, "locale", "en-US");
    await selectPreference(page, "direction", "ltr");
    await page.getByRole("button", { name: "Dark", exact: true }).click();
    await expect(
        page.getByLabel("Current password", { exact: true }),
    ).toHaveValue("draft-secret");
    const after = await page.evaluate(() => {
        const list = (window as unknown as { __f09Audio: HTMLAudioElement[] })
            .__f09Audio;
        return {
            count: list.length,
            time: list[0].currentTime,
            paused: list[0].paused,
        };
    });
    expect(after.count).toBe(before.count);
    expect(after.time).toBeGreaterThanOrEqual(before.time);
    expect(after.paused).toBe(false);
    await page
        .getByRole("button", { name: "Close dialog", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Continue editing", exact: true })
        .click();
    await expect(
        page.getByLabel("Current password", { exact: true }),
    ).toHaveValue("draft-secret");
    await page
        .getByRole("button", { name: "Close dialog", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Discard changes and leave", exact: true })
        .click();
    if (info.project.name === "mobile")
        await page
            .getByRole("button", { name: "Open menu", exact: true })
            .click();
    await page
        .getByRole("link", { name: "Library administration", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Catalog Artists, releases and tracks",
            exact: true,
        })
        .click();
    await page.getByRole("button", { name: "New artist", exact: true }).click();
    await page
        .getByLabel("Display name", { exact: true })
        .fill("Mixed نام 2026");
    await page.keyboard.press("Escape");
    await page
        .getByRole("button", { name: "Continue editing", exact: true })
        .click();
    await expect(page.getByLabel("Display name", { exact: true })).toHaveValue(
        "Mixed نام 2026",
    );
    await page.keyboard.press("Escape");
    await page
        .getByRole("button", { name: "Discard changes and leave", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Import music From files to a playlist",
            exact: true,
        })
        .click();
    await page
        .getByLabel("Playlist name", { exact: true })
        .fill("Unsaved import");
    if (info.project.name === "mobile")
        await page
            .getByRole("button", { name: "Open menu", exact: true })
            .click();
    await page.getByRole("link", { name: "Home", exact: true }).click();
    await page
        .getByRole("button", { name: "Continue editing", exact: true })
        .click();
    if (
        info.project.name === "mobile" &&
        (await page
            .getByRole("button", { name: "Close menu", exact: true })
            .isVisible())
    )
        await page
            .getByRole("button", { name: "Close menu", exact: true })
            .click();
    await expect(page.getByLabel("Playlist name", { exact: true })).toHaveValue(
        "Unsaved import",
    );
    const prevented = await page.evaluate(() => {
        const event = new Event("beforeunload", { cancelable: true });
        window.dispatchEvent(event);
        return event.defaultPrevented;
    });
    expect(prevented).toBe(true);
    await page.evaluate(() => history.go(-3));
    await page
        .getByRole("button", { name: "Continue editing", exact: true })
        .click();
    if (
        info.project.name === "mobile" &&
        (await page
            .getByRole("button", { name: "Close menu", exact: true })
            .isVisible())
    )
        await page
            .getByRole("button", { name: "Close menu", exact: true })
            .click();
    await expect(page.getByLabel("Playlist name", { exact: true })).toHaveValue(
        "Unsaved import",
    );
    if (info.project.name === "mobile")
        await page
            .getByRole("button", { name: "Open menu", exact: true })
            .click();
    await page.getByRole("link", { name: "Home", exact: true }).click();
    await page
        .getByRole("button", { name: "Discard changes and leave", exact: true })
        .click();
    await expect(
        page.getByRole("heading", {
            name: "A song for every mood.",
            exact: true,
        }),
    ).toBeVisible();
    await resetPreferences(page);
});
