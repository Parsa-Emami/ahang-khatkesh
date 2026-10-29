import { tr } from "../i18n/runtime";
import { useLocale } from "../i18n/LocaleProvider";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, Disc3, Play, Shuffle } from "lucide-react";
import { Link, useParams, useSearchParams } from "react-router-dom";
import { usePlayer } from "../context/PlayerContext";
import { useSession, useToast } from "../context/AppContext";
import { api } from "../lib/api";
import {
    digits,
    duration,
    moodLabels,
    type Mood,
    type Page,
    type Playlist,
    type Track,
} from "../types";
import { Artwork, EmptyState, ErrorState, Loading } from "../components/UI";
import { PlaylistCard, SaveButton, TrackList } from "../components/Music";

function Pager({
    page,
    last,
    change,
}: {
    page: number;
    last: number;
    change: (page: number) => void;
}) {
    useLocale();
    return last > 1 ? (
        <div className="pager">
            <button
                className="button secondary"
                disabled={page <= 1}
                onClick={() => change(page - 1)}
            >
                <ArrowRight size={16} />
                {tr("m1a592f6b2d")}
            </button>
            <span>
                {tr("m3dadd024d7", { v0: digits(page), v1: digits(last) })}
            </span>
            <button
                className="button secondary"
                disabled={page >= last}
                onClick={() => change(page + 1)}
            >
                {tr("m54ee927e96")}
                <ArrowLeft size={16} />
            </button>
        </div>
    ) : null;
}
function usePlaylistPlay() {
    const client = useQueryClient(),
        player = usePlayer(),
        notify = useToast();
    return useMutation({
        mutationFn: (playlist: Playlist) =>
            client.fetchQuery({
                queryKey: ["playlist", playlist.id],
                queryFn: () =>
                    api<{ data: Playlist }>(`/playlists/${playlist.id}`),
            }),
        onSuccess: ({ data }) =>
            player.play(
                (data.tracks || []).map((t) => ({
                    ...t,
                    artwork_seed: data.artwork_seed,
                    cover_url: data.cover_url,
                })),
            ),
        onError: (error) => notify(error.message, true),
    });
}
export function LibraryPage({ saved = false }: { saved?: boolean }) {
    useLocale();
    const { user } = useSession(),
        player = usePlaylistPlay();
    const [mood, setMood] = useState<Mood | "">(""),
        [page, setPage] = useState(1);
    const playlists = useQuery({
        queryKey: ["playlists", saved ? "saved" : "all", mood, page],
        queryFn: () =>
            api<Page<Playlist>>(
                `/playlists?page=${page}${saved ? "&saved=1" : ""}${mood ? `&mood=${mood}` : ""}`,
            ),
    });
    const stats = useQuery({
        queryKey: ["stats"],
        queryFn: () =>
            api<{ data: { playlists: number; tracks: number } }>("/stats"),
    });
    if (playlists.isPending) return <Loading />;
    if (playlists.error)
        return (
            <ErrorState
                error={playlists.error}
                retry={() => void playlists.refetch()}
            />
        );
    const lists = playlists.data.data;
    const featured = lists.find((p) => p.mood === "night") || lists[0];
    return (
        <div className="page-enter">
            <div className="page-intro">
                <div>
                    <span className="eyebrow">
                        {saved ? "YOUR COLLECTION" : "YOUR EVERYDAY SOUNDTRACK"}
                    </span>
                    <h1>{saved ? tr("m3bb7980642") : tr("m7e49718b74")}</h1>
                    <p>
                        {saved
                            ? tr("m4443acf78b")
                            : tr("m9808e0ac01", {
                                  v0: user?.name.split(" ")[0],
                              })}
                    </p>
                </div>
                {!saved && (
                    <span className="library-count">
                        <Disc3 size={17} />
                        {tr("m43ed92bcbb", {
                            v0: digits(stats.data?.data.tracks || 0),
                        })}
                    </span>
                )}
            </div>
            {!saved && featured && !mood && page === 1 && (
                <section className="featured">
                    <div className="featured-text">
                        <span className="feature-eyebrow">
                            <span className="live-dot" />
                            {tr("ma1ce3ec2e4")}
                            <span>{tr("editorial.selects")}</span>
                        </span>
                        <h2 dir="auto">{featured.name}</h2>
                        <p>{featured.description}</p>
                        <div className="featured-actions">
                            <button
                                className="button"
                                onClick={() => player.mutate(featured)}
                                disabled={
                                    player.isPending || !featured.tracks_count
                                }
                            >
                                <Play size={18} fill="currentColor" />
                                {tr("m1b4008129b")}
                            </button>
                            <Link
                                to={`/playlists/${featured.id}`}
                                className="text-link"
                            >
                                {tr("maeac03271e")}
                                <ArrowLeft size={18} />
                            </Link>
                        </div>
                        <span className="feature-meta">
                            {tr("mb5e27e3711", {
                                v0: digits(featured.tracks_count),
                            })}
                            <span>·</span> {moodLabels[featured.mood]}{" "}
                            <span>·</span>
                            {tr("mdc4042e89c")}
                        </span>
                    </div>
                    <Link
                        className="featured-art"
                        to={`/playlists/${featured.id}`}
                        tabIndex={-1}
                        aria-hidden="true"
                    >
                        <Artwork
                            seed={featured.artwork_seed}
                            url={featured.cover_url}
                        />
                        <span className="vinyl" aria-hidden="true">
                            <i />
                        </span>
                    </Link>
                </section>
            )}
            <div className="section-heading">
                <h2>{saved ? tr("m48113a6829") : tr("m42b273825c")}</h2>
                <span>
                    {tr("ma755196422", {
                        v0: digits(playlists.data.meta.total),
                    })}
                </span>
            </div>
            <div className="filter-chips" aria-label={tr("mbfbb51603a")}>
                <button
                    aria-pressed={!mood}
                    className={!mood ? "selected" : ""}
                    onClick={() => {
                        setMood("");
                        setPage(1);
                    }}
                >
                    {tr("mbf742c5a3d")}
                </button>
                {Object.entries(moodLabels).map(([key, label]) => (
                    <button
                        key={key}
                        aria-pressed={mood === key}
                        className={mood === key ? "selected" : ""}
                        onClick={() => {
                            setMood(key as Mood);
                            setPage(1);
                        }}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {lists.length ? (
                <div className="playlist-grid">
                    {lists.map((playlist) => (
                        <PlaylistCard
                            key={playlist.id}
                            playlist={playlist}
                            pending={player.isPending}
                            onPlay={() => player.mutate(playlist)}
                        />
                    ))}
                </div>
            ) : (
                <EmptyState
                    title={saved ? tr("mf353f650ac") : tr("m5aee0a9b95")}
                    text={saved ? tr("m0dc6b5d4e0") : tr("m4b9831eb42")}
                    admin={user?.permissions.includes("admin.access") && !saved}
                />
            )}
            <Pager
                page={page}
                last={playlists.data.meta.last_page}
                change={setPage}
            />
            <div className="page-footer">
                <span className="ruler-line" />
                <p>{tr("mcf6d8b6ee9")}</p>
                <span lang="en">AHANG KHATKESH</span>
            </div>
        </div>
    );
}
export function PlaylistPage() {
    useLocale();
    const { id } = useParams(),
        { user } = useSession(),
        player = usePlayer();
    const query = useQuery({
        queryKey: ["playlist", Number(id)],
        queryFn: () => api<{ data: Playlist }>(`/playlists/${id}`),
    });
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    const playlist = query.data.data,
        tracks = (playlist.tracks || []).map((t) => ({
            ...t,
            artwork_seed: playlist.artwork_seed,
            cover_url: playlist.cover_url,
        }));
    const total = tracks.reduce(
        (sum, track) => sum + track.duration_seconds,
        0,
    );
    return (
        <div className="page-enter">
            <Link to="/" className="back-link">
                <ArrowRight size={16} />
                {tr("mc69a5555d5")}
            </Link>
            <section className="playlist-hero">
                <Artwork
                    seed={playlist.artwork_seed}
                    url={playlist.cover_url}
                    label={playlist.name}
                />
                <div>
                    <span className="eyebrow">
                        {playlist.status === "draft"
                            ? tr("m9bdec27f97")
                            : tr("ma906ac387c")}
                    </span>
                    <h1 dir="auto">{playlist.name}</h1>
                    <p>{playlist.description}</p>
                    <div className="playlist-meta">
                        <span className="brand-dot">AK</span>
                        {tr("m0d95cf7cb3")}
                        <span>·</span>
                        {tr("m41ffc70bd9", { v0: digits(tracks.length) })}
                        <span>·</span> <span dir="ltr">{duration(total)}</span>
                    </div>
                </div>
            </section>
            <div className="playlist-actions">
                <button
                    className="button"
                    disabled={!tracks.length}
                    onClick={() => player.play(tracks)}
                >
                    <Play size={20} fill="currentColor" />
                    {tr("m639f237b3e")}
                </button>
                <button
                    className="button secondary"
                    disabled={!tracks.length}
                    onClick={() => {
                        if (!player.shuffle) player.toggleShuffle();
                        player.play(tracks);
                    }}
                >
                    <Shuffle size={18} />
                    {tr("m7678b785f8")}
                </button>
                <SaveButton playlist={playlist} />
                {user?.permissions.includes("admin.access") && (
                    <Link
                        className="text-link admin-edit"
                        to="/admin?tab=library"
                    >
                        {tr("m9d49d86b99")}
                        <ArrowLeft size={16} />
                    </Link>
                )}
            </div>
            {tracks.length ? (
                <TrackList tracks={tracks} seed={playlist.artwork_seed} />
            ) : (
                <EmptyState
                    title={tr("m0fbd067b78")}
                    text={tr("m57b1f2b99c")}
                />
            )}
        </div>
    );
}
export function LikedPage() {
    useLocale();
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: ["tracks", "liked", page],
        queryFn: () => api<Page<Track>>(`/tracks?liked=1&page=${page}`),
    });
    const player = usePlayer();
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    const tracks = query.data.data;
    return (
        <div className="page-enter">
            <div className="page-intro">
                <div>
                    <span className="eyebrow">{tr("editorial.liked")}</span>
                    <h1>{tr("m75f44e8bed")}</h1>
                    <p>
                        {tr("m2fabca4889", {
                            v0: digits(query.data.meta.total),
                        })}
                    </p>
                </div>
                <button
                    className="button"
                    disabled={!tracks.length}
                    onClick={() => player.play(tracks)}
                >
                    <Play size={18} fill="currentColor" />
                    {tr("mee8fa9e019")}
                </button>
            </div>
            {tracks.length ? (
                <TrackList tracks={tracks} />
            ) : (
                <EmptyState
                    title={tr("mcb5a698aa0")}
                    text={tr("m27c811bc71")}
                />
            )}
            <Pager
                page={page}
                last={query.data.meta.last_page}
                change={setPage}
            />
        </div>
    );
}
export function SearchPage() {
    useLocale();
    const [params, setParams] = useSearchParams();
    const q = params.get("q") || "",
        page = Number(params.get("page") || "1");
    const tracks = useQuery({
        queryKey: ["tracks", "search", q, page],
        queryFn: () =>
            api<Page<Track>>(`/tracks?q=${encodeURIComponent(q)}&page=${page}`),
        enabled: Boolean(q),
    });
    const playlists = useQuery({
        queryKey: ["playlists", "search", q, page],
        queryFn: () =>
            api<Page<Playlist>>(
                `/playlists?q=${encodeURIComponent(q)}&page=${page}`,
            ),
        enabled: Boolean(q),
    });
    const play = usePlaylistPlay();
    if (!q)
        return (
            <EmptyState title={tr("md1516efa97")} text={tr("mb3cd712731")} />
        );
    if (tracks.isPending || playlists.isPending) return <Loading />;
    if (tracks.error || playlists.error)
        return (
            <ErrorState
                error={(tracks.error || playlists.error)!}
                retry={() => {
                    void tracks.refetch();
                    void playlists.refetch();
                }}
            />
        );
    return (
        <div className="page-enter">
            <div className="page-intro">
                <div>
                    <span className="eyebrow">{tr("editorial.search")}</span>
                    <h1>{tr("m7e7cd73bad", { v0: q })}</h1>
                    <p>{tr("mc3960046d2")}</p>
                </div>
            </div>
            {!!playlists.data?.data.length && (
                <>
                    <div className="section-heading">
                        <h2>{tr("m810b71a913")}</h2>
                        <span>
                            {tr("ma755196422", {
                                v0: digits(playlists.data.meta.total),
                            })}
                        </span>
                    </div>
                    <div className="playlist-grid">
                        {playlists.data.data.map((p) => (
                            <PlaylistCard
                                key={p.id}
                                playlist={p}
                                pending={play.isPending}
                                onPlay={() => play.mutate(p)}
                            />
                        ))}
                    </div>
                </>
            )}
            <div className="section-heading">
                <h2>{tr("mfc7b9f1ff7")}</h2>
                <span>
                    {tr("m0816e22877", {
                        v0: digits(tracks.data?.meta.total || 0),
                    })}
                </span>
            </div>
            {tracks.data?.data.length ? (
                <TrackList tracks={tracks.data.data} />
            ) : (
                <EmptyState
                    title={tr("mea85ae1f09")}
                    text={tr("mdb1217de70")}
                />
            )}
            <Pager
                page={page}
                last={Math.max(
                    tracks.data?.meta.last_page || 1,
                    playlists.data?.meta.last_page || 1,
                )}
                change={(value) => setParams({ q, page: String(value) })}
            />
        </div>
    );
}
