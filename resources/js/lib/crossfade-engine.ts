export type PlaybackCapabilities = {
    dualAudio: boolean;
    webAudio: boolean;
    mediaSession: boolean;
    safariLike: boolean;
    gaplessNative: boolean;
    bluetoothUnreliable: boolean;
    notes: string[];
};

export type CrossfadeEngine = {
    active: () => HTMLAudioElement;
    standby: () => HTMLAudioElement;
    setSource: (url: string | null) => void;
    play: () => Promise<void>;
    pause: () => void;
    seek: (seconds: number) => void;
    setOutputVolume: (volume: number) => void;
    currentTime: () => number;
    duration: () => number;
    fading: () => boolean;
    maybeCrossfade: (input: {
        nextUrl: string;
        seconds: number;
        remaining: number;
        onSwap: () => void;
    }) => boolean;
    cancelFade: () => void;
    dispose: () => void;
    capabilities: PlaybackCapabilities;
};

export function detectPlaybackCapabilities(
    ua = typeof navigator === "undefined" ? "" : navigator.userAgent,
): PlaybackCapabilities {
    const safariLike =
        /iP(hone|ad|od)|Safari/i.test(ua) && !/Chrome|CriOS|Edg/i.test(ua);
    const notes = [
        "HTMLAudio crossfade is an overlap fade, not codec-level gapless.",
        "Safari/iOS may suspend background tabs and interrupt dual-element fades.",
        "Bluetooth route changes can reset Audio elements; fade is cancelled safely.",
    ];
    if (safariLike)
        notes.push(
            "Safari-like engines do not expose a reliable native gapless API for this player.",
        );
    return {
        dualAudio: typeof Audio !== "undefined",
        webAudio:
            typeof AudioContext !== "undefined" ||
            typeof (globalThis as { webkitAudioContext?: unknown })
                .webkitAudioContext !== "undefined",
        mediaSession:
            typeof navigator !== "undefined" && "mediaSession" in navigator,
        safariLike,
        gaplessNative: false,
        bluetoothUnreliable: true,
        notes,
    };
}

export function createCrossfadeEngine(
    createAudio: () => HTMLAudioElement = () => {
        const element = new Audio();
        element.preload = "auto";
        return element;
    },
): CrossfadeEngine {
    const primary = createAudio();
    const secondary = createAudio();
    let activeEl = primary;
    let standbyEl = secondary;
    let outputVolume = 1;
    let fadeTimer: ReturnType<typeof setInterval> | null = null;
    let fading = false;
    // Two elements are always allocated via createAudio; do not gate on
    // global Audio (absent in jsdom/node harnesses that inject FakeAudio).
    const capabilities: PlaybackCapabilities = {
        ...detectPlaybackCapabilities(),
        dualAudio: true,
    };

    const clearFadeTimer = () => {
        if (fadeTimer) clearInterval(fadeTimer);
        fadeTimer = null;
    };

    const applyVolume = (element: HTMLAudioElement, ratio: number) => {
        element.volume = Math.max(0, Math.min(1, outputVolume * ratio));
    };

    const cancelFade = () => {
        if (!fading) return;
        fading = false;
        clearFadeTimer();
        standbyEl.pause();
        standbyEl.removeAttribute("src");
        applyVolume(activeEl, 1);
        applyVolume(standbyEl, 0);
    };

    return {
        active: () => activeEl,
        standby: () => standbyEl,
        capabilities,
        fading: () => fading,
        setSource(url) {
            cancelFade();
            if (!url) {
                activeEl.pause();
                activeEl.removeAttribute("src");
                activeEl.removeAttribute("data-stream");
                return;
            }
            if (activeEl.getAttribute("data-stream") === url) return;
            activeEl.setAttribute("data-stream", url);
            activeEl.src = url;
            activeEl.load();
            applyVolume(activeEl, 1);
        },
        async play() {
            applyVolume(activeEl, 1);
            await activeEl.play();
        },
        pause() {
            cancelFade();
            activeEl.pause();
        },
        seek(seconds) {
            cancelFade();
            if (activeEl.readyState >= 1)
                activeEl.currentTime = Math.max(0, seconds);
        },
        setOutputVolume(volume) {
            outputVolume = Math.max(0, Math.min(1, volume));
            if (!fading) applyVolume(activeEl, 1);
        },
        currentTime: () => activeEl.currentTime || 0,
        duration: () =>
            Number.isFinite(activeEl.duration) ? activeEl.duration : 0,
        maybeCrossfade({ nextUrl, seconds, remaining, onSwap }) {
            if (
                fading ||
                !capabilities.dualAudio ||
                seconds < 1 ||
                remaining > seconds ||
                remaining <= 0 ||
                !nextUrl
            )
                return false;
            fading = true;
            standbyEl.src = nextUrl;
            standbyEl.load();
            applyVolume(standbyEl, 0);
            const started = performance.now();
            const durationMs = seconds * 1000;
            void standbyEl.play().catch(() => {
                cancelFade();
            });
            fadeTimer = setInterval(() => {
                const progress = Math.min(
                    1,
                    (performance.now() - started) / durationMs,
                );
                applyVolume(activeEl, 1 - progress);
                applyVolume(standbyEl, progress);
                if (progress >= 1) {
                    clearFadeTimer();
                    const outgoing = activeEl;
                    activeEl = standbyEl;
                    standbyEl = outgoing;
                    fading = false;
                    standbyEl.pause();
                    standbyEl.removeAttribute("src");
                    applyVolume(activeEl, 1);
                    applyVolume(standbyEl, 0);
                    onSwap();
                }
            }, 50);
            return true;
        },
        cancelFade,
        dispose() {
            cancelFade();
            for (const element of [primary, secondary]) {
                element.pause();
                element.removeAttribute("src");
            }
        },
    };
}
