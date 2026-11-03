import { describe, expect, it } from "vitest";
import { activeLyricIndex, parseLrc } from "./lrc";

describe("LyricsTimelineTest", () => {
    it("strips BOM, rejects markup, and deduplicates timestamps", () => {
        const parsed = parseLrc(
            "\uFEFF[ti:Demo]\n[00:01.00]one\n[00:01.00]dup\n[00:02.50]two",
        );
        expect(parsed.tags.ti).toBe("Demo");
        expect(parsed.lines).toEqual([
            { time: 1, text: "dup" },
            { time: 2.5, text: "two" },
        ]);
        expect(() => parseLrc("[00:01.00]<b>bad</b>")).toThrow(
            /lyrics_markup_forbidden/,
        );
        expect(() => parseLrc("[99:70.00]bad")).toThrow(
            /lyrics_invalid_timestamp/,
        );
        expect(() => parseLrc("[00:05.00]late", 1)).toThrow(
            /lyrics_timestamp_past_duration/,
        );
    });

    it("maps seek position to the active lyric line", () => {
        const lines = parseLrc("[00:00.00]a\n[00:10.00]b\n[00:20.00]c").lines;
        expect(activeLyricIndex(lines, 0)).toBe(0);
        expect(activeLyricIndex(lines, 10)).toBe(1);
        expect(activeLyricIndex(lines, 19.9)).toBe(1);
        expect(activeLyricIndex(lines, 20)).toBe(2);
    });
});
