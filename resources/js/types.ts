export type Theme = "light" | "dark" | "system";
export type User = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_active: boolean;
    theme: Theme;
};
export type Track = {
    id: number;
    title: string;
    artist: string;
    album: string | null;
    duration_seconds: number;
    stream_url: string;
    is_liked: boolean;
    cover_url?: string | null;
    artwork_seed?: number;
};
export type Mood = "focus" | "night" | "calm" | "energy" | "other";
export type Playlist = {
    id: number;
    name: string;
    description: string | null;
    mood: Mood;
    status: "draft" | "published";
    artwork_seed: number;
    cover_url: string | null;
    tracks_count: number;
    is_saved: boolean;
    tracks?: Track[];
};
export type Page<T> = {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
    links: Record<string, string | null>;
};
export type ManifestRow = {
    asset_id: string;
    title: string;
    artist: string;
    album?: string | null;
};
export type ImportRun = {
    id: string;
    name: string;
    description: string | null;
    mood: Mood;
    status: "ready" | "processing" | "completed" | "failed";
    rows: ManifestRow[];
    report: {
        validated_tracks: number;
        created_tracks?: number;
        reused_tracks?: number;
    } | null;
    playlist_id: number | null;
    error: string | null;
    created_at: string;
};
export type MediaAsset = {
    id: string;
    original_name: string;
    duration_seconds: number;
    size_bytes: number;
};
export type RepeatMode = "off" | "all" | "one";
export type PlaybackSnapshot = {
    track_id: number | null;
    queue: Track[];
    position_seconds: number;
    volume: number;
    shuffle: boolean;
    repeat_mode: RepeatMode;
    revision: number;
};
export const moodLabels: Record<Mood, string> = {
    focus: "تمرکز",
    night: "شبانه",
    calm: "آرامش",
    energy: "انرژی",
    other: "انتخاب آزاد",
};
export const digits = (value: number) =>
    new Intl.NumberFormat("fa-IR").format(value);
export function duration(value: number) {
    const safe = Number.isFinite(value) ? Math.max(0, value) : 0;
    return `${Math.floor(safe / 60)}:${String(Math.floor(safe % 60)).padStart(2, "0")}`;
}
