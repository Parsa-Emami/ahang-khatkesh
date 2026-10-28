export type SleepTimer =
    { kind: "deadline"; deadline: number } | { kind: "end_of_track" } | null;
export const sleepPresets = [5, 15, 30, 45, 60, 90, 120] as const;
export function createSleepTimer(
    value: number | "end_of_track" | null,
    now = Date.now(),
): SleepTimer {
    if (value === null) return null;
    if (value === "end_of_track") return { kind: "end_of_track" };
    if (!sleepPresets.some((minutes) => minutes === value))
        throw new RangeError("Unsupported sleep timer duration.");
    return { kind: "deadline", deadline: now + value * 60000 };
}
export function sleepExpired(
    timer: SleepTimer,
    now = Date.now(),
    ended = false,
): boolean {
    return timer?.kind === "end_of_track"
        ? ended
        : timer?.kind === "deadline"
          ? now >= timer.deadline
          : false;
}
