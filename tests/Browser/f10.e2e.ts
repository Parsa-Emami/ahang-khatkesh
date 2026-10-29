import { expect, test, type Page, type TestInfo } from "@playwright/test";
import { mkdir, writeFile } from "node:fs/promises";
import { contrast } from "../../scripts/visual-contrast.mjs";
async function preferences(page: Page, value: Record<string, string>) {
    const { data } = await (await page.request.get("/api/v1/session")).json();
    const response = await page.request.patch("/api/v1/me", {
        data: value,
        headers: {
            "X-CSRF-TOKEN": data.csrf_token,
            "X-Session-Context": `${data.user.id}:${data.user.access_version}`,
            Accept: "application/json",
        },
    });
    expect(response.status()).toBe(200);
}
async function login(page: Page, device: string) {
    await page.goto("/");
    await page
        .getByLabel("ایمیل", { exact: true })
        .fill(`visual-${device}@example.test`);
    await page
        .getByLabel("رمز عبور", { exact: true })
        .fill(process.env.AHANG_E2E_PASSWORD!);
    await page
        .getByRole("button", { name: "ورود به خط‌کش", exact: true })
        .click();
    await expect(page.locator(".player-bar")).toBeVisible({ timeout: 15000 });
    await preferences(page, {
        locale: "fa-IR",
        direction: "auto",
        theme: "system",
        motion: "system",
    });
    await page.reload();
    await expect(page.locator(".featured")).toBeVisible();
}
async function capture(page: Page, info: TestInfo, name: string) {
    await mkdir("reports/f10-visual", { recursive: true });
    await page.screenshot({
        path: `reports/f10-visual/${info.project.name}-${name}.png`,
        animations: "disabled",
    });
}
function hex(rgb: string) {
    return (
        "#" +
        rgb
            .match(/\d+/g)!
            .slice(0, 3)
            .map((part) => Number(part).toString(16).padStart(2, "0"))
            .join("")
    );
}
async function reflow(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
    ).toBe(true);
    expect(
        await page
            .locator(".main-content")
            .evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
    ).toBe(true);
}
test("visual matrix captures locale direction theme widths and shared empty error loading focus states", async ({
    page,
}, info) => {
    test.setTimeout(240000);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await login(page, info.project.name);
    await mkdir("reports/f10-visual", { recursive: true });
    await writeFile(
        `reports/f10-visual/${info.project.name}-environment.json`,
        JSON.stringify(
            {
                project: info.project.name,
                chrome_version: page.context().browser()?.version(),
                software_renderer_requested:
                    process.env.AHANG_BROWSER_WEBGL === "1",
                platform: process.platform,
            },
            null,
            2,
        ),
    );
    const matrix: unknown[] = [];
    for (const locale of ["fa-IR", "en-US"])
        for (const direction of ["rtl", "ltr"])
            for (const theme of ["light", "dark"]) {
                await preferences(page, { locale, direction, theme });
                await page.reload();
                await expect(page.locator(".featured")).toBeVisible();
                const key = `${locale}-${direction}-${theme}`;
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
                    theme,
                );
                for (const width of [320, 390, 768, 1440]) {
                    await page.setViewportSize({ width, height: 1000 });
                    await reflow(page);
                    await capture(page, info, `${key}-${width}`);
                }
                const pairs = await page
                    .locator(".featured-text")
                    .evaluate((element) => {
                        const styles = (selector: string) =>
                            getComputedStyle(element.querySelector(selector)!);
                        const background =
                            getComputedStyle(element).backgroundColor;
                        return [
                            { foreground: styles("h2").color, background },
                            { foreground: styles("p").color, background },
                            {
                                foreground: styles(".button").color,
                                background: styles(".button").backgroundColor,
                            },
                        ];
                    });
                for (const pair of pairs)
                    expect(
                        contrast(hex(pair.foreground), hex(pair.background)),
                    ).toBeGreaterThanOrEqual(4.5);
                await page.keyboard.press("Tab");
                await page.locator(".featured-actions .button").focus();
                expect(
                    await page
                        .locator(".featured-actions .button")
                        .evaluate((el) =>
                            parseFloat(getComputedStyle(el).outlineWidth),
                        ),
                ).toBeGreaterThanOrEqual(3);
                await capture(page, info, `${key}-focus`);
                await page.setViewportSize({ width: 390, height: 844 });
                for (const state of ["empty", "error", "loading"]) {
                    let release: () => void = () => {};
                    const gate = new Promise<void>((resolve) => {
                        release = resolve;
                    });
                    await page.route("**/api/v1/playlists?*", async (route) => {
                        if (state === "loading") await gate;
                        await route.fulfill(
                            state === "error"
                                ? {
                                      status: 503,
                                      json: {
                                          message:
                                              locale === "fa-IR"
                                                  ? "اتصال موقتاً برقرار نشد."
                                                  : "Connection temporarily unavailable.",
                                      },
                                  }
                                : {
                                      json: {
                                          data: [],
                                          meta: {
                                              current_page: 1,
                                              last_page: 1,
                                              total: 0,
                                              per_page: 24,
                                          },
                                      },
                                  },
                        );
                    });
                    await page.goto("/");
                    const panel = page.locator(`.state-${state}`);
                    await expect(panel).toBeVisible({ timeout: 15000 });
                    await reflow(page);
                    await capture(page, info, `${key}-${state}`);
                    release();
                    await page.unrouteAll({ behavior: "wait" });
                }
                await page.setViewportSize({ width: 1440, height: 1000 });
                await page
                    .locator(".header-actions")
                    .getByRole("button", {
                        name:
                            locale === "fa-IR"
                                ? "تنظیمات حساب"
                                : "Account settings",
                        exact: true,
                    })
                    .click();
                await page.setViewportSize({ width: 390, height: 844 });
                let releasePreference: () => void = () => {};
                const preferenceGate = new Promise<void>((resolve) => {
                    releasePreference = resolve;
                });
                await page.route("**/api/v1/me", async (route) => {
                    if (route.request().method() === "PATCH")
                        await preferenceGate;
                    await route.continue();
                });
                const saved = page.waitForResponse(
                    (response) =>
                        response.url().endsWith("/api/v1/me") &&
                        response.request().method() === "PATCH",
                );
                await page
                    .locator('.settings-theme button[aria-pressed="true"]')
                    .click();
                await expect(
                    page.locator(".settings-theme button").first(),
                ).toBeDisabled();
                await capture(page, info, `${key}-busy-disabled`);
                releasePreference();
                expect((await saved).status()).toBe(200);
                await page.unrouteAll({ behavior: "wait" });
                await page
                    .getByRole("button", {
                        name:
                            locale === "fa-IR"
                                ? "ذخیرهٔ رمز عبور"
                                : "Save password",
                        exact: true,
                    })
                    .click();
                await expect(
                    page.locator('input[name="current_password"]'),
                ).toHaveAttribute("aria-invalid", "true");
                await capture(page, info, `${key}-invalid`);
                await page.keyboard.press("Escape");
                matrix.push({
                    locale,
                    direction,
                    theme,
                    widths: [320, 390, 768, 1440],
                    states: [
                        "default",
                        "focus",
                        "empty",
                        "error",
                        "loading",
                        "busy-disabled",
                        "invalid",
                    ],
                    contrast: pairs,
                });
            }
    await page.reload();
    await expect(page.locator(".featured")).toBeVisible();
    await page.setViewportSize({ width: 768, height: 1000 });
    await page.evaluate(() => {
        document.body.style.zoom = "2";
    });
    await reflow(page);
    await capture(page, info, "zoom-200");
    await page.evaluate(() => {
        document.body.style.zoom = "";
    });
    await page.setViewportSize({ width: 320, height: 1000 });
    await page.locator(".featured-text h2").evaluate((el) => {
        el.textContent =
            "نام ترکیبی Mixed playlist — a longer title with forty percent expansion for reflow";
    });
    await reflow(page);
    await capture(page, info, "expanded-text");
    await writeFile(
        `reports/f10-visual/${info.project.name}-matrix.json`,
        JSON.stringify(matrix, null, 2),
    );
    await preferences(page, {
        locale: "fa-IR",
        direction: "auto",
        theme: "system",
        motion: "system",
    });
});

test("shader fallback and reduced motion stop lazy rendering on system preference save-data hidden offscreen and dialogs while audio persists", async ({
    page,
}, info) => {
    test.setTimeout(120000);
    await page.addInitScript(() => {
        const Original = window.Audio,
            audio: HTMLAudioElement[] = [];
        Object.assign(window, { __f10Audio: audio });
        window.Audio = function (src?: string) {
            const item = new Original(src);
            audio.push(item);
            return item;
        } as unknown as typeof Audio;
        const connection = new EventTarget();
        Object.assign(connection, { saveData: false });
        Object.defineProperty(navigator, "connection", {
            configurable: true,
            value: connection,
        });
    });
    let effectRequests = 0;
    page.on("request", (request) => {
        if (/DitherFlowCanvas.*\.js/.test(request.url())) effectRequests++;
    });
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.setViewportSize({ width: 390, height: 700 });
    await login(page, info.project.name);
    const hero = page.locator('[data-effect-surface="library-hero"]');
    await expect(hero).toHaveAttribute("data-effect-reason", "motion");
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    expect(effectRequests).toBe(0);
    await preferences(page, { motion: "full" });
    await page.reload();
    await expect(hero).toHaveAttribute("data-effect-reason", "motion");
    expect(effectRequests).toBe(0);
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await expect(hero.locator("canvas")).toHaveCount(1);
    await expect
        .poll(() =>
            hero
                .locator("canvas")
                .evaluate(
                    (el: HTMLCanvasElement) =>
                        !!el
                            .getContext("webgl")
                            ?.getParameter(
                                WebGLRenderingContext.CURRENT_PROGRAM,
                            ),
                ),
        )
        .toBe(true);
    const size = await hero
        .locator("canvas")
        .evaluate((el: HTMLCanvasElement) => ({
            pixels: el.width * el.height,
            ratio: el.width / el.clientWidth,
        }));
    expect(size.pixels).toBeLessThanOrEqual(320000);
    expect(size.ratio).toBeLessThanOrEqual(1.5);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await expect(hero.locator("canvas")).toHaveCount(1);
    await page.emulateMedia({ forcedColors: "active" });
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await page.emulateMedia({ forcedColors: "none" });
    await expect(hero.locator("canvas")).toHaveCount(1);
    await page.evaluate(() => {
        Object.defineProperty(document, "hidden", {
            configurable: true,
            value: true,
        });
        document.dispatchEvent(new Event("visibilitychange"));
    });
    await expect(hero).toHaveAttribute("data-effect-reason", "hidden");
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await page.evaluate(() => {
        Object.defineProperty(document, "hidden", {
            configurable: true,
            value: false,
        });
        document.dispatchEvent(new Event("visibilitychange"));
    });
    await expect(hero.locator("canvas")).toHaveCount(1);
    await page.locator(".main-content").evaluate((el) => {
        el.scrollTop = el.scrollHeight;
    });
    await expect
        .poll(() =>
            page.locator(".main-content").evaluate((el) => el.scrollTop),
        )
        .toBeGreaterThan(0);
    await expect(hero).toHaveAttribute("data-effect-reason", "offscreen");
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await page.locator(".main-content").evaluate((el) => {
        el.scrollTop = 0;
    });
    await expect(hero.locator("canvas")).toHaveCount(1);
    await page.evaluate(() => {
        const connection = (
            navigator as Navigator & {
                connection: EventTarget & { saveData: boolean };
            }
        ).connection;
        connection.saveData = true;
        connection.dispatchEvent(new Event("change"));
    });
    await expect(hero).toHaveAttribute("data-effect-reason", "save-data");
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await page.evaluate(() => {
        const connection = (
            navigator as Navigator & {
                connection: EventTarget & { saveData: boolean };
            }
        ).connection;
        connection.saveData = false;
        connection.dispatchEvent(new Event("change"));
    });
    await page.locator(".featured-actions .button").click();
    const repeat = page.locator(".player-bar .transport > button").last();
    for (
        let i = 0;
        i < 3 && (await repeat.locator(".lucide-repeat-1").count()) === 0;
        i++
    )
        await repeat.click();
    await expect(repeat.locator(".lucide-repeat-1")).toBeVisible();
    await expect
        .poll(() =>
            page
                .locator('input[aria-label="موقعیت پخش"]')
                .inputValue()
                .then(Number),
        )
        .toBeGreaterThan(0);
    const before = await page.evaluate(() => {
        const list = (window as unknown as { __f10Audio: HTMLAudioElement[] })
            .__f10Audio;
        return { count: list.length, src: list[0].src };
    });
    await page
        .getByRole("button", { name: "گسترش همین حالا", exact: true })
        .click();
    const expanded = page.locator(
        '[data-effect-surface="expanded-now-playing"]',
    );
    await expect(expanded.locator("canvas")).toHaveCount(1);
    await expect(page.locator(".shader-canvas")).toHaveCount(1);
    await expect(hero).toHaveAttribute("data-effect-reason", "capacity");
    await capture(page, info, "expanded-now-playing");
    await page.keyboard.press("Escape");
    await expect(hero.locator("canvas")).toHaveCount(1);
    await expect(
        page.getByRole("button", { name: "گسترش همین حالا", exact: true }),
    ).toBeFocused();
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page
        .locator(".header-actions")
        .getByRole("button", { name: "تنظیمات حساب", exact: true })
        .click();
    await expect(hero).toHaveAttribute("data-effect-reason", "dialog");
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await page.keyboard.press("Escape");
    await expect(hero.locator("canvas")).toHaveCount(1);
    await page.emulateMedia({ colorScheme: "light" });
    await expect(hero.locator("canvas")).toHaveCount(1);
    await hero.locator("canvas").evaluate((el: HTMLCanvasElement) => {
        el.getContext("webgl")!
            .getExtension("WEBGL_lose_context")!
            .loseContext();
    });
    await expect(hero).toHaveAttribute("data-effect-reason", "context-lost");
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    const after = await page.evaluate(() => {
        const list = (window as unknown as { __f10Audio: HTMLAudioElement[] })
            .__f10Audio;
        return { count: list.length, src: list[0].src, paused: list[0].paused };
    });
    expect(after.count).toBe(before.count);
    expect(after.src).toBe(before.src);
    expect(after.paused).toBe(false);
    await capture(page, info, "context-loss-fallback");
    await preferences(page, { motion: "system", theme: "system" });
});

test("shader fallback and reduced motion preserve controls after unavailable WebGL or failed compilation", async ({
    page,
}, info) => {
    await page.addInitScript(() => {
        const original = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = function (
            this: HTMLCanvasElement,
            ...args: unknown[]
        ) {
            if (args[0] === "webgl") {
                if (localStorage.getItem("f10-failure") !== "compile")
                    return null;
                const gl = Reflect.apply(
                    original,
                    this,
                    args,
                ) as WebGLRenderingContext | null;
                if (gl) gl.getShaderParameter = () => false;
                return gl;
            }
            return Reflect.apply(original, this, args);
        } as typeof original;
    });
    await page.emulateMedia({ reducedMotion: "no-preference" });
    await login(page, info.project.name);
    const hero = page.locator('[data-effect-surface="library-hero"]');
    await expect(hero).toHaveAttribute("data-effect-reason", "webgl");
    await expect(page.locator(".featured-actions .button")).toBeEnabled();
    await capture(page, info, "webgl-unavailable");
    await page.evaluate(() => localStorage.setItem("f10-failure", "compile"));
    await page.reload();
    await expect(hero).toHaveAttribute("data-effect-reason", "compile");
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await page.locator(".featured-actions .button").click();
    await expect(
        page
            .locator(".player-bar")
            .getByRole("button", { name: "توقف پخش", exact: true }),
    ).toBeVisible();
    await capture(page, info, "compile-fallback");
    await page.route("**/build/assets/DitherFlowCanvas-*.js", (route) =>
        route.abort(),
    );
    await page.reload();
    await expect(hero).toHaveAttribute("data-effect-reason", "module");
    await expect(page.locator(".featured-actions .button")).toBeEnabled();
    await expect(page.locator(".shader-canvas")).toHaveCount(0);
    await capture(page, info, "module-fallback");
});
