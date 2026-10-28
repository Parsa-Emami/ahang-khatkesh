import { describe, expect, it } from "vitest";
import { playbackUrl } from "./playback-quality";
import type { Track } from "../types";
const track = {
    id: 5,
    stream_url: "/api/v1/tracks/5/stream",
    audio_qualities: [
        {
            id: "mp3-320",
            stream_url: "/api/v1/tracks/5/stream?quality=mp3-320",
        },
        {
            id: "original",
            stream_url: "/api/v1/tracks/5/stream?quality=original",
        },
    ],
} as Track;
describe("quality picker", () => {
    it("uses the server default until the listener explicitly selects a ready format", () => {
        expect(playbackUrl(track, "auto")).toBe("/api/v1/tracks/5/stream");
        expect(playbackUrl(track, "original")).toBe(
            "/api/v1/tracks/5/stream?quality=original",
        );
        expect(playbackUrl(track, "mp3-320")).toBe(
            "/api/v1/tracks/5/stream?quality=mp3-320",
        );
    });
    it("uses the authorized default on a next track without that format", () => {
        expect(playbackUrl({ ...track, audio_qualities: [] }, "mp3-320")).toBe(
            "/api/v1/tracks/5/stream",
        );
        expect(playbackUrl(null, "original")).toBe("");
    });
});
