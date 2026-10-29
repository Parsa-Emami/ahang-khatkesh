import assert from "node:assert/strict";
import { readFileSync, mkdirSync, writeFileSync } from "node:fs";
import { gzipSync } from "node:zlib";
import { contrastMatrix } from "./visual-contrast.mjs";
const manifest = JSON.parse(readFileSync("public/build/manifest.json", "utf8"));
const root = "resources/js/main.tsx",
    effect = "resources/js/components/effects/DitherFlowCanvas.tsx";
assert(manifest[effect]?.isDynamicEntry, "Effect must remain lazy");
const closure = (entry, found = new Set()) => {
    if (found.has(entry)) return found;
    assert(manifest[entry], `Missing build entry ${entry}`);
    found.add(entry);
    for (const next of manifest[entry].imports ?? []) closure(next, found);
    return found;
};
const eager = closure(root),
    optional = [...closure(effect)].filter((path) => !eager.has(path));
assert(!eager.has(effect), "Shader must not enter the initial bundle");
const files = [
    ...new Set(
        optional.flatMap((path) => [
            manifest[path].file,
            ...(manifest[path].css ?? []),
            ...(manifest[path].assets ?? []),
        ]),
    ),
];
const gzipBytes = files.reduce(
    (sum, path) => sum + gzipSync(readFileSync(`public/build/${path}`)).length,
    0,
);
assert(gzipBytes <= 61440, `Optional effect exceeds 60KiB gzip: ${gzipBytes}`);
const matrix = contrastMatrix();
assert(
    matrix.every((pair) => pair.ratio >= pair.minimum),
    "Semantic contrast regression",
);
const report = {
    optional_files: files,
    optional_gzip_bytes: gzipBytes,
    max_optional_gzip_bytes: 61440,
    contrast_pairs: matrix.length,
    contrast_passed: matrix.length,
};
mkdirSync("reports/f10-visual", { recursive: true });
writeFileSync(
    "reports/f10-visual/budget.json",
    JSON.stringify(report, null, 2),
);
console.log(JSON.stringify(report));
