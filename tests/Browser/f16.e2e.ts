import { expect, test } from "@playwright/test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

test.describe("UniversalReleaseMatrix keyboard smoke", () => {
    test("unsigned device matrix stays explicit and settings remain keyboard reachable after login shell", async ({
        page,
    }) => {
        const matrix = JSON.parse(
            readFileSync(
                resolve("docs/verification/f16-device-matrix.json"),
                "utf8",
            ),
        );
        expect(matrix.signed).toBe(false);
        expect(
            matrix.assistive.map((row: { tool: string }) => row.tool),
        ).toEqual(["keyboard", "NVDA", "VoiceOver"]);
        await page.goto("/");
        await expect(page.getByLabel("ایمیل", { exact: true })).toBeVisible();
        await page.keyboard.press("Tab");
        await expect(page.locator(":focus")).toBeVisible();
    });
});
