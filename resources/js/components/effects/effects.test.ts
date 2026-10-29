import { afterEach, describe, expect, it, vi } from "vitest";
import {
    canvasSize,
    EffectCoordinator,
    effectBudget,
    FrameBudget,
} from "./budget";
import { createDitherFlow } from "./renderer";
const palette = ["#22271d", "#37432a", "#77884c", "#d8ef78"] as const;
function fixture(
    options: { absent?: boolean; compile?: boolean; link?: boolean } = {},
) {
    let callback: FrameRequestCallback = () => {};
    const listeners = new Map<string, EventListener>();
    const methods = [
        "shaderSource",
        "compileShader",
        "attachShader",
        "bindAttribLocation",
        "linkProgram",
        "useProgram",
        "bindBuffer",
        "bufferData",
        "enableVertexAttribArray",
        "vertexAttribPointer",
        "uniform3f",
        "uniform2f",
        "uniform1f",
        "viewport",
        "drawArrays",
        "deleteShader",
        "deleteProgram",
        "deleteBuffer",
    ];
    const gl = Object.fromEntries(methods.map((key) => [key, vi.fn()]));
    Object.assign(gl, {
        VERTEX_SHADER: 1,
        FRAGMENT_SHADER: 2,
        COMPILE_STATUS: 3,
        LINK_STATUS: 4,
        createShader: vi.fn(() => ({})),
        createProgram: vi.fn(() => ({})),
        createBuffer: vi.fn(() => ({})),
        getShaderParameter: vi.fn(() => options.compile !== false),
        getProgramParameter: vi.fn(() => options.link !== false),
        getUniformLocation: vi.fn(() => ({})),
        getExtension: vi.fn(() => ({ loseContext: lose })),
    });
    const canvas = {
        width: 0,
        height: 0,
        clientWidth: 390,
        clientHeight: 240,
        getContext: vi.fn(() => (options.absent ? null : gl)),
        addEventListener: vi.fn((name, listener) =>
            listeners.set(name, listener),
        ),
        removeEventListener: vi.fn((name) => listeners.delete(name)),
    };
    const disconnect = vi.fn();
    vi.stubGlobal(
        "ResizeObserver",
        class {
            observe() {}
            disconnect = disconnect;
        },
    );
    vi.stubGlobal("devicePixelRatio", 4);
    vi.stubGlobal(
        "requestAnimationFrame",
        vi.fn((next) => {
            callback = next;
            return 17;
        }),
    );
    vi.stubGlobal("cancelAnimationFrame", vi.fn());
    const fail = vi.fn(),
        stop = createDitherFlow(
            canvas as unknown as HTMLCanvasElement,
            palette,
            fail,
        );
    return {
        gl,
        canvas,
        fail,
        stop,
        listeners,
        disconnect,
        tick: (now: number) => callback(now),
    };
}
const lose = vi.fn();
afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});
describe("shader fallback and reduced motion budgets", () => {
    it("caps resolution independently of high DPR and large surfaces", () => {
        for (const [w, h] of [
            [390, 240],
            [1440, 900],
            [8000, 8000],
        ]) {
            const size = canvasSize(w, h, 4);
            expect(size.scale).toBeLessThanOrEqual(1.5);
            expect(size.width * size.height).toBeLessThanOrEqual(
                effectBudget.maxPixels,
            );
        }
        expect(canvasSize(0, 0, 0)).toMatchObject({ width: 1, height: 1 });
    });
    it("allows normal cadence and falls back after sustained slow frames", () => {
        const healthy = new FrameBudget();
        for (let i = 0; i < 200; i++)
            expect(healthy.permits(i % 20 ? 34 : 100)).toBe(true);
        const slow = new FrameBudget();
        for (let i = 0; i < 59; i++) expect(slow.permits(100)).toBe(true);
        expect(slow.permits(100)).toBe(false);
    });
    it("grants one slot to expanded playback and resumes the hero on release", () => {
        const coordinator = new EffectCoordinator(),
            hero = Symbol(),
            expanded = Symbol();
        const listener = vi.fn(),
            unsubscribe = coordinator.subscribe(listener);
        const leaveHero = coordinator.enter(hero, 1);
        const leaveExpanded = coordinator.enter(expanded, 10);
        expect(coordinator.winner()).toBe(expanded);
        leaveExpanded();
        expect(coordinator.winner()).toBe(hero);
        leaveHero();
        expect(coordinator.winner()).toBeNull();
        expect(listener).toHaveBeenCalledTimes(4);
        unsubscribe();
        coordinator.enter(Symbol(), 1);
        expect(listener).toHaveBeenCalledTimes(4);
    });
    it("keeps CSS usable without WebGL and allocates no loop", () => {
        const f = fixture({ absent: true });
        expect(f.fail).toHaveBeenCalledWith("webgl");
        expect(requestAnimationFrame).not.toHaveBeenCalled();
        f.stop();
    });
    it.each([{ compile: false }, { link: false }])(
        "reclaims partial allocations when compilation or linking fails: %j",
        (options) => {
            const f = fixture(options);
            expect(f.fail).toHaveBeenCalledWith("compile");
            expect(f.gl.deleteShader).toHaveBeenCalledTimes(
                options.compile === false ? 1 : 2,
            );
            expect(f.gl.deleteProgram).toHaveBeenCalledOnce();
            expect(requestAnimationFrame).not.toHaveBeenCalled();
            expect(f.listeners.size).toBe(0);
            f.stop();
            expect(f.gl.deleteProgram).toHaveBeenCalledOnce();
        },
    );
    it("throttles actual draws to 30fps and frees every resource on unmount", () => {
        const f = fixture();
        for (let now = 0; now <= 1000; now += 10) f.tick(now);
        expect(f.gl.drawArrays.mock.calls.length).toBeLessThanOrEqual(31);
        expect(f.gl.drawArrays.mock.calls.length).toBeGreaterThan(20);
        f.stop();
        f.stop();
        f.tick(2000);
        expect(cancelAnimationFrame).toHaveBeenCalledWith(17);
        expect(f.disconnect).toHaveBeenCalledOnce();
        expect(f.gl.deleteBuffer).toHaveBeenCalledOnce();
        expect(f.gl.deleteProgram).toHaveBeenCalledOnce();
        expect(f.gl.deleteShader).toHaveBeenCalledTimes(2);
        expect(lose).toHaveBeenCalledOnce();
        expect(f.listeners.size).toBe(0);
    });
    it("releases the context and stops scheduling after context loss", () => {
        const f = fixture(),
            preventDefault = vi.fn();
        f.listeners.get("webglcontextlost")!({
            preventDefault,
        } as unknown as Event);
        expect(preventDefault).toHaveBeenCalledOnce();
        expect(f.fail).toHaveBeenCalledWith("context-lost");
        f.tick(1000);
        expect(f.gl.drawArrays).not.toHaveBeenCalled();
        expect(f.gl.deleteBuffer).toHaveBeenCalledOnce();
    });
    it("stops and reports the performance fallback after a slow sustained cadence", () => {
        const f = fixture();
        for (let i = 0; i < 100; i++) f.tick(i * 100);
        expect(f.fail).toHaveBeenCalledOnce();
        expect(f.fail).toHaveBeenCalledWith("performance");
        expect(f.gl.deleteProgram).toHaveBeenCalledOnce();
    });
});
