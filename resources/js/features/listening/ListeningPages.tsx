import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
    Download,
    History,
    Play,
    ShieldCheck,
    Sparkles,
    Trash2,
} from "lucide-react";
import { useSession, useToast } from "../../context/AppContext";
import { usePlayer } from "../../context/PlayerContext";
import {
    Artwork,
    Dialog,
    EmptyState,
    ErrorState,
    IconButton,
    Loading,
} from "../../components/UI";
import { api } from "../../lib/api";
import { date, number, tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import {
    duration,
    moodLabels,
    type Mood,
    type Page,
    type Track,
} from "../../types";
import { useListening } from "./ListeningProvider";
type HistoryEvent = {
    id: string;
    track: Track;
    started_at: string;
    ended_at: string | null;
    listened_seconds: number;
    outcome: "started" | "completed" | "skip";
};
type Reason = "curated" | "mood" | "liked" | "artist" | "history" | "recent";
type Mix = {
    basis: "disabled" | "curated" | "personalized";
    data: {
        track: Track;
        score: number;
        reasons: { code: Reason; weight: number }[];
    }[];
};
export function PrivateListeningToggle() {
    useLocale();
    const listening = useListening();
    return (
        <IconButton
            label={tr(
                listening.settings?.private_mode
                    ? "listening.privateEnd"
                    : "listening.privateStart",
            )}
            aria-pressed={!!listening.settings?.private_mode}
            className={
                listening.settings?.private_mode
                    ? "enabled privacy-toggle"
                    : "privacy-toggle"
            }
            disabled={listening.pending || !listening.settings}
            onClick={() =>
                listening.change({
                    private_session: !listening.settings?.private_mode,
                })
            }
        >
            <ShieldCheck size={20} />
        </IconButton>
    );
}
function ListeningControls() {
    const listening = useListening();
    if (listening.error)
        return <ErrorState error={listening.error} retry={listening.refresh} />;
    if (!listening.settings) return <Loading />;
    const settings = listening.settings;
    return (
        <section
            className="listening-controls"
            aria-label={tr("listening.preferences")}
        >
            <h2>{tr("listening.preferences")}</h2>
            <p className="muted">{tr("listening.consentHelp")}</p>
            <label className="checkbox-label">
                <input
                    type="checkbox"
                    name="listening_history"
                    checked={settings.history_enabled}
                    disabled={listening.pending}
                    onChange={(event) =>
                        listening.change({
                            history_enabled: event.target.checked,
                        })
                    }
                />
                {tr("listening.consent")}
            </label>
            <label className="checkbox-label">
                <input
                    type="checkbox"
                    name="listening_recommendations"
                    checked={settings.recommendations_enabled}
                    disabled={listening.pending}
                    onChange={(event) =>
                        listening.change({
                            recommendations_enabled: event.target.checked,
                        })
                    }
                />
                {tr("listening.recommendations")}
            </label>
            <label>
                {tr("listening.retention")}
                <select
                    name="listening_retention"
                    value={settings.retention_days}
                    disabled={listening.pending}
                    onChange={(event) =>
                        listening.change({
                            retention_days: Number(event.target.value) as
                                7 | 30 | 90,
                        })
                    }
                >
                    {[7, 30, 90].map((days) => (
                        <option key={days} value={days}>
                            {tr("listening.days", { count: days })}
                        </option>
                    ))}
                </select>
            </label>
            <div className="listening-private">
                <ShieldCheck size={22} aria-hidden="true" />
                <div>
                    <strong>
                        {tr(
                            settings.private_mode
                                ? "listening.privateActive"
                                : "listening.privateInactive",
                        )}
                    </strong>
                    <p className="muted">{tr("listening.privateHelp")}</p>
                    {settings.private_mode && settings.private_until && (
                        <p className="muted">
                            {tr("listening.until", {
                                time: date(settings.private_until, {
                                    dateStyle: "medium",
                                    timeStyle: "short",
                                }),
                            })}
                        </p>
                    )}
                </div>
                <button
                    className="button secondary"
                    disabled={listening.pending}
                    onClick={() =>
                        listening.change({
                            private_session: !settings.private_mode,
                        })
                    }
                >
                    {tr(
                        settings.private_mode
                            ? "listening.privateEnd"
                            : "listening.privateStart",
                    )}
                </button>
            </div>
        </section>
    );
}
export function HistoryPage() {
    useLocale();
    const { user } = useSession(),
        listening = useListening(),
        player = usePlayer(),
        notify = useToast();
    const [page, setPage] = useState(1),
        [eraseId, setEraseId] = useState<string | null>(null),
        [eraseAll, setEraseAll] = useState(false),
        [exporting, setExporting] = useState(false);
    useEffect(() => setPage(1), [listening.settings?.revision]);
    const history = useQuery({
        queryKey: [
            "listening-history",
            user!.id,
            listening.settings?.revision,
            page,
        ],
        enabled: !!listening.settings,
        queryFn: ({ signal }) =>
            api<Page<HistoryEvent>>(`/listening/history?page=${page}`, {
                signal,
            }),
    });
    const exportHistory = async () => {
        setExporting(true);
        try {
            const data = await api("/listening/export");
            const url = URL.createObjectURL(
                new Blob([JSON.stringify(data, null, 2)], {
                    type: "application/json",
                }),
            );
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = "ahang-listening-history.json";
            anchor.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) {
            notify((error as Error).message, true);
        } finally {
            setExporting(false);
        }
    };
    return (
        <div className="page-enter listening-page">
            <div className="page-intro">
                <div>
                    <span className="eyebrow">
                        {tr("listening.historyEyebrow")}
                    </span>
                    <h1>{tr("listening.history")}</h1>
                    <p>{tr("listening.historyHelp")}</p>
                </div>
                <History size={28} aria-hidden="true" />
            </div>
            <ListeningControls />
            <div className="listening-actions">
                <button
                    className="button secondary"
                    disabled={exporting || listening.pending}
                    onClick={() => void exportHistory()}
                >
                    <Download size={17} />
                    {tr("listening.export")}
                </button>
                <button
                    className="button danger"
                    disabled={listening.pending || !history.data?.data.length}
                    onClick={() => setEraseAll(true)}
                >
                    <Trash2 size={17} />
                    {tr("listening.eraseAll")}
                </button>
            </div>
            {history.isPending ? (
                <Loading />
            ) : history.error ? (
                <ErrorState
                    error={history.error}
                    retry={() => void history.refetch()}
                />
            ) : !history.data.data.length ? (
                <EmptyState
                    title={tr("listening.empty")}
                    text={tr("listening.emptyHelp")}
                />
            ) : (
                <ol className="listening-list">
                    {history.data.data.map((event) => (
                        <li key={event.id} className="listening-row">
                            <Artwork seed={event.track.id} />
                            <div className="listening-track">
                                <strong dir="auto">{event.track.title}</strong>
                                <p dir="auto">{event.track.artist}</p>
                                <small>
                                    {date(event.started_at, {
                                        dateStyle: "medium",
                                        timeStyle: "short",
                                    })}{" "}
                                    · {tr(`listening.outcome.${event.outcome}`)}{" "}
                                    ·{" "}
                                    <bdi dir="ltr">
                                        {duration(event.listened_seconds)}
                                    </bdi>
                                </small>
                            </div>
                            <IconButton
                                label={tr("listening.playTrack", {
                                    title: event.track.title,
                                })}
                                onClick={() => player.play([event.track])}
                            >
                                <Play size={18} />
                            </IconButton>
                            <IconButton
                                label={tr("listening.eraseTrack", {
                                    title: event.track.title,
                                })}
                                onClick={() => setEraseId(event.id)}
                            >
                                <Trash2 size={18} />
                            </IconButton>
                        </li>
                    ))}
                </ol>
            )}
            {history.data && history.data.meta.last_page > 1 && (
                <div className="pager">
                    <button
                        className="button secondary"
                        disabled={page <= 1}
                        onClick={() => setPage(page - 1)}
                    >
                        {tr("listening.previous")}
                    </button>
                    <span>{number(page)}</span>
                    <button
                        className="button secondary"
                        disabled={page >= history.data.meta.last_page}
                        onClick={() => setPage(page + 1)}
                    >
                        {tr("listening.next")}
                    </button>
                </div>
            )}
            <Dialog
                open={eraseAll || !!eraseId}
                onOpenChange={() => {
                    setEraseId(null);
                    setEraseAll(false);
                }}
                guard={false}
                title={tr("listening.eraseConfirm")}
                description={tr("listening.eraseHelp")}
            >
                <button
                    className="button danger"
                    disabled={listening.pending}
                    onClick={() => {
                        listening.erase(eraseId ?? undefined);
                        setEraseAll(false);
                        setEraseId(null);
                    }}
                >
                    {tr("listening.eraseNow")}
                </button>
            </Dialog>
        </div>
    );
}
export function MixPage() {
    useLocale();
    const { user } = useSession(),
        listening = useListening(),
        player = usePlayer();
    const [mood, setMood] = useState<Mood | "">("");
    const mix = useQuery({
        queryKey: [
            "listening-mix",
            user!.id,
            listening.settings?.revision,
            listening.settings?.private_mode,
            mood,
        ],
        enabled: !!listening.settings,
        queryFn: ({ signal }) =>
            api<Mix>(`/listening/mix${mood ? `?mood=${mood}` : ""}`, {
                signal,
            }),
        staleTime: 0,
    });
    const tracks = mix.data?.data.map((item) => item.track) ?? [];
    return (
        <div className="page-enter listening-page">
            <div className="page-intro">
                <div>
                    <span className="eyebrow">
                        {tr("listening.mixEyebrow")}
                    </span>
                    <h1>{tr("listening.mix")}</h1>
                    <p>{tr("listening.mixHelp")}</p>
                </div>
                <Sparkles size={28} aria-hidden="true" />
            </div>
            <div className="filter-chips" aria-label={tr("listening.mood")}>
                <button
                    className={!mood ? "selected" : ""}
                    aria-pressed={!mood}
                    onClick={() => setMood("")}
                >
                    {tr("listening.allMoods")}
                </button>
                {Object.entries(moodLabels).map(([key, label]) => (
                    <button
                        key={key}
                        className={mood === key ? "selected" : ""}
                        aria-pressed={mood === key}
                        onClick={() => setMood(key as Mood)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {listening.error ? (
                <ErrorState error={listening.error} retry={listening.refresh} />
            ) : mix.isPending ? (
                <Loading />
            ) : mix.error ? (
                <ErrorState
                    error={mix.error}
                    retry={() => void mix.refetch()}
                />
            ) : (
                <>
                    <p className="muted">
                        {tr(`listening.basis.${mix.data.basis}`)}
                    </p>
                    <div className="listening-actions">
                        <button
                            className="button"
                            disabled={!tracks.length}
                            onClick={() => player.play(tracks)}
                        >
                            <Play size={18} />
                            {tr("listening.playMix")}
                        </button>
                    </div>
                    {!tracks.length ? (
                        <EmptyState
                            title={tr("listening.noMix")}
                            text={tr("listening.noMixHelp")}
                        />
                    ) : (
                        <ol className="listening-list">
                            {mix.data.data.map((item) => (
                                <li
                                    key={item.track.id}
                                    className="listening-row"
                                >
                                    <Artwork seed={item.track.id} />
                                    <div className="listening-track">
                                        <strong dir="auto">
                                            {item.track.title}
                                        </strong>
                                        <p dir="auto">{item.track.artist}</p>
                                        <details>
                                            <summary>
                                                {tr("listening.why")}
                                            </summary>
                                            <ul>
                                                {item.reasons.map((reason) => (
                                                    <li key={reason.code}>
                                                        {tr(
                                                            `listening.reason.${reason.code}`,
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        </details>
                                    </div>
                                    <IconButton
                                        label={tr("listening.playTrack", {
                                            title: item.track.title,
                                        })}
                                        onClick={() =>
                                            player.play(
                                                tracks,
                                                tracks.findIndex(
                                                    (track) =>
                                                        track.id ===
                                                        item.track.id,
                                                ),
                                            )
                                        }
                                    >
                                        <Play size={18} />
                                    </IconButton>
                                </li>
                            ))}
                        </ol>
                    )}
                </>
            )}
        </div>
    );
}
