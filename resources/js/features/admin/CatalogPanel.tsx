import { useDeferredValue, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Pencil, X } from "lucide-react";
import { Dialog, ErrorState, Loading, IconButton } from "../../components/UI";
import { api, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import { digits, type Track, type Page } from "../../types";
import type { Artist, Release } from "./contracts";
import { adminApi, adminKeys } from "./api";
import { TrashButton } from "./TrashPanel";
import { ArtistPicker, TrackPicker } from "./Pickers";
const releaseTypes = {
    album: "آلبوم",
    single: "تک‌آهنگ",
    ep: "EP",
    compilation: "گردآوری",
};
export function CatalogPanel() {
    const [kind, setKind] = useState("artists"),
        [q, setQ] = useState(""),
        [page, setPage] = useState(1),
        [editor, setEditor] = useState<Artist | Release | Track | "new" | null>(
            null,
        );
    const term = useDeferredValue(q);
    const query = useQuery<Page<Artist | Release | Track>>({
        queryKey: adminKeys.catalog(kind, term, page),
        queryFn: () =>
            kind === "artists"
                ? adminApi.artists(term, page)
                : kind === "releases"
                  ? adminApi.releases(term, page)
                  : adminApi.tracks(term, page),
    });
    return (
        <>
            <div className="filter-chips" aria-label="نوع موجودیت">
                {[
                    ["artists", "هنرمندان"],
                    ["releases", "انتشارها"],
                    ["tracks", "آهنگ‌ها"],
                ].map(([value, label]) => (
                    <button
                        key={value}
                        aria-pressed={kind === value}
                        className={kind === value ? "selected" : ""}
                        onClick={() => {
                            setKind(value);
                            setQ("");
                            setPage(1);
                        }}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <div className="admin-toolbar">
                <label>
                    جستجو در کاتالوگ
                    <input
                        maxLength={120}
                        placeholder="نام یا عنوان…"
                        value={q}
                        onChange={(e) => {
                            setQ(e.target.value);
                            setPage(1);
                        }}
                    />
                </label>
                {kind !== "tracks" && (
                    <button className="button" onClick={() => setEditor("new")}>
                        <Plus size={16} />
                        {kind === "artists" ? "هنرمند تازه" : "انتشار تازه"}
                    </button>
                )}
            </div>
            <p className="muted admin-footnote">
                نام، شناسهٔ هویت نیست. هنرمندان هم‌نام جدا می‌مانند؛ رابطهٔ
                آهنگ‌ها را آگاهانه انتخاب کن. موارد بازیابی‌شده از متن ایمپورت
                نیاز به بازبینی دارند.
            </p>
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : (
                <>
                    <div className="catalog-list">
                        {query.data.data.map((item) => (
                            <article key={item.id}>
                                <span className="catalog-id" dir="ltr">
                                    #{item.id}
                                </span>
                                <div>
                                    <strong dir="auto">
                                        {"name" in item
                                            ? item.name
                                            : item.title}
                                    </strong>
                                    <small>
                                        {"artist" in item
                                            ? item.artist
                                            : "type" in item
                                              ? `${releaseTypes[item.type]} · ${item.artists.map((a) => a.name).join("، ")}`
                                              : `${digits(item.tracks_count || 0)} اعتبار هنری${item.needs_review ? " · نیاز به بازبینی هویت" : ""}`}
                                    </small>
                                </div>
                                <IconButton
                                    label={`ویرایش ${"name" in item ? item.name : item.title}`}
                                    onClick={() => setEditor(item)}
                                >
                                    <Pencil size={17} />
                                </IconButton>
                                {"artist" in item && (
                                    <TrashButton
                                        kind="track"
                                        id={item.id}
                                        title={item.title}
                                        revision={item.revision}
                                    />
                                )}
                            </article>
                        ))}
                        {!query.data.data.length && (
                            <div className="empty-state">
                                <h3>هنوز موردی اینجا نیست.</h3>
                                <p>
                                    {kind === "tracks"
                                        ? "آهنگ‌ها پس از ایمپورت موفق در کاتالوگ قرار می‌گیرند."
                                        : "یک مورد تازه بساز یا فیلتر جستجو را تغییر بده."}
                                </p>
                            </div>
                        )}
                    </div>
                    {query.data.meta.last_page > 1 && (
                        <div className="pager">
                            <button
                                className="button secondary"
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                قبلی
                            </button>
                            <span>
                                {digits(page)} از{" "}
                                {digits(query.data.meta.last_page)}
                            </span>
                            <button
                                className="button secondary"
                                disabled={page === query.data.meta.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                بعدی
                            </button>
                        </div>
                    )}
                </>
            )}
            {editor && (
                <Dialog
                    open
                    onOpenChange={(v) => {
                        if (!v) setEditor(null);
                    }}
                    title={
                        kind === "artists"
                            ? "هویت هنرمند"
                            : kind === "releases"
                              ? "مشخصات انتشار"
                              : "اطلاعات آهنگ"
                    }
                    description="این اطلاعات مشترک است و در همهٔ پلی‌لیست‌ها نمایش داده می‌شود."
                >
                    {kind === "artists" ? (
                        <ArtistEditor
                            artist={
                                editor === "new" ? null : (editor as Artist)
                            }
                            close={() => setEditor(null)}
                        />
                    ) : kind === "releases" ? (
                        <ReleaseEditor
                            release={
                                editor === "new" ? null : (editor as Release)
                            }
                            close={() => setEditor(null)}
                        />
                    ) : (
                        <TrackEditor
                            track={editor as Track}
                            close={() => setEditor(null)}
                        />
                    )}
                </Dialog>
            )}
        </>
    );
}
function useCatalogSave(close: () => void) {
    const client = useQueryClient(),
        notify = useToast();
    return {
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: adminKeys.root });
            void client.invalidateQueries({ queryKey: ["tracks"] });
            void client.invalidateQueries({ queryKey: ["playlist"] });
            notify("تغییرات کاتالوگ ذخیره شد.");
            close();
        },
        onError: (e: Error) => notify(e.message, true),
    };
}
function ArtistEditor({
    artist,
    close,
}: {
    artist: Artist | null;
    close: () => void;
}) {
    const [form, setForm] = useState({
        name: artist?.name || "",
        sort_name: artist?.sort_name || "",
        description: artist?.description || "",
        country_code: artist?.country_code || "",
    });
    const callbacks = useCatalogSave(close);
    const save = useMutation({
        mutationFn: () =>
            api(artist ? `/admin/artists/${artist.id}` : "/admin/artists", {
                method: artist ? "PATCH" : "POST",
                body: json({ ...form, revision: artist?.revision }),
            }),
        ...callbacks,
    });
    return (
        <form
            className="stack-form"
            onSubmit={(e) => {
                e.preventDefault();
                save.mutate();
            }}
        >
            <label>
                نام نمایشی
                <input
                    required
                    maxLength={255}
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
            </label>
            <label>
                نام برای مرتب‌سازی
                <input
                    maxLength={255}
                    value={form.sort_name}
                    onChange={(e) =>
                        setForm({ ...form, sort_name: e.target.value })
                    }
                />
            </label>
            <label>
                معرفی
                <textarea
                    rows={3}
                    maxLength={3000}
                    value={form.description}
                    onChange={(e) =>
                        setForm({ ...form, description: e.target.value })
                    }
                />
            </label>
            <label>
                کد دوحرفی کشور، اختیاری
                <input
                    dir="ltr"
                    maxLength={2}
                    pattern="[A-Z]{2}"
                    placeholder="IR"
                    value={form.country_code}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            country_code: e.target.value.toUpperCase(),
                        })
                    }
                />
            </label>
            <button className="button" disabled={save.isPending}>
                ذخیرهٔ هنرمند
            </button>
        </form>
    );
}
function ReleaseEditor({
    release,
    close,
}: {
    release: Release | null;
    close: () => void;
}) {
    const [form, setForm] = useState({
        title: release?.title || "",
        type: release?.type || "album",
        released_on: release?.released_on || "",
    });
    const [artists, setArtists] = useState<Artist[]>(release?.artists || []);
    const [arrange, setArrange] = useState(false);
    const callbacks = useCatalogSave(close);
    const save = useMutation({
        mutationFn: () =>
            api(release ? `/admin/releases/${release.id}` : "/admin/releases", {
                method: release ? "PATCH" : "POST",
                body: json({
                    ...form,
                    artist_ids: artists.map((a) => a.id),
                    revision: release?.revision,
                }),
            }),
        ...callbacks,
    });
    return (
        <>
            <form
                className="stack-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    save.mutate();
                }}
            >
                <label>
                    عنوان انتشار
                    <input
                        required
                        maxLength={255}
                        value={form.title}
                        onChange={(e) =>
                            setForm({ ...form, title: e.target.value })
                        }
                    />
                </label>
                <label>
                    نوع انتشار
                    <select
                        value={form.type}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                type: e.target.value as Release["type"],
                            })
                        }
                    >
                        {Object.entries(releaseTypes).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    تاریخ میلادی، اختیاری
                    <input
                        type="date"
                        value={form.released_on}
                        onChange={(e) =>
                            setForm({ ...form, released_on: e.target.value })
                        }
                    />
                </label>
                <ArtistPicker
                    choose={(artist) =>
                        setArtists((old) =>
                            old.some((a) => a.id === artist.id)
                                ? old
                                : [...old, artist],
                        )
                    }
                />
                <div className="selected-credits">
                    {artists.map((a) => (
                        <span key={a.id}>
                            {a.name}{" "}
                            <IconButton
                                label={`برداشتن ${a.name}`}
                                onClick={() =>
                                    setArtists((old) =>
                                        old.filter((x) => x.id !== a.id),
                                    )
                                }
                            >
                                <X size={14} />
                            </IconButton>
                        </span>
                    ))}
                </div>
                <button
                    className="button"
                    disabled={save.isPending || !artists.length}
                >
                    ذخیرهٔ مشخصات انتشار
                </button>
            </form>
            {release && (
                <div className="stack-form admin-footnote">
                    <button
                        className="button secondary"
                        onClick={() => setArrange(!arrange)}
                    >
                        مدیریت آهنگ‌های انتشار
                    </button>
                    {arrange && (
                        <ReleaseTracks release={release} close={close} />
                    )}
                </div>
            )}
        </>
    );
}
function ReleaseTracks({
    release,
    close,
}: {
    release: Release;
    close: () => void;
}) {
    const [rows, setRows] = useState<NonNullable<Release["tracks"]> | null>(
        null,
    );
    const callbacks = useCatalogSave(close);
    const query = useQuery({
        queryKey: ["admin", "release", release.id],
        queryFn: () => api<{ data: Release }>(`/admin/releases/${release.id}`),
    });
    const selected = rows || query.data?.data.tracks || [];
    const save = useMutation({
        mutationFn: () =>
            api(`/admin/releases/${release.id}/tracks`, {
                method: "PUT",
                body: json({
                    revision: query.data?.data.revision,
                    tracks: selected.map((t) => ({
                        track_id: t.id,
                        disc_number: t.disc_number,
                        track_number: t.track_number,
                    })),
                }),
            }),
        ...callbacks,
    });
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    return (
        <>
            <TrackPicker
                choose={(track) =>
                    setRows((old) => {
                        const list = old || selected;
                        return list.some((t) => t.id === track.id)
                            ? list
                            : [
                                  ...list,
                                  {
                                      id: track.id,
                                      title: track.title,
                                      disc_number: 1,
                                      track_number:
                                          Math.max(
                                              0,
                                              ...list
                                                  .filter(
                                                      (t) =>
                                                          t.disc_number === 1,
                                                  )
                                                  .map((t) => t.track_number),
                                          ) + 1,
                                  },
                              ];
                    })
                }
            />
            <div className="release-tracks">
                {selected.map((track, i) => (
                    <div key={track.id}>
                        <strong>{track.title}</strong>
                        <label>
                            دیسک
                            <input
                                type="number"
                                min={1}
                                max={999}
                                value={track.disc_number}
                                onChange={(e) =>
                                    setRows(
                                        selected.map((t, j) =>
                                            i === j
                                                ? {
                                                      ...t,
                                                      disc_number: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : t,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <label>
                            شماره
                            <input
                                type="number"
                                min={1}
                                max={999}
                                value={track.track_number}
                                onChange={(e) =>
                                    setRows(
                                        selected.map((t, j) =>
                                            i === j
                                                ? {
                                                      ...t,
                                                      track_number: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : t,
                                        ),
                                    )
                                }
                            />
                        </label>
                        <IconButton
                            label={`حذف ${track.title} از انتشار`}
                            onClick={() =>
                                setRows(
                                    selected.filter((t) => t.id !== track.id),
                                )
                            }
                        >
                            <X size={16} />
                        </IconButton>
                    </div>
                ))}
            </div>
            <button
                className="button"
                disabled={save.isPending}
                onClick={() => save.mutate()}
            >
                ذخیرهٔ فهرست انتشار
            </button>
        </>
    );
}
function TrackEditor({ track, close }: { track: Track; close: () => void }) {
    const [title, setTitle] = useState(track.title),
        [credits, setCredits] = useState(track.artists || []),
        [primary, setPrimary] = useState(
            track.primary_release_id?.toString() || "",
        );
    const callbacks = useCatalogSave(close);
    const save = useMutation({
        mutationFn: () =>
            api(`/admin/tracks/${track.id}`, {
                method: "PATCH",
                body: json({
                    title,
                    revision: track.revision,
                    primary_release_id: primary ? Number(primary) : null,
                    credits: credits.map((c) => ({
                        artist_id: c.id,
                        role: c.role,
                    })),
                }),
            }),
        ...callbacks,
    });
    return (
        <form
            className="stack-form"
            onSubmit={(e) => {
                e.preventDefault();
                save.mutate();
            }}
        >
            <label>
                عنوان آهنگ
                <input
                    required
                    maxLength={255}
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                />
            </label>
            <ArtistPicker
                choose={(artist) =>
                    setCredits((old) =>
                        old.some(
                            (c) => c.id === artist.id && c.role === "primary",
                        )
                            ? old
                            : [
                                  ...old,
                                  {
                                      id: artist.id,
                                      name: artist.name,
                                      role: "primary",
                                  },
                              ],
                    )
                }
            />
            <div className="credit-rows">
                {credits.map((credit, i) => (
                    <div key={`${credit.id}-${i}`}>
                        <span>
                            {credit.name}
                            <small>#{credit.id}</small>
                        </span>
                        <label>
                            <span className="visually-hidden">
                                نقش {credit.name}
                            </span>
                            <select
                                value={credit.role}
                                onChange={(e) =>
                                    setCredits((old) =>
                                        old.map((c, j) =>
                                            j === i
                                                ? { ...c, role: e.target.value }
                                                : c,
                                        ),
                                    )
                                }
                            >
                                {[
                                    ["primary", "هنرمند اصلی"],
                                    ["featured", "همراه"],
                                    ["composer", "آهنگ‌ساز"],
                                    ["producer", "تهیه‌کننده"],
                                ].map(([v, l]) => (
                                    <option key={v} value={v}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <IconButton
                            label={`برداشتن نقش ${credit.name}`}
                            onClick={() =>
                                setCredits((old) =>
                                    old.filter((_, j) => j !== i),
                                )
                            }
                        >
                            <X size={16} />
                        </IconButton>
                    </div>
                ))}
            </div>
            <label>
                انتشار اصلی
                <select
                    value={primary}
                    onChange={(e) => setPrimary(e.target.value)}
                >
                    <option value="">بدون انتشار اصلی</option>
                    {track.releases?.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.title} · #{r.id}
                        </option>
                    ))}
                </select>
                <small>
                    برای افزودن انتشار دیگر، ابتدا از بخش انتشارها این آهنگ را
                    به فهرستش اضافه کن.
                </small>
            </label>
            <p className="muted">
                ویرایش متادیتا فایل صوتی، شناسهٔ آهنگ و علاقه‌مندی‌ها را عوض
                نمی‌کند.
            </p>
            <button
                className="button"
                disabled={save.isPending || !credits.length}
            >
                ذخیرهٔ اطلاعات آهنگ
            </button>
        </form>
    );
}
