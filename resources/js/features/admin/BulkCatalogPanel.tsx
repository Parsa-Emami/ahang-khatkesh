import { useDeferredValue, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { X } from "lucide-react";
import { useSession } from "../../context/AppContext";
import { api, captureSessionIntent } from "../../lib/api";
import { Pager } from "../catalog/Pager";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { ErrorState, IconButton, Loading } from "../../components/UI";
import { moodLabels, type Page, type Playlist, type Mood } from "../../types";
import { useDraftGuard } from "../drafts/DraftNavigation";
import { ChangeReview, operationLabel } from "../catalog/ChangeReview";
import {
    appendUnique,
    editTrack,
    previewChange,
    type Change,
    type TrackEdit,
} from "../catalog/workflows";
import { TrackPicker, ArtistPicker } from "./Pickers";
export function ChangeHistory() {
    const { user } = useSession();
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<Change | null>(null);
    const query = useQuery({
        queryKey: ["admin", "changes", user?.id, user?.access_version, page],
        queryFn: ({ signal }) =>
            api<Page<Change>>(`/admin/catalog-changes?page=${page}`, {
                signal,
            }),
    });
    return (
        <section>
            <h3>{tr("catalog.journal")}</h3>
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : (
                <>
                    <div className="catalog-history">
                        {query.data.data.map((change) => (
                            <article key={change.id}>
                                <span>
                                    <strong>
                                        {operationLabel(change.operation)}
                                    </strong>
                                    <small className="muted">
                                        {" "}
                                        ·{" "}
                                        {tr(
                                            (
                                                {
                                                    previewed:
                                                        "catalog.previewed",
                                                    committed:
                                                        "catalog.committed",
                                                    rolled_back:
                                                        "catalog.rolledBack",
                                                } as const
                                            )[change.status],
                                        )}
                                    </small>
                                </span>
                                <button
                                    className="button secondary"
                                    onClick={() => setSelected(change)}
                                >
                                    {tr("catalog.review")}
                                </button>
                            </article>
                        ))}
                        {query.data.data.length === 0 && (
                            <p>{tr("catalog.noChanges")}</p>
                        )}
                    </div>
                    <Pager
                        page={page}
                        pages={query.data.meta.last_page}
                        setPage={setPage}
                    />
                </>
            )}
            {selected && (
                <ChangeReview
                    key={selected.id}
                    change={selected}
                    close={() => setSelected(null)}
                />
            )}
        </section>
    );
}
export function BulkCatalogPanel() {
    useLocale();
    const [mode, setMode] = useState("metadata");
    const [rows, setRows] = useState<TrackEdit[]>([]);
    const [playlistRows, setPlaylistRows] = useState<Playlist[]>([]);
    const [artistNames, setArtistNames] = useState<Record<number, string>>({});
    const [releaseOptions, setReleaseOptions] = useState<
        Record<number, { id: number; title: string }[]>
    >({});
    const [artistFor, setArtistFor] = useState<number | null>(null);
    const [q, setQ] = useState("");
    const term = useDeferredValue(q);
    const [page, setPage] = useState(1);
    const [source, setSource] = useState<Playlist | null>(null);
    const [target, setTarget] = useState<Playlist | null>(null);
    const [moveIds, setMoveIds] = useState<number[]>([]);
    const [review, setReview] = useState<Change | null>(null);
    const reset = () => {
        setRows([]);
        setPlaylistRows([]);
        setSource(null);
        setTarget(null);
        setMoveIds([]);
    };
    useDraftGuard(
        rows.length > 0 || playlistRows.length > 0 || moveIds.length > 0,
        reset,
    );
    const playlists = useQuery({
        queryKey: ["admin", "bulk-playlists", term, page],
        queryFn: ({ signal }) =>
            api<Page<Playlist>>(
                `/playlists?${new URLSearchParams({ q: term, page: String(page) })}`,
                { signal },
            ),
        enabled: mode !== "metadata",
    });
    const sourceTracks = useQuery({
        queryKey: ["admin", "move-source", source?.id],
        queryFn: ({ signal }) =>
            api<{ data: Playlist }>(`/playlists/${source!.id}`, { signal }),
        enabled: !!source,
    });
    const preview = useMutation({
        mutationFn: previewChange,
        onSuccess: ({ data }) => setReview(data),
    });
    const patchRow = (id: number, patch: Partial<TrackEdit>) =>
        setRows((current) =>
            current.map((row) => (row.id === id ? { ...row, ...patch } : row)),
        );
    const input =
        mode === "metadata"
            ? { operation: mode, rows }
            : mode === "playlists"
              ? {
                    operation: mode,
                    rows: playlistRows.map(
                        ({
                            id,
                            revision,
                            name,
                            description,
                            mood,
                            status,
                        }) => ({
                            id,
                            revision,
                            name,
                            description,
                            mood,
                            status,
                        }),
                    ),
                }
              : {
                    operation: "move",
                    source_playlist_id: source?.id,
                    source_revision: sourceTracks.data?.data.revision,
                    target_playlist_id: target?.id,
                    target_revision: target?.revision,
                    track_ids: moveIds,
                };
    const ready =
        mode === "metadata"
            ? rows.length > 0
            : mode === "playlists"
              ? playlistRows.length > 0
              : !!source &&
                !!target &&
                moveIds.length > 0 &&
                !!sourceTracks.data;
    const dirty =
        rows.length > 0 || playlistRows.length > 0 || moveIds.length > 0;
    return (
        <div className="catalog-workflow">
            <p className="muted">{tr("catalog.bulkHelp")}</p>
            <div className="filter-chips">
                {(["metadata", "playlists", "move"] as const).map((kind) => (
                    <button
                        key={kind}
                        disabled={dirty && mode !== kind}
                        aria-pressed={mode === kind}
                        onClick={() => {
                            setMode(kind);
                            preview.reset();
                        }}
                    >
                        {tr(
                            (
                                {
                                    metadata: "catalog.metadata",
                                    playlists: "catalog.playlists",
                                    move: "catalog.move",
                                } as const
                            )[kind],
                        )}
                    </button>
                ))}
            </div>
            {mode === "metadata" ? (
                <>
                    <TrackPicker
                        choose={(track) => {
                            setRows((current) =>
                                appendUnique(current, editTrack(track), 100),
                            );
                            setArtistNames((current) => ({
                                ...current,
                                ...Object.fromEntries(
                                    (track.artists ?? []).map((artist) => [
                                        artist.id,
                                        artist.name,
                                    ]),
                                ),
                            }));
                            setReleaseOptions((current) => ({
                                ...current,
                                [track.id]: track.releases ?? [],
                            }));
                        }}
                    />
                    <p>
                        {tr("catalog.selected", {
                            count: rows.length,
                            limit: 100,
                        })}
                    </p>
                    <ul className="catalog-selection">
                        {rows.map((row) => (
                            <li key={row.id}>
                                <header>
                                    <strong>#{row.id}</strong>
                                    <IconButton
                                        label={tr("catalog.remove", {
                                            id: row.id,
                                        })}
                                        onClick={() =>
                                            setRows((current) =>
                                                current.filter(
                                                    (item) =>
                                                        item.id !== row.id,
                                                ),
                                            )
                                        }
                                    >
                                        <X size={18} />
                                    </IconButton>
                                </header>
                                <div className="catalog-form-grid">
                                    <label>
                                        {tr("catalog.title")}
                                        <input
                                            dir="auto"
                                            value={row.title}
                                            required
                                            maxLength={255}
                                            onChange={(e) =>
                                                patchRow(row.id, {
                                                    title: e.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        {tr("catalog.release")}
                                        <select
                                            value={row.primary_release_id ?? ""}
                                            onChange={(e) =>
                                                patchRow(row.id, {
                                                    primary_release_id: e.target
                                                        .value
                                                        ? Number(e.target.value)
                                                        : null,
                                                })
                                            }
                                        >
                                            <option value="">
                                                {tr("catalog.empty")}
                                            </option>
                                            {(releaseOptions[row.id] ?? []).map(
                                                (release) => (
                                                    <option
                                                        key={release.id}
                                                        value={release.id}
                                                    >
                                                        {release.title}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </label>
                                </div>
                                <fieldset>
                                    <legend>{tr("catalog.credits")}</legend>
                                    {row.credits.map((credit, index) => (
                                        <div
                                            className="catalog-credit"
                                            key={`${credit.artist_id}:${index}`}
                                        >
                                            <span>
                                                {artistNames[credit.artist_id]}{" "}
                                                · #{credit.artist_id}
                                            </span>
                                            <select
                                                aria-label={tr(
                                                    "catalog.roleFor",
                                                    { id: credit.artist_id },
                                                )}
                                                value={credit.role}
                                                onChange={(e) =>
                                                    patchRow(row.id, {
                                                        credits:
                                                            row.credits.map(
                                                                (item, i) =>
                                                                    i === index
                                                                        ? {
                                                                              ...item,
                                                                              role: e
                                                                                  .target
                                                                                  .value,
                                                                          }
                                                                        : item,
                                                            ),
                                                    })
                                                }
                                            >
                                                {(
                                                    [
                                                        "primary",
                                                        "featured",
                                                        "composer",
                                                        "producer",
                                                    ] as const
                                                ).map((role) => (
                                                    <option
                                                        key={role}
                                                        value={role}
                                                    >
                                                        {tr(
                                                            (
                                                                {
                                                                    primary:
                                                                        "catalog.primary",
                                                                    featured:
                                                                        "catalog.featured",
                                                                    composer:
                                                                        "catalog.composer",
                                                                    producer:
                                                                        "catalog.producer",
                                                                } as const
                                                            )[role],
                                                        )}
                                                    </option>
                                                ))}
                                            </select>
                                            <IconButton
                                                label={tr(
                                                    "catalog.removeCredit",
                                                    { id: credit.artist_id },
                                                )}
                                                onClick={() =>
                                                    patchRow(row.id, {
                                                        credits:
                                                            row.credits.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                    })
                                                }
                                            >
                                                <X size={16} />
                                            </IconButton>
                                        </div>
                                    ))}
                                    <button
                                        className="button secondary"
                                        onClick={() =>
                                            setArtistFor(
                                                artistFor === row.id
                                                    ? null
                                                    : row.id,
                                            )
                                        }
                                    >
                                        {tr("catalog.addArtist")}
                                    </button>
                                    {artistFor === row.id && (
                                        <ArtistPicker
                                            choose={(artist) => {
                                                setArtistNames((current) => ({
                                                    ...current,
                                                    [artist.id]: artist.name,
                                                }));
                                                patchRow(row.id, {
                                                    credits: [
                                                        ...row.credits,
                                                        {
                                                            artist_id:
                                                                artist.id,
                                                            role: "primary",
                                                        },
                                                    ],
                                                });
                                                setArtistFor(null);
                                            }}
                                        />
                                    )}
                                </fieldset>
                                <div className="catalog-form-grid">
                                    <label>
                                        {tr("catalog.identity")}
                                        <input
                                            dir="ltr"
                                            value={row.recording_identity ?? ""}
                                            maxLength={160}
                                            placeholder="isrc:USAAA2600001"
                                            onChange={(e) =>
                                                patchRow(row.id, {
                                                    recording_identity:
                                                        e.target.value || null,
                                                })
                                            }
                                        />
                                    </label>
                                    <label>
                                        {tr("catalog.provenance")}
                                        <input
                                            dir="auto"
                                            value={
                                                row.recording_provenance ?? ""
                                            }
                                            maxLength={160}
                                            onChange={(e) =>
                                                patchRow(row.id, {
                                                    recording_provenance:
                                                        e.target.value || null,
                                                })
                                            }
                                        />
                                    </label>
                                </div>
                                <small className="muted">
                                    {tr("catalog.identityHelp")}
                                </small>
                            </li>
                        ))}
                    </ul>
                </>
            ) : (
                <>
                    <label>
                        {tr("catalog.findPlaylist")}
                        <input
                            dir="auto"
                            value={q}
                            maxLength={120}
                            onChange={(e) => {
                                setQ(e.target.value);
                                setPage(1);
                            }}
                        />
                    </label>
                    {playlists.isPending ? (
                        <Loading />
                    ) : playlists.error ? (
                        <ErrorState
                            error={playlists.error}
                            retry={() => void playlists.refetch()}
                        />
                    ) : (
                        <>
                            <div className="catalog-picker picker-results">
                                {playlists.data.data.map((item) => (
                                    <div
                                        key={item.id}
                                        className="catalog-actions"
                                    >
                                        <span dir="auto">
                                            {item.name} · #{item.id}
                                        </span>
                                        {mode === "playlists" ? (
                                            <button
                                                className="button secondary"
                                                onClick={() =>
                                                    setPlaylistRows((current) =>
                                                        appendUnique(
                                                            current,
                                                            item,
                                                            25,
                                                        ),
                                                    )
                                                }
                                            >
                                                {tr("catalog.select")}
                                            </button>
                                        ) : (
                                            <>
                                                <button
                                                    className="button secondary"
                                                    disabled={
                                                        target?.id === item.id
                                                    }
                                                    onClick={() => {
                                                        setSource(item);
                                                        setMoveIds([]);
                                                    }}
                                                >
                                                    {tr("catalog.setSource")}
                                                </button>
                                                <button
                                                    className="button secondary"
                                                    disabled={
                                                        source?.id === item.id
                                                    }
                                                    onClick={() =>
                                                        setTarget(item)
                                                    }
                                                >
                                                    {tr("catalog.setTarget")}
                                                </button>
                                            </>
                                        )}
                                    </div>
                                ))}
                            </div>
                            <Pager
                                page={page}
                                pages={playlists.data.meta.last_page}
                                setPage={setPage}
                            />
                        </>
                    )}
                    {mode === "playlists" ? (
                        <ul className="catalog-selection">
                            {playlistRows.map((row) => (
                                <li key={row.id}>
                                    <header>
                                        <strong>#{row.id}</strong>
                                        <IconButton
                                            label={tr("catalog.remove", {
                                                id: row.id,
                                            })}
                                            onClick={() =>
                                                setPlaylistRows((current) =>
                                                    current.filter(
                                                        (item) =>
                                                            item.id !== row.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <X size={18} />
                                        </IconButton>
                                    </header>
                                    <div className="catalog-form-grid">
                                        <label>
                                            {tr("catalog.name")}
                                            <input
                                                dir="auto"
                                                value={row.name}
                                                maxLength={160}
                                                onChange={(e) =>
                                                    setPlaylistRows((current) =>
                                                        current.map((item) =>
                                                            item.id === row.id
                                                                ? {
                                                                      ...item,
                                                                      name: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </label>
                                        <label>
                                            {tr("catalog.status")}
                                            <select
                                                value={row.status}
                                                onChange={(e) =>
                                                    setPlaylistRows((current) =>
                                                        current.map((item) =>
                                                            item.id === row.id
                                                                ? {
                                                                      ...item,
                                                                      status: e
                                                                          .target
                                                                          .value as Playlist["status"],
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                            >
                                                {(
                                                    [
                                                        "draft",
                                                        "published",
                                                        "archived",
                                                    ] as const
                                                ).map((status) => (
                                                    <option
                                                        key={status}
                                                        value={status}
                                                    >
                                                        {tr(
                                                            (
                                                                {
                                                                    draft: "catalog.draft",
                                                                    published:
                                                                        "catalog.published",
                                                                    archived:
                                                                        "catalog.archived",
                                                                } as const
                                                            )[status],
                                                        )}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label>
                                            {tr("catalog.mood")}
                                            <select
                                                value={row.mood}
                                                onChange={(e) =>
                                                    setPlaylistRows((current) =>
                                                        current.map((item) =>
                                                            item.id === row.id
                                                                ? {
                                                                      ...item,
                                                                      mood: e
                                                                          .target
                                                                          .value as Mood,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                            >
                                                {Object.entries(moodLabels).map(
                                                    ([value, label]) => (
                                                        <option
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </label>
                                    </div>
                                    <label>
                                        {tr("catalog.description")}
                                        <textarea
                                            dir="auto"
                                            value={row.description ?? ""}
                                            maxLength={3000}
                                            onChange={(e) =>
                                                setPlaylistRows((current) =>
                                                    current.map((item) =>
                                                        item.id === row.id
                                                            ? {
                                                                  ...item,
                                                                  description:
                                                                      e.target
                                                                          .value,
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <section>
                            <p>
                                {tr("catalog.source")}:{" "}
                                {source?.name ?? tr("catalog.empty")} →{" "}
                                {tr("catalog.target")}:{" "}
                                {target?.name ?? tr("catalog.empty")}
                            </p>
                            <p className="muted">{tr("catalog.moveHelp")}</p>
                            {sourceTracks.isFetching ? (
                                <Loading />
                            ) : sourceTracks.error ? (
                                <ErrorState
                                    error={sourceTracks.error}
                                    retry={() => void sourceTracks.refetch()}
                                />
                            ) : (
                                sourceTracks.data?.data.tracks?.map((track) => (
                                    <label
                                        key={track.id}
                                        className="checkbox-label"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={moveIds.includes(track.id)}
                                            disabled={
                                                !moveIds.includes(track.id) &&
                                                moveIds.length >= 100
                                            }
                                            onChange={(e) =>
                                                setMoveIds((ids) =>
                                                    e.target.checked
                                                        ? [...ids, track.id]
                                                        : ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  track.id,
                                                          ),
                                                )
                                            }
                                        />
                                        <span dir="auto">{track.title}</span>
                                    </label>
                                ))
                            )}
                        </section>
                    )}
                </>
            )}
            {preview.error && <p role="alert">{preview.error.message}</p>}
            <div className="catalog-actions">
                <button
                    className="button"
                    disabled={!ready || preview.isPending}
                    onClick={() => preview.mutate(captureSessionIntent(input))}
                >
                    {tr("catalog.preview")}
                </button>
                <button
                    className="button secondary"
                    disabled={!dirty || preview.isPending}
                    onClick={reset}
                >
                    {tr("catalog.clear")}
                </button>
            </div>
            {review && (
                <ChangeReview
                    key={review.id}
                    change={review}
                    close={() => setReview(null)}
                    changed={(change) => {
                        if (change.status === "committed") reset();
                    }}
                />
            )}
            <ChangeHistory />
        </div>
    );
}
