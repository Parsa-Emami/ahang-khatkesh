import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowUp, ArrowDown, X } from "lucide-react";
import { Dialog, ErrorState, Loading, IconButton } from "../../components/UI";
import { api, ApiError, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import { digits, type Playlist, type Track } from "../../types";
import { TrackPicker } from "./Pickers";
export function PlaylistTracksEditor({
    playlist,
    close,
}: {
    playlist: Playlist;
    close: () => void;
}) {
    const client = useQueryClient(),
        notify = useToast();
    const [tracks, setTracks] = useState<Track[]>([]),
        [revision, setRevision] = useState(playlist.revision),
        [conflict, setConflict] = useState(false);
    const query = useQuery({
        queryKey: ["playlist", "admin-edit", playlist.id],
        queryFn: () => api<{ data: Playlist }>(`/playlists/${playlist.id}`),
        staleTime: 0,
    });
    useEffect(() => {
        if (query.data) {
            setTracks(query.data.data.tracks || []);
            setRevision(query.data.data.revision);
            setConflict(false);
        }
    }, [query.data]);
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
            notify("ترتیب آهنگ‌ها ذخیره شد.");
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
        <Dialog
            open
            onOpenChange={(v) => {
                if (!v && !save.isPending) close();
            }}
            title={`آهنگ‌های ${playlist.name}`}
            description="با فلش‌ها ترتیب را تغییر بده. حذف از این فهرست، فایل یا علاقه‌مندی‌ها را حذف نمی‌کند."
        >
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
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
                        {digits(tracks.length)} آهنگ · سقف ۵۰۰ آهنگ بدون تکرار
                    </p>
                    <ol className="arrange-list">
                        {tracks.map((track, i) => (
                            <li key={track.id}>
                                <span>
                                    <strong>{track.title}</strong>
                                    <small>{track.artist}</small>
                                </span>
                                <IconButton
                                    label={`بالا بردن ${track.title}`}
                                    disabled={i === 0}
                                    onClick={() => move(i, -1)}
                                >
                                    <ArrowUp size={16} />
                                </IconButton>
                                <IconButton
                                    label={`پایین بردن ${track.title}`}
                                    disabled={i === tracks.length - 1}
                                    onClick={() => move(i, 1)}
                                >
                                    <ArrowDown size={16} />
                                </IconButton>
                                <IconButton
                                    label={`برداشتن ${track.title} از پلی‌لیست`}
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
                    {conflict && (
                        <div className="form-error" role="alert">
                            نسخهٔ تازه‌ای در سرور وجود دارد.{" "}
                            <button
                                className="text-link"
                                onClick={() => void query.refetch()}
                            >
                                کنار گذاشتن این ترتیب و بارگذاری نسخهٔ تازه
                            </button>
                        </div>
                    )}
                    <button
                        className="button"
                        disabled={save.isPending || conflict}
                        onClick={() => save.mutate()}
                    >
                        ذخیرهٔ ترتیب و آهنگ‌ها
                    </button>
                </div>
            )}
        </Dialog>
    );
}
