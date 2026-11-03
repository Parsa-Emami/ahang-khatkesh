import { describe, expect, it } from "vitest";
import { createSleepTimer, sleepExpired } from "./sleep-timer";

describe("SleepTimerVisibility", () => {
    it("expires a deadline that elapsed while the document was suspended", () => {
        const start = 1_700_000_000_000;
        const timer = createSleepTimer(5, start);
        expect(sleepExpired(timer, start + 4 * 60_000)).toBe(false);
        expect(sleepExpired(timer, start + 5 * 60_000)).toBe(true);
        expect(sleepExpired({ kind: "end_of_track" }, start, false)).toBe(
            false,
        );
        expect(sleepExpired({ kind: "end_of_track" }, start, true)).toBe(true);
    });
});
