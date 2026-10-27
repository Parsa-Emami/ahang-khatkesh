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
    new Intl.DateTimeFormat("fa-IR", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(new Date(value));
export function OverviewPanel({ go }: { go: (value: string) => void }) {
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
        { label: "آهنگ در کاتالوگ", value: digits(d.tracks), icon: Music2 },
        {
            label: "پلی‌لیست منتشرشده",
            value: digits(d.published_playlists),
            icon: ListMusic,
        },
        {
            label: "پیش‌نویس",
            value: digits(d.draft_playlists),
            icon: ListMusic,
        },
        {
            label: d.members ? "عضو کتابخانه" : "فضای فایل‌ها",
            value: d.members
                ? `${digits(d.members.total)} از ${digits(d.members.capacity)}`
                : `${digits(Math.ceil(d.media_bytes / 1048576))} MB`,
            icon: d.members ? Users : HardDrive,
        },
    ];
    return (
        <>
            <div className="admin-welcome">
                <span className="eyebrow">
                    انتخاب امروز از اینجا شروع می‌شود
                </span>
                <h3>
                    موسیقی خوب،
                    <br />
                    با نظم بهتر.
                </h3>
                <p>
                    فایل‌ها را وارد کن، اطلاعات را مرتب کن و مجموعه را برای اعضا
                    منتشر کن.
                </p>
                <button className="button" onClick={() => go("imports")}>
                    <UploadCloud size={17} />
                    ورود موسیقی
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
                        {digits(d.processing_imports)} ایمپورت در حال ساخت و{" "}
                        {digits(d.failed_imports)} مورد نیازمند بررسی.
                    </p>
                    <button className="text-link" onClick={() => go("imports")}>
                        دیدن سابقه
                    </button>
                </div>
            )}
            <div className="admin-next-grid">
                {[
                    [
                        "01",
                        "ورود فایل و پیش‌نمایش",
                        "صوت خصوصی را آپلود کن و خطاها را پیش از ساخت ببین.",
                        "imports",
                    ],
                    [
                        "02",
                        "مرتب‌کردن کاتالوگ",
                        "هنرمند و انتشار را با شناسهٔ مشخص به آهنگ وصل کن.",
                        "catalog",
                    ],
                    [
                        "03",
                        "ساخت یک مجموعه",
                        "نام، ترتیب و کاور را انتخاب کن؛ انتشار در اختیار توست.",
                        "library",
                    ],
                ].map(([n, title, text, id]) => (
                    <button key={id} onClick={() => go(id)}>
                        <span lang="en">{n}</span>
                        <h3>{title}</h3>
                        <p>{text}</p>
                        <ArrowLeft size={18} />
                    </button>
                ))}
            </div>
            <small className="muted">
                اعداد از دیتابیس برنامه خوانده شده‌اند.{" "}
                <button
                    className="text-link"
                    onClick={() => void query.refetch()}
                >
                    به‌روزرسانی
                </button>
            </small>
        </>
    );
}
export function OperationsPanel() {
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
                <p>
                    ارتباط این درخواست با برنامه برقرار است. شمارش صف، زنده‌بودن
                    worker یا بازیابی موفق بکاپ را اثبات نمی‌کند.
                </p>
            </div>
            <dl className="operations-grid">
                {[
                    ["PHP", d.php_version],
                    ["Laravel", d.laravel_version],
                    ["دیتابیس", d.database_driver],
                    ["درایور صف", d.queue_driver],
                    ["کارهای صف", digits(d.queued_jobs)],
                    ["کارهای شکست‌خورده", digits(d.failed_jobs)],
                    [
                        "دسترس‌پذیری FFprobe",
                        d.ffprobe_available
                            ? "یافت شد"
                            : "نیاز به نصب یا تنظیم",
                    ],
                    ["زمان بررسی", date(d.checked_at)],
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
                بررسی دوباره
            </button>
            <p className="muted admin-footnote">
                مدیریت بکاپ، سرویس worker و تنظیمات سرور از راهنمای عملیات انجام
                می‌شود. هیچ فرمان سروری از این صفحه اجرا نمی‌شود.
            </p>
        </>
    );
}
export function AuditPanel() {
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
                    شناسهٔ رویداد
                    <input
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
                    از تاریخ میلادی، UTC
                    <input
                        type="date"
                        value={since}
                        onChange={(e) => {
                            setSince(e.target.value);
                            setCursor("");
                        }}
                    />
                </label>
                <label>
                    تا تاریخ میلادی، UTC
                    <input
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
            <p className="muted admin-footnote">
                رویدادها با ترتیب جدیدترین شناسه نمایش داده می‌شوند؛ رمز، کلید
                رمزساز و کد بازیابی در این فهرست نیست.
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
                    <div className="audit-timeline">
                        {query.data.data.map((event) => (
                            <article key={event.id}>
                                <div className="audit-dot" />
                                <div>
                                    <span className="audit-actor">
                                        {event.actor?.name || "عملیات سیستم"}
                                        <time dateTime={event.created_at}>
                                            {date(event.created_at)}
                                        </time>
                                    </span>
                                    <strong dir="ltr">{event.action}</strong>
                                    <p>
                                        موجودیت: {event.subject_type || "—"} ·
                                        شناسه:{" "}
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
                                        <summary>شناسهٔ پیگیری درخواست</summary>
                                        <code dir="ltr">
                                            {event.request_id ||
                                                "برای این رویداد ثبت نشده"}
                                        </code>
                                    </details>
                                </div>
                            </article>
                        ))}
                        {!query.data.data.length && (
                            <div className="empty-state">
                                <h3>رویدادی در این بازه پیدا نشد.</h3>
                                <p>
                                    فیلترها را تغییر بده یا پس از ثبت یک تغییر
                                    برگرد.
                                </p>
                            </div>
                        )}
                    </div>
                    <div className="pager">
                        <button
                            className="button secondary"
                            disabled={!cursor}
                            onClick={() => setCursor("")}
                        >
                            جدیدترین‌ها
                        </button>
                        <button
                            className="button secondary"
                            disabled={!query.data.meta.next_cursor}
                            onClick={() =>
                                setCursor(query.data.meta.next_cursor || "")
                            }
                        >
                            رویدادهای قدیمی‌تر
                        </button>
                    </div>
                </>
            )}
        </>
    );
}
