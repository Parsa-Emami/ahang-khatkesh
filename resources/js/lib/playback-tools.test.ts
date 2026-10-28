import { describe, expect, it } from "vitest";
import {
    balancedOrder,
    insertNext,
    moveQueued,
    removeQueued,
    trimUpcoming,
} from "./playback-tools";
import { createSleepTimer, sleepExpired } from "./sleep-timer";
import type { Track } from "../types";
const tracks = [1, 2, 3, 4].map((id) => ({ id }));
describe("personal queue editing", () => {
    it("moves an existing song next without duplicates or changing the playing song", () => {
        expect(
            insertNext({ queue: tracks, index: 1, playing: true }, tracks[0]),
        ).toEqual({
            queue: [tracks[1], tracks[0], tracks[2], tracks[3]],
            index: 0,
            playing: true,
        });
    });
    it("keeps the current track when another entry moves", () => {
        expect(
            moveQueued({ queue: tracks, index: 1, playing: true }, 0, 1),
        ).toEqual({
            queue: [tracks[1], tracks[0], tracks[2], tracks[3]],
            index: 0,
            playing: true,
        });
    });
    it("selects the successor when removing the current entry", () => {
        expect(
            removeQueued({ queue: tracks, index: 1, playing: true }, 1),
        ).toEqual({
            queue: [tracks[0], tracks[2], tracks[3]],
            index: 1,
            playing: true,
        });
    });
    it("pauses when removing the final current track and can clear upcoming entries", () => {
        expect(
            removeQueued({ queue: tracks, index: 3, playing: true }, 3).playing,
        ).toBe(false);
        expect(
            trimUpcoming({ queue: tracks, index: 1, playing: true }).queue,
        ).toEqual([tracks[0], tracks[1]]);
    });
    it("enforces five hundred unique entries without rejecting a reorder", () => {
        const state = {
            queue: Array.from({ length: 500 }, (_, id) => ({ id })),
            index: 0,
            playing: false,
        };
        expect(insertNext(state, { id: 999 })).toBe(state);
        expect(insertNext(state, { id: 499 }).queue.slice(0, 2)).toEqual([
            { id: 0 },
            { id: 499 },
        ]);
    });
    it("balances adjacent artists while retaining every unique track", () => {
        const queue = ["A", "A", "B", "B"].map(
            (artist, id) => ({ id, artist }) as Track,
        );
        const order = balancedOrder(queue, 0, () => 0.5);
        expect(order).toHaveLength(4);
        expect(new Set(order).size).toBe(4);
        expect(order[0]).toBe(0);
        expect(order.map((i) => queue[i].artist)).toEqual(["A", "B", "A", "B"]);
    });
    it("expires a sleep deadline based on wall time", () => {
        const timer = createSleepTimer(5, 1000);
        expect(sleepExpired(timer, 300999)).toBe(false);
        expect(sleepExpired(timer, 301000)).toBe(true);
        expect(() => createSleepTimer(2)).toThrow(RangeError);
    });
    it("stops end-of-track sleep before repeat and supports cancellation", () => {
        const timer = createSleepTimer("end_of_track");
        expect(sleepExpired(timer, 0)).toBe(false);
        expect(sleepExpired(timer, 0, true)).toBe(true);
        expect(sleepExpired(createSleepTimer(null), 999999, true)).toBe(false);
    });
});
