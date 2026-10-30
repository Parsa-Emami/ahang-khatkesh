import { useDeferredValue, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { X } from "lucide-react";
import { useSession, useToast } from "../../context/AppContext";
import { Dialog, ErrorState, IconButton, Loading } from "../../components/UI";
import {
    api,
    captureSessionIntent,
    json,
    type SessionIntent,
} from "../../lib/api";
import { tr, date } from "../../i18n/runtime";
import { useLocale } from "../../i18n/LocaleProvider";
import { moodLabels, type Track, type Page, type Mood } from "../../types";
import { useDraftGuard } from "../drafts/DraftNavigation";
import { Pager } from "./Pager";
import { ChangeReview } from "./ChangeReview";
import {
    appendUnique,
    previewChange,
    type Change,
    type Proposal,
    type ProposalPayload,
} from "./workflows";
export default function ProposalsPage() {
    useLocale();
    return (
        <div className="page-enter catalog-request-page">
            <header>
                <span className="eyebrow">{tr("catalog.memberEyebrow")}</span>
                <h1>{tr("catalog.myProposals")}</h1>
                <p>{tr("catalog.memberHelp")}</p>
            </header>
            <ProposalForm />
            <ProposalList />
        </div>
    );
}
export function ProposalForm({
    initialTracks = [],
}: {
    initialTracks?: Track[];
}) {
    const [kind, setKind] = useState<Proposal["kind"]>("playlist");
    const [tracks, setTracks] = useState<Track[]>(initialTracks.slice(0, 100));
    const [name, setName] = useState("");
    const [description, setDescription] = useState("");
    const [mood, setMood] = useState<Mood>("other");
    const [title, setTitle] = useState("");
    const [q, setQ] = useState("");
    const [page, setPage] = useState(1);
    const [retry, setRetry] = useState<{ hash: string; key: string } | null>(
        null,
    );
    const client = useQueryClient();
    const notify = useToast();
    const { user } = useSession();
    const term = useDeferredValue(q);
    const query = useQuery({
        queryKey: [
            "member-proposals",
            "picker",
            user?.id,
            user?.access_version,
            term,
            page,
        ],
        queryFn: ({ signal }) =>
            api<Page<Track>>(
                `/tracks?${new URLSearchParams({ q: term, page: String(page) })}`,
                { signal },
            ),
    });
    const reset = () => {
        setTracks([]);
        setName("");
        setDescription("");
        setTitle("");
        setRetry(null);
    };
    const dirty = tracks.length > 0 || !!name || !!description || !!title;
    useDraftGuard(dirty, reset);
    const submit = useMutation({
        mutationFn: (
            intent: SessionIntent<{
                kind: Proposal["kind"];
                payload: ProposalPayload;
                idempotency_key: string;
            }>,
        ) =>
            api<{ data: Proposal }>(
                "/member-proposals",
                { method: "POST", body: json(intent.value) },
                true,
                true,
                intent,
            ),
        onSuccess: () => {
            reset();
            void client.invalidateQueries({ queryKey: ["member-proposals"] });
            notify(tr("catalog.proposalSubmitted"));
        },
    });
    const ready =
        kind === "playlist"
            ? tracks.length > 0 && !!name.trim()
            : tracks.length === 1 && !!title.trim();
    return (
        <form
            className="catalog-workflow"
            onSubmit={(e) => {
                e.preventDefault();
                if (!ready || submit.isPending) return;
                const payload: ProposalPayload =
                    kind === "playlist"
                        ? {
                              name,
                              description,
                              mood,
                              track_ids: tracks.map((track) => track.id),
                          }
                        : { track_id: tracks[0].id, title };
                const hash = json({ kind, payload });
                const key =
                    retry?.hash === hash ? retry.key : crypto.randomUUID();
                setRetry({ hash, key });
                submit.mutate(
                    captureSessionIntent({
                        kind,
                        payload,
                        idempotency_key: key,
                    }),
                );
            }}
        >
            <h2>{tr("catalog.newProposal")}</h2>
            <label>
                {tr("catalog.proposalKind")}
                <select
                    value={kind}
                    disabled={dirty}
                    onChange={(e) => {
                        setKind(e.target.value as Proposal["kind"]);
                        submit.reset();
                    }}
                >
                    <option value="playlist">
                        {tr("catalog.playlistProposal")}
                    </option>
                    <option value="track_metadata">
                        {tr("catalog.titleProposal")}
                    </option>
                </select>
            </label>
            {kind === "playlist" ? (
                <>
                    <label>
                        {tr("catalog.name")}
                        <input
                            dir="auto"
                            value={name}
                            maxLength={160}
                            required
                            onChange={(e) => setName(e.target.value)}
                        />
                    </label>
                    <label>
                        {tr("catalog.description")}
                        <textarea
                            dir="auto"
                            value={description}
                            maxLength={3000}
                            onChange={(e) => setDescription(e.target.value)}
                        />
                    </label>
                    <label>
                        {tr("catalog.mood")}
                        <select
                            value={mood}
                            onChange={(e) => setMood(e.target.value as Mood)}
                        >
                            {Object.entries(moodLabels).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                </>
            ) : (
                <label>
                    {tr("catalog.suggestedTitle")}
                    <input
                        dir="auto"
                        value={title}
                        maxLength={255}
                        required
                        onChange={(e) => setTitle(e.target.value)}
                    />
                </label>
            )}
            <label>
                {tr("catalog.findTrack")}
                <input
                    dir="auto"
                    value={q}
                    maxLength={120}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setPage(1);
                    }}
                />
            </label>
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : (
                <>
                    <div className="catalog-picker picker-results">
                        {query.data.data.map((track) => (
                            <button
                                type="button"
                                key={track.id}
                                disabled={
                                    tracks.some(
                                        (item) => item.id === track.id,
                                    ) ||
                                    (kind === "playlist" &&
                                        tracks.length >= 100)
                                }
                                onClick={() =>
                                    setTracks((current) =>
                                        kind === "playlist"
                                            ? appendUnique(current, track, 100)
                                            : [track],
                                    )
                                }
                            >
                                <span dir="auto">
                                    {track.title}
                                    <small>{track.artist}</small>
                                </span>
                                <span>{tr("catalog.select")}</span>
                            </button>
                        ))}
                        {query.data.data.length === 0 && (
                            <p>{tr("catalog.noTracks")}</p>
                        )}
                    </div>
                    <Pager
                        page={page}
                        pages={query.data.meta.last_page}
                        setPage={setPage}
                    />
                </>
            )}
            <p>
                {tr("catalog.selected", {
                    count: tracks.length,
                    limit: kind === "playlist" ? 100 : 1,
                })}
            </p>
            <ul className="catalog-selection">
                {tracks.map((track) => (
                    <li key={track.id}>
                        <header>
                            <span dir="auto">{track.title}</span>
                            <IconButton
                                label={tr("catalog.remove", { id: track.id })}
                                onClick={() =>
                                    setTracks((current) =>
                                        current.filter(
                                            (item) => item.id !== track.id,
                                        ),
                                    )
                                }
                            >
                                <X size={18} />
                            </IconButton>
                        </header>
                    </li>
                ))}
            </ul>
            {submit.error && <p role="alert">{submit.error.message}</p>}
            <div className="catalog-actions">
                <button
                    className="button"
                    type="submit"
                    disabled={!ready || submit.isPending}
                >
                    {tr("catalog.submitProposal")}
                </button>
                <button
                    className="button secondary"
                    type="button"
                    disabled={!dirty || submit.isPending}
                    onClick={reset}
                >
                    {tr("catalog.clear")}
                </button>
            </div>
        </form>
    );
}
export function ProposalList({ owner = false }: { owner?: boolean }) {
    useLocale();
    const { user } = useSession();
    const [status, setStatus] = useState("pending");
    const [page, setPage] = useState(1);
    const [reject, setReject] = useState<Proposal | null>(null);
    const [review, setReview] = useState<Change | null>(null);
    const [publishIds, setPublishIds] = useState<string[]>([]);
    const query = useQuery({
        queryKey: [
            owner ? "admin" : "member-proposals",
            "proposals",
            user?.id,
            user?.access_version,
            status,
            page,
        ],
        queryFn: ({ signal }) =>
            api<Page<Proposal>>(
                `${owner ? "/admin/proposals" : "/member-proposals"}?${new URLSearchParams({ status, page: String(page) })}`,
                { signal },
            ),
    });
    const preview = useMutation({
        mutationFn: previewChange,
        onSuccess: ({ data }) => setReview(data),
    });
    return (
        <section className="catalog-workflow">
            <h2>
                {tr(owner ? "catalog.proposalInbox" : "catalog.proposalStatus")}
            </h2>
            <label>
                {tr("catalog.status")}
                <select
                    value={status}
                    onChange={(e) => {
                        setStatus(e.target.value);
                        setPage(1);
                    }}
                >
                    {(["pending", "accepted", "rejected"] as const).map(
                        (value) => (
                            <option key={value} value={value}>
                                {tr(
                                    (
                                        {
                                            pending: "catalog.pending",
                                            accepted: "catalog.accepted",
                                            rejected: "catalog.rejected",
                                        } as const
                                    )[value],
                                )}
                            </option>
                        ),
                    )}
                </select>
            </label>
            {query.isPending ? (
                <Loading />
            ) : query.error ? (
                <ErrorState
                    error={query.error}
                    retry={() => void query.refetch()}
                />
            ) : (
                <>
                    <div className="proposal-list">
                        {query.data.data.map((proposal) => (
                            <article key={proposal.id}>
                                <div className="proposal-heading">
                                    <h3>
                                        {tr(
                                            proposal.kind === "playlist"
                                                ? "catalog.playlistProposal"
                                                : "catalog.titleProposal",
                                        )}
                                    </h3>
                                    <span>
                                        {date(proposal.created_at, {
                                            dateStyle: "medium",
                                        })}
                                    </span>
                                </div>
                                {proposal.submitter && (
                                    <p dir="auto">{proposal.submitter.name}</p>
                                )}
                                <div className="catalog-proposal-payload">
                                    {"name" in proposal.payload ? (
                                        <>
                                            <strong dir="auto">
                                                {proposal.payload.name}
                                            </strong>
                                            <p dir="auto">
                                                {proposal.payload.description}
                                            </p>
                                            <p>
                                                {
                                                    moodLabels[
                                                        proposal.payload.mood
                                                    ]
                                                }
                                            </p>
                                            <p>
                                                {tr("catalog.trackIds")}:{" "}
                                                {proposal.payload.track_ids
                                                    .map((id) => `#${id}`)
                                                    .join(" · ")}
                                            </p>
                                        </>
                                    ) : (
                                        <>
                                            <strong dir="auto">
                                                {proposal.payload.title}
                                            </strong>
                                            <p>
                                                {tr("catalog.track")}: #
                                                {proposal.payload.track_id}
                                            </p>
                                        </>
                                    )}
                                </div>
                                {proposal.resolution === "rolled_back" && (
                                    <p>{tr("catalog.proposalRolledBack")}</p>
                                )}
                                {proposal.review_note && (
                                    <p dir="auto">{proposal.review_note}</p>
                                )}
                                {proposal.result_playlist_id && (
                                    <Link
                                        className="text-link"
                                        to={`/playlists/${proposal.result_playlist_id}`}
                                    >
                                        {tr("catalog.openResult")}
                                    </Link>
                                )}
                                {proposal.status === "accepted" &&
                                    !proposal.result_playlist_id &&
                                    proposal.kind === "playlist" && (
                                        <p className="muted">
                                            {tr("catalog.acceptedDraftHelp")}
                                        </p>
                                    )}
                                {owner && proposal.status === "pending" && (
                                    <>
                                        {proposal.kind === "playlist" && (
                                            <label className="checkbox-label">
                                                <input
                                                    type="checkbox"
                                                    checked={publishIds.includes(
                                                        proposal.id,
                                                    )}
                                                    onChange={(e) =>
                                                        setPublishIds((ids) =>
                                                            e.target.checked
                                                                ? [
                                                                      ...ids,
                                                                      proposal.id,
                                                                  ]
                                                                : ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          proposal.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {tr("catalog.publishAccepted")}
                                            </label>
                                        )}
                                        <div className="catalog-actions">
                                            <button
                                                className="button"
                                                disabled={preview.isPending}
                                                onClick={() =>
                                                    preview.mutate(
                                                        captureSessionIntent({
                                                            operation:
                                                                "proposal",
                                                            proposal_id:
                                                                proposal.id,
                                                            revision:
                                                                proposal.revision,
                                                            publish:
                                                                publishIds.includes(
                                                                    proposal.id,
                                                                ),
                                                        }),
                                                    )
                                                }
                                            >
                                                {tr("catalog.previewAccept")}
                                            </button>
                                            <button
                                                className="button secondary"
                                                onClick={() =>
                                                    setReject(proposal)
                                                }
                                            >
                                                {tr("catalog.reject")}
                                            </button>
                                        </div>
                                    </>
                                )}
                            </article>
                        ))}
                        {query.data.data.length === 0 && (
                            <p>{tr("catalog.noProposals")}</p>
                        )}
                    </div>
                    <Pager
                        page={page}
                        pages={query.data.meta.last_page}
                        setPage={setPage}
                    />
                </>
            )}
            {preview.error && <p role="alert">{preview.error.message}</p>}
            {review && (
                <ChangeReview
                    key={review.id}
                    change={review}
                    close={() => setReview(null)}
                />
            )}
            {reject && (
                <Dialog
                    open
                    onOpenChange={(value) => {
                        if (!value) setReject(null);
                    }}
                    title={tr("catalog.reject")}
                    description={tr("catalog.rejectHelp")}
                >
                    <RejectProposal
                        proposal={reject}
                        close={() => setReject(null)}
                    />
                </Dialog>
            )}
        </section>
    );
}
function RejectProposal({
    proposal,
    close,
}: {
    proposal: Proposal;
    close: () => void;
}) {
    const [reason, setReason] = useState("");
    const client = useQueryClient();
    useDraftGuard(!!reason, () => setReason(""));
    const reject = useMutation({
        mutationFn: (
            intent: SessionIntent<{ revision: number; reason: string }>,
        ) =>
            api(
                `/admin/proposals/${proposal.id}/reject`,
                { method: "POST", body: json(intent.value) },
                true,
                true,
                intent,
            ),
        onSuccess: () => {
            setReason("");
            void client.invalidateQueries();
            close();
        },
    });
    return (
        <form
            className="catalog-workflow"
            onSubmit={(e) => {
                e.preventDefault();
                reject.mutate(
                    captureSessionIntent({
                        revision: proposal.revision,
                        reason,
                    }),
                );
            }}
        >
            <label>
                {tr("catalog.rejectReason")}
                <textarea
                    dir="auto"
                    required
                    minLength={3}
                    maxLength={1000}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                />
            </label>
            {reject.error && <p role="alert">{reject.error.message}</p>}
            <button
                className="button danger"
                disabled={reason.trim().length < 3 || reject.isPending}
            >
                {tr("catalog.confirmReject")}
            </button>
        </form>
    );
}
