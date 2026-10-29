import { defineConfig } from "@playwright/test";
export default defineConfig({
    testDir: "tests/Browser",
    testMatch: "**/*.e2e.ts",
    timeout: 45000,
    workers: 1,
    retries: 0,
    reporter: [["list"], ["json", { outputFile: "reports/browser.json" }]],
    use: {
        baseURL: "http://127.0.0.1:8000",
        trace: "off",
        screenshot: "only-on-failure",
        launchOptions: {
            executablePath: process.env.AHANG_BROWSER_EXECUTABLE,
            args:
                process.env.AHANG_BROWSER_WEBGL === "1"
                    ? ["--use-angle=swiftshader", "--enable-unsafe-swiftshader"]
                    : [],
        },
        channel: process.env.AHANG_BROWSER_CHANNEL,
    },
    projects: [
        { name: "desktop", use: { viewport: { width: 1440, height: 1000 } } },
        {
            name: "mobile",
            use: {
                viewport: { width: 390, height: 844 },
                isMobile: true,
                hasTouch: true,
            },
        },
    ],
    webServer: {
        command: "node scripts/browser-server.mjs",
        url: "http://127.0.0.1:8000",
        reuseExistingServer: false,
        timeout: 120000,
    },
});
