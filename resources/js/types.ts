import { number } from "./i18n/runtime";
import type { Preferences } from "./i18n/locales";
import { tr } from "./i18n/runtime";
export type Theme = "light" | "dark" | "system";
export type Role = "owner" | "administrator" | "curator" | "listener";
export type Permission =
    | "admin.access"
    | "dashboard.read"
    | "catalog.manage"
    | "media.manage"
    | "imports.manage"
    | "users.manage"
    | "roles.manage"
    | "audit.read"
    | "operations.read";
export type User = Partial<Preferences> & {
    id: number;
    access_version: number;
    name: string;
    email: string;
    is_admin: boolean;
    role: Role;
    role_label: string;
    permissions: Permission[];
    mfa_enabled: boolean;
    is_active: boolean;
    theme: Theme;
    crossfade_enabled?: boolean;
    crossfade_seconds?: number;
    crossfade_acknowledged?: boolean;
    loudness_normalization?: boolean;
    offline_opt_in?: boolean;
    offline_revocation_acknowledged?: boolean;
    offline_quota_bytes?: number;
};
export type AudioQuality = "auto" | "original" | "mp3-320";
export type AudioConversion = {
    id: string;
    source_asset_id: string;
    source_name: string;
    source_revision: number;
    source_bytes: number;
    duration_seconds: number;
    profile_id: string;
    profile_version: number;
    status:
        | "queued"
        | "processing"
        | "validating"
        | "ready"
        | "failed"
        | "needs_review"
        | "cancelled";
    generation: number;
    revision: number;
    progress_seconds: number;
    progress_percent: number;
    reserved_bytes: number;
    output_asset_id: string | null;
    output_bytes: number | null;
    error_code: string | null;
    next_retry_at: string | null;
    heartbeat_at: string | null;
    lease_expires_at: string | null;
    report: {
        full_decode: boolean;
        bitrate_bps: number;
        source_retained: boolean;
        validated_frames: number;
        encoder_version: string;
    } | null;
};
export type Track = {
    id: number;
    title: string;
    artist: string;
    album: string | null;
    revision: number;
    primary_release_id: number | null;
    artists?: { id: number; name: string; role: string }[];
    releases?: { id: number; title: string }[];
    duration_seconds: number;
    loudness_lufs?: number | null;
    audio_qualities?: {
        id: "original" | "mp3-320";
        label: string;
        size_bytes: number;
        mime_type: string;
        stream_url: string;
    }[];
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
    status: "draft" | "published" | "archived";
    revision: number;
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
    artist_id?: number;
    release_id?: number;
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
    status:
        | "ready"
        | "processing"
        | "running"
        | "completed"
        | "failed"
        | "needs_review"
        | "cancelled";
    rows: ManifestRow[];
    report: {
        validated_tracks: number;
        created_tracks?: number;
        reused_tracks?: number;
        media?: Record<
            string,
            {
                source_codec: string | null;
                playback_profile: string;
                status: string;
                conversion_id: string | null;
            }
        >;
    } | null;
    execution?: {
        generation: number;
        recovery_count: number;
        queued_at: string | null;
        heartbeat_at: string | null;
        lease_expires_at: string | null;
    };
    playlist_id: number | null;
    error: string | null;
    created_at: string;
};
export type MediaAsset = {
    codec: string | null;
    revision: number;
    conversion: { id: string; status: AudioConversion["status"] } | null;
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
    get focus() {
        return tr("m6187182080");
    },
    get night() {
        return tr("m4707b9a047");
    },
    get calm() {
        return tr("m641a69e69f");
    },
    get energy() {
        return tr("mc9ebe91684");
    },
    get other() {
        return tr("m1d0ff5f26c");
    },
};
export const digits = (value: number) => number(value);
export function duration(value: number) {
    const safe = Number.isFinite(value) ? Math.max(0, value) : 0;
    return `${Math.floor(safe / 60)}:${String(Math.floor(safe % 60)).padStart(2, "0")}`;
}

export const can = (user: User | null | undefined, permission: Permission) =>
    user?.permissions.includes(permission) ?? false;
