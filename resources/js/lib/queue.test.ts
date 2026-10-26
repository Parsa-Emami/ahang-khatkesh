import { describe, expect, it } from "vitest";
import { adjacent, makeOrder } from "./queue";
describe("playback queue", () => {
    it("stops at the end unless repeat-all is active", () => {
        expect(adjacent([0, 1, 2], 2, 1, false)).toBeNull();
        expect(adjacent([0, 1, 2], 2, 1, true)).toBe(0);
    });
    it("returns the previous actual shuffled item", () => {
        expect(adjacent([2, 0, 3, 1], 3, -1, false)).toBe(0);
    });
    it("preserves the current song and visits every other song exactly once per shuffle cycle", () => {
        const order = makeOrder(60, 19, true, () => 0.33);
        expect(order[0]).toBe(19);
        expect(new Set(order).size).toBe(60);
        expect([...order].sort((a, b) => a - b)).toEqual(
            Array.from({ length: 60 }, (_, i) => i),
        );
    });
    it("keeps sequential order with shuffle off", () => {
        expect(makeOrder(4, 2, false)).toEqual([0, 1, 2, 3]);
    });
    it("handles empty and single-track queues", () => {
        expect(makeOrder(0, 0, true)).toEqual([]);
        expect(adjacent([], 0, 1, true)).toBeNull();
        expect(adjacent([0], 0, 1, true)).toBe(0);
        expect(adjacent([0], 0, 1, false)).toBeNull();
    });
});
