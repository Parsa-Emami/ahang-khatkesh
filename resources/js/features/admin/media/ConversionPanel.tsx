import { currentPreferences } from "../../../i18n/runtime";
import { tr } from "../../../i18n/runtime";
import { useLocale } from "../../../i18n/LocaleProvider";
import type { CursorPage } from "../contracts";
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, RefreshCw, X, AudioLines } from "lucide-react";
import { api, json } from "../../../lib/api";
import { useToast } from "../../../context/AppContext";
import { ErrorState, Loading } from "../../../components/UI";
import { StorageStatus } from "../StorageStatus";
import { digits, type AudioConversion } from "../../../types";

export const conversionLabels: Record<AudioConversion["status"], string> = {
    get queued() {
        return tr("m25b3172b3f");
    },
    get processing() {
        return tr("me73778af35");
    },
    get validating() {
        return tr("m6bd54b9c1e");
    },
    get ready() {
        return tr("m1ca7bcc519");
    },
    get failed() {
        return tr("m0fc68fa9b0");
    },
    get needs_review() {
        return tr("mf9013ae011");
    },
    get cancelled() {
        return tr("m57ab5550d8");
    },
};
const reasons: Record<string, string> = {
    get truncated_source() {
        return tr("m62c510e819");
    },
    get invalid_source() {
        return tr("m8b9b7d0d42");
    },
    get encoder_unavailable() {
        return tr("mbd1bba69af");
    },
    get encoder_build_changed() {
        return tr("ma7284efc22");
    },
    get encoder_failed() {
        return tr("m2d573379a6");
    },
    get source_changed() {
        return tr("mac23641419");
    },
    get invalid_output() {
        return tr("m42c3789178");
    },
    get non_cbr_output() {
        return tr("mdcc4fd2f05");
    },
    get access_revoked() {
        return tr("m977d9bdd97");
    },
    get cancelled_by_owner() {
        return tr("md115fa5848");
    },
    get capacity_unavailable() {
        return tr("mdc95ffa6cc");
    },
    get worker_lost() {
        return tr("me05bcaaaaf");
    },
    get timeout() {
        return tr("m38cbf28ad6");
    },
    get output_limit() {
        return tr("m6f1c69c56e");
    },
    get storage_unavailable() {
        return tr("m6e909c1847");
    },
};
export function mediaSize(bytes: number) {
    return `${new Intl.NumberFormat(currentPreferences().locale, { maximumFractionDigits: 2 }).format(bytes / 1048576)} MiB`;
}
export function ConversionPanel() {
    useLocale();
    const [status, setStatus] = useState(""),
        [cursor, setCursor] = useState("");
    const client = useQueryClient(),
        notify = useToast();
    const query = useQuery({
        queryKey: ["conversions", status, cursor],
        queryFn: () =>
            api<CursorPage<AudioConversion>>(
                `/admin/media-conversions?${new URLSearchParams({ status, cursor })}`,
            ),
        refetchInterval: 2000,
    });
    const readiness = query.data?.data
        .map((c) => `${c.id}:${c.status}`)
        .join(",");
    useEffect(() => {
        void client.invalidateQueries({ queryKey: ["admin", "storage"] });
    }, [readiness, client]);
    const mutation = useMutation({
        mutationFn: ({
            conversion: c,
            action,
        }: {
            conversion: AudioConversion;
            action: "retry" | "cancel";
        }) =>
            api(`/admin/media-conversions/${c.id}/${action}`, {
                method: "POST",
                body: json({
                    expected_generation: c.generation,
                    revision: c.revision,
                }),
            }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: ["conversions"] });
            void client.invalidateQueries({ queryKey: ["admin", "storage"] });
            void client.invalidateQueries({ queryKey: ["media"] });
        },
        onError: (error) => {
            notify(error.message, true);
            void query.refetch();
        },
    });
    return (
        <div className="conversion-workspace">
            <StorageStatus />
            <div className="conversion-intro">
                <AudioLines aria-hidden="true" size={24} />
                <div>
                    <h3>{tr("maf90cd2506")}</h3>
                    <p>{tr("mfdde907d58")}</p>
                </div>
            </div>
            <label className="conversion-filter">
                {tr("me191ee4a2e")}
                <select
                    dir="auto"
                    name="status"
                    value={status}
                    onChange={(event) => {
                        setStatus(event.target.value);
                        setCursor("");
                    }}
                >
                    <option value="">{tr("ma3f52a6cb2")}</option>
                    {Object.entries(conversionLabels).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>
            {query.isPending ? (
                <Loading />
            ) : query.isError ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : !query.data.data.length ? (
                <p role="status">{tr("m05586a0a03")}</p>
            ) : (
                <div className="conversion-list">
                    {query.data.data.map((c) => (
                        <article
                            className="conversion-card"
                            key={c.id}
                            aria-label={tr("m87f8650bcc", {
                                v0: c.source_name,
                            })}
                        >
                            <div className="conversion-heading">
                                <div>
                                    <h3 dir="auto">{c.source_name}</h3>
                                    <span lang="en" dir="ltr">
                                        FLAC → MP3 · 320 kbps CBR
                                    </span>
                                </div>
                                <strong
                                    className={`conversion-state state-${c.status}`}
                                >
                                    {c.status === "ready" && (
                                        <CheckCircle2
                                            aria-hidden="true"
                                            size={16}
                                        />
                                    )}
                                    {conversionLabels[c.status]}
                                </strong>
                            </div>
                            <label className="conversion-progress-label">
                                {tr("mcde299b648")}
                                <bdi>
                                    {tr("m834f2c877b", {
                                        v0: digits(c.progress_percent),
                                    })}
                                </bdi>
                                <progress
                                    aria-label={tr("mbece9efcc0", {
                                        v0: c.source_name,
                                    })}
                                    max={100}
                                    value={c.progress_percent}
                                />
                            </label>
                            <dl className="conversion-metrics">
                                <div>
                                    <dt>{tr("m194b94dcbb")}</dt>
                                    <dd>
                                        <bdi dir="ltr">
                                            {mediaSize(c.source_bytes)}
                                        </bdi>
                                    </dd>
                                </div>
                                <div>
                                    <dt>
                                        {c.output_bytes
                                            ? tr("ma045f6ffba")
                                            : tr("mb49c0ad12b")}
                                    </dt>
                                    <dd>
                                        <bdi dir="ltr">
                                            {mediaSize(
                                                c.output_bytes ??
                                                    c.reserved_bytes,
                                            )}
                                        </bdi>
                                    </dd>
                                </div>
                                <div>
                                    <dt>{tr("m7e1620b34f")}</dt>
                                    <dd>{digits(c.generation)}</dd>
                                </div>
                            </dl>
                            {c.report?.full_decode && (
                                <p className="conversion-validated">
                                    {tr("m8724a3b7fe")}
                                </p>
                            )}
                            {c.output_bytes !== null &&
                                c.output_bytes >= c.source_bytes && (
                                    <p>{tr("m90fb1fc91f")}</p>
                                )}
                            {c.report && (
                                <details>
                                    <summary>{tr("mf714c49732")}</summary>
                                    <p>
                                        <bdi dir="ltr">
                                            {c.report.encoder_version}
                                        </bdi>
                                    </p>
                                    <p>
                                        {tr("m6d0ae23a98", {
                                            v0: digits(
                                                c.report.validated_frames,
                                            ),
                                            v1: digits(c.profile_version),
                                        })}
                                    </p>
                                </details>
                            )}
                            {c.error_code && (
                                <p role="status">
                                    {reasons[c.error_code] ?? tr("md8d7b3ca50")}
                                </p>
                            )}
                            {c.next_retry_at && (
                                <p role="status">{tr("m9644c21dec")}</p>
                            )}
                            <div className="conversion-actions">
                                {[
                                    "failed",
                                    "needs_review",
                                    "cancelled",
                                ].includes(c.status) &&
                                    !c.next_retry_at && (
                                        <button
                                            className="button button-secondary"
                                            disabled={mutation.isPending}
                                            onClick={() =>
                                                mutation.mutate({
                                                    conversion: c,
                                                    action: "retry",
                                                })
                                            }
                                        >
                                            <RefreshCw size={16} />
                                            {tr("med6ee8fe9d")}
                                        </button>
                                    )}
                                {([
                                    "queued",
                                    "processing",
                                    "validating",
                                ].includes(c.status) ||
                                    c.next_retry_at) && (
                                    <button
                                        className="button button-secondary"
                                        disabled={mutation.isPending}
                                        onClick={() =>
                                            mutation.mutate({
                                                conversion: c,
                                                action: "cancel",
                                            })
                                        }
                                    >
                                        <X size={16} />
                                        {tr("m9f10f608f0")}
                                    </button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
            )}
            <div className="conversion-pagination">
                <button
                    className="button button-secondary"
                    disabled={!cursor}
                    onClick={() => setCursor("")}
                >
                    {tr("m60685db411")}
                </button>
                <button
                    className="button button-secondary"
                    disabled={!query.data?.meta.next_cursor}
                    onClick={() => setCursor(query.data!.meta.next_cursor!)}
                >
                    {tr("mce199fd6f9")}
                </button>
            </div>
        </div>
    );
}
