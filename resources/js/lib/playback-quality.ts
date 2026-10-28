import type { AudioQuality, Track } from "../types";
export function playbackUrl(
    track: Track | null,
    quality: AudioQuality,
): string {
    if (!track) return "";
    return (
        track.audio_qualities?.find((option) => option.id === quality)
            ?.stream_url ?? track.stream_url
    );
}
