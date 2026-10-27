import { useDeferredValue, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { adminApi } from "./api";
import type { Artist } from "./contracts";
import type { Track } from "../../types";
export function ArtistPicker({ choose }: { choose: (artist: Artist) => void }) {
    const [q, setQ] = useState("");
    const term = useDeferredValue(q);
    const query = useQuery({
        queryKey: ["admin", "artist-picker", term],
        queryFn: () => adminApi.artists(term),
    });
    return (
        <div className="catalog-picker">
            <label>
                جستجوی هنرمند
                <input
                    value={q}
                    maxLength={120}
                    placeholder="نام هنرمند…"
                    onChange={(e) => setQ(e.target.value)}
                />
            </label>
            <div className="picker-results" aria-label="هنرمندان قابل انتخاب">
                {query.isPending ? (
                    <p role="status">در حال جستجو…</p>
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
                                <small>شناسهٔ {artist.id}</small>
                            </span>
                            <Plus size={16} />
                        </button>
                    ))
                )}
                {query.data && !query.data.data.length && (
                    <p>هنرمندی پیدا نشد؛ از بخش هنرمندان یک مورد تازه بساز.</p>
                )}
            </div>
            <small className="muted">
                حداکثر ۵۰ نتیجه؛ برای نام‌های مشابه، شناسه و آثار را بررسی کن.
            </small>
        </div>
    );
}
export function TrackPicker({ choose }: { choose: (track: Track) => void }) {
    const [q, setQ] = useState("");
    const term = useDeferredValue(q);
    const query = useQuery({
        queryKey: ["admin", "track-picker", term],
        queryFn: () => adminApi.tracks(term),
    });
    return (
        <div className="catalog-picker">
            <label>
                افزودن آهنگ از کاتالوگ
                <input
                    value={q}
                    maxLength={120}
                    placeholder="عنوان یا هنرمند…"
                    onChange={(e) => setQ(e.target.value)}
                />
            </label>
            <div className="picker-results" aria-label="آهنگ‌های قابل انتخاب">
                {query.isPending ? (
                    <p role="status">در حال جستجو…</p>
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
                                    {track.artist} · شناسهٔ {track.id}
                                </small>
                            </span>
                            <Plus size={16} />
                        </button>
                    ))
                )}
                {query.data && !query.data.data.length && (
                    <p>آهنگی پیدا نشد؛ ابتدا فایل‌ها را وارد کن.</p>
                )}
            </div>
            <small className="muted">
                حداکثر ۵۰ نتیجه؛ جستجو را دقیق‌تر کن.
            </small>
        </div>
    );
}
