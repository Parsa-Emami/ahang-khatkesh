import assert from "node:assert/strict";
import { readFileSync, readdirSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { sourceFingerprint } from "./source-fingerprint.mjs";
const xml = readFileSync("reports/phpunit.xml", "utf8");
const cases = [
    ...xml.matchAll(/<testcase\b([^>]*)(?:\/>|>([\s\S]*?)<\/testcase>)/g),
];
assert(cases.length > 0 && !/<(failure|error|skipped)\b/.test(xml));
const attr = (value, name) =>
    value.match(new RegExp(`${name}="([^"]*)"`))?.[1] ?? "";
const frontend = JSON.parse(readFileSync("reports/vitest.json", "utf8"));
assert(
    frontend.success &&
        frontend.numTotalTests > 0 &&
        frontend.numFailedTests === 0 &&
        frontend.numPendingTests === 0,
);
let concurrency = null,
    concurrencyTests = [];
if (process.env.AHANG_DATABASE === "mysql") {
    const report = readFileSync("reports/concurrency.xml", "utf8");
    const tests = [
        ...report.matchAll(
            /<testcase\b([^>]*)(?:\/>|>([\s\S]*?)<\/testcase>)/g,
        ),
    ];
    assert(tests.length > 0 && !/<(failure|error|skipped)\b/.test(report));
    concurrency = {
        tests: tests.length,
        passed: tests.length,
        failed: 0,
        skipped: 0,
    };
    concurrencyTests = tests.map(
        (test) => `${attr(test[1], "classname")}::${attr(test[1], "name")}`,
    );
}
let visual = null,
    privacy = null;
let browser = null,
    browserTests = [];
if (process.env.AHANG_DATABASE === "sqlite") {
    const report = JSON.parse(readFileSync("reports/browser.json", "utf8"));
    assert(
        report.stats.expected > 0 &&
            report.stats.unexpected === 0 &&
            report.stats.skipped === 0 &&
            report.stats.flaky === 0,
    );
    const visit = (suite) => {
        for (const spec of suite.specs ?? [])
            for (const test of spec.tests ?? []) {
                assert(
                    test.results.length === 1 &&
                        test.results[0].status === "passed",
                );
                browserTests.push(`${test.projectName}: ${spec.title}`);
            }
        for (const nested of suite.suites ?? []) visit(nested);
    };
    visit(report);
    const root = "reports/f10-visual";
    const budget = JSON.parse(readFileSync(`${root}/budget.json`, "utf8"));
    const matrix = ["desktop", "mobile"].map((name) =>
        JSON.parse(readFileSync(`${root}/${name}-matrix.json`, "utf8")),
    );
    assert(matrix.every((rows) => rows.length === 8));
    const images = readdirSync(root)
        .filter((name) => name.endsWith(".png"))
        .sort()
        .map((name) => ({
            name,
            sha256: createHash("sha256")
                .update(readFileSync(`${root}/${name}`))
                .digest("hex"),
        }));
    assert(
        images.length >= 140 &&
            budget.contrast_passed === 104 &&
            budget.optional_gzip_bytes <= 61440,
    );
    writeFileSync(`${root}/inventory.json`, JSON.stringify(images, null, 2));
    visual = {
        ...budget,
        screenshot_count: images.length,
        matrix_combinations_per_project: 8,
        screenshot_inventory_sha256: createHash("sha256")
            .update(JSON.stringify(images))
            .digest("hex"),
        environments: ["desktop", "mobile"].map((name) =>
            JSON.parse(
                readFileSync(`${root}/${name}-environment.json`, "utf8"),
            ),
        ),
    };
    browser = {
        tests: report.stats.expected,
        passed: report.stats.expected,
        failed: 0,
        skipped: 0,
    };
    const privacyImages = readdirSync("reports/f11-privacy")
        .filter((name) => name.endsWith(".png"))
        .sort()
        .map((name) => ({
            name,
            sha256: createHash("sha256")
                .update(readFileSync(`reports/f11-privacy/${name}`))
                .digest("hex"),
        }));
    assert.equal(privacyImages.length, 4);
    writeFileSync(
        "reports/f11-privacy/inventory.json",
        JSON.stringify(privacyImages, null, 2),
    );
    privacy = {
        screenshot_count: privacyImages.length,
        screenshot_inventory_sha256: createHash("sha256")
            .update(JSON.stringify(privacyImages))
            .digest("hex"),
    };
}
console.log(
    "AHANG_VERIFICATION_RECORD=" +
        JSON.stringify({
            commit: process.env.GITHUB_SHA,
            database: process.env.AHANG_DATABASE,
            source_fingerprint: sourceFingerprint(),
            php: {
                tests: cases.length,
                passed: cases.length,
                failed: 0,
                skipped: 0,
            },
            frontend: {
                tests: frontend.numTotalTests,
                passed: frontend.numPassedTests,
                failed: 0,
                skipped: 0,
            },
            browser,
            visual,
            privacy,
            concurrency,
            test_inventory: [
                ...cases.map(
                    (c) => `${attr(c[1], "classname")}::${attr(c[1], "name")}`,
                ),
                ...frontend.testResults.flatMap((file) =>
                    file.assertionResults.map((test) => test.fullName),
                ),
                ...browserTests,
                ...concurrencyTests,
            ],
        }),
);
