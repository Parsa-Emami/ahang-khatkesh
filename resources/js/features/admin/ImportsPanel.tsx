import { localizeText } from "../../i18n/runtime";
import { useDraftGuard } from "../drafts/DraftNavigation";
import { currentPreferences } from "../../i18n/runtime";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
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
import { createUploadBatch } from "./uploads";
import { conversionLabels } from "./media/ConversionPanel";
import { StorageStatus } from "./StorageStatus";
const statusLabels: Record<ImportRun["status"], string> = {
    get ready() {
        return tr("mf0ed39bb79");
    },
    get processing() {
        return tr("madb18ca632");
    },
    get running() {
        return tr("ma61754a579");
    },
    get completed() {
        return tr("mf92b11bb14");
    },
    get failed() {
        return tr("m6876ba004a");
    },
    get needs_review() {
        return tr("m2776b285ad");
    },
    get cancelled() {
        return tr("m2ac08a2b65");
    },
};

export function ImportsPanel({ active = true }: { active?: boolean }) {
    useLocale();
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
    useDraftGuard(
        !!(
            (!runId &&
                (name || description || artist || rows.length || manifest)) ||
            uploading
        ),
        () => {
            setName("");
            setDescription("");
            setArtist("");
            setRows([]);
            setManifest(null);
            setRunId(null);
        },
        undefined,
        true,
    );
    const [dragging, setDragging] = useState(false);
    const key = useRef(crypto.randomUUID());
    useEffect(() => {
        key.current = crypto.randomUUID();
    }, [name, description, mood, rows, manifest]);
    const assets = useQuery({
        queryKey: ["media", assetPage],
        enabled: active,
        queryFn: () =>
            api<{
                data: MediaAsset[];
                current_page: number;
                last_page: number;
            }>(`/admin/media?page=${assetPage}`),
    });
    const history = useQuery({
        queryKey: ["imports", historyPage],
        enabled: active,
        queryFn: () =>
            api<Page<ImportRun>>(`/admin/imports?page=${historyPage}`),
    });
    const run = useQuery({
        queryKey: ["import", runId],
        queryFn: () => api<{ data: ImportRun }>(`/admin/imports/${runId}`),
        enabled: active && !!runId,
        refetchInterval: (query) =>
            ["processing", "running"].includes(
                query.state.data?.data.status || "",
            ) ||
            Object.values(query.state.data?.data.report?.media ?? {}).some(
                (m) => m.status !== "ready",
            )
                ? 2000
                : false,
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
            notify(tr("m514f8f6d14"), true);
            return;
        }
        const uploadSelected = createUploadBatch();
        const errors: string[] = [];
        setUploadErrors([]);
        for (let index = 0; index < batch.length; index++) {
            const file = batch[index];
            setUploading(
                tr("m0cbba090b2", {
                    v0: digits(index + 1),
                    v1: digits(batch.length),
                    v2: file.name,
                }),
            );
            if (
                file.size >
                (/\.flac$/i.test(file.name) ? 512 : 100) * 1024 * 1024
            ) {
                errors.push(tr("ma3e43603ed", { v0: file.name }));
                continue;
            }
            try {
                const result = await uploadSelected<{
                    data: MediaAsset;
                    duplicate: boolean;
                }>(file, "audio", "/admin/media");
                addAsset(result.data);
            } catch (error) {
                errors.push(`${file.name}: ${(error as Error).message}`);
            }
        }
        setUploading("");
        setUploadErrors(errors);
        void client.invalidateQueries({ queryKey: ["media"] });
        void client.invalidateQueries({ queryKey: ["admin", "storage"] });
    }
    const requestConversion = useMutation({
        mutationFn: (asset: MediaAsset) =>
            api(`/admin/media/${asset.id}/conversions/mp3-cbr-320-v1`, {
                method: "PUT",
                body: json({
                    source_revision: asset.revision,
                    profile_version: 1,
                }),
            }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ["media"] });
            notify(tr("maf7b1df41b"));
        },
        onError: (error) => notify(error.message, true),
    });
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
    const waitingForMedia = Object.values(current?.report?.media ?? {}).some(
        (media) => media.status !== "ready",
    );
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
            <StorageStatus />
            <div className="import-steps">
                <span className={!runId ? "active" : ""}>
                    <b>{tr("m75e6376c7c")}</b>
                    {tr("m027e69e6af")}
                </span>
                <i />
                <span className={current?.status === "ready" ? "active" : ""}>
                    <b>{tr("m2fe45fb9d1")}</b>
                    {tr("m8255c29747")}
                </span>
                <i />
                <span
                    className={
                        current &&
                        [
                            "processing",
                            "running",
                            "completed",
                            "needs_review",
                            "cancelled",
                        ].includes(current.status)
                            ? "active"
                            : ""
                    }
                >
                    <b>{tr("m48d505a52b")}</b>
                    {tr("m7f803be66a")}
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
                        <h2>{tr("m8d10560fee")}</h2>
                        <p className="muted">{tr("mae73dcd3da")}</p>
                        <div className="form-grid">
                            <label>
                                {tr("ma132581369")}
                                <input
                                    dir="auto"
                                    name="name"
                                    required
                                    maxLength={160}
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder={tr("m718534747f")}
                                />
                            </label>
                            <label>
                                {tr("m7a15be7b2e")}
                                <select
                                    dir="auto"
                                    name="mood"
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
                            {tr("m18e24402ec")}
                            <textarea
                                dir="auto"
                                name="description"
                                maxLength={2000}
                                rows={2}
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder={tr("mc9acb3fbe2")}
                            />
                        </label>
                        <label>
                            {tr("m925f2ebfff")}
                            <input
                                dir="auto"
                                name="artist"
                                maxLength={255}
                                value={artist}
                                onChange={(e) => setArtist(e.target.value)}
                                placeholder={tr("m204da9414a")}
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
                            <strong>{uploading || tr("me4fb71e4af")}</strong>
                            <span>{tr("m8792fbb500")}</span>
                            <small>{tr("m67db005eaa")}</small>
                            <input
                                dir="auto"
                                aria-label={tr("m0cc6ed7f5c")}
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
                            <summary>{tr("md8903fdcd0")}</summary>
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
                                                {(/\.flac$/i.test(
                                                    asset.original_name,
                                                ) ||
                                                    asset.codec === "flac") &&
                                                    !asset.conversion && (
                                                        <button
                                                            className="text-link"
                                                            disabled={
                                                                requestConversion.isPending
                                                            }
                                                            onClick={() =>
                                                                requestConversion.mutate(
                                                                    asset,
                                                                )
                                                            }
                                                        >
                                                            {tr("m2ea5d7fe60")}
                                                        </button>
                                                    )}
                                                <IconButton
                                                    label={tr("m2a67a39b3e")}
                                                    onClick={() =>
                                                        void navigator.clipboard
                                                            .writeText(asset.id)
                                                            .then(() =>
                                                                notify(
                                                                    tr(
                                                                        "m6b83fd3699",
                                                                    ),
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
                                                    label={tr("m5750500ecb")}
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
                                            {tr("m1a592f6b2d")}
                                        </button>
                                        <span>
                                            {tr("ma69132201d", {
                                                v0: digits(assetPage),
                                            })}
                                        </span>
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
                                            {tr("m54ee927e96")}
                                        </button>
                                    </div>
                                </>
                            )}
                        </details>
                    </div>
                    {!!rows.length && (
                        <div className="admin-panel">
                            <div className="section-heading">
                                <h2>
                                    {tr("m81820db6fe", {
                                        v0: digits(rows.length),
                                    })}
                                </h2>
                                <button
                                    type="button"
                                    className="text-link"
                                    onClick={exportManifest}
                                >
                                    <Download size={16} />
                                    {tr("m1759c8079c")}
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
                                                {tr("mb7ceca95ca", {
                                                    v0: digits(index + 1),
                                                })}
                                            </span>
                                            <input
                                                dir="auto"
                                                name="title"
                                                required={!manifest}
                                                maxLength={255}
                                                value={row.title}
                                                placeholder={tr("mfedab857ca")}
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
                                                {tr("m77fd9e763e", {
                                                    v0: digits(index + 1),
                                                })}
                                            </span>
                                            <input
                                                dir="auto"
                                                name="artist"
                                                required={!manifest && !artist}
                                                maxLength={255}
                                                value={row.artist}
                                                placeholder={
                                                    artist || tr("mdb806db879")
                                                }
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
                                            label={tr("m4c7462552f", {
                                                v0: row.title,
                                            })}
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
                            <h2>{tr("m5bf3e847cd")}</h2>
                            <p>{tr("mb051259aa3")}</p>
                            <input
                                dir="auto"
                                aria-label={tr("mf4056d12f6")}
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
                                    {tr("m272a09959d")}
                                </button>
                            )}
                        </div>
                    </div>
                    <div className="form-actions">
                        <span className="muted">
                            {manifest
                                ? tr("mbe1f34ff41", { v0: manifest.name })
                                : tr("ma2d075ab3e", {
                                      v0: digits(rows.length),
                                  })}
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
                            {tr("m13af74da2a")}
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
                                <h2 dir="auto">{current.name}</h2>
                                {current.report?.media && (
                                    <div
                                        className="conversion-preview"
                                        role="status"
                                    >
                                        <h3>{tr("mb644c46d6d")}</h3>
                                        {Object.entries(
                                            current.report.media,
                                        ).map(([id, media]) => (
                                            <p key={id}>
                                                <bdi lang="en">
                                                    {media.source_codec ??
                                                        "Audio"}{" "}
                                                    · {media.playback_profile}
                                                </bdi>{" "}
                                                —{" "}
                                                {media.status === "ready"
                                                    ? tr("m1ca7bcc519")
                                                    : (conversionLabels[
                                                          media.status as keyof typeof conversionLabels
                                                      ] ?? tr("m7719150abd"))}
                                            </p>
                                        ))}
                                        {waitingForMedia && (
                                            <p>
                                                {tr("m771352f4d9")}
                                                <Link to="/admin?tab=conversions">
                                                    {tr("me45cdd8642")}
                                                </Link>
                                                {tr("m94455620cf")}
                                            </p>
                                        )}
                                    </div>
                                )}
                                <p className="muted">
                                    {tr("md51707ae7a", {
                                        v0: digits(current.rows.length),
                                        v1: moodLabels[current.mood],
                                    })}
                                </p>
                                {["processing", "running"].includes(
                                    current.status,
                                ) ? (
                                    <div>
                                        <Loading
                                            label={
                                                current.status === "running"
                                                    ? tr("mce7ca1d4ec")
                                                    : tr("m026475d2cb")
                                            }
                                        />
                                        {current.execution && (
                                            <p className="muted">
                                                {tr("mbbb32b5317", {
                                                    v0: digits(
                                                        current.execution
                                                            .generation,
                                                    ),
                                                    v1: digits(
                                                        current.execution
                                                            .recovery_count,
                                                    ),
                                                    v2:
                                                        current.execution
                                                            .heartbeat_at &&
                                                        tr("mfea207f83b", {
                                                            v0: new Intl.DateTimeFormat(
                                                                currentPreferences()
                                                                    .locale,
                                                                {
                                                                    timeZone:
                                                                        currentPreferences()
                                                                            .time_zone,
                                                                    hour: "2-digit",
                                                                    minute: "2-digit",
                                                                },
                                                            ).format(
                                                                new Date(
                                                                    current
                                                                        .execution
                                                                        .heartbeat_at,
                                                                ),
                                                            ),
                                                        }),
                                                })}
                                            </p>
                                        )}
                                    </div>
                                ) : current.status === "completed" ? (
                                    <div className="import-success">
                                        <CheckCircle2 size={46} />
                                        <h3>{tr("ma607ed7bf6")}</h3>
                                        <p>
                                            {tr("m616f7ec8db", {
                                                v0: digits(
                                                    current.report
                                                        ?.created_tracks || 0,
                                                ),
                                                v1: digits(
                                                    current.report
                                                        ?.reused_tracks || 0,
                                                ),
                                            })}
                                        </p>
                                        {!!current.report?.reused_tracks && (
                                            <p className="muted">
                                                {tr("mfa77da851d")}
                                            </p>
                                        )}
                                        <Link
                                            className="button"
                                            to={`/playlists/${current.playlist_id}`}
                                        >
                                            {tr("m4ec73aacfd")}
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
                                                {localizeText(current.error)}
                                            </p>
                                        )}
                                        <label className="checkbox-label">
                                            <input
                                                dir="auto"
                                                type="checkbox"
                                                checked={publish}
                                                onChange={(e) =>
                                                    setPublish(e.target.checked)
                                                }
                                            />
                                            {tr("m0a93f9fe26")}
                                        </label>
                                        <button
                                            className="button"
                                            disabled={
                                                commit.isPending ||
                                                (publish && waitingForMedia) ||
                                                [
                                                    "needs_review",
                                                    "cancelled",
                                                ].includes(current.status)
                                            }
                                            onClick={() => commit.mutate()}
                                        >
                                            {commit.isPending
                                                ? tr("m03e4d57d5a")
                                                : current.status === "failed"
                                                  ? tr("med6ee8fe9d")
                                                  : tr("m2c645a81fc")}
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
                                        ? tr("m1d2dd88282")
                                        : tr("m83bad639e2")}
                                </button>
                            </>
                        )
                    )}
                </div>
            )}
            <div className="section-heading history-heading">
                <h2>{tr("m43b8194c18")}</h2>
                <span>{tr("m9b21b68712")}</span>
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
                                    <strong dir="auto">{item.name}</strong>
                                    <small>
                                        {new Intl.DateTimeFormat(
                                            currentPreferences().locale,
                                            {
                                                timeZone:
                                                    currentPreferences()
                                                        .time_zone,
                                                dateStyle: "medium",
                                                timeStyle: "short",
                                            },
                                        ).format(new Date(item.created_at))}
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
                            <p className="muted">{tr("mafd1fd371d")}</p>
                        )}
                    </div>
                    {history.data.meta.last_page > 1 && (
                        <div className="pager">
                            <button
                                className="button secondary"
                                disabled={historyPage === 1}
                                onClick={() => setHistoryPage((v) => v - 1)}
                            >
                                {tr("m1a592f6b2d")}
                            </button>
                            <span>{digits(historyPage)}</span>
                            <button
                                className="button secondary"
                                disabled={
                                    historyPage === history.data.meta.last_page
                                }
                                onClick={() => setHistoryPage((v) => v + 1)}
                            >
                                {tr("m54ee927e96")}
                            </button>
                        </div>
                    )}
                </>
            )}
        </>
    );
}
