import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useEffect, useMemo, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { api } from "../../lib/api";
import { activeLyricIndex, parseLrc, type LyricLine } from "../../lib/lrc";
import { usePlayer } from "../../context/PlayerContext";
import { ErrorState, Loading } from "../../components/UI";

type LyricPayload = {
    format: "plain" | "lrc";
    source: "manual" | "licensed";
    license_note: string | null;
    body: string;
    lines?: LyricLine[] | null;
};

export function LyricsPanel({ trackId }: { trackId: number }) {
    useLocale();
    const player = usePlayer();
    const reduced =
        typeof window !== "undefined" &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const [paused, setPaused] = useState(false);
    const activeRef = useRef<HTMLLIElement | null>(null);
    const query = useQuery({
        queryKey: ["lyrics", trackId],
        queryFn: () =>
            api<{ data: LyricPayload | null }>(`/tracks/${trackId}/lyrics`),
    });
    const lines = useMemo(() => {
        const data = query.data?.data;
        if (!data) return [] as LyricLine[];
        if (data.format === "lrc") {
            if (data.lines?.length) return data.lines;
            try {
                return parseLrc(
                    data.body,
                    player.current?.duration_seconds ?? null,
                ).lines;
            } catch {
                return [];
            }
        }
        return data.body
            .split(/\n+/)
            .map((text) => text.trim())
            .filter(Boolean)
            .map((text) => ({ time: 0, text }));
    }, [query.data, player.current?.duration_seconds]);
    const active =
        query.data?.data?.format === "lrc"
            ? activeLyricIndex(lines, player.position)
            : -1;
    useEffect(() => {
        if (paused || reduced || active < 0) return;
        activeRef.current?.scrollIntoView({
            block: "center",
            behavior: reduced ? "auto" : "smooth",
        });
    }, [active, paused, reduced]);
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    if (!query.data?.data) return <p className="muted">{tr("lyrics.empty")}</p>;
    const data = query.data.data;
    return (
        <section className="lyrics-panel" aria-label={tr("lyrics.title")}>
            <header className="lyrics-toolbar">
                <div>
                    <h3>{tr("lyrics.title")}</h3>
                    <p className="muted">
                        {data.source === "licensed"
                            ? tr("lyrics.licensed")
                            : tr("lyrics.manual")}
                        {data.license_note ? ` · ${data.license_note}` : ""}
                    </p>
                </div>
                {data.format === "lrc" && (
                    <button
                        className="button ghost"
                        aria-pressed={paused}
                        onClick={() => setPaused((value) => !value)}
                    >
                        {paused
                            ? tr("lyrics.resumeScroll")
                            : tr("lyrics.pauseScroll")}
                    </button>
                )}
            </header>
            <ul className="lyrics-lines">
                {lines.map((line, index) => (
                    <li
                        key={`${line.time}-${index}`}
                        ref={index === active ? activeRef : undefined}
                        className={index === active ? "active" : undefined}
                        dir="auto"
                    >
                        {line.text}
                    </li>
                ))}
            </ul>
        </section>
    );
}
