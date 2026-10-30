import { useSearchParams } from "react-router-dom";
import { tr } from "../../i18n/runtime";
import { CatalogPanel } from "./CatalogPanel";
import { BulkCatalogPanel } from "./BulkCatalogPanel";
import { CatalogHealthPanel } from "./CatalogHealthPanel";
export function CatalogWorkspace() {
    const [params, setParams] = useSearchParams();
    const active = params.get("workflow") ?? "inventory";
    return (
        <>
            <nav className="catalog-tabs" aria-label={tr("catalog.workflows")}>
                {(["inventory", "bulk", "health"] as const).map((workflow) => (
                    <button
                        className={`button secondary ${active === workflow ? "selected" : ""}`}
                        key={workflow}
                        aria-current={active === workflow ? "page" : undefined}
                        onClick={() => setParams({ tab: "catalog", workflow })}
                    >
                        {tr(
                            (
                                {
                                    inventory: "catalog.inventory",
                                    bulk: "catalog.bulk",
                                    health: "catalog.health",
                                } as const
                            )[workflow],
                        )}
                    </button>
                ))}
            </nav>
            {active === "bulk" ? (
                <BulkCatalogPanel />
            ) : active === "health" ? (
                <CatalogHealthPanel />
            ) : (
                <CatalogPanel />
            )}
        </>
    );
}
