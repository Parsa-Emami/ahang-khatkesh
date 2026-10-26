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
    return last > 1 ? (
        <div className="pager">
            <button
                className="button secondary"
                disabled={page <= 1}
                onClick={() => change(page - 1)}
            >
                <ArrowRight size={16} />
                قبلی
            </button>
            <span>
                {digits(page)} از {digits(last)}
            </span>
            <button
                className="button secondary"
                disabled={page >= last}
                onClick={() => change(page + 1)}
            >
                بعدی
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
                    <h1>{saved ? "کتابخانهٔ تو" : "هر حال، یک آهنگ."}</h1>
                    <p>
                        {saved
                            ? "انتخاب‌هایی که دوست داری، همیشه نزدیک تو."
                            : `${user?.name.split(" ")[0]}، اینجا همه‌چیز روی حال تو تنظیم می‌شود.`}
                    </p>
                </div>
                {!saved && (
                    <span className="library-count">
                        <Disc3 size={17} />
                        {digits(stats.data?.data.tracks || 0)} آهنگ در کتابخانه
                    </span>
                )}
            </div>
            {!saved && featured && !mood && page === 1 && (
                <section className="featured">
                    <div className="featured-text">
                        <span className="feature-eyebrow">
                            <span className="live-dot" /> انتخاب این لحظه{" "}
                            <span lang="en">AK SELECTS / 001</span>
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
                                پخش پلی‌لیست
                            </button>
                            <Link
                                to={`/playlists/${featured.id}`}
                                className="text-link"
                            >
                                کشف آهنگ‌ها
                                <ArrowLeft size={18} />
                            </Link>
                        </div>
                        <span className="feature-meta">
                            {digits(featured.tracks_count)} آهنگ <span>·</span>{" "}
                            {moodLabels[featured.mood]} <span>·</span> فقط برای
                            اعضا
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
                <h2>{saved ? "انتخاب‌های ذخیره‌شده" : "به حال خودت گوش کن"}</h2>
                <span>{digits(playlists.data.meta.total)} مجموعه</span>
            </div>
            <div className="filter-chips" aria-label="فیلتر حال‌وهوا">
                <button
                    aria-pressed={!mood}
                    className={!mood ? "selected" : ""}
                    onClick={() => {
                        setMood("");
                        setPage(1);
                    }}
                >
                    همه
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
                    title={
                        saved
                            ? "کتابخانه‌ات منتظر انتخاب توست"
                            : "هنوز صدایی به اینجا نرسیده"
                    }
                    text={
                        saved
                            ? "با دکمهٔ نشانک در صفحهٔ هر پلی‌لیست، آن را ذخیره کن."
                            : "پلی‌لیست‌های واردشده از پنل مدیریت، اینجا قرار می‌گیرند."
                    }
                    admin={user?.is_admin && !saved}
                />
            )}
            <Pager
                page={page}
                last={playlists.data.meta.last_page}
                change={setPage}
            />
            <div className="page-footer">
                <span className="ruler-line" />
                <p>برای گوش دادن. برای خودت.</p>
                <span lang="en">AHANG KHATKESH</span>
            </div>
        </div>
    );
}
export function PlaylistPage() {
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
                همهٔ پلی‌لیست‌ها
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
                            ? "پیش‌نویس · فقط مدیران"
                            : "پلی‌لیست اختصاصی"}
                    </span>
                    <h1 dir="auto">{playlist.name}</h1>
                    <p>{playlist.description}</p>
                    <div className="playlist-meta">
                        <span className="brand-dot">AK</span> آهنگ خط‌کش{" "}
                        <span>·</span> {digits(tracks.length)} آهنگ{" "}
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
                    پخش همه
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
                    تصادفی
                </button>
                <SaveButton playlist={playlist} />
                {user?.is_admin && (
                    <Link
                        className="text-link admin-edit"
                        to="/admin?tab=library"
                    >
                        مدیریت پلی‌لیست
                        <ArrowLeft size={16} />
                    </Link>
                )}
            </div>
            {tracks.length ? (
                <TrackList tracks={tracks} seed={playlist.artwork_seed} />
            ) : (
                <EmptyState
                    title="این پلی‌لیست خالی است"
                    text="برای افزودن آهنگ از مسیر ورود پلی‌لیست در مدیریت استفاده کنید."
                />
            )}
        </div>
    );
}
export function LikedPage() {
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
                    <span className="eyebrow">ON REPEAT, IN YOUR HEART</span>
                    <h1>پسندیده‌ها</h1>
                    <p>
                        {digits(query.data.meta.total)} آهنگ که یک جایی به دلت
                        نشسته‌اند.
                    </p>
                </div>
                <button
                    className="button"
                    disabled={!tracks.length}
                    onClick={() => player.play(tracks)}
                >
                    <Play size={18} fill="currentColor" />
                    پخش این صفحه
                </button>
            </div>
            {tracks.length ? (
                <TrackList tracks={tracks} />
            ) : (
                <EmptyState
                    title="هنوز آهنگی را نپسندیده‌ای"
                    text="روی قلب کنار آهنگ‌ها بزن تا مجموعهٔ خودت را بسازی."
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
            <EmptyState
                title="دنبال چه صدایی می‌گردی؟"
                text="نام آهنگ، هنرمند، آلبوم یا پلی‌لیست را در جستجوی بالای صفحه بنویس."
            />
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
                    <span className="eyebrow">FIND YOUR FREQUENCY</span>
                    <h1>نتیجه برای «{q}»</h1>
                    <p>در آهنگ‌ها، هنرمندان و مجموعه‌های کتابخانهٔ خودت.</p>
                </div>
            </div>
            {!!playlists.data?.data.length && (
                <>
                    <div className="section-heading">
                        <h2>پلی‌لیست‌ها</h2>
                        <span>{digits(playlists.data.meta.total)} مجموعه</span>
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
                <h2>آهنگ‌ها</h2>
                <span>{digits(tracks.data?.meta.total || 0)} آهنگ</span>
            </div>
            {tracks.data?.data.length ? (
                <TrackList tracks={tracks.data.data} />
            ) : (
                <EmptyState
                    title="آهنگی پیدا نشد"
                    text="یک نام کوتاه‌تر یا نام هنرمند را امتحان کن."
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
