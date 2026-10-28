import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Copy,
    Download,
    FileJson,
    LoaderCircle,
    Music2,
    Plus,
    Trash2,
    UploadCloud,
} from "lucide-react";
import { Link } from "react-router-dom";
import { api, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import {
    digits,
    duration,
    moodLabels,
    type ImportRun,
    type ManifestRow,
    type MediaAsset,
    type Mood,
    type Page,
} from "../../types";
import { ErrorState, IconButton, Loading } from "../../components/UI";
const statusLabels: Record<ImportRun["status"], string> = {
    ready: "آمادهٔ تأیید",
    processing: "در حال ساخت",
    completed: "انجام‌شده",
    failed: "خطای پردازش؛ قابل تلاش دوباره",
    needs_review: "نیاز به پیش‌نمایش تازه",
    cancelled: "لغو به دلیل تغییر دسترسی",
};

export function ImportsPanel() {
    const client = useQueryClient(),
        notify = useToast();
    const [name, setName] = useState(""),
        [description, setDescription] = useState(""),
        [mood, setMood] = useState<Mood>("focus"),
        [artist, setArtist] = useState("");
    const [rows, setRows] = useState<ManifestRow[]>([]),
        [manifest, setManifest] = useState<File | null>(null),
        [uploading, setUploading] = useState(""),
        [uploadErrors, setUploadErrors] = useState<string[]>([]),
        [runId, setRunId] = useState<string | null>(null),
        [publish, setPublish] = useState(true),
        [assetPage, setAssetPage] = useState(1),
        [historyPage, setHistoryPage] = useState(1);
    const [dragging, setDragging] = useState(false);
    const key = useRef(crypto.randomUUID());
    useEffect(() => {
        key.current = crypto.randomUUID();
    }, [name, description, mood, rows, manifest]);
    const assets = useQuery({
        queryKey: ["media", assetPage],
        queryFn: () =>
            api<{
                data: MediaAsset[];
                current_page: number;
                last_page: number;
            }>(`/admin/media?page=${assetPage}`),
    });
    const history = useQuery({
        queryKey: ["imports", historyPage],
        queryFn: () =>
            api<Page<ImportRun>>(`/admin/imports?page=${historyPage}`),
    });
    const run = useQuery({
        queryKey: ["import", runId],
        queryFn: () => api<{ data: ImportRun }>(`/admin/imports/${runId}`),
        enabled: !!runId,
        refetchInterval: (query) =>
            query.state.data?.data.status === "processing" ? 2000 : false,
    });
    useEffect(() => {
        if (run.data?.data.status === "completed") {
            void client.invalidateQueries({ queryKey: ["playlists"] });
            void client.invalidateQueries({ queryKey: ["stats"] });
            void client.invalidateQueries({ queryKey: ["imports"] });
        }
    }, [run.data?.data.status, client]);
    function addAsset(asset: MediaAsset) {
        setRows((previous) =>
            previous.some((row) => row.asset_id === asset.id)
                ? previous
                : [
                      ...previous,
                      {
                          asset_id: asset.id,
                          title: asset.original_name.replace(/\.[^.]+$/, ""),
                          artist,
                          album: "",
                      },
                  ],
        );
    }
    async function upload(files: FileList | File[]) {
        if (uploading) return;
        const batch = Array.from(files);
        if (batch.length > 20) {
            notify("در هر نوبت حداکثر ۲۰ فایل انتخاب کن.", true);
            return;
        }
        const errors: string[] = [];
        setUploadErrors([]);
        for (let index = 0; index < batch.length; index++) {
            const file = batch[index];
            setUploading(
                `${digits(index + 1)} از ${digits(batch.length)} — ${file.name}`,
            );
            if (file.size > 100 * 1024 * 1024) {
                errors.push(`${file.name}: حجم بیشتر از ۱۰۰ مگابایت`);
                continue;
            }
            const form = new FormData();
            form.append("audio", file);
            try {
                const result = await api<{
                    data: MediaAsset;
                    duplicate: boolean;
                }>("/admin/media", { method: "POST", body: form });
                addAsset(result.data);
            } catch (error) {
                errors.push(`${file.name}: ${(error as Error).message}`);
            }
        }
        setUploading("");
        setUploadErrors(errors);
        void client.invalidateQueries({ queryKey: ["media"] });
    }
    const preview = useMutation({
        mutationFn: () => {
            const form = new FormData();
            form.append("name", name);
            form.append("description", description);
            form.append("mood", mood);
            form.append(
                "manifest",
                manifest ||
                    new File(
                        [
                            json({
                                tracks: rows.map((row) => ({
                                    ...row,
                                    artist: row.artist || artist,
                                })),
                            }),
                        ],
                        "playlist.json",
                        { type: "application/json" },
                    ),
            );
            return api<{ data: ImportRun }>("/admin/imports", {
                method: "POST",
                headers: { "Idempotency-Key": key.current },
                body: form,
            });
        },
        onSuccess: (result) => {
            client.setQueryData(["import", result.data.id], result);
            setRunId(result.data.id);
            void client.invalidateQueries({ queryKey: ["imports"] });
        },
        onError: (error) => notify(error.message, true),
    });
    const commit = useMutation({
        mutationFn: () =>
            api<{ data: ImportRun }>(`/admin/imports/${runId}/commit`, {
                method: "POST",
                body: json({ publish }),
            }),
        onSuccess: (result) => {
            client.setQueryData(["import", runId], result);
            void client.invalidateQueries({ queryKey: ["imports"] });
        },
        onError: (error) => notify(error.message, true),
    });
    const current = run.data?.data;
    function exportManifest() {
        const url = URL.createObjectURL(
            new Blob(
                [
                    JSON.stringify(
                        {
                            tracks: rows.map((row) => ({
                                ...row,
                                artist: row.artist || artist,
                            })),
                        },
                        null,
                        2,
                    ),
                ],
                { type: "application/json" },
            ),
        );
        const a = document.createElement("a");
        a.href = url;
        a.download = "ahang-khatkesh-playlist.json";
        a.click();
        URL.revokeObjectURL(url);
    }
    return (
        <>
            <div className="import-steps">
                <span className={!runId ? "active" : ""}>
                    <b>۱</b>آماده‌سازی
                </span>
                <i />
                <span className={current?.status === "ready" ? "active" : ""}>
                    <b>۲</b>بررسی
                </span>
                <i />
                <span
                    className={
                        current &&
                        [
                            "processing",
                            "completed",
                            "needs_review",
                            "cancelled",
                        ].includes(current.status)
                            ? "active"
                            : ""
                    }
                >
                    <b>۳</b>ساخت مجموعه
                </span>
            </div>
            {!runId ? (
                <form
                    className="import-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        preview.mutate();
                    }}
                >
                    <div className="admin-panel">
                        <h2>یک پلی‌لیست تازه</h2>
                        <p className="muted">
                            فایل‌ها را بارگذاری کن، اطلاعات آهنگ‌ها را تنظیم کن
                            و قبل از ساخت، پیش‌نمایش را ببین.
                        </p>
                        <div className="form-grid">
                            <label>
                                نام پلی‌لیست
                                <input
                                    required
                                    maxLength={160}
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="مثلاً: ساعتِ آبی"
                                />
                            </label>
                            <label>
                                حال‌وهوا
                                <select
                                    value={mood}
                                    onChange={(e) =>
                                        setMood(e.target.value as Mood)
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
                        </div>
                        <label>
                            توضیح کوتاه
                            <textarea
                                maxLength={2000}
                                rows={2}
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder="این مجموعه برای چه لحظه‌هایی ساخته شده؟"
                            />
                        </label>
                        <label>
                            هنرمند پیش‌فرض
                            <input
                                maxLength={255}
                                value={artist}
                                onChange={(e) => setArtist(e.target.value)}
                                placeholder="برای ردیف‌هایی که هنرمندشان خالی است"
                            />
                        </label>
                        <label
                            className={`upload-zone ${dragging ? "dragging" : ""}`}
                            onDragOver={(e) => {
                                e.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(e) => {
                                e.preventDefault();
                                setDragging(false);
                                void upload(e.dataTransfer.files);
                            }}
                        >
                            <UploadCloud size={29} />
                            <strong>
                                {uploading || "آهنگ‌ها را اینجا رها کن"}
                            </strong>
                            <span>
                                یا برای انتخاب فایل بزن · MP3، M4A، WAV، OGG و
                                FLAC
                            </span>
                            <small>
                                هر فایل تا ۱۰۰ مگابایت · هر نوبت تا ۲۰ فایل
                            </small>
                            <input
                                aria-label="بارگذاری فایل‌های صوتی"
                                type="file"
                                accept=".mp3,.m4a,.wav,.ogg,.flac"
                                multiple
                                disabled={!!uploading}
                                onChange={(e) => {
                                    if (e.target.files)
                                        void upload(e.target.files);
                                    e.target.value = "";
                                }}
                            />
                        </label>
                        {uploadErrors.length > 0 && (
                            <div className="form-error" role="alert">
                                {uploadErrors.map((message) => (
                                    <p key={message}>{message}</p>
                                ))}
                            </div>
                        )}
                        <details className="asset-library">
                            <summary>انتخاب از فایل‌های بارگذاری‌شده</summary>
                            {assets.isPending ? (
                                <Loading />
                            ) : assets.error ? (
                                <ErrorState
                                    error={assets.error}
                                    retry={() => void assets.refetch()}
                                />
                            ) : (
                                <>
                                    <div className="asset-list">
                                        {assets.data.data.map((asset) => (
                                            <div
                                                className="asset-row"
                                                key={asset.id}
                                            >
                                                <Music2 size={18} />
                                                <span>
                                                    <strong dir="auto">
                                                        {asset.original_name}
                                                    </strong>
                                                    <small dir="ltr">
                                                        {duration(
                                                            asset.duration_seconds,
                                                        )}{" "}
                                                        ·{" "}
                                                        {(
                                                            asset.size_bytes /
                                                            1048576
                                                        ).toFixed(1)}{" "}
                                                        MB
                                                    </small>
                                                </span>
                                                <IconButton
                                                    label="کپی شناسهٔ فایل"
                                                    onClick={() =>
                                                        void navigator.clipboard
                                                            .writeText(asset.id)
                                                            .then(() =>
                                                                notify(
                                                                    "شناسهٔ فایل کپی شد.",
                                                                ),
                                                            )
                                                            .catch(() =>
                                                                notify(
                                                                    asset.id,
                                                                ),
                                                            )
                                                    }
                                                >
                                                    <Copy size={16} />
                                                </IconButton>
                                                <IconButton
                                                    label="افزودن فایل به فهرست"
                                                    disabled={rows.some(
                                                        (row) =>
                                                            row.asset_id ===
                                                            asset.id,
                                                    )}
                                                    onClick={() =>
                                                        addAsset(asset)
                                                    }
                                                >
                                                    <Plus size={18} />
                                                </IconButton>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="pager">
                                        <button
                                            type="button"
                                            className="button secondary"
                                            disabled={assetPage <= 1}
                                            onClick={() =>
                                                setAssetPage((v) => v - 1)
                                            }
                                        >
                                            قبلی
                                        </button>
                                        <span>صفحهٔ {digits(assetPage)}</span>
                                        <button
                                            type="button"
                                            className="button secondary"
                                            disabled={
                                                assetPage >=
                                                assets.data.last_page
                                            }
                                            onClick={() =>
                                                setAssetPage((v) => v + 1)
                                            }
                                        >
                                            بعدی
                                        </button>
                                    </div>
                                </>
                            )}
                        </details>
                    </div>
                    {!!rows.length && (
                        <div className="admin-panel">
                            <div className="section-heading">
                                <h2>اطلاعات {digits(rows.length)} آهنگ</h2>
                                <button
                                    type="button"
                                    className="text-link"
                                    onClick={exportManifest}
                                >
                                    <Download size={16} />
                                    دریافت JSON
                                </button>
                            </div>
                            <div className="import-rows">
                                {rows.map((row, index) => (
                                    <div
                                        className="import-row"
                                        key={row.asset_id}
                                    >
                                        <span>{digits(index + 1)}</span>
                                        <label>
                                            <span className="visually-hidden">
                                                عنوان آهنگ {digits(index + 1)}
                                            </span>
                                            <input
                                                required={!manifest}
                                                maxLength={255}
                                                value={row.title}
                                                placeholder="عنوان آهنگ"
                                                onChange={(e) =>
                                                    setRows((previous) =>
                                                        previous.map(
                                                            (item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          title: e
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </label>
                                        <label>
                                            <span className="visually-hidden">
                                                هنرمند آهنگ {digits(index + 1)}
                                            </span>
                                            <input
                                                required={!manifest && !artist}
                                                maxLength={255}
                                                value={row.artist}
                                                placeholder={artist || "هنرمند"}
                                                onChange={(e) =>
                                                    setRows((previous) =>
                                                        previous.map(
                                                            (item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          artist: e
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        </label>
                                        <IconButton
                                            label={`حذف ${row.title} از فهرست`}
                                            onClick={() =>
                                                setRows((previous) =>
                                                    previous.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 size={16} />
                                        </IconButton>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                    <div className="admin-panel manifest-panel">
                        <FileJson size={26} />
                        <div>
                            <h2>فهرست آماده داری؟</h2>
                            <p>
                                می‌توانی JSON یا CSV با ستون‌های asset_id،
                                title، artist و album وارد کنی. شناسه‌ها متعلق
                                به فایل‌های بارگذاری‌شدهٔ همین کتابخانه‌اند.
                            </p>
                            <input
                                aria-label="انتخاب فایل فهرست JSON یا CSV"
                                type="file"
                                accept=".json,.csv"
                                onChange={(e) =>
                                    setManifest(e.target.files?.[0] || null)
                                }
                            />
                            {manifest && (
                                <button
                                    className="text-link"
                                    type="button"
                                    onClick={() => setManifest(null)}
                                >
                                    حذف فهرست انتخاب‌شده
                                </button>
                            )}
                        </div>
                    </div>
                    <div className="form-actions">
                        <span className="muted">
                            {manifest
                                ? `منبع: ${manifest.name}`
                                : `${digits(rows.length)} آهنگ انتخاب شده`}
                        </span>
                        <button
                            className="button"
                            disabled={
                                !!uploading ||
                                preview.isPending ||
                                (!manifest && !rows.length)
                            }
                        >
                            {preview.isPending ? (
                                <LoaderCircle size={17} className="spin" />
                            ) : (
                                <Check size={17} />
                            )}
                            بررسی و پیش‌نمایش
                        </button>
                    </div>
                </form>
            ) : (
                <div className="admin-panel import-result">
                    {run.isPending ? (
                        <Loading />
                    ) : run.error ? (
                        <ErrorState
                            error={run.error}
                            retry={() => void run.refetch()}
                        />
                    ) : (
                        current && (
                            <>
                                <span
                                    className={`status-pill status-${current.status}`}
                                >
                                    {statusLabels[current.status]}
                                </span>
                                <h2>{current.name}</h2>
                                <p className="muted">
                                    {digits(current.rows.length)} آهنگ بررسی شده
                                    · {moodLabels[current.mood]}
                                </p>
                                {current.status === "processing" ? (
                                    <Loading label="پلی‌لیست در صف پردازش است؛ وضعیت به‌صورت خودکار به‌روزرسانی می‌شود." />
                                ) : current.status === "completed" ? (
                                    <div className="import-success">
                                        <CheckCircle2 size={46} />
                                        <h3>یک مجموعهٔ تازه آماده است.</h3>
                                        <p>
                                            {digits(
                                                current.report
                                                    ?.created_tracks || 0,
                                            )}{" "}
                                            آهنگ تازه و{" "}
                                            {digits(
                                                current.report?.reused_tracks ||
                                                    0,
                                            )}{" "}
                                            آهنگ موجود در این مجموعه قرار گرفت.
                                        </p>
                                        {!!current.report?.reused_tracks && (
                                            <p className="muted">
                                                متادیتای آهنگ‌های مشترک موجود
                                                حفظ شده است.
                                            </p>
                                        )}
                                        <Link
                                            className="button"
                                            to={`/playlists/${current.playlist_id}`}
                                        >
                                            دیدن پلی‌لیست
                                            <ArrowLeft size={17} />
                                        </Link>
                                    </div>
                                ) : (
                                    <>
                                        <div className="preview-rows">
                                            {current.rows.map((row, index) => (
                                                <div key={row.asset_id}>
                                                    <span>
                                                        {digits(index + 1)}
                                                    </span>
                                                    <strong dir="auto">
                                                        {row.title}
                                                    </strong>
                                                    <span dir="auto">
                                                        {row.artist}
                                                    </span>
                                                    <Check size={16} />
                                                </div>
                                            ))}
                                        </div>
                                        {current.error && (
                                            <p
                                                className="form-error"
                                                role="alert"
                                            >
                                                {current.error}
                                            </p>
                                        )}
                                        <label className="checkbox-label">
                                            <input
                                                type="checkbox"
                                                checked={publish}
                                                onChange={(e) =>
                                                    setPublish(e.target.checked)
                                                }
                                            />
                                            پس از ساخت، برای اعضا منتشر شود
                                        </label>
                                        <button
                                            className="button"
                                            disabled={
                                                commit.isPending ||
                                                [
                                                    "needs_review",
                                                    "cancelled",
                                                ].includes(current.status)
                                            }
                                            onClick={() => commit.mutate()}
                                        >
                                            {commit.isPending
                                                ? "در حال ثبت…"
                                                : current.status === "failed"
                                                  ? "تلاش دوباره"
                                                  : "تأیید و ساخت پلی‌لیست"}
                                        </button>
                                    </>
                                )}
                                <button
                                    className="text-link reset-import"
                                    onClick={() => {
                                        setRunId(null);
                                        key.current = crypto.randomUUID();
                                        if (current.status === "completed") {
                                            setRows([]);
                                            setManifest(null);
                                            setName("");
                                            setDescription("");
                                        }
                                    }}
                                >
                                    {" "}
                                    {current.status === "completed"
                                        ? "ساخت مجموعهٔ بعدی"
                                        : "بازگشت به آماده‌سازی"}
                                </button>
                            </>
                        )
                    )}
                </div>
            )}
            <div className="section-heading history-heading">
                <h2>سابقهٔ ورود پلی‌لیست</h2>
                <span>قابل پیگیری، از ابتدا تا انتشار</span>
            </div>
            {history.error ? (
                <ErrorState
                    error={history.error}
                    retry={() => void history.refetch()}
                />
            ) : history.isPending ? (
                <Loading />
            ) : (
                <>
                    <div className="import-history">
                        {history.data.data.map((item) => (
                            <button
                                key={item.id}
                                onClick={() => setRunId(item.id)}
                            >
                                <FileJson size={20} />
                                <span>
                                    <strong>{item.name}</strong>
                                    <small>
                                        {new Intl.DateTimeFormat("fa-IR", {
                                            dateStyle: "medium",
                                            timeStyle: "short",
                                        }).format(new Date(item.created_at))}
                                    </small>
                                </span>
                                <span
                                    className={`status-pill status-${item.status}`}
                                >
                                    {statusLabels[item.status]}
                                </span>
                                <ArrowLeft size={17} />
                            </button>
                        ))}
                        {!history.data.data.length && (
                            <p className="muted">
                                اولین ورود پلی‌لیست اینجا ثبت می‌شود.
                            </p>
                        )}
                    </div>
                    {history.data.meta.last_page > 1 && (
                        <div className="pager">
                            <button
                                className="button secondary"
                                disabled={historyPage === 1}
                                onClick={() => setHistoryPage((v) => v - 1)}
                            >
                                قبلی
                            </button>
                            <span>{digits(historyPage)}</span>
                            <button
                                className="button secondary"
                                disabled={
                                    historyPage === history.data.meta.last_page
                                }
                                onClick={() => setHistoryPage((v) => v + 1)}
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
