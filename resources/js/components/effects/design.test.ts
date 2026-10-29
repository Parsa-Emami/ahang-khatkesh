import { describe, expect, it } from "vitest";
import { readFileSync, mkdirSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { contrastMatrix } from "../../../../scripts/visual-contrast.mjs";
describe("visual matrix semantic contrast and pinned source", () => {
    it("meets text and control contrast in both themes for every supported surface", () => {
        const matrix = contrastMatrix();
        mkdirSync("reports/f10-visual", { recursive: true });
        writeFileSync(
            "reports/f10-visual/contrast.json",
            JSON.stringify(matrix, null, 2),
        );
        for (const pair of matrix)
            expect(
                pair.ratio,
                `${pair.theme} ${pair.foreground}/${pair.background}`,
            ).toBeGreaterThanOrEqual(pair.minimum);
    });
    it("preserves exact pinned upstream code CSS and full MIT license", () => {
        for (const [file, sha] of Object.entries({
            "DitherFlow.tsx.txt": "eb8273aef893113ead05c4915623d61a68876418",
            "dither-flow.css.txt": "fc76db8b94623b4e8a1f5fe10822f48500dae6c6",
            LICENSE: "dc7f5a9b79f500372338ba1287cf67851c2033e3",
        })) {
            const bytes = readFileSync(`third_party/dither-flow/${file}`);
            const hash = createHash("sha1")
                .update(`blob ${bytes.length}\0`)
                .update(bytes)
                .digest("hex");
            expect(hash).toBe(sha);
        }
    });
});
