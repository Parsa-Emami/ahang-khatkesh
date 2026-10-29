import { canvasSize, effectBudget, FrameBudget } from "./budget";
import { fragment, vertex } from "./shaders";

export type Palette = readonly [string, string, string, string];
export type EffectFailure =
    "webgl" | "compile" | "context-lost" | "performance";

export function createDitherFlow(
    canvas: HTMLCanvasElement,
    palette: Palette,
    fail: (reason: EffectFailure) => void,
) {
    const gl = canvas.getContext("webgl", {
        antialias: false,
        alpha: false,
        depth: false,
        stencil: false,
        powerPreference: "low-power",
        preserveDrawingBuffer: false,
    });
    if (!gl) {
        fail("webgl");
        return () => {};
    }
    const shaders: WebGLShader[] = [];
    let program: WebGLProgram | null = null;
    let buffer: WebGLBuffer | null = null;
    let observer: ResizeObserver | null = null;
    let raf = 0,
        disposed = false,
        last: number | null = null,
        time = 40;
    const budget = new FrameBudget();
    const dispose = () => {
        if (disposed) return;
        disposed = true;
        cancelAnimationFrame(raf);
        observer?.disconnect();
        canvas.removeEventListener("webglcontextlost", lost);
        if (buffer) gl.deleteBuffer(buffer);
        if (program) gl.deleteProgram(program);
        shaders.forEach((shader) => gl.deleteShader(shader));
        gl.getExtension("WEBGL_lose_context")?.loseContext();
    };
    const stop = (reason: EffectFailure) => {
        dispose();
        fail(reason);
    };
    const lost = (event: Event) => {
        event.preventDefault();
        stop("context-lost");
    };
    canvas.addEventListener("webglcontextlost", lost);
    try {
        const compile = (kind: number, source: string) => {
            const shader = gl.createShader(kind);
            if (!shader) throw new Error("shader");
            shaders.push(shader);
            gl.shaderSource(shader, source);
            gl.compileShader(shader);
            if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS))
                throw new Error("compile");
            return shader;
        };
        program = gl.createProgram();
        if (!program) throw new Error("program");
        gl.attachShader(program, compile(gl.VERTEX_SHADER, vertex));
        gl.attachShader(program, compile(gl.FRAGMENT_SHADER, fragment));
        gl.bindAttribLocation(program, 0, "a");
        gl.linkProgram(program);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS))
            throw new Error("link");
        gl.useProgram(program);
        buffer = gl.createBuffer();
        if (!buffer) throw new Error("buffer");
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(
            gl.ARRAY_BUFFER,
            new Float32Array([-1, -1, 3, -1, -1, 3]),
            gl.STATIC_DRAW,
        );
        gl.enableVertexAttribArray(0);
        gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
        const uniform = (name: string) => gl.getUniformLocation(program!, name);
        const resolution = uniform("uRes"),
            clock = uniform("uTime"),
            cell = uniform("uCell");
        palette.forEach((hex, i) => {
            const value = parseInt(hex.slice(1), 16);
            gl.uniform3f(
                uniform(`uC${i}`),
                ((value >> 16) & 255) / 255,
                ((value >> 8) & 255) / 255,
                (value & 255) / 255,
            );
        });
        gl.uniform2f(uniform("uMouse"), 0, 0);
        gl.uniform1f(uniform("uStir"), 0);
        const size = () => {
            if (disposed) return;
            const next = canvasSize(
                canvas.clientWidth,
                canvas.clientHeight,
                globalThis.devicePixelRatio,
            );
            canvas.width = next.width;
            canvas.height = next.height;
            gl.viewport(0, 0, canvas.width, canvas.height);
            gl.uniform2f(resolution, canvas.width, canvas.height);
            gl.uniform1f(cell, Math.max(1, Math.round(2.5 * next.scale)));
        };
        const tick = (now: number) => {
            if (disposed) return;
            const delta =
                last === null ? 1000 / effectBudget.maxFps : now - last;
            if (delta >= 1000 / effectBudget.maxFps) {
                if (!budget.permits(delta)) {
                    stop("performance");
                    return;
                }
                last = now;
                time += Math.min(delta / 1000, 0.1);
                gl.uniform1f(clock, time);
                gl.drawArrays(gl.TRIANGLES, 0, 3);
            }
            raf = requestAnimationFrame(tick);
        };
        observer = new ResizeObserver(size);
        observer.observe(canvas);
        size();
        raf = requestAnimationFrame(tick);
    } catch {
        stop("compile");
    }
    return dispose;
}
