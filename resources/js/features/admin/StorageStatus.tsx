import { useQuery } from "@tanstack/react-query";
import { api } from "../../lib/api";
import { digits } from "../../types";
export function StorageStatus() {
    const query = useQuery({
        queryKey: ["admin", "storage"],
        queryFn: () =>
            api<{
                data: {
                    used_bytes: number;
                    reserved_bytes: number;
                    quota_bytes: number;
                    available_bytes: number;
                    inventory_ready: boolean;
                    legacy_assets: number;
                    pending_cleanup: number;
                };
            }>("/admin/storage"),
        refetchInterval: 15000,
    });
    if (query.isPending)
        return (
            <p className="muted" role="status">
                در حال بررسی فضای کتابخانه…
            </p>
        );
    if (query.error)
        return (
            <p className="form-error" role="alert">
                وضعیت فضا دریافت نشد: {query.error.message}
            </p>
        );
    const data = query.data.data;
    const size = (bytes: number) => {
        const unit =
            bytes >= 1073741824 ? "GiB" : bytes >= 1048576 ? "MiB" : "KiB";
        const value =
            bytes /
            (unit === "GiB" ? 1073741824 : unit === "MiB" ? 1048576 : 1024);
        return `${new Intl.NumberFormat("fa-IR", { maximumFractionDigits: 1 }).format(value)} ${unit}`;
    };
    return (
        <div className="storage-status" aria-label="فضای کتابخانه">
            <strong>فضای کتابخانه</strong>
            <dl className="storage-metrics">
                {[
                    ["مصرف‌شده", data.used_bytes],
                    ["رزرو", data.reserved_bytes],
                    ["ظرفیت", data.quota_bytes],
                    ["قابل رزرو", data.available_bytes],
                ].map(([label, value]) => (
                    <div key={label}>
                        <dt>{label}</dt>
                        <dd>
                            <bdi dir="ltr">{size(Number(value))}</bdi>
                        </dd>
                    </div>
                ))}
            </dl>
            {data.pending_cleanup > 0 && (
                <p className="muted">
                    {digits(data.pending_cleanup)} آپلود در انتظار آزادسازی
                    فضاست.
                </p>
            )}
            {data.legacy_assets > 0 && (
                <p role="alert" className="form-error">
                    رسانه‌های قدیمی باید پیش از آپلود تازه توسط اپراتور بررسی و
                    منتقل شوند.
                </p>
            )}
        </div>
    );
}
