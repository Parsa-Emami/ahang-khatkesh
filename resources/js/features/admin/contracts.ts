import type { Page } from "../../types";
export type Overview = {
    tracks: number;
    published_playlists: number;
    draft_playlists: number;
    processing_imports: number;
    failed_imports: number;
    media_bytes: number;
    members: { total: number; capacity: number } | null;
};
export type Operations = {
    php_version: string;
    laravel_version: string;
    database_driver: string;
    queue_driver: string;
    queued_jobs: number;
    failed_jobs: number;
    ffprobe_available: boolean;
    disk_available_bytes: number;
    disk_low: boolean;
    oldest_job_seconds: number | null;
    dead_queue: boolean;
    expired_import_leases: number;
    expired_conversion_leases: number;
    expired_reservations: number;
    alerts: { code: string; severity: string; message: string }[];
    stream_accel_enabled: boolean;
    checked_at: string;
};
export type AuditEvent = {
    id: number;
    actor: { id: number; name: string } | null;
    action: string;
    subject_type: string | null;
    subject_id: string | null;
    request_id: string | null;
    metadata: Record<string, string | number | boolean>;
    created_at: string;
};
export type CursorPage<T> = {
    data: T[];
    meta: { next_cursor: string | null; prev_cursor: string | null };
    links: Record<string, string | null>;
};
export type Artist = {
    id: number;
    name: string;
    sort_name: string | null;
    description: string | null;
    country_code: string | null;
    revision: number;
    tracks_count?: number;
    needs_review: boolean;
};
export type Release = {
    id: number;
    title: string;
    type: "album" | "single" | "ep" | "compilation";
    released_on: string | null;
    revision: number;
    artists: Artist[];
    tracks_count: number;
    tracks?: {
        id: number;
        title: string;
        disc_number: number;
        track_number: number;
    }[];
};
export type CatalogPage<T> = Page<T>;
