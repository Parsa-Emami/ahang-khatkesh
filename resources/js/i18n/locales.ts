export type Locale = "fa-IR" | "en-US";
export type Direction = "auto" | "rtl" | "ltr";
export type Motion = "system" | "full" | "reduced";
export type Preferences = {
    locale: Locale;
    direction: Direction;
    motion: Motion;
    time_zone: string;
};
export const defaults: Preferences = {
    locale: "fa-IR",
    direction: "auto",
    motion: "system",
    time_zone: "Asia/Tehran",
};
export function normalizePreferences(
    value: Partial<Preferences> = {},
): Preferences {
    value = value && typeof value === "object" ? value : {};
    let time_zone = defaults.time_zone;
    try {
        if (value.time_zone && value.time_zone.length <= 64) {
            new Intl.DateTimeFormat("en-US", {
                timeZone: value.time_zone,
            }).format();
            time_zone = value.time_zone;
        }
    } catch {}
    return {
        locale: value.locale === "en-US" ? "en-US" : "fa-IR",
        direction:
            value.direction === "rtl" || value.direction === "ltr"
                ? value.direction
                : "auto",
        motion:
            value.motion === "full" || value.motion === "reduced"
                ? value.motion
                : "system",
        time_zone,
    };
}
export function resolveDirection({ locale, direction }: Preferences) {
    return direction === "auto"
        ? locale === "fa-IR"
            ? "rtl"
            : "ltr"
        : direction;
}
export function readPreferences(): Preferences {
    try {
        return normalizePreferences(
            JSON.parse(localStorage.getItem("ak-preferences") || "{}"),
        );
    } catch {
        return defaults;
    }
}
