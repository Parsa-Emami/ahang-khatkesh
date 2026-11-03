export const TARGET_LUFS = -14;
export const MIN_GAIN = 0.25;
export const MAX_GAIN = 1;

export function loudnessGain(
    loudnessLufs: number | null | undefined,
    enabled: boolean,
): number {
    if (!enabled || loudnessLufs === null || loudnessLufs === undefined)
        return 1;
    const gain = 10 ** ((TARGET_LUFS - loudnessLufs) / 20);
    return Math.max(MIN_GAIN, Math.min(MAX_GAIN, gain));
}

export function applyOutputVolume(
    userVolume: number,
    loudnessLufs: number | null | undefined,
    normalize: boolean,
): number {
    return Math.max(
        0,
        Math.min(1, userVolume * loudnessGain(loudnessLufs, normalize)),
    );
}
