<?php

namespace App\Services\Catalog;

use App\Actions\AuthorizeLibraryWrite;
use App\Actions\LockCurrentAccount;
use App\Models\AuditEvent;
use App\Models\MemberProposal;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackMedia;
use Illuminate\Support\Facades\DB;

class MemberProposals
{
    public function __construct(private LockCurrentAccount $accounts, private AuthorizeLibraryWrite $authorize, private CatalogSnapshot $snapshots, private PlaybackMedia $media) {}

    public function reference(User $user, array $ids): array
    {
        $tracks = Track::visibleTo($user)->whereIn('id', $ids)->with('mediaAsset.blob')->get();
        abort_unless($tracks->count() === count($ids), 404);
        $mapping = $tracks->keyBy('id');
        $canonical = array_values(array_unique(array_map(fn ($id) => (int) ($mapping[$id]->merged_into_id ?? $id), $ids)));
        $roots = Track::visibleTo($user)->canonical()->whereIn('id', $canonical)->with('mediaAsset.blob')->get();
        abort_unless($roots->count() === count($canonical), 404);
        foreach ($roots as $track) {
            abort_unless($track->mediaAsset->duration_seconds > 0 && (! $track->mediaAsset->blob_id || $track->mediaAsset->blob?->state === 'ready'), 422, __('catalog.media_unavailable'));
        }
        $this->media->assertReady($roots->pluck('media_asset_id')->all());

        return ['ids' => $canonical, 'snapshot' => $this->snapshots->capture(['tracks' => $canonical, 'playlists' => []])];
    }

    public function submit(User $snapshot, array $data): MemberProposal
    {
        return DB::transaction(function () use ($snapshot, $data): MemberProposal {
            $user = $this->accounts->handle($snapshot);
            $hash = $this->snapshots->hash(['kind' => $data['kind'], 'payload' => $data['payload']]);
            $existing = MemberProposal::where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->actor_version === $user->access_version && hash_equals($existing->payload_hash, $hash), 409, __('catalog.idempotency_conflict'));

                return $existing;
            }
            abort_if(MemberProposal::where('user_id', $user->id)->where('status', 'pending')->count() >= config('catalog.max_pending_proposals'), 422, __('catalog.proposal_limit'));
            $payload = $data['payload'];
            $reference = $this->reference($user, $data['kind'] === 'playlist' ? $payload['track_ids'] : [$payload['track_id']]);
            if ($data['kind'] === 'playlist') {
                $payload['track_ids'] = $reference['ids'];
                $payload['description'] = $payload['description'] ?? '';
            } else {
                $payload['track_id'] = $reference['ids'][0];
            }
            $proposal = MemberProposal::create(['user_id' => $user->id, 'actor_version' => $user->access_version, 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'kind' => $data['kind'], 'status' => 'pending', 'revision' => 0, 'payload' => $payload, 'snapshot' => $reference['snapshot']]);
            AuditEvent::create(['user_id' => $user->id, 'action' => 'proposal.submitted', 'subject_type' => 'member_proposal', 'subject_id' => $proposal->id, 'metadata' => ['kind' => $proposal->kind]]);

            return $proposal;
        }, 5);
    }

    public function pending(string $id, int $revision): MemberProposal
    {
        $proposal = MemberProposal::whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($proposal->status === 'pending' && $proposal->revision === $revision, 409, __('catalog.proposal_changed'));
        $submitter = User::find($proposal->user_id);
        abort_unless($submitter?->is_active && $submitter->access_version === $proposal->actor_version, 409, __('catalog.proposal_actor_changed'));
        $payload = $proposal->payload;
        $reference = $this->reference($submitter, $proposal->kind === 'playlist' ? $payload['track_ids'] : [$payload['track_id']]);
        abort_unless(hash_equals($this->snapshots->hash($proposal->snapshot), $this->snapshots->hash($reference['snapshot'])), 409, __('catalog.proposal_snapshot_changed'));

        return $proposal;
    }

    public function reject(User $snapshot, string $id, int $revision, string $note): MemberProposal
    {
        return DB::transaction(function () use ($snapshot, $id, $revision, $note): MemberProposal {
            $actor = $this->authorize->handle($snapshot);
            $proposal = MemberProposal::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($proposal->status === 'pending' && $proposal->revision === $revision, 409, __('catalog.proposal_changed'));
            $proposal->update(['status' => 'rejected', 'revision' => $proposal->revision + 1, 'reviewed_by' => $actor->id, 'review_note' => $note, 'resolution' => 'rejected', 'reviewed_at' => now()]);
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'proposal.rejected', 'subject_type' => 'member_proposal', 'subject_id' => $id, 'metadata' => ['revision' => $proposal->revision]]);

            return $proposal;
        }, 5);
    }
}
