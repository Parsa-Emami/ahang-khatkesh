import "../../../css/admin.css";
import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import {
    LayoutDashboard,
    UploadCloud,
    ListMusic,
    Users,
    Fingerprint,
    Activity,
    ShieldCheck,
    Disc3,
    ArrowLeft,
} from "lucide-react";
import { can, type Permission } from "../../types";
import { useSession } from "../../context/AppContext";
import { ImportsPanel } from "./ImportsPanel";
import { MembersPanel } from "./MembersPanel";
import { ManageLibraryPanel } from "./ManageLibraryPanel";
import { OverviewPanel, OperationsPanel, AuditPanel } from "./ReadPanels";
import { CatalogPanel } from "./CatalogPanel";
import { MfaPanel } from "../security/MfaPanel";
const sections: {
    id: string;
    label: string;
    description: string;
    permission: Permission;
    icon: typeof Users;
}[] = [
    {
        id: "overview",
        label: "نمای کلی",
        description: "نبض کتابخانه",
        permission: "dashboard.read",
        icon: LayoutDashboard,
    },
    {
        id: "imports",
        label: "ورود موسیقی",
        description: "از فایل تا پلی‌لیست",
        permission: "imports.manage",
        icon: UploadCloud,
    },
    {
        id: "library",
        label: "پلی‌لیست‌ها",
        description: "انتخاب، ترتیب و انتشار",
        permission: "catalog.manage",
        icon: ListMusic,
    },
    {
        id: "catalog",
        label: "کاتالوگ",
        description: "هنرمند، انتشار و آهنگ",
        permission: "catalog.manage",
        icon: Disc3,
    },
    {
        id: "users",
        label: "اعضا و دسترسی",
        description: "کتابخانهٔ ده‌نفره",
        permission: "users.manage",
        icon: Users,
    },
    {
        id: "audit",
        label: "رویدادها",
        description: "رد تغییرات مدیریتی",
        permission: "audit.read",
        icon: Fingerprint,
    },
    {
        id: "operations",
        label: "وضعیت سیستم",
        description: "زیرساخت و صف",
        permission: "operations.read",
        icon: Activity,
    },
    {
        id: "security",
        label: "امنیت حساب",
        description: "رمزساز و بازیابی",
        permission: "admin.access",
        icon: ShieldCheck,
    },
];
export default function AdminPage() {
    const { user } = useSession();
    const [params, setParams] = useSearchParams();
    const [enrollment, setEnrollment] = useState(!user?.mfa_enabled);
    const visible = sections.filter((s) => can(user, s.permission));
    const active =
        visible.find((s) => s.id === params.get("tab")) || visible[0];
    const go = (tab: string) => setParams({ tab });
    if (!active) return null;
    return (
        <div className="page-enter admin-workspace">
            <header className="admin-title">
                <div>
                    <span className="eyebrow" lang="en">
                        THE CURATOR’S DESK / 02
                    </span>
                    <h1>پشت صحنهٔ خط‌کش</h1>
                    <p>هر انتخاب، بخشی از صدای این کتابخانه است.</p>
                </div>
                <span className="admin-role">
                    <ShieldCheck size={16} />
                    {user?.role_label}
                </span>
            </header>
            {enrollment || !user?.mfa_enabled ? (
                <div className="admin-panel enrollment-panel">
                    <span className="eyebrow">پیش از اولین تغییر</span>
                    <h2>یک قفل دیگر، برای انتخاب‌های تو.</h2>
                    <p>
                        مدیریت کتابخانه به تأیید دومرحله‌ای نیاز دارد. فعال‌سازی
                        را کامل کن و کدهای بازیابی را ذخیره کن.
                    </p>
                    <MfaPanel onReady={() => setEnrollment(false)} />
                </div>
            ) : (
                <div className="admin-workspace-grid">
                    <nav className="admin-nav" aria-label="بخش‌های مدیریت">
                        {visible.map(
                            ({ id, label, description, icon: Icon }) => (
                                <button
                                    key={id}
                                    aria-current={
                                        active.id === id ? "page" : undefined
                                    }
                                    className={
                                        active.id === id ? "selected" : ""
                                    }
                                    onClick={() => go(id)}
                                >
                                    <Icon size={19} />
                                    <span>
                                        <strong>{label}</strong>
                                        <small>{description}</small>
                                    </span>
                                    <ArrowLeft size={15} />
                                </button>
                            ),
                        )}
                        <p>
                            فضای خصوصی، انتخاب‌های دقیق.
                            <br />
                            AHANG KHATKESH
                        </p>
                    </nav>
                    <section
                        className="admin-stage"
                        aria-label={active.label}
                        key={active.id}
                    >
                        <div className="admin-section-title">
                            <span className="eyebrow" lang="en">
                                LIBRARY CONTROL
                            </span>
                            <h2>{active.label}</h2>
                            <p>{active.description}</p>
                        </div>
                        {active.id === "overview" ? (
                            <OverviewPanel go={go} />
                        ) : active.id === "imports" ? (
                            <ImportsPanel />
                        ) : active.id === "library" ? (
                            <ManageLibraryPanel />
                        ) : active.id === "catalog" ? (
                            <CatalogPanel />
                        ) : active.id === "users" ? (
                            <MembersPanel />
                        ) : active.id === "audit" ? (
                            <AuditPanel />
                        ) : active.id === "operations" ? (
                            <OperationsPanel />
                        ) : (
                            <MfaPanel />
                        )}
                    </section>
                </div>
            )}
        </div>
    );
}
