import { localizeText } from "../i18n/runtime";
import { currentPreferences } from "../i18n/runtime";
import { tr } from "../i18n/runtime";
import { useLocale } from "../i18n/LocaleProvider";
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
    ListPlus,
    ArrowUp,
    ArrowDown,
    X,
    Timer,
    AudioLines,
    Maximize2,
} from "lucide-react";
import { Link } from "react-router-dom";
import { sleepPresets } from "../lib/sleep-timer";
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
import { ShaderBackdrop } from "./effects/ShaderBackdrop";
import { LyricsPanel } from "../features/lyrics/LyricsPanel";

export function LikeButton({ track }: { track: Track }) {
    useLocale();
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
            label={track.is_liked ? tr("mb9d4f6c42b") : tr("m4bf6c9ac88")}
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
    useLocale();
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
            notify(playlist.is_saved ? tr("m2ab4a384c1") : tr("m50fcb56388"));
        },
        onError: (error) => notify(error.message, true),
    });
    return (
        <IconButton
            label={playlist.is_saved ? tr("m12686f2fc5") : tr("m7778bb5bce")}
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
    useLocale();
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
                    aria-label={tr("m84995b84c0", { v0: playlist.name })}
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
                    {moodLabels[playlist.mood]} <span className="dot">·</span>
                    {tr("m607a37d687", { v0: digits(playlist.tracks_count) })}
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
    useLocale();
    const player = usePlayer();
    return (
        <div className="track-list" role="table" aria-label={tr("m68e0c3bfdc")}>
            <div className="track-row track-heading" role="row">
                <span role="columnheader">#</span>
                <span role="columnheader">{tr("m9294a0da00")}</span>
                <span className="track-album" role="columnheader">
                    {tr("m7cd289235e")}
                </span>
                <span role="columnheader" className="visually-hidden">
                    {tr("m42f7fc0276")}
                </span>
                <span role="columnheader" aria-label={tr("mc46aff0539")}>
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
                                aria-label={`${active && player.playing ? tr("mbfc3318bde") : tr("md83169b1f4")} ${track.title}`}
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
                            {track.album || tr("mb25f61389c")}
                        </span>
                        <div role="cell" className="track-actions">
                            <IconButton
                                label={tr("m75e2e05181", { v0: track.title })}
                                disabled={
                                    player.queue.length >= 500 &&
                                    !player.queue.some((t) => t.id === track.id)
                                }
                                onClick={() => player.enqueueNext(track)}
                            >
                                <ListPlus size={17} />
                            </IconButton>
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
    useLocale();
    const player = usePlayer();
    return (
        <div className="queue-list">
            {player.queue.length > 0 && (
                <div className="queue-actions">
                    <button
                        className="text-link"
                        onClick={player.clearUpcoming}
                    >
                        {tr("m7443ac7c50")}
                    </button>
                    <button className="text-link" onClick={player.clearQueue}>
                        {tr("ma3939f0f38")}
                    </button>
                </div>
            )}
            {player.order.map((index, orderIndex) => {
                const track = player.queue[index];
                return (
                    <div
                        key={track.id}
                        className={`queue-row ${index === player.index ? "active" : ""}`}
                    >
                        <button
                            className="queue-track"
                            aria-label={tr("mbde261fc68", { v0: track.title })}
                            onClick={() => player.select(index)}
                        >
                            <span className="queue-index">
                                {digits(orderIndex + 1)}
                            </span>
                            <span>
                                <strong dir="auto">{track.title}</strong>
                                <small dir="auto">{track.artist}</small>
                            </span>
                            <span className="time" dir="ltr">
                                {duration(track.duration_seconds)}
                            </span>
                        </button>
                        <div className="queue-edit-actions">
                            <IconButton
                                label={tr("mc3b47d4bab", { v0: track.title })}
                                disabled={orderIndex === 0}
                                onClick={() => player.moveQueued(track.id, -1)}
                            >
                                <ArrowUp size={15} />
                            </IconButton>
                            <IconButton
                                label={tr("m1160d527f0", { v0: track.title })}
                                disabled={
                                    orderIndex === player.order.length - 1
                                }
                                onClick={() => player.moveQueued(track.id, 1)}
                            >
                                <ArrowDown size={15} />
                            </IconButton>
                            <IconButton
                                label={tr("mb27eca428d", { v0: track.title })}
                                onClick={() => player.removeQueued(track.id)}
                            >
                                <X size={15} />
                            </IconButton>
                        </div>
                    </div>
                );
            })}
            {!player.queue.length && (
                <p className="muted">{tr("me54b440950")}</p>
            )}
        </div>
    );
}
export function SleepTimerControl() {
    useLocale();
    const player = usePlayer(),
        [open, setOpen] = useState(false);
    const timer = player.sleepTimer;
    return (
        <>
            <IconButton
                label={tr("mb4cbcfc206")}
                aria-pressed={!!timer}
                onClick={() => setOpen(true)}
            >
                <Timer size={20} />
            </IconButton>
            <Dialog
                open={open}
                onOpenChange={setOpen}
                title={tr("mb4cbcfc206")}
                description={tr("m429f7486e2")}
            >
                <p role="status">
                    {timer?.kind === "end_of_track"
                        ? tr("mcf64af366a")
                        : timer?.kind === "deadline"
                          ? tr("mdff08489aa", {
                                v0: new Intl.DateTimeFormat(
                                    currentPreferences().locale,
                                    {
                                        timeZone:
                                            currentPreferences().time_zone,
                                        hour: "2-digit",
                                        minute: "2-digit",
                                    },
                                ).format(timer.deadline),
                            })
                          : tr("m78e6f9877a")}
                </p>
                <div className="sleep-options">
                    {sleepPresets.map((minutes) => (
                        <button
                            className="button secondary"
                            key={minutes}
                            onClick={() => {
                                player.setSleep(minutes);
                                setOpen(false);
                            }}
                        >
                            {tr("m49722e8908", { v0: digits(minutes) })}
                        </button>
                    ))}
                </div>
                <button
                    className="button"
                    onClick={() => {
                        player.setSleep("end_of_track");
                        setOpen(false);
                    }}
                >
                    {tr("mac6d498672")}
                </button>
                {timer && (
                    <button
                        className="text-link"
                        onClick={() => {
                            player.setSleep(null);
                            setOpen(false);
                        }}
                    >
                        {tr("m072fcab9ab")}
                    </button>
                )}
            </Dialog>
        </>
    );
}
export function NowPlaying() {
    useLocale();
    const player = usePlayer(),
        current = player.current;
    return (
        <aside className="now-playing">
            <div className="panel-heading">
                <h2>{tr("m2f6b22ecce")}</h2>
                <span className="tiny-label">{tr("editorial.frequency")}</span>
            </div>
            <Artwork
                seed={current?.artwork_seed ?? current?.id ?? 0}
                url={current?.cover_url}
                className={`now-cover ${player.playing ? "is-playing" : ""}`}
            />
            <div className="now-title">
                <div>
                    <h3 dir="auto">{current?.title || tr("m605b5cc99a")}</h3>
                    <p dir="auto">{current?.artist || tr("m1a6bc1bd66")}</p>
                </div>
                {current && <LikeButton track={current} />}
            </div>
            <div className="listening-note">
                <span className="live-dot" />{" "}
                {player.playing ? tr("mbf317fc15a") : tr("m1e4c6f7ddc")}
            </div>
            <div className="panel-heading queue-heading">
                <h2>{tr("mf2d7a34efa")}</h2>
                <span>
                    {tr("m0816e22877", { v0: digits(player.queue.length) })}
                </span>
            </div>
            <QueueContent />
        </aside>
    );
}
export function PlayerBar() {
    useLocale();
    const player = usePlayer(),
        current = player.current;
    const [qualityOpen, setQualityOpen] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const hasQualityChoice = (current?.audio_qualities?.length ?? 0) > 1;
    const [queueOpen, setQueueOpen] = useState(false),
        [lastVolume, setLastVolume] = useState(0.8);
    return (
        <>
            <footer
                className={`player-bar ${hasQualityChoice ? "has-quality" : ""}`}
                aria-label={tr("m66becb6623")}
            >
                <div className="player-track">
                    <button
                        className="player-artwork"
                        aria-label={tr("player.expand")}
                        disabled={!current}
                        onClick={() => setExpanded(true)}
                    >
                        <Artwork
                            seed={current?.artwork_seed ?? current?.id ?? 0}
                            url={current?.cover_url}
                        />
                        <Maximize2 size={17} aria-hidden="true" />
                    </button>
                    <div>
                        <strong dir="auto">
                            {current?.title || tr("m9b3b1e10e7")}
                        </strong>
                        <small dir="auto">
                            {current?.artist || tr("m70fd5f26c1")}
                        </small>
                    </div>
                    {current && <LikeButton track={current} />}
                </div>
                <div className="playback-controls">
                    <div className="transport" dir="ltr">
                        <IconButton
                            label={tr("mb0b9a44dbf")}
                            aria-pressed={player.shuffle}
                            className={player.shuffle ? "enabled" : ""}
                            disabled={!current}
                            onClick={player.toggleShuffle}
                        >
                            <Shuffle size={17} />
                        </IconButton>
                        <IconButton
                            label={tr("m4241fd2fd4")}
                            disabled={!current}
                            onClick={player.previous}
                        >
                            <SkipBack size={20} fill="currentColor" />
                        </IconButton>
                        <IconButton
                            label={
                                player.playing
                                    ? tr("m66703b010c")
                                    : tr("m2260125ca7")
                            }
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
                            label={tr("m7eb2f064d0")}
                            disabled={!current}
                            onClick={player.next}
                        >
                            <SkipForward size={20} fill="currentColor" />
                        </IconButton>
                        <IconButton
                            label={tr("mba1305367f", {
                                v0:
                                    player.repeat === "off"
                                        ? tr("mb2e61b5037")
                                        : player.repeat === "all"
                                          ? tr("mbf742c5a3d")
                                          : tr("m5d2bd957be"),
                            })}
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
                            dir="ltr"
                            onKeyDown={(event) => {
                                if (
                                    event.key !== "ArrowRight" &&
                                    event.key !== "ArrowLeft"
                                )
                                    return;
                                event.preventDefault();
                                player.seek(
                                    player.position +
                                        (event.key === "ArrowRight" ? 5 : -5),
                                );
                            }}
                            aria-label={tr("m6546753d1d")}
                            type="range"
                            aria-valuetext={`${duration(player.position)} / ${duration(current?.duration_seconds || 0)}`}
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
                    {hasQualityChoice && (
                        <IconButton
                            label={tr("m024a3b906d")}
                            onClick={() => setQualityOpen(true)}
                        >
                            <AudioLines size={20} />
                        </IconButton>
                    )}
                    <SleepTimerControl />
                    <IconButton
                        label={tr("m8d3e2fbcb3")}
                        onClick={() => setQueueOpen(true)}
                    >
                        <ListMusic size={21} />
                    </IconButton>
                    <span className="tool-divider" />
                    <IconButton
                        label={
                            player.volume
                                ? tr("m474ca157e4")
                                : tr("m3cd691d675")
                        }
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
                        name="volume"
                        className="volume-range"
                        dir="ltr"
                        type="range"
                        aria-label={tr("mcf0e93d2f1")}
                        aria-valuetext={`${digits(Math.round(player.volume * 100))}%`}
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
            <Dialog
                open={qualityOpen}
                onOpenChange={setQualityOpen}
                title={tr("maf59da77db")}
                description={tr("mc8fa61f66d")}
            >
                <label className="quality-picker">
                    {tr("maf59da77db")}
                    <select
                        dir="auto"
                        name="quality"
                        aria-label={tr("maf59da77db")}
                        value={player.quality}
                        onChange={(event) =>
                            player.setQuality(
                                event.target
                                    .value as import("../types").AudioQuality,
                            )
                        }
                    >
                        <option value="auto">{tr("mdf0e52ea91")}</option>
                        {current?.audio_qualities?.map((option) => (
                            <option key={option.id} value={option.id}>
                                {tr(
                                    option.id === "original"
                                        ? "quality.original"
                                        : "quality.mp3",
                                )}
                            </option>
                        ))}
                    </select>
                </label>
                <p>{tr("m396d33ce21")}</p>
            </Dialog>
            <Dialog
                open={expanded}
                onOpenChange={setExpanded}
                guard={false}
                className="dialog-now-playing"
                title={tr("m2f6b22ecce")}
                description={tr("player.expandedDescription")}
            >
                <div className="now-stage">
                    <ShaderBackdrop surface="expanded-now-playing" />
                    <div className="now-stage-content">
                        <Artwork
                            seed={current?.artwork_seed ?? current?.id ?? 0}
                            url={current?.cover_url}
                        />
                        <div className="now-stage-meta">
                            <h3 dir="auto">
                                {current?.title || tr("m605b5cc99a")}
                            </h3>
                            <p dir="auto">
                                {current?.artist || tr("m1a6bc1bd66")}
                            </p>
                            <div className="transport" dir="ltr">
                                <IconButton
                                    label={tr("m4241fd2fd4")}
                                    disabled={!current}
                                    onClick={player.previous}
                                >
                                    <SkipBack size={20} />
                                </IconButton>
                                <IconButton
                                    label={
                                        player.playing
                                            ? tr("m66703b010c")
                                            : tr("m2260125ca7")
                                    }
                                    disabled={!current}
                                    className="main-play"
                                    onClick={player.toggle}
                                >
                                    {player.loading ? (
                                        <LoaderCircle
                                            className="spin"
                                            size={22}
                                        />
                                    ) : player.playing ? (
                                        <Pause size={22} />
                                    ) : (
                                        <Play size={22} />
                                    )}
                                </IconButton>
                                <IconButton
                                    label={tr("m7eb2f064d0")}
                                    disabled={!current}
                                    onClick={player.next}
                                >
                                    <SkipForward size={20} />
                                </IconButton>
                            </div>
                        </div>
                    </div>
                    {current ? <LyricsPanel trackId={current.id} /> : null}
                </div>
            </Dialog>
            {(player.error || player.syncError) && (
                <div className="player-error" role="alert">
                    {localizeText(player.error || player.syncError || "")}
                    {player.syncError && (
                        <button onClick={() => window.location.reload()}>
                            {tr("mb98662b701")}
                        </button>
                    )}
                </div>
            )}
            <Dialog
                open={queueOpen}
                onOpenChange={setQueueOpen}
                title={tr("m10b59aa75d")}
                description={tr("md8d0a792f9")}
            >
                <QueueContent />
            </Dialog>
        </>
    );
}
