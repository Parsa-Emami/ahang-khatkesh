export type ListeningSettings = {
    history_enabled: boolean;
    recommendations_enabled: boolean;
    retention_days: 7 | 30 | 90;
    private_mode: boolean;
    private_until: string | null;
    revision: number;
};
type Runtime = {
    actor: number | null;
    settings: ListeningSettings | null;
    blocked: boolean;
    epoch: number;
};
let runtime: Runtime = { actor: null, settings: null, blocked: true, epoch: 0 };
export const listeningRuntime = () => runtime;
export function setListeningRuntime(
    actor: number | null,
    settings: ListeningSettings | null,
    blocked: boolean,
) {
    if (
        runtime.actor === actor &&
        runtime.settings?.revision === settings?.revision &&
        runtime.settings?.private_mode === settings?.private_mode &&
        runtime.blocked === blocked
    )
        return;
    runtime = { actor, settings, blocked, epoch: runtime.epoch + 1 };
    if (typeof window !== "undefined")
        window.dispatchEvent(new Event("listening-context-changed"));
}
export function playbackPrivacy() {
    return {
        listening_revision: runtime.settings?.revision ?? 0,
        private_mode:
            runtime.blocked ||
            !runtime.settings ||
            runtime.settings.private_mode,
    };
}
export function canRecord() {
    return (
        !!runtime.actor &&
        !!runtime.settings?.history_enabled &&
        !runtime.settings.private_mode &&
        !runtime.blocked
    );
}
export class ListeningClock {
    seconds = 0;
    private last: { position: number; time: number; audible: boolean } | null =
        null;
    sample(position: number, time: number, audible: boolean, seeking = false) {
        if (this.last && this.last.audible && audible && !seeking) {
            const delta = position - this.last.position;
            if (delta > 0)
                this.seconds += Math.min(
                    delta,
                    Math.max(0, (time - this.last.time) / 1000),
                );
        }
        this.last = { position, time, audible: audible && !seeking };
        return this.seconds;
    }
    resetBaseline(position: number, time: number) {
        this.last = { position, time, audible: false };
    }
}
