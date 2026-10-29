import { useEffect } from "react";
import {
    api,
    captureSessionIntent,
    getCsrfToken,
    json,
    sessionHeaders,
} from "../../lib/api";
import { canRecord, ListeningClock, listeningRuntime } from "./runtime";
type Grant = {
    recording: boolean;
    token?: string;
    expires_at?: string;
    minimum_seconds?: number;
};
type Visit = {
    clock: ListeningClock;
    epoch: number;
    grant: Promise<Grant>;
    terminal: boolean;
    sending: Promise<void>;
    minimum: number;
    ready: boolean;
    expires: number;
};
export function useListeningEvents(audio: HTMLAudioElement, trackId?: number) {
    useEffect(() => {
        let visit: Visit | null = null,
            alive = true,
            retryAt = 0;
        const audible = () =>
            !audio.paused &&
            !audio.ended &&
            !audio.muted &&
            audio.volume > 0 &&
            audio.readyState >= 2;
        const sample = () =>
            visit?.ready &&
            visit.clock.sample(
                audio.currentTime,
                performance.now(),
                audible(),
                audio.seeking,
            );
        const allowed = (item: Visit) =>
            canRecord() && item.epoch === listeningRuntime().epoch;
        const send = (
            item: Visit,
            kind: "progress" | "skip" | "completed",
            keepalive = false,
        ) => {
            const seconds = Math.floor(item.clock.seconds * 1000) / 1000;
            item.sending = item.sending
                .catch(() => {})
                .then(async () => {
                    const grant = await item.grant;
                    if (
                        !allowed(item) ||
                        !grant.recording ||
                        !grant.token ||
                        seconds < item.minimum
                    )
                        return;
                    const body = {
                        token: grant.token,
                        kind,
                        listened_seconds: seconds,
                    };
                    if (keepalive) {
                        void fetch("/api/v1/listening/events", {
                            method: "POST",
                            credentials: "same-origin",
                            keepalive: true,
                            headers: {
                                "Content-Type": "application/json",
                                Accept: "application/json",
                                "X-CSRF-TOKEN": getCsrfToken(),
                                ...sessionHeaders(),
                            },
                            body: json(body),
                        }).catch(() => {});
                    } else
                        await api(
                            "/listening/events",
                            { method: "POST", body: json(body) },
                            true,
                            true,
                            captureSessionIntent(body),
                        );
                })
                .catch(() => {});
        };
        const ensure = () => {
            if (!alive || !trackId || !canRecord() || !audible()) return;
            if (Date.now() < retryAt) return;
            if (visit && Date.now() >= visit.expires) visit = null;
            if (visit) return;
            const current = listeningRuntime();
            const item: Visit = {
                clock: new ListeningClock(),
                epoch: current.epoch,
                grant: Promise.resolve({ recording: false }),
                terminal: false,
                sending: Promise.resolve(),
                minimum: Infinity,
                ready: false,
                expires: Infinity,
            };
            const data = {
                track_id: trackId,
                privacy_revision: current.settings!.revision,
            };
            item.clock.resetBaseline(audio.currentTime, performance.now());
            item.grant = api<{ data: Grant }>(
                "/listening/plays",
                { method: "POST", body: json(data) },
                true,
                true,
                captureSessionIntent(data),
            )
                .then(({ data }) => {
                    item.minimum = data.minimum_seconds ?? Infinity;
                    item.expires = data.expires_at
                        ? Date.parse(data.expires_at)
                        : Infinity;
                    if (
                        allowed(item) &&
                        alive &&
                        visit === item &&
                        !item.terminal
                    ) {
                        item.ready = data.recording;
                        item.clock.resetBaseline(
                            audio.currentTime,
                            performance.now(),
                        );
                    }
                    return data;
                })
                .catch(() => {
                    if (visit === item) {
                        visit = null;
                        retryAt = Date.now() + 30000;
                    }
                    return { recording: false };
                });
            visit = item;
        };
        const time = () => {
            ensure();
            sample();
        };
        const baseline = () => {
            ensure();
            visit?.clock.resetBaseline(audio.currentTime, performance.now());
        };
        const pause = () => {
            sample();
            if (visit) send(visit, "progress");
        };
        const finish = (kind: "skip" | "completed", keepalive = false) => {
            sample();
            if (visit && !visit.terminal) {
                visit.terminal = true;
                send(visit, kind, keepalive);
            }
            visit = null;
        };
        const ended = () => finish("completed");
        const changed = () => {
            visit = null;
            retryAt = 0;
            ensure();
        };
        const hide = () => finish("skip", true);
        const timer = window.setInterval(() => {
            sample();
            if (visit) send(visit, "progress");
        }, 15000);
        audio.addEventListener("timeupdate", time);
        audio.addEventListener("playing", baseline);
        audio.addEventListener("seeking", baseline);
        audio.addEventListener("seeked", baseline);
        audio.addEventListener("waiting", baseline);
        audio.addEventListener("loadstart", baseline);
        audio.addEventListener("pause", pause);
        audio.addEventListener("ended", ended);
        window.addEventListener("listening-context-changed", changed);
        window.addEventListener("pagehide", hide);
        ensure();
        return () => {
            alive = false;
            finish("skip");
            clearInterval(timer);
            audio.removeEventListener("timeupdate", time);
            audio.removeEventListener("playing", baseline);
            audio.removeEventListener("seeking", baseline);
            audio.removeEventListener("seeked", baseline);
            audio.removeEventListener("waiting", baseline);
            audio.removeEventListener("loadstart", baseline);
            audio.removeEventListener("pause", pause);
            audio.removeEventListener("ended", ended);
            window.removeEventListener("listening-context-changed", changed);
            window.removeEventListener("pagehide", hide);
        };
    }, [audio, trackId]);
}
