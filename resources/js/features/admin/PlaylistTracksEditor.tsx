import { useDraftGuard } from "../drafts/DraftNavigation";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowUp, ArrowDown, X } from "lucide-react";
import { Dialog, ErrorState, Loading, IconButton } from "../../components/UI";
import { api, ApiError, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import { digits, type Playlist, type Track } from "../../types";
import { freshDraft, receiveDraft, editDraft } from "../../lib/versioned-draft";
import { TrackPicker } from "./Pickers";
export function PlaylistTracksEditor({
    playlist,
    close,
}: {
    playlist: Playlist;
    close: () => void;
}) {
    useLocale();
    const client = useQueryClient(),
        notify = useToast();
    const [draft, setDraft] = useState(() =>
        freshDraft<Track>([], playlist.revision),
    );
    const [conflict, setConflict] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    useDraftGuard(draft.dirty, () =>
        setDraft(freshDraft([], playlist.revision)),
    );
    const tracks = draft.items,
        revision = draft.revision;
    const setTracks = (change: (items: Track[]) => Track[]) =>
        setDraft((old) => editDraft(old, change(old.items)));
    const query = useQuery({
        queryKey: ["playlist", "admin-edit", playlist.id],
        queryFn: () => api<{ data: Playlist }>(`/playlists/${playlist.id}`),
        staleTime: 0,
    });
    useEffect(() => {
        if (query.data)
            setDraft((old) =>
                receiveDraft(
                    old,
                    query.data.data.tracks || [],
                    query.data.data.revision,
                ),
            );
    }, [query.data]);
    useEffect(() => {
        if (!draft.dirty) return;
        const guard = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = "";
        };
        window.addEventListener("beforeunload", guard);
        return () => window.removeEventListener("beforeunload", guard);
    }, [draft.dirty]);
    async function discardAndReload() {
        const result = await query.refetch();
        if (result.data && !result.isError) {
            setDraft(
                freshDraft(
                    result.data.data.tracks || [],
                    result.data.data.revision,
                ),
            );
            setConflict(false);
        }
    }
    const save = useMutation({
        mutationFn: () =>
            api<{ data: Playlist }>(`/admin/playlists/${playlist.id}/tracks`, {
                method: "PUT",
                body: json({ revision, track_ids: tracks.map((t) => t.id) }),
            }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ["playlists"] });
            void client.invalidateQueries({ queryKey: ["playlist"] });
            void client.invalidateQueries({ queryKey: ["tracks"] });
            notify(tr("m8ec7871359"));
            close();
        },
        onError: (e) => {
            if (e instanceof ApiError && e.status === 409) setConflict(true);
            notify(e.message, true);
        },
    });
    function move(index: number, offset: number) {
        setTracks((previous) => {
            const next = [...previous];
            [next[index], next[index + offset]] = [
                next[index + offset],
                next[index],
            ];
            return next;
        });
    }
    return (
        <>
            <Dialog
                guard={false}
                open
                onOpenChange={(v) => {
                    if (!v && !save.isPending) {
                        if (draft.dirty) setConfirmClose(true);
                        else close();
                    }
                }}
                title={tr("m8c3b5bed96", { v0: playlist.name })}
                description={tr("m2423a3ab76")}
            >
                {query.isPending ? (
                    <Loading />
                ) : query.error && !draft.dirty ? (
                    <ErrorState
                        error={query.error}
                        retry={() => void query.refetch()}
                    />
                ) : (
                    <div className="stack-form">
                        <TrackPicker
                            choose={(track) =>
                                setTracks((old) =>
                                    old.some((t) => t.id === track.id) ||
                                    old.length >= 500
                                        ? old
                                        : [...old, track],
                                )
                            }
                        />
                        <p className="muted">
                            {tr("m2d17c5b900", { v0: digits(tracks.length) })}
                        </p>
                        <ol className="arrange-list">
                            {tracks.map((track, i) => (
                                <li key={track.id}>
                                    <span>
                                        <strong dir="auto">
                                            {track.title}
                                        </strong>
                                        <small dir="auto">{track.artist}</small>
                                    </span>
                                    <IconButton
                                        label={tr("mfc689a87c8", {
                                            v0: track.title,
                                        })}
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                    >
                                        <ArrowUp size={16} />
                                    </IconButton>
                                    <IconButton
                                        label={tr("m9479e7ac62", {
                                            v0: track.title,
                                        })}
                                        disabled={i === tracks.length - 1}
                                        onClick={() => move(i, 1)}
                                    >
                                        <ArrowDown size={16} />
                                    </IconButton>
                                    <IconButton
                                        label={tr("m3a6c4886b7", {
                                            v0: track.title,
                                        })}
                                        onClick={() =>
                                            setTracks((old) =>
                                                old.filter(
                                                    (t) => t.id !== track.id,
                                                ),
                                            )
                                        }
                                    >
                                        <X size={16} />
                                    </IconButton>
                                </li>
                            ))}
                        </ol>
                        {(conflict || draft.remoteUpdate) && (
                            <div className="form-error" role="alert">
                                {tr("mf5eb871ae0")}
                                <button
                                    className="text-link"
                                    onClick={() => void discardAndReload()}
                                >
                                    {tr("m88500d4452")}
                                </button>
                            </div>
                        )}
                        <button
                            className="button"
                            disabled={
                                save.isPending || conflict || draft.remoteUpdate
                            }
                            onClick={() => save.mutate()}
                        >
                            {tr("m2e4200c259")}
                        </button>
                    </div>
                )}
            </Dialog>
            <Dialog
                open={confirmClose}
                onOpenChange={setConfirmClose}
                title={tr("m707b70b7c3")}
                description={tr("m2c01bb9449")}
            >
                <button
                    className="button"
                    onClick={() => setConfirmClose(false)}
                >
                    {tr("m835821087b")}
                </button>
                <button className="button secondary" onClick={close}>
                    {tr("m08d8acd5ab")}
                </button>
            </Dialog>
        </>
    );
}
