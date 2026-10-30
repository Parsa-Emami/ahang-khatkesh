import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    applyChange,
    appendUnique,
    changeIntent,
    editTrack,
    type Change,
} from "./workflows";
import { setCsrfToken, setSessionIdentity } from "../../lib/api";
import type { Track } from "../../types";
beforeEach(() => {
    vi.stubGlobal("window", new EventTarget());
    setSessionIdentity({ id: 7, access_version: 2 });
    setCsrfToken("catalog-csrf");
});
afterEach(() => vi.unstubAllGlobals());
describe("catalog review intent", () => {
    it("retains two independent homonyms and keeps an existing dirty selection", () => {
        const original = { id: 1, title: "Edited draft" };
        const rows = appendUnique(
            [original],
            { id: 1, title: "Remote replacement" },
            100,
        );
        expect(rows[0]).toBe(original);
        expect(
            appendUnique(rows, { id: 2, title: "Edited draft" }, 100),
        ).toHaveLength(2);
        expect(appendUnique(rows, { id: 2, title: "Other" }, 1)).toBe(rows);
    });
    it("copies editable credits with the displayed revision and recording identity", () => {
        const track = {
            id: 1,
            title: "Title",
            revision: 4,
            primary_release_id: 8,
            recording_identity: "isrc:USAAA2600001",
            recording_provenance: "Register",
            artists: [{ id: 9, name: "Artist", role: "primary" }],
        } as Track;
        const draft = editTrack(track);
        draft.credits[0].role = "producer";
        expect(track.artists![0].role).toBe("primary");
        expect(draft).toMatchObject({
            revision: 4,
            primary_release_id: 8,
            recording_identity: track.recording_identity,
            recording_provenance: "Register",
        });
    });
    it("commits the exact reviewed snapshot without replacing its fence with later data", async () => {
        const change = {
            id: "reviewed",
            revision: 0,
            snapshot_hash: "a".repeat(64),
        } as Change;
        const intent = changeIntent(change);
        change.revision = 9;
        change.snapshot_hash = "b".repeat(64);
        const fetcher = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: {} }), {
                status: 200,
                headers: { "Content-Type": "application/json" },
            }),
        );
        vi.stubGlobal("fetch", fetcher);
        await applyChange(intent, "commit");
        expect(fetcher.mock.calls[0][0]).toBe(
            "/api/v1/admin/catalog-changes/reviewed/commit",
        );
        expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({
            revision: 0,
            snapshot_hash: "a".repeat(64),
        });
        expect(fetcher.mock.calls[0][1].headers.get("X-Session-Context")).toBe(
            "7:2",
        );
    });
    it("blocks a queued review after account access changes before sending it", async () => {
        const intent = changeIntent({
            id: "reviewed",
            revision: 1,
            snapshot_hash: "a".repeat(64),
        } as Change);
        const fetcher = vi.fn();
        vi.stubGlobal("fetch", fetcher);
        setSessionIdentity({ id: 7, access_version: 3 });
        await expect(applyChange(intent, "rollback")).rejects.toMatchObject({
            code: "session_context_changed",
        });
        expect(fetcher).not.toHaveBeenCalled();
    });
});
