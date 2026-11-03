export type LyricLine = { time: number; text: string };
export type ParsedLyrics = {
    tags: Record<string, string>;
    lines: LyricLine[];
};

export const LYRICS_MAX_BYTES = 65536;
export const LYRICS_MAX_SECONDS = 86400;

export function parseLrc(
    raw: string,
    durationSeconds: number | null = null,
): ParsedLyrics {
    if (new TextEncoder().encode(raw).length > LYRICS_MAX_BYTES) {
        throw new Error("lyrics_too_large");
    }
    const text = raw.replace(/^\uFEFF/, "").replace(/\r\n?/g, "\n");
    const tags: Record<string, string> = {};
    const timed: LyricLine[] = [];
    for (const original of text.split("\n")) {
        const line = original.trim();
        if (!line) continue;
        const meta = line.match(/^\[([a-zA-Z]+):([^\]]*)\]\s*$/);
        if (meta) {
            tags[meta[1].toLowerCase()] = meta[2].trim();
            continue;
        }
        const stamps = [
            ...line.matchAll(/\[(\d{1,3}):(\d{2})(?:\.(\d{1,3}))?\]/g),
        ];
        if (!stamps.length) continue;
        const plain = line
            .replace(/\[\d{1,3}:\d{2}(?:\.\d{1,3})?\]/g, "")
            .trim();
        if (!plain || /<[^>]*>/.test(plain))
            throw new Error("lyrics_markup_forbidden");
        for (const stamp of stamps) {
            const minutes = Number(stamp[1]);
            const seconds = Number(stamp[2]);
            const fraction = stamp[3] ? Number(`0.${stamp[3]}`) : 0;
            if (seconds >= 60) throw new Error("lyrics_invalid_timestamp");
            const time = minutes * 60 + seconds + fraction;
            if (time < 0 || time > LYRICS_MAX_SECONDS)
                throw new Error("lyrics_timestamp_out_of_range");
            if (durationSeconds !== null && time > durationSeconds + 1)
                throw new Error("lyrics_timestamp_past_duration");
            timed.push({ time: Math.round(time * 1000) / 1000, text: plain });
        }
    }
    timed.sort((a, b) => a.time - b.time);
    const deduped = new Map<string, LyricLine>();
    for (const row of timed) deduped.set(row.time.toFixed(3), row);
    return { tags, lines: [...deduped.values()] };
}

export function activeLyricIndex(lines: LyricLine[], position: number): number {
    if (!lines.length) return -1;
    let index = -1;
    for (let i = 0; i < lines.length; i++) {
        if (lines[i].time <= position + 0.05) index = i;
        else break;
    }
    return index;
}
