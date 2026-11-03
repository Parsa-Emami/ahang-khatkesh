import { afterEach, describe, expect, it, vi } from "vitest";
import {
    createCrossfadeEngine,
    detectPlaybackCapabilities,
} from "./crossfade-engine";

class FakeAudio {
    static instances: FakeAudio[] = [];
    src = "";
    volume = 1;
    paused = true;
    currentTime = 0;
    duration = 30;
    readyState = 2;
    preload = "auto";
    private listeners = new Map<string, Set<() => void>>();
    constructor() {
        FakeAudio.instances.push(this);
    }
    addEventListener(type: string, handler: () => void) {
        if (!this.listeners.has(type)) this.listeners.set(type, new Set());
        this.listeners.get(type)!.add(handler);
    }
    removeEventListener(type: string, handler: () => void) {
        this.listeners.get(type)?.delete(handler);
    }
    play = vi.fn(async () => {
        this.paused = false;
    });
    pause = vi.fn(() => {
        this.paused = true;
    });
    load = vi.fn();
    attrs: Record<string, string> = {};
    removeAttribute(name: string) {
        if (name === "src") this.src = "";
        delete this.attrs[name];
    }
    getAttribute(name: string) {
        if (name === "src") return this.src || null;
        return this.attrs[name] ?? null;
    }
    setAttribute(name: string, value: string) {
        this.attrs[name] = value;
    }
}

describe("CrossfadeDeviceMatrix", () => {
    afterEach(() => {
        FakeAudio.instances = [];
        vi.useRealTimers();
    });

    it("keeps a single audible element when crossfade never starts", async () => {
        const engine = createCrossfadeEngine(
            () => new FakeAudio() as unknown as HTMLAudioElement,
        );
        engine.setSource("/a.wav");
        engine.setOutputVolume(0.8);
        await engine.play();
        expect(engine.active().paused).toBe(false);
        expect(engine.standby().src).toBe("");
        expect(engine.fading()).toBe(false);
        engine.dispose();
        expect(engine.active().src).toBe("");
        expect(engine.standby().src).toBe("");
    });

    it("crossfades with two elements, swaps once, and tears down the outgoing source", () => {
        vi.useFakeTimers();
        const engine = createCrossfadeEngine(
            () => new FakeAudio() as unknown as HTMLAudioElement,
        );
        engine.setSource("/a.wav");
        const swapped = vi.fn();
        const started = engine.maybeCrossfade({
            nextUrl: "/b.wav",
            seconds: 1,
            remaining: 0.5,
            onSwap: swapped,
        });
        expect(started).toBe(true);
        expect(engine.fading()).toBe(true);
        expect(engine.standby().src).toBe("/b.wav");
        vi.advanceTimersByTime(1100);
        expect(swapped).toHaveBeenCalledTimes(1);
        expect(engine.fading()).toBe(false);
        expect(engine.active().src).toBe("/b.wav");
        expect(engine.standby().src).toBe("");
        expect(engine.standby().paused).toBe(true);
    });

    it("cancels an in-flight fade without leaving dual playback", () => {
        vi.useFakeTimers();
        const engine = createCrossfadeEngine(
            () => new FakeAudio() as unknown as HTMLAudioElement,
        );
        engine.setSource("/a.wav");
        engine.maybeCrossfade({
            nextUrl: "/b.wav",
            seconds: 2,
            remaining: 1,
            onSwap: () => {},
        });
        engine.cancelFade();
        expect(engine.fading()).toBe(false);
        expect(engine.standby().src).toBe("");
        expect(engine.active().volume).toBe(1);
    });

    it("records Safari/iOS and gapless limitations in the device matrix", () => {
        const safari = detectPlaybackCapabilities(
            "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1",
        );
        expect(safari.safariLike).toBe(true);
        expect(safari.gaplessNative).toBe(false);
        expect(safari.bluetoothUnreliable).toBe(true);
        expect(safari.notes.some((note) => /Safari/i.test(note))).toBe(true);
        const desktop = detectPlaybackCapabilities(
            "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        );
        expect(desktop.safariLike).toBe(false);
        expect(desktop.gaplessNative).toBe(false);
    });
});
