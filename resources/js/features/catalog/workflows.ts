import type { Mood, Track } from "../../types";
import {
    api,
    captureSessionIntent,
    json,
    type SessionIntent,
} from "../../lib/api";
export type Change = {
    id: string;
    operation:
        "metadata" | "playlists" | "move" | "merge" | "repair" | "proposal";
    status: "previewed" | "committed" | "rolled_back";
    revision: number;
    snapshot_hash: string;
    summary: {
        entity: string;
        id: number | null;
        before: unknown;
        after: unknown;
    }[];
    result: { created_playlist_id?: number };
    proposal_id: string | null;
    can_commit: boolean;
    can_rollback: boolean;
    expires_at: string;
    created_at: string;
    committed_at: string | null;
    rolled_back_at: string | null;
    rollback_until: string | null;
};
export type ProposalPayload =
    | { name: string; description: string; mood: Mood; track_ids: number[] }
    | { track_id: number; title: string };
export type Proposal = {
    id: string;
    kind: "playlist" | "track_metadata";
    status: "pending" | "accepted" | "rejected";
    revision: number;
    payload: ProposalPayload;
    resolution: "accepted" | "rejected" | "rolled_back" | null;
    review_note: string | null;
    result_playlist_id: number | null;
    submitter?: { id: number; name: string };
    created_at: string;
    reviewed_at: string | null;
};
export type TrackEdit = {
    id: number;
    revision: number;
    title: string;
    credits: { artist_id: number; role: string }[];
    primary_release_id: number | null;
    recording_identity: string | null;
    recording_provenance: string | null;
};
export const editTrack = (track: Track): TrackEdit => ({
    id: track.id,
    revision: track.revision,
    title: track.title,
    credits: (track.artists ?? []).map(({ id, role }) => ({
        artist_id: id,
        role,
    })),
    primary_release_id: track.primary_release_id,
    recording_identity: track.recording_identity ?? null,
    recording_provenance: track.recording_provenance ?? null,
});
export function appendUnique<T extends { id: number }>(
    rows: T[],
    row: T,
    limit: number,
): T[] {
    return rows.some((item) => item.id === row.id) || rows.length >= limit
        ? rows
        : [...rows, row];
}
export const previewChange = (intent: SessionIntent<unknown>) =>
    api<{ data: Change }>(
        "/admin/catalog-changes/preview",
        { method: "POST", body: json(intent.value) },
        true,
        true,
        intent,
    );
export function changeIntent(change: Change) {
    return captureSessionIntent({
        id: change.id,
        revision: change.revision,
        snapshot_hash: change.snapshot_hash,
    });
}
export type ChangeIntent = ReturnType<typeof changeIntent>;
export function applyChange(
    intent: ChangeIntent,
    action: "commit" | "rollback",
) {
    const { id, ...body } = intent.value;
    return api<{ data: Change }>(
        `/admin/catalog-changes/${id}/${action}`,
        { method: "POST", body: json(body) },
        true,
        true,
        intent,
    );
}
