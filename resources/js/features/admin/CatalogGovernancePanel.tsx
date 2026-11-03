import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ErrorState, Loading } from "../../components/UI";
import { api, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";
import { digits } from "../../types";
import { adminKeys } from "./api";

type Finding = {
    kind: string;
    reason: string;
    track_ids: number[];
    artist_ids?: number[];
    evidence: Record<string, unknown>;
};
type Issue = {
    code: string;
    track_id: number;
    detail: Record<string, unknown>;
};
type Proposal = {
    id: string;
    kind: string;
    status: string;
    summary: string;
    revision: number;
    proposed_by?: { id: number; name: string };
};
type Batch = {
    id: string;
    kind: string;
    status: string;
    plan_hash: string;
    result?: { effects?: unknown[] } | null;
};

export function CatalogGovernancePanel() {
    useLocale();
    const notify = useToast();
    const client = useQueryClient();
    const [view, setView] = useState<
        "workflows" | "duplicates" | "health" | "proposals" | "bulk"
    >("workflows");
    const [title, setTitle] = useState("");
    const [trackId, setTrackId] = useState("");
    const [revision, setRevision] = useState("0");
    const [batch, setBatch] = useState<Batch | null>(null);
    const workflows = useQuery({
        queryKey: [...adminKeys.root, "workflows"],
        queryFn: () =>
            api<{ data: Record<string, Record<string, unknown>> }>(
                "/admin/workflows",
            ),
    });
    const duplicates = useQuery({
        queryKey: [...adminKeys.root, "duplicates"],
        queryFn: () =>
            api<{ data: { findings: Finding[] } }>("/admin/catalog-duplicates"),
        enabled: view === "duplicates",
    });
    const health = useQuery({
        queryKey: [...adminKeys.root, "health"],
        queryFn: () =>
            api<{ data: { issues: Issue[] } }>("/admin/catalog-health"),
        enabled: view === "health",
    });
    const proposals = useQuery({
        queryKey: [...adminKeys.root, "proposals", "pending"],
        queryFn: () =>
            api<{ data: Proposal[] }>(
                `/admin/proposals?${new URLSearchParams({ status: "pending" })}`,
            ),
        enabled: view === "proposals",
    });
    const preview = useMutation({
        mutationFn: () =>
            api<{ data: Batch }>("/admin/catalog-batches", {
                method: "POST",
                body: json({
                    kind: "track_metadata",
                    changes: [
                        {
                            track_id: Number(trackId),
                            revision: Number(revision),
                            title: title.trim(),
                        },
                    ],
                }),
            }),
        onSuccess: ({ data }) => {
            setBatch(data);
            notify(tr("catalog.bulk.previewReady"));
        },
        onError: (error: Error) => notify(error.message, true),
    });
    const commit = useMutation({
        mutationFn: (current: Batch) =>
            api<{ data: Batch }>(
                `/admin/catalog-batches/${current.id}/commit`,
                {
                    method: "POST",
                    body: json({ plan_hash: current.plan_hash }),
                },
            ),
        onSuccess: ({ data }) => {
            setBatch(data);
            notify(tr("catalog.bulk.committed"));
            void client.invalidateQueries({ queryKey: adminKeys.root });
        },
        onError: (error: Error) => notify(error.message, true),
    });
    const rollback = useMutation({
        mutationFn: (current: Batch) =>
            api<{ data: Batch }>(
                `/admin/catalog-batches/${current.id}/rollback`,
                {
                    method: "POST",
                    body: json({}),
                },
            ),
        onSuccess: ({ data }) => {
            setBatch(data);
            notify(tr("catalog.bulk.rolledBack"));
            void client.invalidateQueries({ queryKey: adminKeys.root });
        },
        onError: (error: Error) => notify(error.message, true),
    });
    const review = useMutation({
        mutationFn: ({
            id,
            revision,
            action,
        }: {
            id: string;
            revision: number;
            action: "accept" | "reject";
        }) =>
            api<{ data: Proposal }>(`/admin/proposals/${id}/${action}`, {
                method: "POST",
                body: json({ revision }),
            }),
        onSuccess: () => {
            notify(tr("catalog.proposals.reviewed"));
            void client.invalidateQueries({
                queryKey: [...adminKeys.root, "proposals"],
            });
            void client.invalidateQueries({
                queryKey: [...adminKeys.root, "workflows"],
            });
        },
        onError: (error: Error) => notify(error.message, true),
    });
    const tabs = useMemo(
        () =>
            [
                ["workflows", tr("catalog.workflows")],
                ["duplicates", tr("catalog.duplicates")],
                ["health", tr("catalog.health")],
                ["proposals", tr("catalog.proposals")],
                ["bulk", tr("catalog.bulk")],
            ] as const,
        [],
    );
    return (
        <>
            <div className="filter-chips" aria-label={tr("catalog.governance")}>
                {tabs.map(([id, label]) => (
                    <button
                        key={id}
                        aria-pressed={view === id}
                        className={view === id ? "selected" : ""}
                        onClick={() => setView(id)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <p className="muted admin-footnote">
                {tr("catalog.governanceHelp")}
            </p>
            {view === "workflows" &&
                (workflows.isPending ? (
                    <Loading />
                ) : workflows.error ? (
                    <ErrorState
                        error={workflows.error}
                        retry={() => void workflows.refetch()}
                    />
                ) : (
                    <div className="admin-stat-grid">
                        {Object.entries(workflows.data.data).map(
                            ([name, value]) => (
                                <div className="admin-stat" key={name}>
                                    <strong dir="ltr">{name}</strong>
                                    <span>
                                        {typeof value.pending_proposals ===
                                        "number"
                                            ? tr("catalog.pendingCount", {
                                                  count: digits(
                                                      value.pending_proposals,
                                                  ),
                                              })
                                            : typeof value.tracks === "number"
                                              ? tr("catalog.trackCount", {
                                                    count: digits(value.tracks),
                                                })
                                              : tr("catalog.nextAction", {
                                                    next: String(
                                                        value.next ?? "",
                                                    ),
                                                })}
                                    </span>
                                </div>
                            ),
                        )}
                    </div>
                ))}
            {view === "duplicates" &&
                (duplicates.isPending ? (
                    <Loading />
                ) : duplicates.error ? (
                    <ErrorState
                        error={duplicates.error}
                        retry={() => void duplicates.refetch()}
                    />
                ) : (
                    <ul className="admin-list">
                        {duplicates.data.data.findings.map((finding, index) => (
                            <li key={`${finding.kind}-${index}`}>
                                <strong dir="ltr">{finding.kind}</strong>
                                <span dir="ltr">{finding.reason}</span>
                                <small dir="ltr">
                                    tracks:{" "}
                                    {finding.track_ids.join(", ") || "—"}
                                    {finding.artist_ids
                                        ? ` · artists: ${finding.artist_ids.join(", ")}`
                                        : ""}
                                </small>
                            </li>
                        ))}
                        {!duplicates.data.data.findings.length && (
                            <p className="muted">
                                {tr("catalog.duplicatesEmpty")}
                            </p>
                        )}
                    </ul>
                ))}
            {view === "health" &&
                (health.isPending ? (
                    <Loading />
                ) : health.error ? (
                    <ErrorState
                        error={health.error}
                        retry={() => void health.refetch()}
                    />
                ) : (
                    <ul className="admin-list">
                        {health.data.data.issues.map((issue, index) => (
                            <li
                                key={`${issue.code}-${issue.track_id}-${index}`}
                            >
                                <strong dir="ltr">{issue.code}</strong>
                                <span dir="ltr">#{issue.track_id}</span>
                            </li>
                        ))}
                        {!health.data.data.issues.length && (
                            <p className="muted">{tr("catalog.healthEmpty")}</p>
                        )}
                    </ul>
                ))}
            {view === "proposals" &&
                (proposals.isPending ? (
                    <Loading />
                ) : proposals.error ? (
                    <ErrorState
                        error={proposals.error}
                        retry={() => void proposals.refetch()}
                    />
                ) : (
                    <ul className="admin-list">
                        {proposals.data.data.map((proposal) => (
                            <li key={proposal.id}>
                                <strong dir="auto">{proposal.summary}</strong>
                                <span>
                                    {proposal.proposed_by?.name ?? "—"} ·{" "}
                                    <bdi>{proposal.kind}</bdi>
                                </span>
                                <div className="admin-toolbar">
                                    <button
                                        className="button"
                                        disabled={review.isPending}
                                        onClick={() =>
                                            review.mutate({
                                                id: proposal.id,
                                                revision: proposal.revision,
                                                action: "accept",
                                            })
                                        }
                                    >
                                        {tr("catalog.proposals.accept")}
                                    </button>
                                    <button
                                        className="button ghost"
                                        disabled={review.isPending}
                                        onClick={() =>
                                            review.mutate({
                                                id: proposal.id,
                                                revision: proposal.revision,
                                                action: "reject",
                                            })
                                        }
                                    >
                                        {tr("catalog.proposals.reject")}
                                    </button>
                                </div>
                            </li>
                        ))}
                        {!proposals.data.data.length && (
                            <p className="muted">
                                {tr("catalog.proposalsEmpty")}
                            </p>
                        )}
                    </ul>
                ))}
            {view === "bulk" && (
                <div
                    className="admin-toolbar"
                    style={{ flexDirection: "column", alignItems: "stretch" }}
                >
                    <label>
                        {tr("catalog.bulk.trackId")}
                        <input
                            dir="ltr"
                            inputMode="numeric"
                            value={trackId}
                            onChange={(event) => setTrackId(event.target.value)}
                        />
                    </label>
                    <label>
                        {tr("catalog.bulk.title")}
                        <input
                            dir="auto"
                            maxLength={255}
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                        />
                    </label>
                    <label>
                        {tr("catalog.bulk.revision")}
                        <input
                            dir="ltr"
                            inputMode="numeric"
                            value={revision}
                            onChange={(event) =>
                                setRevision(event.target.value)
                            }
                        />
                    </label>
                    <div className="admin-toolbar">
                        <button
                            className="button"
                            disabled={
                                preview.isPending || !trackId || !title.trim()
                            }
                            onClick={() => preview.mutate()}
                        >
                            {tr("catalog.bulk.preview")}
                        </button>
                        <button
                            className="button"
                            disabled={
                                !batch ||
                                batch.status !== "previewed" ||
                                commit.isPending
                            }
                            onClick={() => batch && commit.mutate(batch)}
                        >
                            {tr("catalog.bulk.commit")}
                        </button>
                        <button
                            className="button ghost"
                            disabled={
                                !batch ||
                                batch.status !== "committed" ||
                                rollback.isPending
                            }
                            onClick={() => batch && rollback.mutate(batch)}
                        >
                            {tr("catalog.bulk.rollback")}
                        </button>
                    </div>
                    {batch && (
                        <p className="muted" dir="ltr">
                            {batch.status} · {batch.id} · effects{" "}
                            {Array.isArray(batch.result?.effects)
                                ? batch.result.effects.length
                                : 0}
                        </p>
                    )}
                </div>
            )}
        </>
    );
}
