import { useDraftGuard, useDraftValue } from "../drafts/DraftNavigation";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
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
    get album() {
        return tr("m7cd289235e");
    },
    get single() {
        return tr("mb25f61389c");
    },
    ep: "EP",
    get compilation() {
        return tr("mf4d6a3ee65");
    },
};
export function CatalogPanel() {
    useLocale();
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
            <div className="filter-chips" aria-label={tr("mab60b85b01")}>
                {[
                    ["artists", tr("mb36164f96b")],
                    ["releases", tr("ma3ae686a13")],
                    ["tracks", tr("mfc7b9f1ff7")],
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
                    {tr("m1c320d8d2c")}
                    <input
                        dir="auto"
                        name="q"
                        maxLength={120}
                        placeholder={tr("m7b95f59cb8")}
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
                        {kind === "artists"
                            ? tr("mec1731180c")
                            : tr("m6a273403d6")}
                    </button>
                )}
            </div>
            <p className="muted admin-footnote">{tr("mf7b40c37ec")}</p>
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
                                              ? `${releaseTypes[item.type]} · ${item.artists.map((a) => a.name).join(tr("m8715d7bc59"))}`
                                              : tr("m079a4eccd7", {
                                                    v0: digits(
                                                        item.tracks_count || 0,
                                                    ),
                                                    v1: item.needs_review
                                                        ? tr("m33874082fa")
                                                        : "",
                                                })}
                                    </small>
                                </div>
                                <IconButton
                                    label={tr("m54a609b1a4", {
                                        v0:
                                            "name" in item
                                                ? item.name
                                                : item.title,
                                    })}
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
                                <h3>{tr("m3a486027fa")}</h3>
                                <p>
                                    {kind === "tracks"
                                        ? tr("m0ff737e557")
                                        : tr("m65848d6a13")}
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
                                {tr("m1a592f6b2d")}
                            </button>
                            <span>
                                {tr("m3dadd024d7", {
                                    v0: digits(page),
                                    v1: digits(query.data.meta.last_page),
                                })}
                            </span>
                            <button
                                className="button secondary"
                                disabled={page === query.data.meta.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                {tr("m54ee927e96")}
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
                            ? tr("mbe89a2f1e0")
                            : kind === "releases"
                              ? tr("m71d5b1dbdc")
                              : tr("m6b5543e817")
                    }
                    description={tr("m67b086f5a2")}
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
            notify(tr("md9998764b8"));
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
    useLocale();
    const [form, setForm] = useState({
        name: artist?.name || "",
        sort_name: artist?.sort_name || "",
        description: artist?.description || "",
        country_code: artist?.country_code || "",
    });
    useDraftValue(form, () => {});
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
                {tr("m4c2b280afc")}
                <input
                    dir="auto"
                    name="name"
                    required
                    maxLength={255}
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                />
            </label>
            <label>
                {tr("m24d6f9bb85")}
                <input
                    dir="auto"
                    name="sort_name"
                    maxLength={255}
                    value={form.sort_name}
                    onChange={(e) =>
                        setForm({ ...form, sort_name: e.target.value })
                    }
                />
            </label>
            <label>
                {tr("m37eb44417c")}
                <textarea
                    dir="auto"
                    name="description"
                    rows={3}
                    maxLength={3000}
                    value={form.description}
                    onChange={(e) =>
                        setForm({ ...form, description: e.target.value })
                    }
                />
            </label>
            <label>
                {tr("m35e07cd7e5")}
                <input
                    name="country_code"
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
                {tr("mb8a4d4ad81")}
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
    useLocale();
    const [form, setForm] = useState({
        title: release?.title || "",
        type: release?.type || "album",
        released_on: release?.released_on || "",
    });
    const [artists, setArtists] = useState<Artist[]>(release?.artists || []);
    const [arrange, setArrange] = useState(false);
    useDraftValue({ form, artists }, () => {});
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
                    {tr("me4b6ad8394")}
                    <input
                        dir="auto"
                        name="title"
                        required
                        maxLength={255}
                        value={form.title}
                        onChange={(e) =>
                            setForm({ ...form, title: e.target.value })
                        }
                    />
                </label>
                <label>
                    {tr("m17d8cbdd76")}
                    <select
                        dir="auto"
                        name="type"
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
                    {tr("m8b3c35e781")}
                    <input
                        dir="auto"
                        name="released_on"
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
                                label={tr("mbb4d3d0725", { v0: a.name })}
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
                    {tr("me1aca3b14d")}
                </button>
            </form>
            {release && (
                <div className="stack-form admin-footnote">
                    <button
                        className="button secondary"
                        onClick={() => setArrange(!arrange)}
                    >
                        {tr("m7a2a9f8ba9")}
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
    useLocale();
    const [rows, setRows] = useState<NonNullable<Release["tracks"]> | null>(
        null,
    );
    useDraftGuard(rows !== null, () => setRows(null));
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
                        <strong dir="auto">{track.title}</strong>
                        <label>
                            {tr("m22ed8cceaf")}
                            <input
                                dir="auto"
                                name="disc_number"
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
                            {tr("m987584a338")}
                            <input
                                dir="auto"
                                name="track_number"
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
                            label={tr("m164c47422b", { v0: track.title })}
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
                {tr("m8cfcf334b6")}
            </button>
        </>
    );
}
function TrackEditor({ track, close }: { track: Track; close: () => void }) {
    useLocale();
    const [title, setTitle] = useState(track.title),
        [credits, setCredits] = useState(track.artists || []),
        [primary, setPrimary] = useState(
            track.primary_release_id?.toString() || "",
        );
    useDraftValue({ title, credits, primary }, () => {});
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
                {tr("mfedab857ca")}
                <input
                    dir="auto"
                    name="title"
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
                                {tr("m7d2b1dd01b", { v0: credit.name })}
                            </span>
                            <select
                                dir="auto"
                                name="role"
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
                                    ["primary", tr("mb360d9b48a")],
                                    ["featured", tr("mc70b859e1b")],
                                    ["composer", tr("m74ece0f96d")],
                                    ["producer", tr("m0648dfd258")],
                                ].map(([v, l]) => (
                                    <option key={v} value={v}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <IconButton
                            label={tr("m1c8815724b", { v0: credit.name })}
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
                {tr("m94b1d6f558")}
                <select
                    dir="auto"
                    name="primary"
                    value={primary}
                    onChange={(e) => setPrimary(e.target.value)}
                >
                    <option value="">{tr("me8a8814899")}</option>
                    {track.releases?.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.title} · #{r.id}
                        </option>
                    ))}
                </select>
                <small>{tr("mb8ccc57a22")}</small>
            </label>
            <p className="muted">{tr("m5b5d1a7a47")}</p>
            <button
                className="button"
                disabled={save.isPending || !credits.length}
            >
                {tr("m2d2b494fda")}
            </button>
        </form>
    );
}
