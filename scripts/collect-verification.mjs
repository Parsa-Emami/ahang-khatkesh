import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
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
    browser = {
        tests: report.stats.expected,
        passed: report.stats.expected,
        failed: 0,
        skipped: 0,
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
            test_inventory: [
                ...cases.map(
                    (c) => `${attr(c[1], "classname")}::${attr(c[1], "name")}`,
                ),
                ...frontend.testResults.flatMap((file) =>
                    file.assertionResults.map((test) => test.fullName),
                ),
                ...browserTests,
            ],
        }),
);
