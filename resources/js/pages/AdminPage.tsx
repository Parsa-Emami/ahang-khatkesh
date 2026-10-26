import { useSearchParams } from "react-router-dom";
import { ImportsPanel } from "./admin/ImportsPanel";
import { MembersPanel } from "./admin/MembersPanel";
import { ManageLibraryPanel } from "./admin/ManageLibraryPanel";

export function AdminPage() {
    const [params, setParams] = useSearchParams();
    const tab = params.get("tab") || "imports";
    return (
        <div className="page-enter admin-page">
            <div className="page-intro">
                <div>
                    <span className="eyebrow">THE CURATOR’S DESK</span>
                    <h1>پشت صحنهٔ خط‌کش</h1>
                    <p>یک مجموعهٔ خوب، از یک انتخاب خوب شروع می‌شود.</p>
                </div>
                <span className="admin-badge">مدیریت خصوصی</span>
            </div>
            <nav className="admin-tabs" aria-label="بخش‌های مدیریت">
                {[
                    ["imports", "ورود پلی‌لیست"],
                    ["library", "مدیریت مجموعه‌ها"],
                    ["users", "اعضای کتابخانه"],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        className={tab === key ? "selected" : ""}
                        aria-current={tab === key ? "page" : undefined}
                        onClick={() => setParams({ tab: key })}
                    >
                        {label}
                    </button>
                ))}
            </nav>
            {tab === "users" ? (
                <MembersPanel />
            ) : tab === "library" ? (
                <ManageLibraryPanel />
            ) : (
                <ImportsPanel />
            )}
        </div>
    );
}
