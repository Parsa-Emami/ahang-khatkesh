import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
    Bookmark,
    Clock3,
    Heart,
    ListMusic,
    LoaderCircle,
    Pause,
    Play,
    Repeat,
    Repeat1,
    Shuffle,
    SkipBack,
    SkipForward,
    Volume2,
    VolumeX,
} from "lucide-react";
import { Link } from "react-router-dom";
import { useState } from "react";
import { usePlayer } from "../context/PlayerContext";
import { useToast } from "../context/AppContext";
import { api } from "../lib/api";
import {
    digits,
    duration,
    moodLabels,
    type Playlist,
    type Track,
} from "../types";
import { Artwork, Dialog, IconButton } from "./UI";

export function LikeButton({ track }: { track: Track }) {
    const client = useQueryClient(),
        player = usePlayer(),
        notify = useToast();
    const mutation = useMutation({
        mutationFn: () =>
            api(`/tracks/${track.id}/like`, {
                method: track.is_liked ? "DELETE" : "PUT",
            }),
        onSuccess: () => {
            player.setLiked(track.id, !track.is_liked);
            void client.invalidateQueries({ queryKey: ["tracks"] });
            void client.invalidateQueries({ queryKey: ["playlist"] });
        },
        onError: (error) => notify(error.message, true),
    });
    return (
        <IconButton
            label={
                track.is_liked ? "حذف از پسندیده‌ها" : "افزودن به پسندیده‌ها"
            }
            aria-pressed={track.is_liked}
            disabled={mutation.isPending}
            className={track.is_liked ? "liked" : ""}
            onClick={() => mutation.mutate()}
        >
            <Heart size={18} fill={track.is_liked ? "currentColor" : "none"} />
        </IconButton>
    );
}
export function SaveButton({ playlist }: { playlist: Playlist }) {
    const client = useQueryClient(),
        notify = useToast();
    const mutation = useMutation({
        mutationFn: () =>
            api(`/playlists/${playlist.id}/save`, {
                method: playlist.is_saved ? "DELETE" : "PUT",
            }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ["playlists"] });
            void client.invalidateQueries({
                queryKey: ["playlist", playlist.id],
            });
            notify(
                playlist.is_saved
                    ? "از کتابخانه حذف شد."
                    : "به کتابخانه اضافه شد.",
            );
        },
        onError: (error) => notify(error.message, true),
    });
    return (
        <IconButton
            label={playlist.is_saved ? "حذف از کتابخانه" : "ذخیره در کتابخانه"}
            aria-pressed={playlist.is_saved}
            disabled={mutation.isPending}
            onClick={() => mutation.mutate()}
        >
            <Bookmark
                size={22}
                fill={playlist.is_saved ? "currentColor" : "none"}
            />
        </IconButton>
    );
}
export function PlaylistCard({
    playlist,
    onPlay,
    pending = false,
}: {
    playlist: Playlist;
    onPlay: () => void;
    pending?: boolean;
}) {
    return (
        <article className="playlist-card">
            <div className="playlist-card-art">
                <Link
                    to={`/playlists/${playlist.id}`}
                    tabIndex={-1}
                    aria-hidden="true"
                >
                    <Artwork
                        seed={playlist.artwork_seed}
                        url={playlist.cover_url}
                        label={playlist.name}
                    />
                </Link>
                <button
                    className="card-play"
                    onClick={onPlay}
                    disabled={pending || !playlist.tracks_count}
                    aria-label={`پخش ${playlist.name}`}
                >
                    {pending ? (
                        <LoaderCircle size={23} className="spin" />
                    ) : (
                        <Play size={23} fill="currentColor" />
                    )}
                </button>
            </div>
            <div className="card-caption">
                <Link to={`/playlists/${playlist.id}`}>
                    <h3 dir="auto">{playlist.name}</h3>
                </Link>
                <span>
                    {moodLabels[playlist.mood]} <span className="dot">·</span>{" "}
                    {digits(playlist.tracks_count)} آهنگ
                </span>
            </div>
        </article>
    );
}
export function TrackList({
    tracks,
    seed = 0,
}: {
    tracks: Track[];
    seed?: number;
}) {
    const player = usePlayer();
    return (
        <div className="track-list" role="table" aria-label="فهرست آهنگ‌ها">
            <div className="track-row track-heading" role="row">
                <span role="columnheader">#</span>
                <span role="columnheader">آهنگ / هنرمند</span>
                <span className="track-album" role="columnheader">
                    آلبوم
                </span>
                <span role="columnheader" className="visually-hidden">
                    پسندیدن
                </span>
                <span role="columnheader" aria-label="مدت">
                    <Clock3 size={15} />
                </span>
            </div>
            {tracks.map((track, index) => {
                const active = track.id === player.current?.id;
                return (
                    <div
                        className={`track-row ${active ? "track-active" : ""}`}
                        role="row"
                        key={track.id}
                    >
                        <div role="cell">
                            <button
                                className="track-play"
                                aria-label={`${active && player.playing ? "توقف" : "پخش"} ${track.title}`}
                                onClick={() =>
                                    active
                                        ? player.toggle()
                                        : player.play(
                                              tracks.map((t) => ({
                                                  ...t,
                                                  artwork_seed: seed,
                                              })),
                                              index,
                                          )
                                }
                            >
                                <span className="row-number">
                                    {active && player.playing ? (
                                        <span className="equalizer">
                                            <i />
                                            <i />
                                            <i />
                                        </span>
                                    ) : (
                                        digits(index + 1)
                                    )}
                                </span>
                                <span className="row-play-icon">
                                    {active && player.playing ? (
                                        <Pause size={16} />
                                    ) : (
                                        <Play size={16} fill="currentColor" />
                                    )}
                                </span>
                            </button>
                        </div>
                        <div role="cell" className="track-title">
                            <span dir="auto">{track.title}</span>
                            <small dir="auto">{track.artist}</small>
                        </div>
                        <span className="track-album" role="cell" dir="auto">
                            {track.album || "تک‌آهنگ"}
                        </span>
                        <div role="cell">
                            <LikeButton track={track} />
                        </div>
                        <span role="cell" className="time" dir="ltr">
                            {duration(track.duration_seconds)}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
export function QueueContent() {
    const player = usePlayer();
    return (
        <div className="queue-list">
            {player.order.map((index, orderIndex) => {
                const track = player.queue[index];
                return (
                    <button
                        key={track.id}
                        className={`queue-row ${index === player.index ? "active" : ""}`}
                        onClick={() => player.select(index)}
                    >
                        <span className="queue-index">
                            {index === player.index && player.playing ? (
                                <span className="equalizer">
                                    <i />
                                    <i />
                                    <i />
                                </span>
                            ) : (
                                digits(orderIndex + 1)
                            )}
                        </span>
                        <span>
                            <strong dir="auto">{track.title}</strong>
                            <small dir="auto">{track.artist}</small>
                        </span>
                        <span className="time" dir="ltr">
                            {duration(track.duration_seconds)}
                        </span>
                    </button>
                );
            })}
            {!player.queue.length && (
                <p className="muted">
                    با پخش یک پلی‌لیست، آهنگ‌های بعدی اینجا قرار می‌گیرند.
                </p>
            )}
        </div>
    );
}
export function NowPlaying() {
    const player = usePlayer(),
        current = player.current;
    return (
        <aside className="now-playing">
            <div className="panel-heading">
                <h2>همین حالا</h2>
                <span className="tiny-label" lang="en">
                    ON YOUR FREQUENCY
                </span>
            </div>
            <Artwork
                seed={current?.artwork_seed ?? current?.id ?? 0}
                url={current?.cover_url}
                className={`now-cover ${player.playing ? "is-playing" : ""}`}
            />
            <div className="now-title">
                <div>
                    <h3 dir="auto">{current?.title || "جایی برای صدای تو"}</h3>
                    <p dir="auto">
                        {current?.artist ||
                            "یک آهنگ انتخاب کن و به حال خودت برس."}
                    </p>
                </div>
                {current && <LikeButton track={current} />}
            </div>
            <div className="listening-note">
                <span className="live-dot" />{" "}
                {player.playing
                    ? "در حال پخش از کتابخانهٔ شما"
                    : "به اندازهٔ یک آهنگ، برای خودت وقت بگذار."}
            </div>
            <div className="panel-heading queue-heading">
                <h2>صف پخش</h2>
                <span>{digits(player.queue.length)} آهنگ</span>
            </div>
            <QueueContent />
        </aside>
    );
}
export function PlayerBar() {
    const player = usePlayer(),
        current = player.current;
    const [queueOpen, setQueueOpen] = useState(false),
        [lastVolume, setLastVolume] = useState(0.8);
    return (
        <>
            <footer className="player-bar" aria-label="کنترل پخش">
                <div className="player-track">
                    <Artwork
                        seed={current?.artwork_seed ?? current?.id ?? 0}
                        url={current?.cover_url}
                    />
                    <div>
                        <strong dir="auto">
                            {current?.title || "موسیقی از اینجا شروع می‌شود"}
                        </strong>
                        <small dir="auto">
                            {current?.artist || "پلی‌لیست دلخواهت را انتخاب کن"}
                        </small>
                    </div>
                    {current && <LikeButton track={current} />}
                </div>
                <div className="playback-controls">
                    <div className="transport" dir="ltr">
                        <IconButton
                            label="پخش تصادفی"
                            aria-pressed={player.shuffle}
                            className={player.shuffle ? "enabled" : ""}
                            disabled={!current}
                            onClick={player.toggleShuffle}
                        >
                            <Shuffle size={17} />
                        </IconButton>
                        <IconButton
                            label="آهنگ قبلی"
                            disabled={!current}
                            onClick={player.previous}
                        >
                            <SkipBack size={20} fill="currentColor" />
                        </IconButton>
                        <IconButton
                            label={player.playing ? "توقف پخش" : "پخش موسیقی"}
                            className="main-play"
                            disabled={!current}
                            onClick={player.toggle}
                        >
                            {player.loading ? (
                                <LoaderCircle className="spin" size={20} />
                            ) : player.playing ? (
                                <Pause size={21} fill="currentColor" />
                            ) : (
                                <Play size={21} fill="currentColor" />
                            )}
                        </IconButton>
                        <IconButton
                            label="آهنگ بعدی"
                            disabled={!current}
                            onClick={player.next}
                        >
                            <SkipForward size={20} fill="currentColor" />
                        </IconButton>
                        <IconButton
                            label={`تکرار: ${player.repeat === "off" ? "خاموش" : player.repeat === "all" ? "همه" : "یک آهنگ"}`}
                            aria-pressed={player.repeat !== "off"}
                            className={player.repeat !== "off" ? "enabled" : ""}
                            disabled={!current}
                            onClick={player.cycleRepeat}
                        >
                            {player.repeat === "one" ? (
                                <Repeat1 size={18} />
                            ) : (
                                <Repeat size={18} />
                            )}
                        </IconButton>
                    </div>
                    <div className="timeline" dir="ltr">
                        <span className="time">
                            {duration(player.position)}
                        </span>
                        <input
                            aria-label="موقعیت پخش"
                            type="range"
                            min="0"
                            max={current?.duration_seconds || 1}
                            step="0.1"
                            value={Math.min(
                                player.position,
                                current?.duration_seconds || 0,
                            )}
                            disabled={!current}
                            onChange={(event) =>
                                player.seek(Number(event.target.value))
                            }
                        />
                        <span className="time">
                            {duration(current?.duration_seconds || 0)}
                        </span>
                    </div>
                </div>
                <div className="player-tools">
                    <IconButton
                        label="نمایش صف پخش"
                        onClick={() => setQueueOpen(true)}
                    >
                        <ListMusic size={21} />
                    </IconButton>
                    <span className="tool-divider" />
                    <IconButton
                        label={player.volume ? "بی‌صدا کردن" : "بازگرداندن صدا"}
                        onClick={() => {
                            if (player.volume) {
                                setLastVolume(player.volume);
                                player.setVolume(0);
                            } else player.setVolume(lastVolume || 0.8);
                        }}
                    >
                        {player.volume ? (
                            <Volume2 size={20} />
                        ) : (
                            <VolumeX size={20} />
                        )}
                    </IconButton>
                    <input
                        className="volume-range"
                        type="range"
                        aria-label="بلندی صدا"
                        min="0"
                        max="1"
                        step="0.01"
                        value={player.volume}
                        onChange={(e) =>
                            player.setVolume(Number(e.target.value))
                        }
                    />
                </div>
            </footer>
            {(player.error || player.syncError) && (
                <div className="player-error" role="alert">
                    {player.error || player.syncError}
                    {player.syncError && (
                        <button onClick={() => window.location.reload()}>
                            تازه‌سازی
                        </button>
                    )}
                </div>
            )}
            <Dialog
                open={queueOpen}
                onOpenChange={setQueueOpen}
                title="صف پخش شما"
                description="آهنگ‌های این نوبت، به ترتیب پخش"
            >
                <QueueContent />
            </Dialog>
        </>
    );
}
