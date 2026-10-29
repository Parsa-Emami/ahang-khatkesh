export const effectBudget = {
    maxDpr: 1.5,
    maxPixels: 320_000,
    maxFps: 30,
    maxOptionalGzipBytes: 60 * 1024,
} as const;

export function canvasSize(width: number, height: number, deviceDpr: number) {
    const scale = Math.min(
        effectBudget.maxDpr,
        Math.max(0.1, deviceDpr || 1),
        Math.sqrt(effectBudget.maxPixels / Math.max(1, width * height)),
    );
    return {
        width: Math.max(1, Math.floor(width * scale)),
        height: Math.max(1, Math.floor(height * scale)),
        scale,
    };
}

export class FrameBudget {
    private warmup = 15;
    private intervals: number[] = [];
    permits(interval: number) {
        if (this.warmup-- > 0) return true;
        this.intervals.push(interval);
        if (this.intervals.length > 45) this.intervals.shift();
        return !(
            this.intervals.length === 45 &&
            this.intervals.filter((value) => value > 70).length >= 36
        );
    }
}

type Candidate = { priority: number; order: number };
export class EffectCoordinator {
    private candidates = new Map<symbol, Candidate>();
    private listeners = new Set<() => void>();
    private order = 0;
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    winner = () => {
        let winner: symbol | null = null;
        let selected: Candidate | null = null;
        for (const [key, value] of this.candidates) {
            if (
                !selected ||
                value.priority > selected.priority ||
                (value.priority === selected.priority &&
                    value.order < selected.order)
            ) {
                winner = key;
                selected = value;
            }
        }
        return winner;
    };
    enter(key: symbol, priority: number) {
        this.candidates.set(key, { priority, order: this.order++ });
        this.listeners.forEach((listener) => listener());
        return () => {
            this.candidates.delete(key);
            this.listeners.forEach((listener) => listener());
        };
    }
}
