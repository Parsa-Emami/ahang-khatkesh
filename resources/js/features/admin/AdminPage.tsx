import { roleLabel } from "../../i18n/runtime";
import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
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
    Trash2,
    AudioLines,
} from "lucide-react";
import { can, type Permission } from "../../types";
import { useSession } from "../../context/AppContext";
import { ConversionPanel } from "./media/ConversionPanel";
import { ImportsPanel } from "./ImportsPanel";
import { MembersPanel } from "./MembersPanel";
import { ManageLibraryPanel } from "./ManageLibraryPanel";
import { OverviewPanel, OperationsPanel, AuditPanel } from "./ReadPanels";
import { TrashPanel } from "./TrashPanel";
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
        get label() {
            return tr("mcdcef6d04a");
        },
        get description() {
            return tr("m97802422f4");
        },
        permission: "dashboard.read",
        icon: LayoutDashboard,
    },
    {
        id: "imports",
        get label() {
            return tr("m32c110efdb");
        },
        get description() {
            return tr("m82c61b824d");
        },
        permission: "imports.manage",
        icon: UploadCloud,
    },
    {
        id: "conversions",
        get label() {
            return tr("me45cdd8642");
        },
        get description() {
            return tr("m5998577cb9");
        },
        permission: "media.manage",
        icon: AudioLines,
    },
    {
        id: "library",
        get label() {
            return tr("m810b71a913");
        },
        get description() {
            return tr("m52d367f294");
        },
        permission: "catalog.manage",
        icon: ListMusic,
    },
    {
        id: "catalog",
        get label() {
            return tr("m83f8b96a07");
        },
        get description() {
            return tr("m4dec42d7ad");
        },
        permission: "catalog.manage",
        icon: Disc3,
    },
    {
        id: "trash",
        get label() {
            return tr("m33a33be876");
        },
        get description() {
            return tr("mfe0ef858b3");
        },
        permission: "catalog.manage",
        icon: Trash2,
    },
    {
        id: "users",
        get label() {
            return tr("mc50f3a13b6");
        },
        get description() {
            return tr("me9a079b15f");
        },
        permission: "users.manage",
        icon: Users,
    },
    {
        id: "audit",
        get label() {
            return tr("m8d1d1dfc1f");
        },
        get description() {
            return tr("m48186cf1d8");
        },
        permission: "audit.read",
        icon: Fingerprint,
    },
    {
        id: "operations",
        get label() {
            return tr("ma700a96df8");
        },
        get description() {
            return tr("mbeffee3078");
        },
        permission: "operations.read",
        icon: Activity,
    },
    {
        id: "security",
        get label() {
            return tr("m34a02d2297");
        },
        get description() {
            return tr("m3a5ff5459d");
        },
        permission: "admin.access",
        icon: ShieldCheck,
    },
];
export default function AdminPage() {
    useLocale();
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
                    <span className="eyebrow">{tr("editorial.desk")}</span>
                    <h1>{tr("mcbac960bbe")}</h1>
                    <p>{tr("mb23d146821")}</p>
                </div>
                <span className="admin-role">
                    <ShieldCheck size={16} />
                    {roleLabel(user?.role)}
                </span>
            </header>
            {enrollment || !user?.mfa_enabled ? (
                <div className="admin-panel enrollment-panel">
                    <span className="eyebrow">{tr("m8c868bc477")}</span>
                    <h2>{tr("me3f3cd5503")}</h2>
                    <p>{tr("m4b2e249123")}</p>
                    <MfaPanel onReady={() => setEnrollment(false)} />
                </div>
            ) : (
                <div className="admin-workspace-grid">
                    <nav className="admin-nav" aria-label={tr("m7b143199db")}>
                        {visible.map(
                            ({ id, label, description, icon: Icon }) => (
                                <button
                                    key={id}
                                    aria-label={label}
                                    aria-describedby={`admin-section-description-${id}`}
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
                                        <small
                                            id={`admin-section-description-${id}`}
                                        >
                                            {description}
                                        </small>
                                    </span>
                                    <ArrowLeft size={15} />
                                </button>
                            ),
                        )}
                        <p>
                            {tr("md1f7133312")}
                            <br />
                            AHANG KHATKESH
                        </p>
                    </nav>
                    <section className="admin-stage" aria-label={active.label}>
                        <div className="admin-section-title">
                            <span className="eyebrow">
                                {tr("editorial.control")}
                            </span>
                            <h2>{active.label}</h2>
                            <p>{active.description}</p>
                        </div>
                        {can(user, "imports.manage") && (
                            <div
                                hidden={active.id !== "imports"}
                                key={`${user?.id}:${user?.access_version}`}
                            >
                                <ImportsPanel
                                    active={active.id === "imports"}
                                />
                            </div>
                        )}
                        {active.id === "overview" ? (
                            <OverviewPanel go={go} />
                        ) : active.id === "imports" ? null : active.id ===
                          "conversions" ? (
                            <ConversionPanel />
                        ) : active.id === "library" ? (
                            <ManageLibraryPanel />
                        ) : active.id === "catalog" ? (
                            <CatalogPanel />
                        ) : active.id === "trash" ? (
                            <TrashPanel />
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
