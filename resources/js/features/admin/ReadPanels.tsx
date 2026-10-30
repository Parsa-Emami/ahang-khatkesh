import { WorkflowOverview } from "./WorkflowOverview";
import { currentPreferences } from "../../i18n/runtime";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useDeferredValue, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
    ArrowLeft,
    CheckCircle2,
    RefreshCw,
    UploadCloud,
    ListMusic,
    Music2,
    Users,
    HardDrive,
    AlertCircle,
} from "lucide-react";
import { ErrorState, Loading } from "../../components/UI";
import { digits } from "../../types";
import { adminApi, adminKeys } from "./api";
const date = (value: string) =>
    new Intl.DateTimeFormat(currentPreferences().locale, {
        timeZone: currentPreferences().time_zone,
        dateStyle: "medium",
        timeStyle: "short",
    }).format(new Date(value));
export function OverviewPanel({ go }: { go: (value: string) => void }) {
    useLocale();
    const query = useQuery({
        queryKey: adminKeys.overview,
        queryFn: adminApi.overview,
        staleTime: 15000,
    });
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    const d = query.data.data;
    const stats = [
        { label: tr("me137a4ae1c"), value: digits(d.tracks), icon: Music2 },
        {
            label: tr("m191965535a"),
            value: digits(d.published_playlists),
            icon: ListMusic,
        },
        {
            label: tr("m7d739ea1cc"),
            value: digits(d.draft_playlists),
            icon: ListMusic,
        },
        {
            label: d.members ? tr("md603d77bf4") : tr("mc9e5748665"),
            value: d.members
                ? tr("m3dadd024d7", {
                      v0: digits(d.members.total),
                      v1: digits(d.members.capacity),
                  })
                : `${digits(Math.ceil(d.media_bytes / 1048576))} MB`,
            icon: d.members ? Users : HardDrive,
        },
    ];
    return (
        <>
            <div className="admin-welcome">
                <span className="eyebrow">{tr("m9224c56280")}</span>
                <h3>
                    {tr("ma6623f4e49")}
                    <br />
                    {tr("mda3eab6147")}
                </h3>
                <p>{tr("me9c5f7ef93")}</p>
                <button className="button" onClick={() => go("imports")}>
                    <UploadCloud size={17} />
                    {tr("m32c110efdb")}
                    <ArrowLeft size={17} />
                </button>
                <div className="admin-welcome-art" aria-hidden="true">
                    <i />
                    <i />
                    <i />
                    <i />
                    <i />
                </div>
            </div>
            <div className="admin-stat-grid">
                {stats.map(({ label, value, icon: Icon }) => (
                    <div className="admin-stat" key={label}>
                        <Icon size={21} />
                        <strong>{value}</strong>
                        <span>{label}</span>
                    </div>
                ))}
            </div>
            {(d.failed_imports > 0 || d.processing_imports > 0) && (
                <div className="admin-notice">
                    <AlertCircle size={20} />
                    <p>
                        {tr("m00fd3da19e", {
                            v0: digits(d.processing_imports),
                            v1: digits(d.failed_imports),
                        })}
                    </p>
                    <button className="text-link" onClick={() => go("imports")}>
                        {tr("m315507fad6")}
                    </button>
                </div>
            )}
            <div className="admin-next-grid">
                {[
                    ["01", tr("m0746cb4186"), tr("m0a8dda41c6"), "imports"],
                    ["02", tr("m7abf13371d"), tr("m24b481aab3"), "catalog"],
                    ["03", tr("m9d5d1ceb8a"), tr("m4967bdf381"), "library"],
                ].map(([n, title, text, id]) => (
                    <button key={id} onClick={() => go(id)}>
                        <span lang="en">{n}</span>
                        <h3>{title}</h3>
                        <p>{text}</p>
                        <ArrowLeft size={18} />
                    </button>
                ))}
            </div>
            <WorkflowOverview />
            <small className="muted">
                {tr("m22303f1f45")}
                <button
                    className="text-link"
                    onClick={() => void query.refetch()}
                >
                    {tr("m915a6b85fe")}
                </button>
            </small>
        </>
    );
}
export function OperationsPanel() {
    useLocale();
    const query = useQuery({
        queryKey: adminKeys.operations,
        queryFn: adminApi.operations,
        staleTime: 0,
    });
    if (query.isPending) return <Loading />;
    if (query.error)
        return (
            <ErrorState
                error={query.error}
                retry={() => void query.refetch()}
            />
        );
    const d = query.data.data;
    return (
        <>
            <div className="admin-notice">
                <CheckCircle2 size={20} />
                <p>{tr("m0c1e8a1db7")}</p>
            </div>
            <dl className="operations-grid">
                {[
                    ["PHP", d.php_version],
                    ["Laravel", d.laravel_version],
                    [tr("me28ce058f5"), d.database_driver],
                    [tr("m4fe3d3125e"), d.queue_driver],
                    [tr("mc03c413f05"), digits(d.queued_jobs)],
                    [tr("mc541970bf1"), digits(d.failed_jobs)],
                    [
                        tr("mf3704f9f9b"),
                        d.ffprobe_available
                            ? tr("m30572438bf")
                            : tr("m8ef9e291f9"),
                    ],
                    [tr("m2fa21e0b08"), date(d.checked_at)],
                ].map(([key, value]) => (
                    <div key={key}>
                        <dt>{key}</dt>
                        <dd dir="auto">{value}</dd>
                    </div>
                ))}
            </dl>
            <button
                className="button secondary"
                disabled={query.isFetching}
                onClick={() => void query.refetch()}
            >
                <RefreshCw size={16} />
                {tr("m9551d7a298")}
            </button>
            <p className="muted admin-footnote">{tr("m9d01da0c78")}</p>
        </>
    );
}
export function AuditPanel() {
    useLocale();
    const [action, setAction] = useState("");
    const [since, setSince] = useState("");
    const [until, setUntil] = useState("");
    const [cursor, setCursor] = useState("");
    const filter = useDeferredValue(action);
    const query = useQuery({
        queryKey: adminKeys.audit(filter, since, until, cursor),
        queryFn: () => adminApi.audit(filter, since, until, cursor),
    });
    return (
        <>
            <div className="admin-filter-grid">
                <label>
                    {tr("mc730eaa490")}
                    <input
                        name="action"
                        value={action}
                        dir="ltr"
                        placeholder="playlist.updated"
                        maxLength={80}
                        onChange={(e) => {
                            setAction(e.target.value);
                            setCursor("");
                        }}
                    />
                </label>
                <label>
                    {tr("m12424763de")}
                    <input
                        dir="auto"
                        name="since"
                        type="date"
                        value={since}
                        onChange={(e) => {
                            setSince(e.target.value);
                            setCursor("");
                        }}
                    />
                </label>
                <label>
                    {tr("mb2b7ac8a0d")}
                    <input
                        dir="auto"
                        name="until"
                        type="date"
                        min={since}
                        value={until}
                        onChange={(e) => {
                            setUntil(e.target.value);
                            setCursor("");
                        }}
                    />
                </label>
            </div>
            <p className="muted admin-footnote">{tr("mf00df49715")}</p>
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : (
                <>
                    <div className="audit-timeline">
                        {query.data.data.map((event) => (
                            <article key={event.id}>
                                <div className="audit-dot" />
                                <div>
                                    <span className="audit-actor">
                                        {event.actor?.name || tr("m09b06da6a1")}
                                        <time dateTime={event.created_at}>
                                            {date(event.created_at)}
                                        </time>
                                    </span>
                                    <strong dir="ltr">{event.action}</strong>
                                    <p>
                                        {tr("m46f03d4903", {
                                            v0: event.subject_type || "—",
                                        })}
                                        <bdi>{event.subject_id || "—"}</bdi>
                                    </p>
                                    {Object.keys(event.metadata).length > 0 && (
                                        <dl className="audit-meta">
                                            {Object.entries(event.metadata).map(
                                                ([key, value]) => (
                                                    <div key={key}>
                                                        <dt>{key}</dt>
                                                        <dd>{String(value)}</dd>
                                                    </div>
                                                ),
                                            )}
                                        </dl>
                                    )}
                                    <details>
                                        <summary>{tr("me5d8b54730")}</summary>
                                        <code dir="ltr">
                                            {event.request_id ||
                                                tr("mbb9d347b85")}
                                        </code>
                                    </details>
                                </div>
                            </article>
                        ))}
                        {!query.data.data.length && (
                            <div className="empty-state">
                                <h3>{tr("m7f970348db")}</h3>
                                <p>{tr("mce806c7ebb")}</p>
                            </div>
                        )}
                    </div>
                    <div className="pager">
                        <button
                            className="button secondary"
                            disabled={!cursor}
                            onClick={() => setCursor("")}
                        >
                            {tr("m3b88aec624")}
                        </button>
                        <button
                            className="button secondary"
                            disabled={!query.data.meta.next_cursor}
                            onClick={() =>
                                setCursor(query.data.meta.next_cursor || "")
                            }
                        >
                            {tr("mecbf1930d6")}
                        </button>
                    </div>
                </>
            )}
        </>
    );
}
