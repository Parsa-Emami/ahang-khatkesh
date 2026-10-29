import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useState, useRef } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ImagePlus, Pencil, ListMusic, Plus } from "lucide-react";
import { uploadWithReservation } from "./uploads";
import { TrashButton } from "./TrashPanel";
import { PlaylistTracksEditor } from "./PlaylistTracksEditor";
import { Link } from "react-router-dom";
import { api, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import {
    digits,
    moodLabels,
    type Mood,
    type Page,
    type Playlist,
} from "../../types";
import {
    Artwork,
    Dialog,
    ErrorState,
    IconButton,
    Loading,
} from "../../components/UI";

export function ManageLibraryPanel() {
    useLocale();
    const editingBaseline = useRef("");
    const client = useQueryClient(),
        notify = useToast();
    const [page, setPage] = useState(1),
        [editing, setEditing] = useState<Playlist | null>(null);
    const query = useQuery({
        queryKey: ["playlists", "admin", page],
        queryFn: () => api<Page<Playlist>>(`/playlists?page=${page}`),
    });
    const [creating, setCreating] = useState(false),
        [newName, setNewName] = useState(""),
        [arranging, setArranging] = useState<Playlist | null>(null);
    const create = useMutation({
        mutationFn: () =>
            api("/admin/playlists", {
                method: "POST",
                body: json({ name: newName, mood: "other" }),
            }),
        onSuccess: () => {
            setCreating(false);
            setNewName("");
            void client.invalidateQueries({ queryKey: ["playlists"] });
            notify(tr("mbc049697c0"));
        },
        onError: (e) => notify(e.message, true),
    });
    const update = useMutation({
        mutationFn: () =>
            api(`/admin/playlists/${editing!.id}`, {
                method: "PATCH",
                body: json({
                    revision: editing!.revision,
                    name: editing!.name,
                    description: editing!.description,
                    mood: editing!.mood,
                    status: editing!.status,
                }),
            }),
        onSuccess: () => {
            setEditing(null);
            void client.invalidateQueries({ queryKey: ["playlists"] });
            void client.invalidateQueries({ queryKey: ["playlist"] });
            notify(tr("m39bbf7b230"));
        },
        onError: (error) => notify(error.message, true),
    });
    const cover = useMutation({
        mutationFn: async ({
            playlist,
            file,
        }: {
            playlist: Playlist;
            file: File;
        }) => {
            return uploadWithReservation(
                file,
                "cover",
                `/admin/playlists/${playlist.id}/cover`,
                { revision: String(playlist.revision) },
            );
        },
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ["playlists"] });
            void client.invalidateQueries({ queryKey: ["playlist"] });
            notify(tr("m6c37c2f0aa"));
        },
        onError: (error) => notify(error.message, true),
    });
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    return (
        <>
            <div className="admin-toolbar">
                <p className="muted">{tr("mf66af47953")}</p>
                <button className="button" onClick={() => setCreating(true)}>
                    <Plus size={16} />
                    {tr("m384f02a592")}
                </button>
            </div>
            <p className="muted">{tr("m265e118451")}</p>
            <div className="manage-list">
                {query.data.data.map((playlist) => (
                    <div className="manage-row" key={playlist.id}>
                        <Artwork
                            seed={playlist.artwork_seed}
                            url={playlist.cover_url}
                        />
                        <div>
                            <Link to={`/playlists/${playlist.id}`}>
                                <strong dir="auto">{playlist.name}</strong>
                            </Link>
                            <small>
                                {tr("m540dfb95d2", {
                                    v0: digits(playlist.tracks_count),
                                    v1: moodLabels[playlist.mood],
                                })}
                            </small>
                        </div>
                        <span
                            className={`status-pill status-${playlist.status === "published" ? "completed" : "ready"}`}
                        >
                            {playlist.status === "published"
                                ? tr("m082d672daf")
                                : playlist.status === "archived"
                                  ? tr("m217fad791a")
                                  : tr("m7d739ea1cc")}
                        </span>
                        <label
                            className="icon-button cover-upload"
                            title={tr("m3b2d3856d6")}
                        >
                            <ImagePlus size={18} />
                            <input
                                dir="auto"
                                aria-label={tr("m44ea914810", {
                                    v0: playlist.name,
                                })}
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp"
                                disabled={cover.isPending}
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (file) cover.mutate({ playlist, file });
                                    e.target.value = "";
                                }}
                            />
                        </label>
                        <IconButton
                            label={tr("m1d37d5c204", { v0: playlist.name })}
                            onClick={() => setArranging(playlist)}
                        >
                            <ListMusic size={18} />
                        </IconButton>
                        <IconButton
                            label={tr("m54a609b1a4", { v0: playlist.name })}
                            onClick={() => {
                                editingBaseline.current =
                                    JSON.stringify(playlist);
                                setEditing({ ...playlist });
                            }}
                        >
                            <Pencil size={18} />
                        </IconButton>
                        <TrashButton
                            kind="playlist"
                            id={playlist.id}
                            title={playlist.name}
                            revision={playlist.revision}
                        />
                    </div>
                ))}
                {!query.data.data.length && (
                    <p className="muted">{tr("m4ad2e3e933")}</p>
                )}
            </div>
            {query.data.meta.last_page > 1 && (
                <div className="pager">
                    <button
                        className="button secondary"
                        disabled={page === 1}
                        onClick={() => setPage((v) => v - 1)}
                    >
                        {tr("m1a592f6b2d")}
                    </button>
                    <span>{digits(page)}</span>
                    <button
                        className="button secondary"
                        disabled={page === query.data.meta.last_page}
                        onClick={() => setPage((v) => v + 1)}
                    >
                        {tr("m54ee927e96")}
                    </button>
                </div>
            )}
            <Dialog
                open={creating}
                dirty={!!newName}
                onDiscard={() => setNewName("")}
                onOpenChange={(v) => {
                    if (!create.isPending) setCreating(v);
                }}
                title={tr("m3e01f24d25")}
                description={tr("m45a92e34c4")}
            >
                <form
                    className="stack-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.mutate();
                    }}
                >
                    <label>
                        {tr("m8a75d58198")}
                        <input
                            dir="auto"
                            name="name"
                            required
                            maxLength={160}
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                        />
                    </label>
                    <button className="button" disabled={create.isPending}>
                        {tr("m15145e2f87")}
                    </button>
                </form>
            </Dialog>
            {arranging && (
                <PlaylistTracksEditor
                    playlist={arranging}
                    close={() => setArranging(null)}
                />
            )}
            <Dialog
                open={!!editing}
                dirty={
                    !!editing &&
                    JSON.stringify(editing) !== editingBaseline.current
                }
                onDiscard={() => setEditing(null)}
                onOpenChange={(value) => {
                    if (!value && !update.isPending) setEditing(null);
                }}
                title={tr("m9d8cc5b670")}
                description={tr("mba108739ff")}
            >
                {editing && (
                    <form
                        className="stack-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            update.mutate();
                        }}
                    >
                        <label>
                            {tr("m45dd06ba40")}
                            <input
                                dir="auto"
                                name="name"
                                required
                                maxLength={160}
                                value={editing.name}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        name: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            {tr("md47dc50e87")}
                            <textarea
                                dir="auto"
                                maxLength={2000}
                                rows={3}
                                value={editing.description || ""}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        description: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            {tr("m7a15be7b2e")}
                            <select
                                dir="auto"
                                name="mood"
                                value={editing.mood}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        mood: e.target.value as Mood,
                                    })
                                }
                            >
                                {Object.entries(moodLabels).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label>
                            {tr("m55518965c6")}
                            <select
                                dir="auto"
                                name="status"
                                value={editing.status}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        status: e.target
                                            .value as Playlist["status"],
                                    })
                                }
                            >
                                <option value="draft">
                                    {tr("m7d739ea1cc")}
                                </option>
                                <option value="archived">
                                    {tr("m217fad791a")}
                                </option>
                                <option value="published">
                                    {tr("m082d672daf")}
                                </option>
                            </select>
                        </label>
                        <button className="button" disabled={update.isPending}>
                            {tr("m745b2d2795")}
                        </button>
                    </form>
                )}
            </Dialog>
        </>
    );
}
