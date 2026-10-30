import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { api } from "../../lib/api";
import { useSession } from "../../context/AppContext";
import { tr, number } from "../../i18n/runtime";
import { ErrorState, Loading } from "../../components/UI";
type Workflow = {
    id:
        | "catalog"
        | "changes"
        | "proposals"
        | "trash"
        | "imports"
        | "conversions"
        | "users"
        | "audit"
        | "operations";
    count: number;
    attention: number;
    capacity?: number;
    next: string;
};
export function WorkflowOverview() {
    const { user } = useSession();
    const navigate = useNavigate();
    const query = useQuery({
        queryKey: ["admin", "workflows", user?.id, user?.access_version],
        queryFn: ({ signal }) =>
            api<{ data: Workflow[]; generated_at: string }>(
                "/admin/workflows",
                { signal },
            ),
        staleTime: 15000,
    });
    return (
        <section>
            <h3>{tr("catalog.workflows")}</h3>
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : (
                <div className="workflow-grid">
                    {query.data.data.map((row) => (
                        <button
                            className="workflow-card"
                            key={row.id}
                            onClick={() =>
                                navigate(
                                    row.next === "bulk"
                                        ? "/admin?tab=catalog&workflow=bulk"
                                        : `/admin?tab=${row.next}`,
                                )
                            }
                        >
                            <strong>
                                {tr(
                                    (
                                        {
                                            catalog: "catalog.inventory",
                                            changes: "catalog.journal",
                                            proposals: "catalog.proposalInbox",
                                            trash: "m33a33be876",
                                            imports: "m32c110efdb",
                                            conversions: "me45cdd8642",
                                            users: "mc50f3a13b6",
                                            audit: "m8d1d1dfc1f",
                                            operations: "ma700a96df8",
                                        } as const
                                    )[row.id],
                                )}
                            </strong>
                            <span>
                                {tr("catalog.total", {
                                    count: number(row.count),
                                })}
                                {row.capacity
                                    ? ` / ${number(row.capacity)}`
                                    : ""}
                            </span>
                            <small>
                                {tr("catalog.attention", {
                                    count: number(row.attention),
                                })}
                            </small>
                            <span className="text-link">
                                {tr("catalog.nextAction")}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </section>
    );
}
