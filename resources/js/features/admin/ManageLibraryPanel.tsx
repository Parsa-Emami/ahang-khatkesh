import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ImagePlus, Pencil, ListMusic, Plus } from "lucide-react";
import { PlaylistTracksEditor } from "./PlaylistTracksEditor";
import { Link } from "react-router-dom";
import { api, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import {
    digits,
    moodLabels,
    type Mood,
    type Page,
    type Playlist,
} from "../../types";
import {
    Artwork,
    Dialog,
    ErrorState,
    IconButton,
    Loading,
} from "../../components/UI";

export function ManageLibraryPanel() {
    const client = useQueryClient(),
        notify = useToast();
    const [page, setPage] = useState(1),
        [editing, setEditing] = useState<Playlist | null>(null);
    const query = useQuery({
        queryKey: ["playlists", "admin", page],
        queryFn: () => api<Page<Playlist>>(`/playlists?page=${page}`),
    });
    const [creating, setCreating] = useState(false),
        [newName, setNewName] = useState(""),
        [arranging, setArranging] = useState<Playlist | null>(null);
    const create = useMutation({
        mutationFn: () =>
            api("/admin/playlists", {
                method: "POST",
                body: json({ name: newName, mood: "other" }),
            }),
        onSuccess: () => {
            setCreating(false);
            setNewName("");
            void client.invalidateQueries({ queryKey: ["playlists"] });
            notify("پیش‌نویس ساخته شد؛ آهنگ‌هایش را انتخاب کن.");
        },
        onError: (e) => notify(e.message, true),
    });
    const update = useMutation({
        mutationFn: () =>
            api(`/admin/playlists/${editing!.id}`, {
                method: "PATCH",
                body: json({
                    revision: editing!.revision,
                    name: editing!.name,
                    description: editing!.description,
                    mood: editing!.mood,
                    status: editing!.status,
                }),
            }),
        onSuccess: () => {
            setEditing(null);
            void client.invalidateQueries({ queryKey: ["playlists"] });
            void client.invalidateQueries({ queryKey: ["playlist"] });
            notify("پلی‌لیست به‌روزرسانی شد.");
        },
        onError: (error) => notify(error.message, true),
    });
    const cover = useMutation({
        mutationFn: async ({
            playlist,
            file,
        }: {
            playlist: Playlist;
            file: File;
        }) => {
            const form = new FormData();
            form.append("cover", file);
            form.append("revision", String(playlist.revision));
            return api(`/admin/playlists/${playlist.id}/cover`, {
                method: "POST",
                body: form,
            });
        },
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ["playlists"] });
            void client.invalidateQueries({ queryKey: ["playlist"] });
            notify("کاور تازه ثبت شد.");
        },
        onError: (error) => notify(error.message, true),
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
            <div className="admin-toolbar">
                <p className="muted">
                    نام‌های روشن و کوتاه، پیدا کردن مجموعه را آسان می‌کنند.
                </p>
                <button className="button" onClick={() => setCreating(true)}>
                    <Plus size={16} />
                    پلی‌لیست تازه
                </button>
            </div>
            <p className="muted">
                پیش‌نویس‌ها فقط برای نقش‌های مدیریت محتوا دیده می‌شوند. با
                انتشار، همهٔ اعضا به مجموعه دسترسی دارند.
            </p>
            <div className="manage-list">
                {query.data.data.map((playlist) => (
                    <div className="manage-row" key={playlist.id}>
                        <Artwork
                            seed={playlist.artwork_seed}
                            url={playlist.cover_url}
                        />
                        <div>
                            <Link to={`/playlists/${playlist.id}`}>
                                <strong>{playlist.name}</strong>
                            </Link>
                            <small>
                                {digits(playlist.tracks_count)} آهنگ ·{" "}
                                {moodLabels[playlist.mood]}
                            </small>
                        </div>
                        <span
                            className={`status-pill status-${playlist.status === "published" ? "completed" : "ready"}`}
                        >
                            {playlist.status === "published"
                                ? "منتشرشده"
                                : playlist.status === "archived"
                                  ? "بایگانی‌شده"
                                  : "پیش‌نویس"}
                        </span>
                        <label
                            className="icon-button cover-upload"
                            title="تغییر کاور"
                        >
                            <ImagePlus size={18} />
                            <input
                                aria-label={`تغییر کاور ${playlist.name}`}
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp"
                                disabled={cover.isPending}
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (file) cover.mutate({ playlist, file });
                                    e.target.value = "";
                                }}
                            />
                        </label>
                        <IconButton
                            label={`ترتیب آهنگ‌های ${playlist.name}`}
                            onClick={() => setArranging(playlist)}
                        >
                            <ListMusic size={18} />
                        </IconButton>
                        <IconButton
                            label={`ویرایش ${playlist.name}`}
                            onClick={() => setEditing({ ...playlist })}
                        >
                            <Pencil size={18} />
                        </IconButton>
                    </div>
                ))}
                {!query.data.data.length && (
                    <p className="muted">پلی‌لیستی برای مدیریت وجود ندارد.</p>
                )}
            </div>
            {query.data.meta.last_page > 1 && (
                <div className="pager">
                    <button
                        className="button secondary"
                        disabled={page === 1}
                        onClick={() => setPage((v) => v - 1)}
                    >
                        قبلی
                    </button>
                    <span>{digits(page)}</span>
                    <button
                        className="button secondary"
                        disabled={page === query.data.meta.last_page}
                        onClick={() => setPage((v) => v + 1)}
                    >
                        بعدی
                    </button>
                </div>
            )}
            <Dialog
                open={creating}
                onOpenChange={(v) => {
                    if (!create.isPending) setCreating(v);
                }}
                title="یک مجموعهٔ تازه"
                description="پلی‌لیست به‌صورت پیش‌نویس ساخته می‌شود؛ سپس آهنگ و کاور اضافه کن."
            >
                <form
                    className="stack-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.mutate();
                    }}
                >
                    <label>
                        نام مجموعه
                        <input
                            required
                            maxLength={160}
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                        />
                    </label>
                    <button className="button" disabled={create.isPending}>
                        ساخت پیش‌نویس
                    </button>
                </form>
            </Dialog>
            {arranging && (
                <PlaylistTracksEditor
                    playlist={arranging}
                    close={() => setArranging(null)}
                />
            )}
            <Dialog
                open={!!editing}
                onOpenChange={(value) => {
                    if (!value && !update.isPending) setEditing(null);
                }}
                title="ویرایش مجموعه"
                description="اطلاعات و وضعیت دسترسی این پلی‌لیست را تغییر بده."
            >
                {editing && (
                    <form
                        className="stack-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            update.mutate();
                        }}
                    >
                        <label>
                            نام
                            <input
                                required
                                maxLength={160}
                                value={editing.name}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        name: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            توضیح
                            <textarea
                                maxLength={2000}
                                rows={3}
                                value={editing.description || ""}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        description: e.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            حال‌وهوا
                            <select
                                value={editing.mood}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        mood: e.target.value as Mood,
                                    })
                                }
                            >
                                {Object.entries(moodLabels).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label>
                            وضعیت
                            <select
                                value={editing.status}
                                onChange={(e) =>
                                    setEditing({
                                        ...editing,
                                        status: e.target
                                            .value as Playlist["status"],
                                    })
                                }
                            >
                                <option value="draft">پیش‌نویس</option>
                                <option value="archived">بایگانی‌شده</option>
                                <option value="published">منتشرشده</option>
                            </select>
                        </label>
                        <button className="button" disabled={update.isPending}>
                            ذخیرهٔ تغییرات
                        </button>
                    </form>
                )}
            </Dialog>
        </>
    );
}
