import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
const included = (path) =>
    /^(app|bootstrap|config|database|deploy|resources|routes|lang|public|tests|scripts|\.github)\//.test(
        path,
    ) ||
    /^(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml|tsconfig\.json|vite\.config\.ts|vitest\.config\.ts|playwright\.config\.ts|index\.html|docs\/openapi\.json)$/.test(
        path,
    );
export function sourceFingerprint(ref) {
    const paths = execFileSync(
        "git",
        ref
            ? ["ls-tree", "-r", "--name-only", "-z", ref]
            : ["ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        { encoding: "utf8" },
    )
        .split("\0")
        .filter(Boolean)
        .filter(included);
    const entries = [...new Set(paths)].sort().map((path) => [
        path,
        createHash("sha256")
            .update(
                ref
                    ? execFileSync("git", ["show", `${ref}:${path}`], {
                          maxBuffer: 10000000,
                      })
                    : readFileSync(path),
            )
            .digest("hex"),
    ]);
    return createHash("sha256").update(JSON.stringify(entries)).digest("hex");
}
