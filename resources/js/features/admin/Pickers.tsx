import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useDeferredValue, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { adminApi } from "./api";
import type { Artist } from "./contracts";
import type { Track } from "../../types";
export function ArtistPicker({ choose }: { choose: (artist: Artist) => void }) {
    useLocale();
    const [q, setQ] = useState("");
    const term = useDeferredValue(q);
    const query = useQuery({
        queryKey: ["admin", "artist-picker", term],
        queryFn: () => adminApi.artists(term),
    });
    return (
        <div className="catalog-picker">
            <label>
                {tr("m2c42a75eca")}
                <input
                    dir="auto"
                    name="q"
                    value={q}
                    maxLength={120}
                    placeholder={tr("m9652203787")}
                    onChange={(e) => setQ(e.target.value)}
                />
            </label>
            <div className="picker-results" aria-label={tr("m7cb0e58356")}>
                {query.isPending ? (
                    <p role="status">{tr("ma278059340")}</p>
                ) : query.error ? (
                    <p role="alert">{query.error.message}</p>
                ) : (
                    query.data.data.map((artist) => (
                        <button
                            type="button"
                            key={artist.id}
                            onClick={() => choose(artist)}
                        >
                            <span>
                                {artist.name}
                                <small>
                                    {tr("mab9829700c", { v0: artist.id })}
                                </small>
                            </span>
                            <Plus size={16} />
                        </button>
                    ))
                )}
                {query.data && !query.data.data.length && (
                    <p>{tr("mbde94c8edf")}</p>
                )}
            </div>
            <small className="muted">{tr("m6d3137ffcb")}</small>
        </div>
    );
}
export function TrackPicker({ choose }: { choose: (track: Track) => void }) {
    useLocale();
    const [q, setQ] = useState("");
    const term = useDeferredValue(q);
    const query = useQuery({
        queryKey: ["admin", "track-picker", term],
        queryFn: () => adminApi.tracks(term),
    });
    return (
        <div className="catalog-picker">
            <label>
                {tr("m9ee0d80fdd")}
                <input
                    dir="auto"
                    name="q"
                    value={q}
                    maxLength={120}
                    placeholder={tr("m276a994c94")}
                    onChange={(e) => setQ(e.target.value)}
                />
            </label>
            <div className="picker-results" aria-label={tr("mde7c5309a5")}>
                {query.isPending ? (
                    <p role="status">{tr("ma278059340")}</p>
                ) : query.error ? (
                    <p role="alert">{query.error.message}</p>
                ) : (
                    query.data.data.map((track) => (
                        <button
                            type="button"
                            key={track.id}
                            onClick={() => choose(track)}
                        >
                            <span>
                                {track.title}
                                <small>
                                    {tr("mc8429c8e54", {
                                        v0: track.artist,
                                        v1: track.id,
                                    })}
                                </small>
                            </span>
                            <Plus size={16} />
                        </button>
                    ))
                )}
                {query.data && !query.data.data.length && (
                    <p>{tr("mc6021a1c4a")}</p>
                )}
            </div>
            <small className="muted">{tr("m6fcde83655")}</small>
        </div>
    );
}
