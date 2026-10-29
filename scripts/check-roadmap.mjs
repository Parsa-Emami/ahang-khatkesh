import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { sourceFingerprint } from "./source-fingerprint.mjs";
const roadmap = JSON.parse(readFileSync("docs/roadmap/phases.json", "utf8"));
assert.equal(roadmap.format_version, 1);
assert.equal(roadmap.phases.length, 16);
const phases = new Map(roadmap.phases.map((phase) => [phase.id, phase]));
assert.equal(phases.size, 16);
const markdown = readFileSync("docs/ROADMAP.fa.md", "utf8");
const ticks = new Map(
    [...markdown.matchAll(/^- \[([ x])\] (F\d{2})\b/gm)].map((match) => [
        match[2],
        match[1] === "x",
    ]),
);
assert.equal(ticks.size, 16);
const visited = new Set(),
    visiting = new Set();
function visit(id) {
    assert(phases.has(id), `Unknown dependency ${id}`);
    assert(!visiting.has(id), `Dependency cycle ${id}`);
    if (visited.has(id)) return;
    visiting.add(id);
    for (const dependency of phases.get(id).depends_on) visit(dependency);
    visiting.delete(id);
    visited.add(id);
}
let verified = 0,
    remoteEvidenceChecked = false;
const records = new Map();
for (const phase of roadmap.phases) {
    visit(phase.id);
    assert(["planned", "implemented", "verified"].includes(phase.status));
    assert.equal(
        ticks.get(phase.id),
        phase.status === "verified",
        `Unbacked checkbox ${phase.id}`,
    );
    assert(phase.required_tests.length > 0 && phase.acceptance.length > 0);
    if (phase.status !== "planned")
        for (const path of phase.implemented_paths) readFileSync(path);
    if (phase.status !== "verified") continue;
    for (const dependency of phase.depends_on)
        assert.equal(
            phases.get(dependency).status,
            "verified",
            `Unverified dependency ${dependency} for ${phase.id}`,
        );
    verified++;
    assert(
        phase.evidence?.startsWith("docs/verification/") &&
            !phase.evidence.includes(".."),
    );
    if (!records.has(phase.evidence))
        records.set(
            phase.evidence,
            JSON.parse(readFileSync(phase.evidence, "utf8")),
        );
    const record = records.get(phase.evidence);
    assert.equal(
        record.source_fingerprint,
        sourceFingerprint(),
        `Stale implementation evidence for ${phase.id}`,
    );
    assert.match(record.commit, /^[a-f0-9]{40}$/);
    assert.equal(record.ci.head_sha, record.commit);
    assert.equal(record.ci.conclusion, "success");
    assert(Number.isInteger(record.ci.run_id));
    assert(record.phases.includes(phase.id));
    for (const suite of [
        record.php.sqlite,
        record.php.mysql,
        record.frontend,
        record.browser,
    ]) {
        assert(
            suite.tests > 0 &&
                suite.passed === suite.tests &&
                suite.failed === 0 &&
                suite.skipped === 0,
            `Incomplete test evidence for ${phase.id}`,
        );
    }
    if (phase.id === "F07") {
        assert(
            record.concurrency &&
                record.concurrency.tests >= 4 &&
                record.concurrency.passed === record.concurrency.tests &&
                record.concurrency.failed === 0 &&
                record.concurrency.skipped === 0,
            "F07 requires real MySQL process evidence",
        );
    }
    if (phase.id === "F08") {
        assert(
            record.concurrency &&
                record.concurrency.tests >= 9 &&
                record.concurrency.passed === record.concurrency.tests &&
                record.concurrency.failed === 0 &&
                record.concurrency.skipped === 0,
            "F08 requires real media worker process evidence",
        );
        assert(
            record.browser.tests >= 12,
            "F08 requires the conversion and quality browser journey",
        );
    }
    if (phase.id === "F10") {
        assert(
            record.visual &&
                record.visual.screenshot_count >= 140 &&
                record.visual.contrast_passed === 104 &&
                record.visual.optional_gzip_bytes > 0 &&
                record.visual.optional_gzip_bytes <= 61440 &&
                record.visual.matrix_combinations_per_project === 8,
            "F10 requires measured visual matrix and optional effect budget",
        );
    }
    for (const gate of [
        "build",
        "format",
        "pint",
        "composer_validate",
        "composer_audit",

        "architecture",
    ])
        assert.equal(record.checks[gate], "passed");
    assert.equal(
        record.checks.npm_advisory_scan ?? record.checks.npm_audit,
        "passed",
    );
    for (const required of phase.required_tests)
        assert(
            record.test_inventory.some((name) => name.includes(required)),
            `Missing test ${required}`,
        );
    let historicalTree = false;
    try {
        execFileSync("git", ["cat-file", "-e", `${record.commit}^{tree}`], {
            stdio: "ignore",
        });
        historicalTree = true;
    } catch {}
    if (historicalTree)
        assert.equal(
            record.source_fingerprint,
            sourceFingerprint(record.commit),
            "Evidence differs from the tested Git tree",
        );
    if (process.env.GITHUB_ACTIONS === "true")
        assert(
            historicalTree,
            "Checkout full history to verify the evidence tree",
        );
}
if (process.env.GITHUB_ACTIONS === "true" && records.size) {
    assert(
        process.env.GH_TOKEN,
        "Read-only Actions token required to verify remote proof",
    );
    for (const record of records.values()) {
        const response = await fetch(
            `https://api.github.com/repos/Parsa-Emami/ahang-khatkesh/actions/runs/${record.ci.run_id}`,
            {
                headers: {
                    Authorization: `Bearer ${process.env.GH_TOKEN}`,
                    Accept: "application/vnd.github+json",
                },
            },
        );
        assert.equal(response.status, 200);
        const run = await response.json();
        assert.equal(run.head_sha, record.commit);
        assert.equal(run.path, ".github/workflows/verify.yml");
        assert.equal(run.conclusion, "success");
        assert.equal(run.status, "completed");
    }
    remoteEvidenceChecked = true;
}
if (roadmap.production_approved)
    assert.equal(verified, 16, "Production approval requires all phases");
console.log(
    JSON.stringify({
        result: "pass",
        phases: 16,
        verified,
        implemented: roadmap.phases.filter(
            (phase) => phase.status === "implemented",
        ).length,
        planned: roadmap.phases.filter((phase) => phase.status === "planned")
            .length,
        remote_evidence_checked: remoteEvidenceChecked,
        production_approved: roadmap.production_approved,
    }),
);
