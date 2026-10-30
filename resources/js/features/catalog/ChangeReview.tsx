import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Dialog } from "../../components/UI";
import { useToast } from "../../context/AppContext";
import { date, number, tr, type MessageKey } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import {
    applyChange,
    changeIntent,
    type Change,
    type ChangeIntent,
} from "./workflows";
export const operationLabel = (operation: Change["operation"]) =>
    tr(
        (
            {
                metadata: "catalog.metadata",
                playlists: "catalog.playlists",
                move: "catalog.move",
                merge: "catalog.merge",
                repair: "catalog.repair",
                proposal: "catalog.proposal",
            } as const
        )[operation],
    );
const fields: Record<string, MessageKey> = {
    title: "catalog.title",
    artist: "catalog.artist",
    credits: "catalog.credits",
    artist_id: "catalog.artistId",
    role: "catalog.role",
    primary_release_id: "catalog.release",
    recording_identity: "catalog.identity",
    recording_provenance: "catalog.provenance",
    name: "catalog.name",
    description: "catalog.description",
    mood: "catalog.mood",
    status: "catalog.status",
    canonical_id: "catalog.canonical",
    identity: "catalog.identity",
    provenance: "catalog.provenance",
    retained_ids: "catalog.retainedIds",
    sources: "catalog.sources",
    track_ids: "catalog.trackIds",
    asset_id: "catalog.asset",
    sha256: "catalog.checksum",
    size_bytes: "catalog.bytes",
    source_exists: "catalog.sourceExists",
};
function Value({ value }: { value: unknown }) {
    if (value === null || value === undefined || value === "")
        return <span className="muted">{tr("catalog.empty")}</span>;
    if (typeof value === "boolean")
        return <>{tr(value ? "catalog.yes" : "catalog.no")}</>;
    if (Array.isArray(value))
        return (
            <ol className="change-values">
                {value.map((item, index) => (
                    <li key={index}>
                        <Value value={item} />
                    </li>
                ))}
            </ol>
        );
    if (typeof value === "object")
        return (
            <dl className="change-fields">
                {Object.entries(value).map(([key, item]) => (
                    <div key={key}>
                        <dt>{fields[key] ? tr(fields[key]) : key}</dt>
                        <dd>
                            <Value value={item} />
                        </dd>
                    </div>
                ))}
            </dl>
        );
    const label =
        typeof value === "string"
            ? (
                  {
                      published: "catalog.published",
                      draft: "catalog.draft",
                      archived: "catalog.archived",
                      primary: "catalog.primary",
                      featured: "catalog.featured",
                      composer: "catalog.composer",
                      producer: "catalog.producer",
                      focus: "m6187182080",
                      night: "m4707b9a047",
                      calm: "m641a69e69f",
                      energy: "mc9ebe91684",
                      other: "m1d0ff5f26c",
                  } as Record<string, MessageKey>
              )[value]
            : undefined;
    return (
        <span dir="auto">
            {label
                ? tr(label)
                : typeof value === "number"
                  ? number(value)
                  : String(value)}
        </span>
    );
}
export function ChangeReview({
    change,
    close,
    changed,
}: {
    change: Change;
    close: () => void;
    changed?: (change: Change) => void;
}) {
    useLocale();
    const [current, setCurrent] = useState(change);
    const [undo, setUndo] = useState(false);
    const client = useQueryClient();
    const notify = useToast();
    const mutation = useMutation({
        mutationFn: ({
            intent,
            action,
        }: {
            intent: ChangeIntent;
            action: "commit" | "rollback";
        }) => applyChange(intent, action),
        onSuccess: ({ data }) => {
            setCurrent(data);
            setUndo(false);
            void client.invalidateQueries();
            notify(
                tr(
                    data.status === "committed"
                        ? "catalog.committed"
                        : "catalog.rolledBack",
                ),
            );
            changed?.(data);
        },
    });
    const expired = Date.now() >= Date.parse(current.expires_at);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !mutation.isPending) close();
            }}
            title={operationLabel(current.operation)}
            description={tr("catalog.reviewHelp")}
            className="catalog-review"
        >
            <p role="status" className="catalog-status">
                {tr(
                    (
                        {
                            previewed: "catalog.previewed",
                            committed: "catalog.committed",
                            rolled_back: "catalog.rolledBack",
                        } as const
                    )[current.status],
                )}
            </p>
            <p className="muted">
                {tr(
                    current.status === "previewed"
                        ? "catalog.expires"
                        : "catalog.created",
                    {
                        time: date(
                            current.status === "previewed"
                                ? current.expires_at
                                : current.created_at,
                            { dateStyle: "medium", timeStyle: "short" },
                        ),
                    },
                )}
            </p>
            {current.summary.map((row, index) => (
                <section className="change-row" key={index}>
                    <h3>
                        {tr(
                            (
                                {
                                    track: "catalog.track",
                                    playlist: "catalog.playlist",
                                    playlist_order: "catalog.order",
                                    recording_merge: "catalog.merge",
                                    media_repair: "catalog.repair",
                                    new_playlist: "catalog.newPlaylist",
                                } as Record<string, MessageKey>
                            )[row.entity] ?? "catalog.change",
                        )}
                        {row.id !== null && ` · #${row.id}`}
                    </h3>
                    <div className="change-comparison">
                        <div>
                            <h4>{tr("catalog.before")}</h4>
                            <Value value={row.before} />
                        </div>
                        <div>
                            <h4>{tr("catalog.after")}</h4>
                            <Value value={row.after} />
                        </div>
                    </div>
                </section>
            ))}
            {current.result.created_playlist_id && (
                <Link
                    className="text-link"
                    to={`/playlists/${current.result.created_playlist_id}`}
                >
                    {tr("catalog.openResult")}
                </Link>
            )}
            {mutation.error && <p role="alert">{mutation.error.message}</p>}
            {current.status === "previewed" &&
                (expired ? (
                    <p role="alert">{tr("catalog.expiredHelp")}</p>
                ) : (
                    <button
                        className="button"
                        disabled={mutation.isPending}
                        onClick={() =>
                            mutation.mutate({
                                intent: changeIntent(current),
                                action: "commit",
                            })
                        }
                    >
                        {tr("catalog.confirmCommit")}
                    </button>
                ))}
            {current.can_rollback && !undo && (
                <button
                    className="button danger"
                    disabled={mutation.isPending}
                    onClick={() => setUndo(true)}
                >
                    {tr("catalog.undo")}
                </button>
            )}
            {undo && (
                <div className="catalog-undo">
                    <p>
                        {tr("catalog.undoHelp", {
                            time: date(current.rollback_until!, {
                                dateStyle: "medium",
                                timeStyle: "short",
                            }),
                        })}
                    </p>
                    <div className="dialog-actions">
                        <button
                            className="button secondary"
                            disabled={mutation.isPending}
                            onClick={() => setUndo(false)}
                        >
                            {tr("catalog.cancel")}
                        </button>
                        <button
                            className="button danger"
                            disabled={mutation.isPending}
                            onClick={() =>
                                mutation.mutate({
                                    intent: changeIntent(current),
                                    action: "rollback",
                                })
                            }
                        >
                            {tr("catalog.confirmUndo")}
                        </button>
                    </div>
                </div>
            )}
            <small className="muted">{tr("catalog.conflictHelp")}</small>
        </Dialog>
    );
}
