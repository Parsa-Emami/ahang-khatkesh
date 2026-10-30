import { useDeferredValue, useState } from "react";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { Dialog, ErrorState, Loading } from "../../components/UI";
import { api, captureSessionIntent } from "../../lib/api";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { type Page, type MediaAsset } from "../../types";
import { useDraftGuard } from "../drafts/DraftNavigation";
import { previewChange, type Change } from "../catalog/workflows";
import { ChangeReview } from "../catalog/ChangeReview";
import { Pager } from "../catalog/Pager";
import { createUploadBatch } from "./uploads";
type MediaPage = {
    data: MediaAsset[];
    current_page: number;
    last_page: number;
};
type Health = {
    id: number;
    title: string;
    revision: number;
    recording_identity: string | null;
    recording_provenance: string | null;
    source_exists: boolean;
    source_ready: boolean;
    issues: string[];
    identity_matches: { id: number; title: string; revision: number }[];
    title_matches: { id: number; title: string }[];
    bytes_matches: { id: number; title: string }[];
    candidates_truncated: boolean;
};
export function CatalogHealthPanel() {
    useLocale();
    const [q, setQ] = useState("");
    const [page, setPage] = useState(1);
    const [editor, setEditor] = useState<{
        row: Health;
        kind: "merge" | "repair";
    } | null>(null);
    const term = useDeferredValue(q);
    const query = useQuery({
        queryKey: ["admin", "catalog-health", term, page],
        queryFn: ({ signal }) =>
            api<Page<Health>>(
                `/admin/catalog-health?${new URLSearchParams({ q: term, page: String(page) })}`,
                { signal },
            ),
    });
    return (
        <div className="catalog-workflow">
            <p>{tr("catalog.healthHelp")}</p>
            <label>
                {tr("catalog.findTrack")}
                <input
                    dir="auto"
                    value={q}
                    maxLength={120}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setPage(1);
                    }}
                />
            </label>
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : (
                <>
                    <div className="catalog-health-list">
                        {query.data.data.map((row) => (
                            <article key={row.id}>
                                <h3 dir="auto">
                                    {row.title} · #{row.id}
                                </h3>
                                <p>
                                    {tr(
                                        row.source_exists && row.source_ready
                                            ? "catalog.sourceHealthy"
                                            : "catalog.sourceMissing",
                                    )}
                                </p>
                                {row.identity_matches.length > 0 && (
                                    <p>
                                        {tr("catalog.identityMatches", {
                                            count: row.identity_matches.length,
                                        })}
                                    </p>
                                )}
                                {(row.title_matches.length > 0 ||
                                    row.bytes_matches.length > 0) && (
                                    <p className="muted">
                                        {tr("catalog.advisory", {
                                            titles: row.title_matches.length,
                                            bytes: row.bytes_matches.length,
                                        })}
                                    </p>
                                )}
                                {row.candidates_truncated && (
                                    <p>{tr("catalog.truncated")}</p>
                                )}
                                <div className="catalog-actions">
                                    <button
                                        className="button secondary"
                                        disabled={
                                            row.identity_matches.length === 0
                                        }
                                        onClick={() =>
                                            setEditor({ row, kind: "merge" })
                                        }
                                    >
                                        {tr("catalog.reviewMerge")}
                                    </button>
                                    <button
                                        className="button secondary"
                                        disabled={
                                            !row.recording_identity ||
                                            !row.recording_provenance
                                        }
                                        onClick={() =>
                                            setEditor({ row, kind: "repair" })
                                        }
                                    >
                                        {tr("catalog.reviewRepair")}
                                    </button>
                                </div>
                                {!row.recording_identity && (
                                    <p className="muted">
                                        {tr("catalog.assignIdentityFirst")}
                                    </p>
                                )}
                            </article>
                        ))}
                        {query.data.data.length === 0 && (
                            <p>{tr("catalog.noTracks")}</p>
                        )}
                    </div>
                    <Pager
                        page={page}
                        pages={query.data.meta.last_page}
                        setPage={setPage}
                    />
                </>
            )}
            {editor && (
                <Dialog
                    open
                    onOpenChange={(value) => {
                        if (!value) setEditor(null);
                    }}
                    title={tr(
                        editor.kind === "merge"
                            ? "catalog.merge"
                            : "catalog.repair",
                    )}
                    description={tr("catalog.confirmIdentityHelp")}
                >
                    <HealthEditor
                        key={`${editor.kind}:${editor.row.id}`}
                        row={editor.row}
                        kind={editor.kind}
                    />
                </Dialog>
            )}
        </div>
    );
}
function HealthEditor({
    row,
    kind,
}: {
    row: Health;
    kind: "merge" | "repair";
}) {
    const [identity, setIdentity] = useState("");
    const [provenance, setProvenance] = useState("");
    const [ids, setIds] = useState<number[]>([]);
    const [asset, setAsset] = useState<MediaAsset | null>(null);
    const [page, setPage] = useState(1);
    const [review, setReview] = useState<Change | null>(null);
    const assets = useQuery({
        queryKey: ["admin", "repair-assets", page],
        queryFn: ({ signal }) =>
            api<MediaPage>(`/admin/media?page=${page}`, { signal }),
        enabled: kind === "repair",
    });
    const upload = useMutation({
        mutationFn: ({
            file,
            send,
        }: {
            file: File;
            send: ReturnType<typeof createUploadBatch>;
        }) =>
            send<{ data: MediaAsset }>(file, "audio", "/admin/media", {
                reuse_existing: "0",
            }),
        onSuccess: ({ data }) => {
            setAsset(data);
            void assets.refetch();
        },
    });
    const preview = useMutation({
        mutationFn: previewChange,
        onSuccess: ({ data }) => setReview(data),
    });
    useDraftGuard(
        !!identity || !!provenance || ids.length > 0 || !!asset,
        () => {
            setIdentity("");
            setProvenance("");
            setIds([]);
            setAsset(null);
        },
    );
    const input =
        kind === "merge"
            ? {
                  operation: kind,
                  canonical_id: row.id,
                  canonical_revision: row.revision,
                  source_ids: ids,
                  recording_identity: identity,
                  recording_provenance: provenance,
              }
            : {
                  operation: kind,
                  track_id: row.id,
                  revision: row.revision,
                  asset_id: asset?.id,
                  recording_identity: identity,
                  recording_provenance: provenance,
              };
    const ready =
        identity === row.recording_identity &&
        provenance === row.recording_provenance &&
        (kind === "merge" ? ids.length > 0 : !!asset);
    return (
        <div className="catalog-workflow">
            <p dir="auto">
                <strong>
                    {row.title} · #{row.id}
                </strong>
            </p>
            <dl>
                <dt>{tr("catalog.identity")}</dt>
                <dd dir="ltr">{row.recording_identity}</dd>
                <dt>{tr("catalog.provenance")}</dt>
                <dd dir="auto">{row.recording_provenance}</dd>
            </dl>
            {kind === "merge" ? (
                <>
                    <p>{tr("catalog.mergeHelp")}</p>
                    {row.identity_matches.map((item) => (
                        <label className="checkbox-label" key={item.id}>
                            <input
                                type="checkbox"
                                checked={ids.includes(item.id)}
                                onChange={(e) =>
                                    setIds((current) =>
                                        e.target.checked
                                            ? [...current, item.id]
                                            : current.filter(
                                                  (id) => id !== item.id,
                                              ),
                                    )
                                }
                            />
                            <span dir="auto">
                                {item.title} · #{item.id}
                            </span>
                        </label>
                    ))}
                </>
            ) : (
                <>
                    <p>{tr("catalog.repairHelp")}</p>
                    <label>
                        {tr("catalog.uploadReplacement")}
                        <input
                            aria-label={tr("catalog.uploadReplacement")}
                            type="file"
                            accept="audio/*,.flac,.wav,.mp3,.m4a,.ogg"
                            disabled={upload.isPending}
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file)
                                    upload.mutate({
                                        file,
                                        send: createUploadBatch(),
                                    });
                            }}
                        />
                    </label>
                    {upload.isPending && <Loading />}
                    {upload.error && <p role="alert">{upload.error.message}</p>}
                    <label>
                        {tr("catalog.selectReplacement")}
                        <select
                            aria-label={tr("catalog.selectReplacement")}
                            value={asset?.id ?? ""}
                            onChange={(e) =>
                                setAsset(
                                    assets.data?.data.find(
                                        (item) => item.id === e.target.value,
                                    ) ?? null,
                                )
                            }
                        >
                            <option value="">{tr("catalog.select")}</option>
                            {asset &&
                                !assets.data?.data.some(
                                    (item) => item.id === asset.id,
                                ) && (
                                    <option value={asset.id}>
                                        {asset.original_name}
                                    </option>
                                )}
                            {assets.data?.data.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.original_name}
                                </option>
                            ))}
                        </select>
                    </label>
                    {assets.error && (
                        <ErrorState
                            error={assets.error}
                            retry={() => void assets.refetch()}
                        />
                    )}
                    {assets.data && (
                        <Pager
                            page={page}
                            pages={assets.data.last_page}
                            setPage={setPage}
                        />
                    )}
                    <Link className="text-link" to="/admin?tab=conversions">
                        {tr("catalog.conversionHelp")}
                    </Link>
                    <button
                        className="button secondary"
                        onClick={() => void assets.refetch()}
                    >
                        {tr("catalog.refresh")}
                    </button>
                </>
            )}
            <label>
                {tr("catalog.confirmIdentity")}
                <input
                    dir="ltr"
                    value={identity}
                    maxLength={160}
                    onChange={(e) => setIdentity(e.target.value)}
                />
            </label>
            <label>
                {tr("catalog.confirmProvenance")}
                <input
                    dir="auto"
                    value={provenance}
                    maxLength={160}
                    onChange={(e) => setProvenance(e.target.value)}
                />
            </label>
            {preview.error && <p role="alert">{preview.error.message}</p>}
            <button
                className="button"
                disabled={!ready || preview.isPending || upload.isPending}
                onClick={() => preview.mutate(captureSessionIntent(input))}
            >
                {tr("catalog.preview")}
            </button>
            {review && (
                <ChangeReview
                    key={review.id}
                    change={review}
                    close={() => setReview(null)}
                    changed={() => {
                        setIds([]);
                        setIdentity("");
                        setProvenance("");
                        setAsset(null);
                    }}
                />
            )}
        </div>
    );
}
