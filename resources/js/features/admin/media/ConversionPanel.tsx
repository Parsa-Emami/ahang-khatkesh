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
    queued: "در صف تبدیل",
    processing: "در حال تبدیل",
    validating: "بررسی کامل خروجی",
    ready: "آمادهٔ پخش",
    failed: "تبدیل ناموفق",
    needs_review: "نیاز به بررسی اصل فایل",
    cancelled: "لغوشده",
};
const reasons: Record<string, string> = {
    truncated_source:
        "فایل FLAC ناقص است؛ شمار نمونه‌های decodeشده با اصل اعلام‌شده همخوان نیست.",
    invalid_source: "ساختار یا STREAMINFO فایل FLAC معتبر نیست.",
    encoder_unavailable:
        "ابزار تبدیل در دسترس نیست؛ پیکربندی سرور را بررسی کنید.",
    encoder_build_changed: "نسخهٔ ابزار تبدیل با نسخهٔ تأییدشده همخوان نیست.",
    encoder_failed:
        "تبدیل انجام نشد؛ فایل اصلی و گزارش سلامت سرور را بررسی کنید.",
    source_changed:
        "اصل فایل یا مالکیت آن تغییر کرده است؛ پیش از تلاش دوباره بررسی کنید.",
    invalid_output: "خروجی اعتبارسنجی کامل را نگذرانده و منتشر نشده است.",
    non_cbr_output: "خروجی با CBR 320kbps همخوان نیست و منتشر نشده است.",
    access_revoked: "دسترسی درخواست‌کننده تغییر کرده است.",
    cancelled_by_owner: "تبدیل به درخواست سوپرادمین لغو شد.",
    capacity_unavailable:
        "ظرفیت کافی نیست؛ فضای رزروشده و پاک‌سازی را بررسی کنید.",
    worker_lost: "پردازش متوقف شده و در چرخهٔ بازیابی قرار گرفته است.",
    timeout: "مهلت پردازش تمام شد؛ فایل اصلی محفوظ است.",
    output_limit: "خروجی از فضای رزروشده بیشتر شد و منتشر نشد.",
    storage_unavailable: "نوشتن روی فضای خصوصی ممکن نیست؛ دیسک را بررسی کنید.",
};
export function mediaSize(bytes: number) {
    return `${new Intl.NumberFormat("fa-IR", { maximumFractionDigits: 2 }).format(bytes / 1048576)} MiB`;
}
export function ConversionPanel() {
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
                    <h3>اصل محفوظ، نسخهٔ سبک‌تر برای پخش</h3>
                    <p>
                        MP3 با نرخ ثابت 320kbps اتلافی است. اصل FLAC نگه داشته
                        می‌شود؛ مجموع فضای ذخیره‌سازی ممکن است بیشتر شود. ۱۰۰٪
                        فقط پس از بررسی فریم‌ها و decode کامل نمایش داده می‌شود.
                    </p>
                </div>
            </div>
            <label className="conversion-filter">
                وضعیت تبدیل
                <select
                    value={status}
                    onChange={(event) => {
                        setStatus(event.target.value);
                        setCursor("");
                    }}
                >
                    <option value="">همهٔ وضعیت‌ها</option>
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
                <p role="status">
                    تبدیلی در این فهرست نیست. FLAC را از بخش ورود موسیقی
                    بارگذاری کن.
                </p>
            ) : (
                <div className="conversion-list">
                    {query.data.data.map((c) => (
                        <article
                            className="conversion-card"
                            key={c.id}
                            aria-label={`تبدیل ${c.source_name}`}
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
                                پیشرفت تخمینی تبدیل{" "}
                                <bdi>{digits(c.progress_percent)}٪</bdi>
                                <progress
                                    aria-label={`پیشرفت تبدیل ${c.source_name}`}
                                    max={100}
                                    value={c.progress_percent}
                                />
                            </label>
                            <dl className="conversion-metrics">
                                <div>
                                    <dt>فایل اصلی</dt>
                                    <dd>
                                        <bdi dir="ltr">
                                            {mediaSize(c.source_bytes)}
                                        </bdi>
                                    </dd>
                                </div>
                                <div>
                                    <dt>
                                        {c.output_bytes
                                            ? "MP3 واقعی"
                                            : "رزرو موقت"}
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
                                    <dt>نسل پردازش</dt>
                                    <dd>{digits(c.generation)}</dd>
                                </div>
                            </dl>
                            {c.report?.full_decode && (
                                <p className="conversion-validated">
                                    بررسی فریم CBR و decode کامل موفق · اصل FLAC
                                    محفوظ
                                </p>
                            )}
                            {c.output_bytes !== null &&
                                c.output_bytes >= c.source_bytes && (
                                    <p>
                                        برای این فایل، MP3 از FLAC کوچک‌تر نشده
                                        است؛ اندازهٔ واقعی هر دو نسخه بالا مشخص
                                        است.
                                    </p>
                                )}
                            {c.report && (
                                <details>
                                    <summary>جزئیات بررسی</summary>
                                    <p>
                                        <bdi dir="ltr">
                                            {c.report.encoder_version}
                                        </bdi>
                                    </p>
                                    <p>
                                        فریم‌های CBR بررسی‌شده:{" "}
                                        {digits(c.report.validated_frames)} ·
                                        پروفایل {digits(c.profile_version)}
                                    </p>
                                </details>
                            )}
                            {c.error_code && (
                                <p role="status">
                                    {reasons[c.error_code] ??
                                        "تبدیل کامل نشد؛ فایل اصلی محفوظ است. وضعیت سرور و فایل را بررسی کنید."}
                                </p>
                            )}
                            {c.next_retry_at && (
                                <p role="status">
                                    تلاش خودکار پس از مهلت کوتاه انجام می‌شود؛
                                    حداکثر سه تلاش.
                                </p>
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
                                            تلاش دوباره
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
                                        لغو تبدیل
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
                    ابتدای فهرست
                </button>
                <button
                    className="button button-secondary"
                    disabled={!query.data?.meta.next_cursor}
                    onClick={() => setCursor(query.data!.meta.next_cursor!)}
                >
                    صفحهٔ بعد
                </button>
            </div>
        </div>
    );
}
