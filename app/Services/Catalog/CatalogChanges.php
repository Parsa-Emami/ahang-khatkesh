<?php

namespace App\Services\Catalog;

use App\Actions\AuthorizeLibraryWrite;
use App\Models\AuditEvent;
use App\Models\CatalogChangeSet;
use App\Models\MemberProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CatalogChanges
{
    public function __construct(private AuthorizeLibraryWrite $authorize, private CatalogPlan $plans, private CatalogSnapshot $snapshots, private ApplyCatalogPlan $apply) {}

    public function preview(User $snapshot, array $input): CatalogChangeSet
    {
        return DB::transaction(function () use ($snapshot, $input): CatalogChangeSet {
            $actor = $this->authorize->handle($snapshot);
            $plan = $this->plans->build($input);
            $change = CatalogChangeSet::create(['user_id' => $actor->id, 'actor_version' => $actor->access_version, 'operation' => $input['operation'], 'status' => 'previewed', 'revision' => 0, 'expires_at' => now()->addMinutes(config('catalog.preview_minutes'))] + $plan);
            $this->audit($actor, $change, 'previewed');

            return $change;
        }, 5);
    }

    public function commit(User $snapshot, string $id, int $revision, string $hash): CatalogChangeSet
    {
        return DB::transaction(function () use ($snapshot, $id, $revision, $hash): CatalogChangeSet {
            $actor = $this->authorize->handle($snapshot);
            $change = $this->locked($actor, $id, $hash);
            if ($change->status === 'committed' && $revision === 0) {
                return $change;
            }
            abort_unless($change->status === 'previewed' && $change->revision === $revision && $revision === 0, 409, __('catalog.changed'));
            abort_if($change->expires_at->isPast(), 410, __('catalog.preview_expired'));
            $plan = $this->plans->build($change->input);
            abort_unless(hash_equals($change->snapshot_hash, $plan['snapshot_hash']), 409, __('catalog.changed'));
            $result = $this->apply->apply($actor, $plan);
            $scope = $change->scope;
            if (isset($result['created_playlist_id'])) {
                $scope['playlists'][] = $result['created_playlist_id'];
            }
            $change->scope = $scope;
            $change->result = $result;
            $change->after = $this->capture($change);
            $change->status = 'committed';
            $change->revision = 1;
            $change->committed_at = now();
            $change->save();
            $this->audit($actor, $change, 'committed');

            return $change;
        }, 5);
    }

    public function rollback(User $snapshot, string $id, int $revision, string $hash): CatalogChangeSet
    {
        return DB::transaction(function () use ($snapshot, $id, $revision, $hash): CatalogChangeSet {
            $actor = $this->authorize->handle($snapshot);
            $change = $this->locked($actor, $id, $hash);
            if ($change->status === 'rolled_back' && $revision === 1) {
                return $change;
            }
            abort_unless($change->status === 'committed' && $change->revision === $revision && $revision === 1, 409, __('catalog.changed'));
            abort_if($change->committed_at->addDays(config('catalog.rollback_days'))->isPast(), 410, __('catalog.rollback_expired'));
            abort_unless(hash_equals($this->snapshots->hash($change->after), $this->snapshots->hash($this->capture($change))), 409, __('catalog.rollback_conflict'));
            $this->apply->restore($actor, $change->before, $change->result ?? [], $change->operation, $change->proposal_id);
            $change->update(['status' => 'rolled_back', 'revision' => 2, 'rolled_back_at' => now()]);
            $this->audit($actor, $change, 'rolled_back');

            return $change;
        }, 5);
    }

    private function locked(User $actor, string $id, string $hash): CatalogChangeSet
    {
        $change = CatalogChangeSet::where('user_id', $actor->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($change->actor_version === $actor->access_version && hash_equals($change->snapshot_hash, $hash), 409, __('catalog.changed'));

        return $change;
    }

    private function capture(CatalogChangeSet $change): array
    {
        $snapshot = $this->snapshots->capture($change->scope);
        if ($change->proposal_id) {
            $proposal = MemberProposal::findOrFail($change->proposal_id);
            $snapshot['proposal'] = ['id' => $proposal->id, 'revision' => $proposal->revision, 'status' => $proposal->status, 'payload_hash' => $proposal->payload_hash];
        }

        return $snapshot;
    }

    private function audit(User $actor, CatalogChangeSet $change, string $verb): void
    {
        AuditEvent::create(['user_id' => $actor->id, 'action' => 'catalog.batch_'.$verb, 'subject_type' => 'catalog_change_set', 'subject_id' => $change->id, 'metadata' => ['operation' => $change->operation, 'tracks' => count($change->scope['tracks']), 'playlists' => count($change->scope['playlists']), 'revision' => $change->revision]]);
    }
}
