import { tr } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ErrorState, Loading } from "../../components/UI";
import { api, json } from "../../lib/api";
import { useToast } from "../../context/AppContext";

type Proposal = {
    id: string;
    kind: string;
    status: string;
    summary: string;
    revision: number;
    note?: string | null;
};

export function ProposalsPage() {
    useLocale();
    const notify = useToast();
    const client = useQueryClient();
    const [trackId, setTrackId] = useState("");
    const [title, setTitle] = useState("");
    const [note, setNote] = useState("");
    const query = useQuery({
        queryKey: ["proposals", "mine"],
        queryFn: () => api<{ data: Proposal[] }>("/proposals"),
    });
    const submit = useMutation({
        mutationFn: () =>
            api<{ data: Proposal }>("/proposals", {
                method: "POST",
                body: json({
                    kind: "track_metadata",
                    track_id: Number(trackId),
                    title: title.trim(),
                    note: note.trim() || null,
                }),
            }),
        onSuccess: () => {
            setTitle("");
            setNote("");
            notify(tr("proposals.submitted"));
            void client.invalidateQueries({ queryKey: ["proposals", "mine"] });
        },
        onError: (error: Error) => notify(error.message, true),
    });
    const withdraw = useMutation({
        mutationFn: (proposal: Proposal) =>
            api<{ data: Proposal }>(`/proposals/${proposal.id}`, {
                method: "DELETE",
                body: json({ revision: proposal.revision }),
            }),
        onSuccess: () => {
            notify(tr("proposals.withdrawn"));
            void client.invalidateQueries({ queryKey: ["proposals", "mine"] });
        },
        onError: (error: Error) => notify(error.message, true),
    });
    return (
        <div className="page-enter">
            <header className="page-header">
                <span className="eyebrow">{tr("proposals.nav")}</span>
                <h1>{tr("proposals.title")}</h1>
                <p>{tr("proposals.help")}</p>
            </header>
            <section className="panel">
                <h2>{tr("proposals.kind.track_metadata")}</h2>
                <label>
                    {tr("proposals.trackId")}
                    <input
                        dir="ltr"
                        inputMode="numeric"
                        value={trackId}
                        onChange={(event) => setTrackId(event.target.value)}
                    />
                </label>
                <label>
                    {tr("proposals.newTitle")}
                    <input
                        dir="auto"
                        maxLength={255}
                        value={title}
                        onChange={(event) => setTitle(event.target.value)}
                    />
                </label>
                <label>
                    {tr("proposals.note")}
                    <textarea
                        dir="auto"
                        maxLength={2000}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                </label>
                <button
                    className="button"
                    disabled={submit.isPending || !trackId || !title.trim()}
                    onClick={() => submit.mutate()}
                >
                    {tr("proposals.submit")}
                </button>
            </section>
            <section className="panel">
                {query.isPending ? (
                    <Loading />
                ) : query.error ? (
                    <ErrorState
                        error={query.error}
                        retry={() => void query.refetch()}
                    />
                ) : (
                    <ul className="admin-list">
                        {query.data.data.map((proposal) => (
                            <li key={proposal.id}>
                                <strong dir="auto">{proposal.summary}</strong>
                                <span dir="ltr">
                                    {proposal.status} · {proposal.kind}
                                </span>
                                {proposal.status === "pending" && (
                                    <button
                                        className="button ghost"
                                        disabled={withdraw.isPending}
                                        onClick={() =>
                                            withdraw.mutate(proposal)
                                        }
                                    >
                                        {tr("proposals.withdraw")}
                                    </button>
                                )}
                            </li>
                        ))}
                        {!query.data.data.length && (
                            <p className="muted">{tr("proposals.empty")}</p>
                        )}
                    </ul>
                )}
            </section>
        </div>
    );
}
