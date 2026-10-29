import { currentPreferences } from "../../i18n/runtime";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useQuery } from "@tanstack/react-query";
import { api } from "../../lib/api";
import { digits } from "../../types";
export function StorageStatus() {
    useLocale();
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
                {tr("m7bca970158")}
            </p>
        );
    if (query.error)
        return (
            <p className="form-error" role="alert">
                {tr("mc96137ba06", { v0: query.error.message })}
            </p>
        );
    const data = query.data.data;
    const size = (bytes: number) => {
        const unit =
            bytes >= 1073741824 ? "GiB" : bytes >= 1048576 ? "MiB" : "KiB";
        const value =
            bytes /
            (unit === "GiB" ? 1073741824 : unit === "MiB" ? 1048576 : 1024);
        return `${new Intl.NumberFormat(currentPreferences().locale, { maximumFractionDigits: 1 }).format(value)} ${unit}`;
    };
    return (
        <div className="storage-status" aria-label={tr("m7e4b9ecaee")}>
            <strong>{tr("m7e4b9ecaee")}</strong>
            <dl className="storage-metrics">
                {[
                    [tr("m5877b032a2"), data.used_bytes],
                    [tr("m67ae9942a7"), data.reserved_bytes],
                    [tr("mf953aa629c"), data.quota_bytes],
                    [tr("m350f53b36a"), data.available_bytes],
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
                    {tr("m44686987cd", { v0: digits(data.pending_cleanup) })}
                </p>
            )}
            {data.legacy_assets > 0 && (
                <p role="alert" className="form-error">
                    {tr("maa331124c8")}
                </p>
            )}
        </div>
    );
}
