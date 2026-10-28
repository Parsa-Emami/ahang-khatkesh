import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Trash2, RotateCcw } from "lucide-react";
import { api, json } from "../../lib/api";
import { Dialog, ErrorState, IconButton, Loading } from "../../components/UI";
import { useToast } from "../../context/AppContext";
import { digits } from "../../types";

type Kind = "track" | "playlist";
type TrashedItem = {
    id: number;
    type: Kind;
    title: string;
    revision: number;
    deleted_at: string;
};
const pathFor = (kind: Kind, id: number) =>
    `/admin/${kind === "track" ? "tracks" : "playlists"}/${id}`;
function useTrashChange() {
    const client = useQueryClient();
    return () => {
        for (const key of [
            "admin",
            "tracks",
            "playlists",
            "playlist",
            "stats",
            "trash",
            "playback",
        ])
            void client.invalidateQueries({ queryKey: [key] });
    };
}
export function TrashButton({
    kind,
    id,
    title,
    revision,
}: {
    kind: Kind;
    id: number;
    title: string;
    revision: number;
}) {
    const [open, setOpen] = useState(false);
    const changed = useTrashChange(),
        notify = useToast();
    const mutation = useMutation({
        mutationFn: () =>
            api(pathFor(kind, id), {
                method: "DELETE",
                body: json({ revision }),
            }),
        onSuccess: () => {
            setOpen(false);
            changed();
            notify("به سطل بازیافت منتقل شد.");
        },
        onError: (error) => notify(error.message, true),
    });
    return (
        <>
            <IconButton
                label={`انتقال ${title} به سطل بازیافت`}
                onClick={() => setOpen(true)}
            >
                <Trash2 size={17} />
            </IconButton>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!mutation.isPending) setOpen(value);
                }}
                title={`حذف ${title}`}
                description="این مورد از کتابخانه پنهان می‌شود. سوپرادمین می‌تواند آن را از سطل بازیافت برگرداند."
            >
                <button
                    className="button"
                    disabled={mutation.isPending}
                    onClick={() => mutation.mutate()}
                >
                    انتقال به سطل بازیافت
                </button>
                <button
                    className="button secondary"
                    disabled={mutation.isPending}
                    onClick={() => setOpen(false)}
                >
                    انصراف
                </button>
            </Dialog>
        </>
    );
}
export function TrashPanel() {
    const [kind, setKind] = useState<Kind>("track"),
        [page, setPage] = useState(1);
    const changed = useTrashChange(),
        notify = useToast();
    const query = useQuery({
        queryKey: ["trash", kind, page],
        queryFn: () =>
            api<{ data: TrashedItem[]; last_page: number }>(
                `/admin/trash?type=${kind}&page=${page}`,
            ),
    });
    const restore = useMutation({
        mutationFn: (item: TrashedItem) =>
            api(`${pathFor(item.type, item.id)}/restore`, {
                method: "POST",
                body: json({ revision: item.revision }),
            }),
        onSuccess: () => {
            changed();
            notify("مورد بازیابی شد.");
        },
        onError: (error) => notify(error.message, true),
    });
    return (
        <>
            <div className="filter-chips" aria-label="نوع موارد حذف‌شده">
                {(
                    [
                        ["track", "آهنگ‌ها"],
                        ["playlist", "پلی‌لیست‌ها"],
                    ] as const
                ).map(([value, label]) => (
                    <button
                        key={value}
                        className={value === kind ? "selected" : ""}
                        aria-pressed={value === kind}
                        onClick={() => {
                            setKind(value);
                            setPage(1);
                        }}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <p className="muted">
                بازیابی با همان شناسه انجام می‌شود و ترتیب و علاقه‌مندی‌های قبلی
                حفظ می‌شوند.
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
                                <span className="catalog-id">#{item.id}</span>
                                <div>
                                    <strong dir="auto">{item.title}</strong>
                                    <small>
                                        {new Intl.DateTimeFormat("fa-IR", {
                                            dateStyle: "medium",
                                        }).format(new Date(item.deleted_at))}
                                    </small>
                                </div>
                                <IconButton
                                    label={`بازیابی ${item.title}`}
                                    disabled={restore.isPending}
                                    onClick={() => restore.mutate(item)}
                                >
                                    <RotateCcw size={17} />
                                </IconButton>
                            </article>
                        ))}
                        {!query.data.data.length && (
                            <p className="muted">
                                سطل بازیافت این بخش خالی است.
                            </p>
                        )}
                    </div>
                    {query.data.last_page > 1 && (
                        <div className="pager">
                            <button
                                className="button secondary"
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                قبلی
                            </button>
                            <span>{digits(page)}</span>
                            <button
                                className="button secondary"
                                disabled={page >= query.data.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                بعدی
                            </button>
                        </div>
                    )}
                </>
            )}
        </>
    );
}
