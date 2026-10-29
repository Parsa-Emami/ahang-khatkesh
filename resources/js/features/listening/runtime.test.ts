import { afterEach, describe, expect, it } from "vitest";
import {
    canRecord,
    ListeningClock,
    listeningRuntime,
    playbackPrivacy,
    setListeningRuntime,
    type ListeningSettings,
} from "./runtime";
const settings: ListeningSettings = {
    history_enabled: true,
    recommendations_enabled: true,
    retention_days: 30,
    private_mode: false,
    private_until: null,
    revision: 1,
};
afterEach(() => setListeningRuntime(null, null, true));
describe("ListeningPrivacy client epoch and native listening clock", () => {
    it("starts blocked and never persists a private or uncertain snapshot", () => {
        setListeningRuntime(1, null, false);
        expect(canRecord()).toBe(false);
        expect(playbackPrivacy().private_mode).toBe(true);
        setListeningRuntime(1, settings, false);
        expect(canRecord()).toBe(true);
        setListeningRuntime(1, settings, true);
        expect(canRecord()).toBe(false);
        setListeningRuntime(1, { ...settings, private_mode: true }, false);
        expect(canRecord()).toBe(false);
        expect(playbackPrivacy().private_mode).toBe(true);
    });
    it("invalidates outstanding grants on erase preferences private mode or actor changes", () => {
        setListeningRuntime(1, settings, false);
        const first = listeningRuntime().epoch;
        setListeningRuntime(1, settings, false);
        expect(listeningRuntime().epoch).toBe(first);
        setListeningRuntime(1, { ...settings, revision: 2 }, false);
        expect(listeningRuntime().epoch).toBeGreaterThan(first);
        const second = listeningRuntime().epoch;
        setListeningRuntime(2, settings, false);
        expect(listeningRuntime().epoch).toBeGreaterThan(second);
    });
    it("counts forward native progress while audible and limits it by wall time", () => {
        const clock = new ListeningClock();
        clock.sample(0, 0, true);
        clock.sample(2, 2000, true);
        clock.sample(12, 3000, true);
        expect(clock.seconds).toBe(3);
    });
    it("ignores seeks pause silence stalls and resumed position jumps", () => {
        const clock = new ListeningClock();
        clock.sample(0, 0, true);
        clock.sample(1, 1000, true);
        clock.sample(90, 1100, true, true);
        clock.resetBaseline(90, 1200);
        clock.sample(90, 2000, true);
        clock.sample(91, 3000, true);
        clock.sample(91, 5000, false);
        clock.sample(91, 10000, false);
        clock.sample(91, 11000, true);
        clock.sample(92, 12000, true);
        expect(clock.seconds).toBe(3);
    });
});
