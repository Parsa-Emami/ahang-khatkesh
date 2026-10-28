import {
    createContext,
    useContext,
    useEffect,
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
};

export function PlayerProvider({ children }: PropsWithChildren) {
    const [state, setState] = useState<State>(initial);
    const [audio] = useState(() => {
        const element = new Audio();
        element.preload = "metadata";
        return element;
    });
    const latest = useRef(state);
    latest.current = state;
    const hydrated = useRef(false),
        revision = useRef(0),
        saving = useRef(false),
        sequence = useRef(0),
        savedSequence = useRef(0),
        conflicted = useRef(false),
        interacted = useRef(false),
        pendingSeek = useRef(0);
    const current = state.queue[state.index] || null;
    const streamUrl = playbackUrl(current, state.quality);
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
        audio.pause();
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
        pendingSeek.current = 0;
        if (index === latest.current.index) audio.currentTime = 0;
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
            audio.currentTime = 0;
            void audio.play().catch(() => {});
            changed();
            return;
        }
        jump(index);
    }
    function previous() {
        if (audio.currentTime > 3) {
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
        if (latest.current.error) audio.load();
        setState((s) => ({ ...s, playing: !s.playing, error: null }));
    }
    function seek(value: number) {
        const track = latest.current.queue[latest.current.index];
        if (!track) return;
        const position = Math.min(track.duration_seconds, Math.max(0, value));
        changed();
        pendingSeek.current = position;
        if (audio.readyState >= 1) audio.currentTime = position;
        setState((s) => ({ ...s, position }));
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
                conflicted.current = true;
                setState((s) => ({ ...s, syncError: error.message }));
            } else
                setState((s) => ({
                    ...s,
                    syncError: "پیشرفت پخش ذخیره نشد؛ اتصال را بررسی کنید.",
                }));
        } finally {
            saving.current = false;
        }
    }

    useEffect(() => {
        let cancelled = false;
        api<{ data: PlaybackSnapshot }>("/playback")
            .then(({ data }) => {
                if (cancelled) return;
                revision.current = data.revision;
                hydrated.current = true;
                if (!interacted.current) {
                    const index = Math.max(
                        0,
                        data.queue.findIndex((t) => t.id === data.track_id),
                    );
                    pendingSeek.current = data.position_seconds;
                    setState({
                        ...initial,
                        queue: data.queue,
                        index,
                        order: makeOrder(data.queue.length, index, false),
                        position: data.position_seconds,
                        volume: data.volume,
                        shuffle: data.shuffle,
                        repeat: data.repeat_mode,
                    });
                }
            })
            .catch(() => {
                if (!cancelled)
                    setState((s) => ({
                        ...s,
                        syncError:
                            "بازیابی وضعیت پخش ناموفق بود. صفحه را تازه کنید.",
                    }));
            });
        const timer = window.setInterval(() => void persist(), 15000);
        return () => {
            cancelled = true;
            window.clearInterval(timer);
            audio.pause();
            audio.removeAttribute("src");
            audio.load();
        };
    }, [audio]);

    useEffect(() => {
        if (!current) {
            audio.pause();
            audio.removeAttribute("src");
            document.title = "آهنگ خط‌کش";
            if ("mediaSession" in navigator)
                navigator.mediaSession.metadata = null;
            return;
        }
        audio.src = streamUrl;
        audio.load();
        document.title = `${current.title} · آهنگ خط‌کش`;
        if ("mediaSession" in navigator)
            navigator.mediaSession.metadata = new MediaMetadata({
                title: current.title,
                artist: current.artist,
                album: current.album || "آهنگ خط‌کش",
            });
    }, [current?.id, streamUrl, audio]);

    useEffect(() => {
        if (!current) return;
        if (state.playing)
            void audio.play().catch((error) => {
                if (error.name !== "AbortError")
                    setState((s) => ({
                        ...s,
                        playing: false,
                        error: "پخش آغاز نشد. دوباره دکمهٔ پخش را بزنید.",
                    }));
            });
        else {
            audio.pause();
            void persist();
        }
        if ("mediaSession" in navigator)
            navigator.mediaSession.playbackState = state.playing
                ? "playing"
                : "paused";
    }, [state.playing, current?.id, streamUrl, audio]);
    useEffect(() => {
        audio.volume = state.volume;
    }, [state.volume, audio]);
    useEffect(() => {
        const time = () => {
            if (sleepExpired(latest.current.sleepTimer)) {
                stopForSleep();
                return;
            }
            if (latest.current.playing) sequence.current++;
            setState((s) => ({ ...s, position: audio.currentTime }));
        };
        const metadata = () => {
            if (pendingSeek.current > 0 && Number.isFinite(audio.duration))
                audio.currentTime = Math.min(
                    pendingSeek.current,
                    audio.duration,
                );
            pendingSeek.current = 0;
        };
        const ended = () => {
            if (sleepExpired(latest.current.sleepTimer, Date.now(), true)) {
                stopForSleep();
                return;
            }
            if (latest.current.repeat === "one") {
                audio.currentTime = 0;
                void audio.play().catch(() => {});
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
                error: "این فایل پخش نشد؛ دسترسی یا قالب فایل را بررسی کنید.",
            }));
        audio.addEventListener("timeupdate", time);
        audio.addEventListener("loadedmetadata", metadata);
        audio.addEventListener("ended", ended);
        audio.addEventListener("waiting", waiting);
        audio.addEventListener("playing", ready);
        audio.addEventListener("canplay", ready);
        audio.addEventListener("error", failed);
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
                seek(audio.currentTime + 5);
            }
            if (event.key === "ArrowLeft") {
                event.preventDefault();
                seek(audio.currentTime - 5);
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
                        seek(audio.currentTime + (details.seekOffset || 10)),
                ],
                [
                    "seekbackward",
                    (details) =>
                        seek(audio.currentTime - (details.seekOffset || 10)),
                ],
            ];
            for (const [action, handler] of actions) {
                try {
                    navigator.mediaSession.setActionHandler(action, handler);
                } catch {}
            }
        }
        return () => {
            audio.removeEventListener("timeupdate", time);
            audio.removeEventListener("loadedmetadata", metadata);
            audio.removeEventListener("ended", ended);
            audio.removeEventListener("waiting", waiting);
            audio.removeEventListener("playing", ready);
            audio.removeEventListener("canplay", ready);
            audio.removeEventListener("error", failed);
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
    }, [audio]);
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
                audio.currentTime = 0;
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
            pendingSeek.current = audio.currentTime || latest.current.position;
            setState((s) => ({ ...s, quality, error: null }));
        },
        setSleep(value) {
            setState((s) => ({ ...s, sleepTimer: createSleepTimer(value) }));
        },
        setLiked(id, is_liked) {
            setState((s) => ({
                ...s,
                queue: s.queue.map((t) =>
                    t.id === id ? { ...t, is_liked } : t,
                ),
            }));
        },
    };
    return <Context.Provider value={value}>{children}</Context.Provider>;
}
export function usePlayer() {
    const context = useContext(Context);
    if (!context) throw new Error("PlayerProvider is required");
    return context;
}
