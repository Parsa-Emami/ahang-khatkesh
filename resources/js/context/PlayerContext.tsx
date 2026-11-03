import { playbackPrivacy } from "../features/listening/runtime";
import { useListeningEvents } from "../features/listening/useListeningEvents";
import { tr } from "../i18n/runtime";
import { useLocale } from "../i18n/LocaleProvider";
import {
    createContext,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type PropsWithChildren,
} from "react";
import { api, ApiError, getCsrfToken, json, sessionHeaders } from "../lib/api";
import {
    balancedOrder,
    insertNext,
    moveQueued,
    removeQueued,
    trimUpcoming,
    type EditableQueue,
} from "../lib/playback-tools";
import {
    createSleepTimer,
    sleepExpired,
    type SleepTimer,
} from "../lib/sleep-timer";
import { playbackUrl } from "../lib/playback-quality";
import { createCrossfadeEngine } from "../lib/crossfade-engine";
import { applyOutputVolume } from "../lib/loudness";
import {
    deviceDisplayName,
    deviceKey,
    type PlaybackLease,
} from "../lib/playback-device";
import { useSession } from "./AppContext";
import type { AudioQuality } from "../types";
import { adjacent, makeOrder } from "../lib/queue";
import type { PlaybackSnapshot, RepeatMode, Track } from "../types";

type State = {
    queue: Track[];
    index: number;
    order: number[];
    playing: boolean;
    loading: boolean;
    position: number;
    volume: number;
    shuffle: boolean;
    repeat: RepeatMode;
    error: string | null;
    syncError: string | null;
    sleepTimer: SleepTimer;
    quality: AudioQuality;
    deviceId: string | null;
    leaseToken: string | null;
    leaseState: PlaybackLease["state"] | "unknown";
};
type Player = State & {
    current: Track | null;
    play: (tracks: Track[], start?: number) => void;
    toggle: () => void;
    next: () => void;
    previous: () => void;
    seek: (value: number) => void;
    setVolume: (value: number) => void;
    toggleShuffle: () => void;
    cycleRepeat: () => void;
    select: (index: number) => void;
    setLiked: (id: number, value: boolean) => void;
    enqueueNext: (track: Track) => void;
    removeQueued: (id: number) => void;
    moveQueued: (id: number, offset: -1 | 1) => void;
    clearUpcoming: () => void;
    clearQueue: () => void;
    setQuality: (quality: AudioQuality) => void;
    setSleep: (value: number | "end_of_track" | null) => void;
    applyLease: (lease: PlaybackLease) => void;
};
const Context = createContext<Player | null>(null);
const initial: State = {
    queue: [],
    index: 0,
    order: [],
    playing: false,
    loading: false,
    position: 0,
    volume: 0.8,
    shuffle: false,
    repeat: "off",
    error: null,
    syncError: null,
    sleepTimer: null,
    quality: "auto",
    deviceId: null,
    leaseToken: null,
    leaseState: "unknown",
};

export function PlayerProvider({ children }: PropsWithChildren) {
    const { locale } = useLocale();
    const session = useSession();
    const [state, setState] = useState<State>(initial);
    const [audioEpoch, setAudioEpoch] = useState(0);
    const engine = useMemo(
        () =>
            createCrossfadeEngine(() => {
                const element = new Audio();
                element.preload = "metadata";
                return element;
            }),
        [],
    );
    const sleepChannel = useMemo(
        () =>
            typeof BroadcastChannel === "undefined"
                ? null
                : new BroadcastChannel("ak-sleep-v1"),
        [],
    );
    const audio = engine.active();
    const latest = useRef(state);
    latest.current = state;
    const hydrated = useRef(false),
        revision = useRef(0),
        saving = useRef(false),
        sequence = useRef(0),
        savedSequence = useRef(0),
        conflicted = useRef(false),
        interacted = useRef(false),
        pendingSeek = useRef(0),
        skipSourceReload = useRef(false),
        deviceIdRef = useRef<string | null>(null),
        leaseTokenRef = useRef<string | null>(null);
    const current = state.queue[state.index] || null;
    const streamUrl = playbackUrl(current, state.quality);
    const crossfadeEnabled = !!(
        session.user?.crossfade_enabled && session.user?.crossfade_acknowledged
    );
    const crossfadeSeconds = Math.max(
        1,
        Math.min(12, session.user?.crossfade_seconds ?? 3),
    );
    const normalizeLoudness = session.user?.loudness_normalization !== false;
    useListeningEvents(audio, current?.id);
    void audioEpoch;
    const changed = () => {
        interacted.current = true;
        sequence.current++;
    };

    function editQueue(
        change: (queue: EditableQueue<Track>) => EditableQueue<Track>,
    ) {
        changed();
        setState((s) => {
            const ordered = s.order.map((i) => s.queue[i]);
            const index = ordered.findIndex(
                (t) => t.id === s.queue[s.index]?.id,
            );
            const next = change({
                queue: ordered,
                index: Math.max(0, index),
                playing: s.playing,
            });
            if (next.queue[next.index]?.id !== s.queue[s.index]?.id)
                pendingSeek.current = 0;
            return {
                ...s,
                ...next,
                order: makeOrder(next.queue.length, next.index, false),
                position:
                    next.queue[next.index]?.id === s.queue[s.index]?.id
                        ? s.position
                        : 0,
            };
        });
    }
    function stopForSleep() {
        changed();
        engine.cancelFade();
        engine.pause();
        latest.current = {
            ...latest.current,
            playing: false,
            sleepTimer: null,
        };
        setState((s) => ({ ...s, playing: false, sleepTimer: null }));
        void persist();
    }
    function jump(index: number) {
        if (!latest.current.queue[index]) return;
        changed();
        engine.cancelFade();
        pendingSeek.current = 0;
        if (index === latest.current.index) engine.seek(0);
        setState((s) => ({
            ...s,
            index,
            position: 0,
            playing: true,
            error: null,
        }));
    }
    function next() {
        const s = latest.current;
        let order = s.order;
        if (
            s.shuffle &&
            s.repeat === "all" &&
            order.indexOf(s.index) === order.length - 1
        ) {
            order = balancedOrder(s.queue, s.index);
            setState((value) => ({ ...value, order }));
        }
        const index = adjacent(order, s.index, 1, s.repeat === "all");
        if (index === null) {
            changed();
            setState((v) => ({ ...v, playing: false }));
            return;
        }
        if (index === s.index) {
            engine.seek(0);
            void engine.play().catch(() => {});
            changed();
            return;
        }
        jump(index);
    }
    function previous() {
        if (engine.currentTime() > 3) {
            seek(0);
            return;
        }
        const s = latest.current;
        const index = adjacent(s.order, s.index, -1, s.repeat === "all");
        if (index !== null) jump(index);
        else seek(0);
    }
    function toggle() {
        if (!latest.current.queue.length) return;
        changed();
        engine.cancelFade();
        if (latest.current.error) engine.active().load();
        setState((s) => ({ ...s, playing: !s.playing, error: null }));
    }
    function seek(value: number) {
        const track = latest.current.queue[latest.current.index];
        if (!track) return;
        const position = Math.min(track.duration_seconds, Math.max(0, value));
        changed();
        engine.cancelFade();
        pendingSeek.current = position;
        if (engine.active().readyState >= 1) engine.seek(position);
        setState((s) => ({ ...s, position }));
    }
    function outputVolumeFor(track: Track | null, volume: number) {
        return applyOutputVolume(
            volume,
            track?.loudness_lufs ?? null,
            normalizeLoudness,
        );
    }
    function applyLease(lease: PlaybackLease) {
        const mine =
            !!deviceIdRef.current && lease.device_id === deviceIdRef.current;
        if (mine) leaseTokenRef.current = lease.lease_token;
        else if (lease.state === "active") leaseTokenRef.current = null;
        setState((s) => ({
            ...s,
            deviceId: deviceIdRef.current ?? lease.device_id,
            leaseToken: mine ? lease.lease_token : null,
            leaseState: lease.state,
            syncError: mine && lease.state === "active" ? null : s.syncError,
        }));
        if (mine && lease.state === "active") conflicted.current = false;
    }
    function fenceLocal(message: string) {
        conflicted.current = true;
        leaseTokenRef.current = null;
        engine.pause();
        setState((s) => ({
            ...s,
            playing: false,
            leaseToken: null,
            leaseState: "idle",
            syncError: message,
        }));
    }
    async function persist() {
        if (
            !hydrated.current ||
            saving.current ||
            conflicted.current ||
            sequence.current === savedSequence.current
        )
            return;
        const snapshot = latest.current,
            atSequence = sequence.current;
        saving.current = true;
        try {
            const result = await api<{ data: { revision: number } }>(
                "/playback",
                {
                    method: "PUT",
                    body: json({
                        ...playbackPrivacy(),
                        device_id: deviceIdRef.current,
                        lease_token: leaseTokenRef.current,
                        track_id: snapshot.queue[snapshot.index]?.id || null,
                        queue: snapshot.order.map((i) => snapshot.queue[i].id),
                        position_seconds: snapshot.position,
                        volume: snapshot.volume,
                        shuffle: snapshot.shuffle,
                        repeat_mode: snapshot.repeat,
                        revision: revision.current,
                    }),
                },
            );
            revision.current = result.data.revision;
            savedSequence.current = atSequence;
            setState((s) => ({ ...s, syncError: null }));
        } catch (error) {
            if (error instanceof ApiError && error.status === 409) {
                if (error.code === "playback_device_fenced")
                    fenceLocal(error.message);
                else {
                    conflicted.current = true;
                    setState((s) => ({ ...s, syncError: error.message }));
                }
            } else
                setState((s) => ({
                    ...s,
                    syncError: tr("m77264156d3"),
                }));
        } finally {
            saving.current = false;
        }
    }

    useEffect(() => {
        let cancelled = false;
        async function boot() {
            try {
                if (session.user) {
                    const registered = await api<{
                        data: { id: string };
                    }>("/playback/devices", {
                        method: "POST",
                        body: json({
                            device_key: deviceKey(),
                            name: deviceDisplayName(),
                        }),
                    });
                    if (cancelled) return;
                    deviceIdRef.current = registered.data.id;
                    const claimed = await api<{ data: PlaybackLease }>(
                        `/playback/devices/${registered.data.id}/claim`,
                        { method: "POST" },
                    );
                    if (cancelled) return;
                    applyLease(claimed.data);
                }
                const { data } = await api<{ data: PlaybackSnapshot }>(
                    "/playback",
                );
                if (cancelled) return;
                revision.current = data.revision;
                hydrated.current = true;
                if (!interacted.current) {
                    const index = Math.max(
                        0,
                        data.queue.findIndex((t) => t.id === data.track_id),
                    );
                    pendingSeek.current = data.position_seconds;
                    setState((s) => ({
                        ...initial,
                        queue: data.queue,
                        index,
                        order: makeOrder(data.queue.length, index, false),
                        position: data.position_seconds,
                        volume: data.volume,
                        shuffle: data.shuffle,
                        repeat: data.repeat_mode,
                        deviceId: s.deviceId,
                        leaseToken: s.leaseToken,
                        leaseState: s.leaseState,
                    }));
                }
            } catch {
                if (!cancelled)
                    setState((s) => ({
                        ...s,
                        syncError: tr("m6e4360d42d"),
                    }));
            }
        }
        void boot();
        const timer = window.setInterval(() => void persist(), 15000);
        return () => {
            cancelled = true;
            window.clearInterval(timer);
            engine.dispose();
        };
    }, [engine, session.user?.id]);
    useEffect(() => {
        if (!state.deviceId || !state.leaseToken) return;
        const deviceId = state.deviceId;
        const token = state.leaseToken;
        const beat = window.setInterval(() => {
            void api<{ data: PlaybackLease }>(
                `/playback/devices/${deviceId}/heartbeat`,
                {
                    method: "POST",
                    body: json({ lease_token: token }),
                },
            )
                .then((result) => applyLease(result.data))
                .catch((error) => {
                    if (
                        error instanceof ApiError &&
                        error.code === "playback_device_fenced"
                    )
                        fenceLocal(error.message);
                });
        }, 30000);
        const poll = window.setInterval(() => {
            void api<{
                data: Array<{ type: string; payload: Record<string, unknown> }>;
            }>(`/playback/devices/${deviceId}/commands`)
                .then(({ data }) => {
                    for (const command of data) {
                        if (
                            command.type === "fence" ||
                            command.type === "revoke" ||
                            command.type === "transfer_accept"
                        ) {
                            fenceLocal(tr("devices.fenced"));
                            engine.pause();
                        }
                        if (command.type === "pause") {
                            engine.pause();
                            setState((s) => ({ ...s, playing: false }));
                        }
                        if (
                            command.type === "seek" &&
                            typeof command.payload.position_seconds === "number"
                        )
                            engine.seek(
                                command.payload.position_seconds as number,
                            );
                        if (command.type === "transfer_offer")
                            setState((s) => ({
                                ...s,
                                leaseState: "transferring",
                            }));
                        if (command.type === "transfer_fallback")
                            setState((s) => ({
                                ...s,
                                leaseState: "active",
                                syncError: null,
                            }));
                    }
                })
                .catch(() => {});
        }, 4000);
        const onVisible = () => {
            if (document.visibilityState === "visible")
                void api<{ data: PlaybackLease }>(
                    `/playback/devices/${deviceId}/heartbeat`,
                    {
                        method: "POST",
                        body: json({ lease_token: token }),
                    },
                )
                    .then((result) => applyLease(result.data))
                    .catch(() => {});
        };
        document.addEventListener("visibilitychange", onVisible);
        return () => {
            window.clearInterval(beat);
            window.clearInterval(poll);
            document.removeEventListener("visibilitychange", onVisible);
        };
    }, [state.deviceId, state.leaseToken, engine]);

    useEffect(() => {
        if (skipSourceReload.current) {
            skipSourceReload.current = false;
            return;
        }
        if (!current) {
            engine.setSource(null);
            return;
        }
        engine.setSource(streamUrl);
    }, [current?.id, streamUrl, engine]);

    useEffect(() => {
        if (!current) {
            document.title = tr("mb39fa3f0f8");
            if ("mediaSession" in navigator)
                navigator.mediaSession.metadata = null;
            return;
        }
        document.title = tr("ma0ff9ce0f3", { v0: current.title });
        if ("mediaSession" in navigator)
            navigator.mediaSession.metadata = new MediaMetadata({
                title: current.title,
                artist: current.artist,
                album: current.album || tr("mb39fa3f0f8"),
            });
    }, [locale, current?.id, current?.title, current?.artist, current?.album]);

    useEffect(() => {
        if (!current) return;
        engine.setOutputVolume(outputVolumeFor(current, state.volume));
        if (state.playing)
            void engine.play().catch((error) => {
                if (error.name !== "AbortError")
                    setState((s) => ({
                        ...s,
                        playing: false,
                        error: tr("m64a408670e"),
                    }));
            });
        else {
            engine.pause();
            void persist();
        }
        if ("mediaSession" in navigator)
            navigator.mediaSession.playbackState = state.playing
                ? "playing"
                : "paused";
    }, [
        state.playing,
        current?.id,
        streamUrl,
        state.volume,
        normalizeLoudness,
        audioEpoch,
    ]);
    useEffect(() => {
        engine.setOutputVolume(outputVolumeFor(current, state.volume));
    }, [
        state.volume,
        current?.loudness_lufs,
        normalizeLoudness,
        engine,
        audioEpoch,
    ]);
    useEffect(() => {
        const active = () => engine.active();
        const time = () => {
            if (sleepExpired(latest.current.sleepTimer)) {
                stopForSleep();
                return;
            }
            if (latest.current.playing) sequence.current++;
            const position = engine.currentTime();
            setState((s) => ({ ...s, position }));
            const s = latest.current;
            if (
                !crossfadeEnabled ||
                s.repeat === "one" ||
                !s.playing ||
                engine.fading()
            )
                return;
            const duration =
                engine.duration() || s.queue[s.index]?.duration_seconds || 0;
            if (!duration) return;
            const remaining = duration - position;
            let order = s.order;
            const nextIndex = adjacent(order, s.index, 1, s.repeat === "all");
            if (nextIndex === null || nextIndex === s.index) return;
            const nextTrack = s.queue[nextIndex];
            const nextUrl = playbackUrl(nextTrack, s.quality);
            if (!nextUrl) return;
            engine.maybeCrossfade({
                nextUrl,
                seconds: crossfadeSeconds,
                remaining,
                onSwap: () => {
                    skipSourceReload.current = true;
                    changed();
                    setState((value) => ({
                        ...value,
                        index: nextIndex,
                        position: 0,
                        playing: true,
                        error: null,
                    }));
                    setAudioEpoch((value) => value + 1);
                },
            });
        };
        const metadata = () => {
            const element = active();
            if (pendingSeek.current > 0 && Number.isFinite(element.duration))
                element.currentTime = Math.min(
                    pendingSeek.current,
                    element.duration,
                );
            pendingSeek.current = 0;
        };
        const ended = () => {
            if (engine.fading()) return;
            if (sleepExpired(latest.current.sleepTimer, Date.now(), true)) {
                stopForSleep();
                return;
            }
            if (latest.current.repeat === "one") {
                engine.seek(0);
                void engine.play().catch(() => {});
                return;
            }
            next();
        };
        const waiting = () => setState((s) => ({ ...s, loading: true }));
        const ready = () => setState((s) => ({ ...s, loading: false }));
        const failed = () =>
            setState((s) => ({
                ...s,
                playing: false,
                loading: false,
                error: tr("m548a910dd8"),
            }));
        const element = active();
        element.addEventListener("timeupdate", time);
        element.addEventListener("loadedmetadata", metadata);
        element.addEventListener("ended", ended);
        element.addEventListener("waiting", waiting);
        element.addEventListener("playing", ready);
        element.addEventListener("canplay", ready);
        element.addEventListener("error", failed);
        const keyboard = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement;
            if (
                event.ctrlKey ||
                event.metaKey ||
                event.altKey ||
                target.closest(
                    'input,textarea,select,button,[contenteditable="true"],[role="dialog"]',
                )
            )
                return;
            if (event.code === "Space" || event.key.toLowerCase() === "k") {
                event.preventDefault();
                toggle();
            }
            if (event.key === "ArrowRight") {
                event.preventDefault();
                seek(engine.currentTime() + 5);
            }
            if (event.key === "ArrowLeft") {
                event.preventDefault();
                seek(engine.currentTime() - 5);
            }
        };
        window.addEventListener("keydown", keyboard);
        if ("mediaSession" in navigator) {
            const actions: [MediaSessionAction, MediaSessionActionHandler][] = [
                [
                    "play",
                    () => {
                        changed();
                        setState((s) => ({ ...s, playing: true }));
                    },
                ],
                [
                    "pause",
                    () => {
                        changed();
                        setState((s) => ({ ...s, playing: false }));
                    },
                ],
                ["nexttrack", next],
                ["previoustrack", previous],
                ["seekto", (details) => seek(details.seekTime || 0)],
                [
                    "seekforward",
                    (details) =>
                        seek(engine.currentTime() + (details.seekOffset || 10)),
                ],
                [
                    "seekbackward",
                    (details) =>
                        seek(engine.currentTime() - (details.seekOffset || 10)),
                ],
            ];
            for (const [action, handler] of actions) {
                try {
                    navigator.mediaSession.setActionHandler(action, handler);
                } catch {}
            }
        }
        return () => {
            element.removeEventListener("timeupdate", time);
            element.removeEventListener("loadedmetadata", metadata);
            element.removeEventListener("ended", ended);
            element.removeEventListener("waiting", waiting);
            element.removeEventListener("playing", ready);
            element.removeEventListener("canplay", ready);
            element.removeEventListener("error", failed);
            window.removeEventListener("keydown", keyboard);
            if ("mediaSession" in navigator)
                for (const action of [
                    "play",
                    "pause",
                    "nexttrack",
                    "previoustrack",
                    "seekto",
                    "seekforward",
                    "seekbackward",
                ] as MediaSessionAction[])
                    try {
                        navigator.mediaSession.setActionHandler(action, null);
                    } catch {}
        };
    }, [audio, audioEpoch, crossfadeEnabled, crossfadeSeconds]);
    useEffect(() => {
        const flush = () => {
            if (
                !hydrated.current ||
                saving.current ||
                conflicted.current ||
                sequence.current === savedSequence.current
            )
                return;
            const s = latest.current;
            void fetch("/api/v1/playback", {
                method: "PUT",
                credentials: "same-origin",
                keepalive: true,
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": getCsrfToken(),
                    ...sessionHeaders(),
                },
                body: json({
                    ...playbackPrivacy(),
                    device_id: deviceIdRef.current,
                    lease_token: leaseTokenRef.current,
                    track_id: s.queue[s.index]?.id || null,
                    queue: s.order.map((i) => s.queue[i].id),
                    position_seconds: s.position,
                    volume: s.volume,
                    shuffle: s.shuffle,
                    repeat_mode: s.repeat,
                    revision: revision.current,
                }),
            });
        };
        window.addEventListener("pagehide", flush);
        return () => window.removeEventListener("pagehide", flush);
    }, []);

    useEffect(() => {
        if (!sleepChannel) return;
        const onMessage = (event: MessageEvent) => {
            const data = event.data;
            const valid =
                data === null ||
                data?.kind === "end_of_track" ||
                (data?.kind === "deadline" &&
                    typeof data.deadline === "number");
            if (!valid) return;
            setState((s) => ({ ...s, sleepTimer: data }));
        };
        sleepChannel.addEventListener("message", onMessage);
        return () => {
            sleepChannel.removeEventListener("message", onMessage);
            sleepChannel.close();
        };
    }, [sleepChannel]);
    useEffect(() => {
        const timer = state.sleepTimer;
        if (timer?.kind !== "deadline") return;
        const check = () => {
            if (sleepExpired(latest.current.sleepTimer)) stopForSleep();
        };
        const timeout = window.setTimeout(
            check,
            Math.max(0, timer.deadline - Date.now()),
        );
        document.addEventListener("visibilitychange", check);
        return () => {
            clearTimeout(timeout);
            document.removeEventListener("visibilitychange", check);
        };
    }, [state.sleepTimer]);
    const value: Player = {
        ...state,
        current,
        play(tracks, start = 0) {
            const unique = [
                ...new Map(tracks.map((t) => [t.id, t])).values(),
            ].slice(0, 500);
            if (!unique[start]) return;
            changed();
            pendingSeek.current = 0;
            if (
                unique[start].id ===
                latest.current.queue[latest.current.index]?.id
            )
                engine.seek(0);
            setState((s) => ({
                ...s,
                queue: unique,
                index: start,
                order: s.shuffle
                    ? balancedOrder(unique, start)
                    : makeOrder(unique.length, start, false),
                position: 0,
                playing: true,
                error: null,
            }));
        },
        toggle,
        next,
        previous,
        seek,
        setVolume(volume) {
            changed();
            setState((s) => ({
                ...s,
                volume: Math.max(0, Math.min(1, volume)),
            }));
        },
        toggleShuffle() {
            changed();
            setState((s) => ({
                ...s,
                shuffle: !s.shuffle,
                order: !s.shuffle
                    ? balancedOrder(s.queue, s.index)
                    : makeOrder(s.queue.length, s.index, false),
            }));
        },
        cycleRepeat() {
            changed();
            setState((s) => ({
                ...s,
                repeat:
                    s.repeat === "off"
                        ? "all"
                        : s.repeat === "all"
                          ? "one"
                          : "off",
            }));
        },
        select: jump,
        enqueueNext: (track) => editQueue((s) => insertNext(s, track)),
        removeQueued: (id) =>
            editQueue((s) =>
                removeQueued(
                    s,
                    s.queue.findIndex((t) => t.id === id),
                ),
            ),
        moveQueued: (id, offset) =>
            editQueue((s) =>
                moveQueued(
                    s,
                    s.queue.findIndex((t) => t.id === id),
                    offset,
                ),
            ),
        clearUpcoming: () => editQueue(trimUpcoming),
        clearQueue: () =>
            editQueue(() => ({ queue: [], index: 0, playing: false })),
        setQuality(quality) {
            engine.cancelFade();
            pendingSeek.current =
                engine.currentTime() || latest.current.position;
            setState((s) => ({ ...s, quality, error: null }));
        },
        setSleep(value) {
            const timer = createSleepTimer(value);
            setState((s) => ({ ...s, sleepTimer: timer }));
            sleepChannel?.postMessage(timer);
        },
        setLiked(id, is_liked) {
            setState((s) => ({
                ...s,
                queue: s.queue.map((t) =>
                    t.id === id ? { ...t, is_liked } : t,
                ),
            }));
        },
        applyLease,
    };
    return <Context.Provider value={value}>{children}</Context.Provider>;
}
export function usePlayer() {
    const context = useContext(Context);
    if (!context) throw new Error("PlayerProvider is required");
    return context;
}
