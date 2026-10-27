import assert from "node:assert/strict";
import { readFile, access } from "node:fs/promises";
import { dirname, resolve, relative, isAbsolute } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const blueprintPath = resolve(root, process.argv[2] ?? "docs/architecture/blueprint.json");
const blueprint = JSON.parse(await readFile(blueprintPath, "utf8"));
const requireValue = (condition, message) => assert.ok(condition, message);
const unique = (items, label) => {
    requireValue(new Set(items).size === items.length, `${label}: duplicate value`);
};
const checkPath = async (path) => {
    const target = resolve(root, path);
    const local = relative(root, target);
    requireValue(local !== "" && !local.startsWith("..") && !isAbsolute(local), `Invalid repository path: ${path}`);
    await access(target);
};
const checkSha = (sha) => requireValue(/^[a-f0-9]{40}$/.test(sha), `Invalid Git SHA: ${sha}`);

requireValue(blueprint.format_version === 1, "Unsupported blueprint format");
requireValue(blueprint.product === "ahang-khatkesh", "Unexpected product");
requireValue(blueprint.status === "design_target", "Blueprint must identify itself as a design target");
checkSha(blueprint.review.base_commit);
requireValue(blueprint.stack.php === "8.5", "PHP contract must remain 8.5");
requireValue(blueprint.stack.database === "MySQL 8.4", "Database contract must remain MySQL 8.4");
requireValue(blueprint.scope.max_accounts_including_admin_and_inactive === 10, "Account cap must include all ten accounts");
await Promise.all(blueprint.documents.map(checkPath));

const packages = new Map(blueprint.packages.map((item) => [item.id, item]));
const findings = new Map(blueprint.findings.map((item) => [item.id, item]));
const gates = new Map(blueprint.quality_gates.map((item) => [item.id, item]));
unique(blueprint.packages.map((item) => item.id), "Package IDs");
unique(blueprint.findings.map((item) => item.id), "Finding IDs");
unique(blueprint.quality_gates.map((item) => item.id), "Gate IDs");

const visiting = new Set();
const visited = new Set();
function visit(id) {
    requireValue(packages.has(id), `Unknown package: ${id}`);
    requireValue(!visiting.has(id), `Package dependency cycle at ${id}`);
    if (visited.has(id)) return;
    visiting.add(id);
    for (const dependency of packages.get(id).depends_on) visit(dependency);
    visiting.delete(id);
    visited.add(id);
}
for (const item of blueprint.packages) {
    requireValue(["planned", "implemented", "verified"].includes(item.status), `Invalid package status: ${item.id}`);
    visit(item.id);
    for (const id of item.finding_ids) {
        requireValue(findings.has(id), `${item.id} references unknown finding ${id}`);
        requireValue(findings.get(id).package_ids.includes(item.id), `Finding reverse mapping missing: ${id}/${item.id}`);
    }
}
for (const item of blueprint.findings) {
    requireValue(item.package_ids.length > 0, `Unassigned finding: ${item.id}`);
    requireValue(["open_runtime", "resolved", "unverified"].includes(item.status), `Invalid finding status: ${item.id}`);
    for (const id of item.package_ids) {
        requireValue(packages.get(id)?.finding_ids.includes(item.id), `Package reverse mapping missing: ${item.id}/${id}`);
    }
    if (item.status === "resolved") await checkPath(item.evidence_file);
}
for (const item of blueprint.quality_gates) {
    requireValue(packages.has(item.package_id), `Gate references unknown package: ${item.id}`);
    requireValue(["open", "verified"].includes(item.status), `Invalid gate status: ${item.id}`);
    if (item.status === "verified") await checkPath(item.evidence_file);
}
if (blueprint.implementation_complete || blueprint.review.production_approved) {
    requireValue(blueprint.packages.every((item) => item.status === "verified"), "Completion requires verified packages");
    requireValue(blueprint.findings.every((item) => item.status === "resolved"), "Completion requires resolved findings");
    requireValue(blueprint.quality_gates.every((item) => item.status === "verified"), "Completion requires verified gates");
}

const catalog = await readFile(resolve(root, "docs/COMPONENT_CATALOG.fa.md"), "utf8");
const documentedIds = [...catalog.matchAll(/^\| (C\d{2,}) \|/gm)].map((match) => match[1]);
const componentIds = blueprint.components.map((item) => item.id);
unique(componentIds, "Component IDs");
unique(blueprint.components.map((item) => item.name), "Component names");
unique(blueprint.components.map((item) => item.target_path), "Component target paths");
assert.deepEqual([...componentIds].sort(), [...documentedIds].sort(), "Catalog and blueprint component IDs differ");
requireValue(componentIds.length >= 64, "Required component inventory is incomplete");
for (const item of blueprint.components) {
    requireValue(["planned", "existing_requires_work", "implemented", "verified"].includes(item.status), `Invalid component status: ${item.id}`);
    requireValue(item.target_path.startsWith("resources/js/") && !item.target_path.includes(".."), `Invalid component target: ${item.id}`);
    requireValue(item.quality_gates.includes("G06") && item.quality_gates.includes("G11"), `Universal gates missing: ${item.id}`);
    for (const id of item.quality_gates) requireValue(gates.has(id), `Unknown component gate: ${item.id}/${id}`);
    await Promise.all(item.existing_sources.map(checkPath));
    if (item.status === "existing_requires_work") requireValue(item.existing_sources.length > 0, `Existing source missing: ${item.id}`);
    if (["implemented", "verified"].includes(item.status)) await checkPath(item.target_path);
}

const ui = blueprint.universal_ui;
assert.deepEqual([...ui.directions].sort(), ["ltr", "rtl"]);
assert.deepEqual([...ui.themes].sort(), ["dark", "light", "system"]);
assert.deepEqual(ui.initial_locales.map((item) => [item.tag, item.direction]), [["fa-IR", "rtl"], ["en-US", "ltr"]]);
requireValue(ui.all_components_inherit_contract, "All components must inherit the universal contract");
requireValue(ui.seek_axis === "ltr" && !ui.player_remount_on_preference_change, "Player direction and lifetime contract broken");
requireValue(blueprint.icons.mirror_only_semantic_navigation && !blueprint.icons.runtime_external_requests, "Icon admission contract broken");
checkSha(blueprint.shader.commit);
checkSha(blueprint.shader.source_blob_sha);
checkSha(blueprint.shader.license_blob_sha);
requireValue(blueprint.shader.license === "MIT" && !blueprint.shader.runtime_external_requests, "Shader license or local-source contract broken");
requireValue(blueprint.shader.fallback === "local_static_css" && blueprint.shader.reduced_motion_stops_loop, "Shader must degrade gracefully");

const { profile, queue, states, transitions } = blueprint.media;
const argumentValue = (name) => profile.arguments_template[profile.arguments_template.indexOf(name) + 1];
requireValue(profile.bitrate_bps === 320000 && profile.bitrate_mode === "CBR" && !profile.abr, "MP3 must be 320kbps CBR");
requireValue(argumentValue("-b:a") === "320k" && argumentValue("-abr") === "0", "Encoder argument bitrate contract differs");
requireValue(argumentValue("-c:a") === "libmp3lame" && argumentValue("-f") === "flac", "Encoder or input demuxer differs");
requireValue(profile.retain_original && profile.full_decode_required, "Original retention and output validation are mandatory");
requireValue(blueprint.media.identity.deduplicate_bytes_not_recordings, "Blob and recording identities must remain distinct");
requireValue(queue.sql_connection === "same_as_application", "Queue must preserve the SQL transaction boundary");
requireValue(queue.encoder_timeout_seconds + queue.validation_timeout_seconds < queue.job_timeout_seconds, "Process budgets exceed job timeout");
requireValue(queue.job_timeout_seconds < queue.supervisor_stopwait_seconds && queue.supervisor_stopwait_seconds < queue.retry_after_seconds, "Worker timeout ordering is unsafe");
requireValue(queue.retry_after_seconds < queue.lease_seconds && queue.heartbeat_seconds < queue.job_timeout_seconds, "Lease timing contract is unsafe");
unique(states, "Conversion states");
assert.deepEqual(Object.keys(transitions).sort(), [...states].sort(), "Conversion transition states differ");
for (const [state, next] of Object.entries(transitions)) {
    unique(next, `Transitions from ${state}`);
    for (const target of next) requireValue(states.includes(target), `Unknown transition: ${state}/${target}`);
}
requireValue(transitions.ready.length === 0 && transitions.cancelled.length === 0, "Terminal conversions must remain terminal");
unique(blueprint.planned_api.map((item) => `${item.method} ${item.path}`), "Planned API operations");
for (const item of blueprint.planned_api) {
    requireValue(item.status === "planned" && item.implemented === false, "Move implemented endpoints into the runtime OpenAPI contract");
    requireValue(item.path.startsWith("/api/v1/admin/") && !item.path.includes(".."), "Planned API must remain internal Laravel administration");
    requireValue(item.permissions.length > 0, "Planned API permission missing");
    if (item.mutation) requireValue(item.recent_verification, "Sensitive media mutations require step-up");
}
requireValue(blueprint.release_targets.status === "unmeasured_targets", "Measured release results belong in verification evidence");

console.log(JSON.stringify({ result: "pass", scope: "architecture_structure_only", components: componentIds.length, packages: packages.size, findings: findings.size, open_runtime_findings: blueprint.findings.filter((item) => item.status !== "resolved").length, application_runtime_verified: false }));
