import { test } from "node:test";
import assert from "node:assert/strict";
import {
    mkdtempSync,
    mkdirSync,
    readFileSync,
    writeFileSync,
    rmSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { resolve, join } from "node:path";
import { spawnSync, execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
const checker = resolve("scripts/check-roadmap.mjs");
const base = JSON.parse(readFileSync("docs/roadmap/phases.json", "utf8"));
const markdown = readFileSync("docs/ROADMAP.fa.md", "utf8").replace(
    /^- \[x\]/gm,
    "- [ ]",
);
function run(change) {
    const root = mkdtempSync(join(tmpdir(), "ahang-roadmap-"));
    try {
        mkdirSync(join(root, "docs/roadmap"), { recursive: true });
        mkdirSync(join(root, "docs/verification"), { recursive: true });
        execFileSync("git", ["init", "-q"], { cwd: root });
        const data = structuredClone(base);
        for (const phase of data.phases) {
            phase.status = "planned";
            phase.evidence = null;
            phase.implemented_paths = [];
        }
        let text = markdown;
        const input = { data, text, root };
        change(input);
        text = input.text;
        writeFileSync(join(root, "docs/ROADMAP.fa.md"), text);
        writeFileSync(
            join(root, "docs/roadmap/phases.json"),
            JSON.stringify(data),
        );
        return spawnSync(process.execPath, [checker], {
            cwd: root,
            encoding: "utf8",
            env: { ...process.env, GITHUB_ACTIONS: "false", GH_TOKEN: "" },
        });
    } finally {
        rmSync(root, { recursive: true, force: true });
    }
}
test("a planned acyclic roadmap passes without claiming verification", () => {
    const result = run(() => {});
    assert.equal(result.status, 0, result.stderr);
    assert.equal(JSON.parse(result.stdout).verified, 0);
});
test("an unchecked evidence-free phase cannot acquire a verified tick", () => {
    const result = run((input) => {
        input.text = input.text.replace("- [ ] F01", "- [x] F01");
    });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Unbacked checkbox/);
});
test("dependency cycles are refused", () => {
    const result = run((input) => {
        input.data.phases[0].depends_on = ["F06"];
    });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Dependency cycle/);
});
test("failed tests prevent verification even with a matching source fingerprint", () => {
    const result = run((input) => {
        input.data.phases[0].status = "verified";
        input.data.phases[0].evidence = "docs/verification/test.json";
        input.text = input.text.replace("- [ ] F01", "- [x] F01");
        const suite = { tests: 1, passed: 0, failed: 1, skipped: 0 };
        writeFileSync(
            join(input.root, "docs/verification/test.json"),
            JSON.stringify({
                source_fingerprint: createHash("sha256")
                    .update("[]")
                    .digest("hex"),
                commit: "a".repeat(40),
                ci: {
                    head_sha: "a".repeat(40),
                    run_id: 1,
                    conclusion: "success",
                },
                phases: ["F01"],
                php: { sqlite: suite, mysql: suite },
                frontend: suite,
                browser: suite,
            }),
        );
    });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Incomplete test evidence/);
});
test("verified phases cannot bypass an unverified dependency", () => {
    const result = run((input) => {
        const phase = input.data.phases[6];
        phase.status = "verified";
        phase.evidence = "docs/verification/absent.json";
        input.text = input.text.replace("- [ ] F07", "- [x] F07");
    });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Unverified dependency/);
});
test("F07 cannot be verified using unit and browser evidence without real process tests", () => {
    const result = run((input) => {
        for (const phase of input.data.phases.slice(0, 7)) {
            phase.status = "verified";
            phase.evidence = "docs/verification/test.json";
            phase.required_tests = ["fixture"];
            input.text = input.text.replace(
                `- [ ] ${phase.id}`,
                `- [x] ${phase.id}`,
            );
        }
        const suite = { tests: 1, passed: 1, failed: 0, skipped: 0 };
        writeFileSync(
            join(input.root, "docs/verification/test.json"),
            JSON.stringify({
                source_fingerprint: createHash("sha256")
                    .update("[]")
                    .digest("hex"),
                commit: "a".repeat(40),
                ci: {
                    head_sha: "a".repeat(40),
                    run_id: 1,
                    conclusion: "success",
                },
                phases: input.data.phases.slice(0, 7).map((phase) => phase.id),
                php: { sqlite: suite, mysql: suite },
                frontend: suite,
                browser: suite,
                checks: Object.fromEntries(
                    [
                        "build",
                        "format",
                        "pint",
                        "composer_validate",
                        "composer_audit",
                        "npm_audit",
                        "architecture",
                    ].map((gate) => [gate, "passed"]),
                ),
                test_inventory: ["fixture"],
            }),
        );
    });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /F07 requires real MySQL process evidence/);
});

test("F08 cannot be verified using only the earlier process and browser suites", () => {
    const result = run((input) => {
        for (const phase of input.data.phases.slice(0, 8)) {
            phase.status = "verified";
            phase.evidence = "docs/verification/test.json";
            phase.required_tests = ["fixture"];
            input.text = input.text.replace(
                `- [ ] ${phase.id}`,
                `- [x] ${phase.id}`,
            );
        }
        const suite = { tests: 1, passed: 1, failed: 0, skipped: 0 };
        writeFileSync(
            join(input.root, "docs/verification/test.json"),
            JSON.stringify({
                source_fingerprint: createHash("sha256")
                    .update("[]")
                    .digest("hex"),
                commit: "a".repeat(40),
                ci: {
                    head_sha: "a".repeat(40),
                    run_id: 1,
                    conclusion: "success",
                },
                phases: input.data.phases.slice(0, 8).map((phase) => phase.id),
                php: { sqlite: suite, mysql: suite },
                frontend: suite,
                browser: suite,
                concurrency: { tests: 4, passed: 4, failed: 0, skipped: 0 },
                checks: Object.fromEntries(
                    [
                        "build",
                        "format",
                        "pint",
                        "composer_validate",
                        "composer_audit",
                        "npm_audit",
                        "architecture",
                    ].map((gate) => [gate, "passed"]),
                ),
                test_inventory: ["fixture"],
            }),
        );
    });
    assert.notEqual(result.status, 0);
    assert.match(
        result.stderr,
        /F08 requires real media worker process evidence/,
    );
});
