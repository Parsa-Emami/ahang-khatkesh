import { describe, expect, it } from "vitest";
import { shouldCacheUrl, shellWorkerSource } from "./shell-sw";

describe("ShellServiceWorkerMatrix", () => {
    it("never caches private API or stream paths", () => {
        expect(shouldCacheUrl("/api/v1/tracks/1/stream")).toBe(false);
        expect(shouldCacheUrl("/api/v1/playback")).toBe(false);
        expect(shouldCacheUrl("/build/assets/app.js")).toBe(true);
        expect(shouldCacheUrl("/")).toBe(true);
        const source = shellWorkerSource("test");
        expect(source).toContain("/api/");
        expect(source).toContain("stream");
        expect(source).toContain("ak-shell-v1-test");
    });
});
