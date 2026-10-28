import { describe, expect, it } from "vitest";
import { editDraft, freshDraft, receiveDraft } from "./versioned-draft";
describe("versioned playlist draft", () => {
    it("adopts remote content when no local work exists", () => {
        expect(receiveDraft(freshDraft([1, 2], 4), [2, 1], 5)).toEqual({
            items: [2, 1],
            revision: 5,
            dirty: false,
            remoteUpdate: false,
        });
    });
    it("preserves dirty order and base revision after reconnect", () => {
        const dirty = editDraft(freshDraft([1, 2], 4), [2, 1]);
        expect(receiveDraft(dirty, [1, 2, 3], 5)).toEqual({
            items: [2, 1],
            revision: 4,
            dirty: true,
            remoteUpdate: true,
        });
    });
    it("does not mistake identical remote revisions for a conflict", () => {
        const dirty = editDraft(freshDraft([1, 2], 4), [2, 1]);
        expect(receiveDraft(dirty, [1, 2], 4)).toEqual(dirty);
    });
});
