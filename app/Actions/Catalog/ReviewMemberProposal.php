<?php

namespace App\Actions\Catalog;

use App\Actions\AuthorizeLibraryWrite;
use App\Actions\LockCurrentAccount;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\CatalogChangeBatch;
use App\Models\MemberProposal;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReviewMemberProposal
{
    public function __construct(
        private AuthorizeLibraryWrite $authorize,
        private PreviewCatalogBatch $preview,
        private CommitCatalogBatch $commit,
    ) {}

    public function accept(User $actor, MemberProposal $proposal, int $revision, ?string $reviewNote = null): MemberProposal
    {
        return DB::transaction(function () use ($actor, $proposal, $revision, $reviewNote): MemberProposal {
            $actor = $this->authorize->handle($actor);
            $locked = MemberProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === MemberProposal::PENDING, 409, __('این پیشنهاد دیگر در انتظار نیست.'));
            abort_unless((int) $locked->revision === $revision, 409, __('پیشنهاد تغییر کرده است؛ دوباره بارگذاری کنید.'));

            $batch = null;
            if (in_array($locked->kind, ['track_metadata', 'artist_rename', 'duplicate_report'], true)) {
                $batch = $this->previewFromProposal($actor, $locked);
                $batch = $this->commit->handle($actor, $batch, $batch->plan_hash);
            } elseif ($locked->kind === 'playlist_create') {
                $this->acceptPlaylist($actor, $locked);
            } elseif ($locked->kind === 'repair_report') {
                $batch = $this->previewRepair($actor, $locked);
                if ($batch !== null) {
                    $batch = $this->commit->handle($actor, $batch, $batch->plan_hash);
                }
            }

            $locked->update([
                'status' => MemberProposal::ACCEPTED,
                'revision' => $locked->revision + 1,
                'reviewed_by' => $actor->id,
                'review_note' => $reviewNote,
                'accepted_batch_id' => $batch?->id,
                'reviewed_at' => now(),
            ]);
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.proposal_accepted',
                'subject_type' => 'member_proposal',
                'subject_id' => $locked->id,
                'metadata' => ['kind' => $locked->kind, 'batch_id' => $batch?->id],
            ]);

            return $locked->fresh();
        }, 5);
    }

    public function reject(User $actor, MemberProposal $proposal, int $revision, ?string $reviewNote = null): MemberProposal
    {
        return DB::transaction(function () use ($actor, $proposal, $revision, $reviewNote): MemberProposal {
            $actor = $this->authorize->handle($actor);
            $locked = MemberProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === MemberProposal::PENDING, 409, __('این پیشنهاد دیگر در انتظار نیست.'));
            abort_unless((int) $locked->revision === $revision, 409, __('پیشنهاد تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $locked->update([
                'status' => MemberProposal::REJECTED,
                'revision' => $locked->revision + 1,
                'reviewed_by' => $actor->id,
                'review_note' => $reviewNote,
                'reviewed_at' => now(),
            ]);
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.proposal_rejected',
                'subject_type' => 'member_proposal',
                'subject_id' => $locked->id,
                'metadata' => ['kind' => $locked->kind],
            ]);

            return $locked->fresh();
        }, 5);
    }

    public function withdraw(User $actor, MemberProposal $proposal, int $revision): MemberProposal
    {
        return DB::transaction(function () use ($actor, $proposal, $revision): MemberProposal {
            $actor = app(LockCurrentAccount::class)->handle($actor);
            $locked = MemberProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->proposed_by === $actor->id, 403);
            abort_unless($locked->status === MemberProposal::PENDING, 409, __('این پیشنهاد دیگر در انتظار نیست.'));
            abort_unless((int) $locked->revision === $revision, 409, __('پیشنهاد تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $locked->update([
                'status' => MemberProposal::WITHDRAWN,
                'revision' => $locked->revision + 1,
            ]);
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.proposal_withdrawn',
                'subject_type' => 'member_proposal',
                'subject_id' => $locked->id,
                'metadata' => ['kind' => $locked->kind],
            ]);

            return $locked->fresh();
        }, 5);
    }

    private function previewFromProposal(User $actor, MemberProposal $proposal): CatalogChangeBatch
    {
        $payload = $proposal->payload;
        $snapshot = $proposal->catalog_snapshot;
        if ($proposal->kind === 'track_metadata') {
            $track = Track::findOrFail($payload['track_id']);
            abort_unless((int) $track->catalog_revision === (int) $snapshot['expected_revision'], 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));

            return $this->preview->handle($actor, [
                'kind' => 'track_metadata',
                'changes' => [[
                    'track_id' => $track->id,
                    'revision' => (int) $track->catalog_revision,
                    'title' => $payload['title'],
                    'credits' => $payload['credits'],
                    'primary_release_id' => $payload['primary_release_id'],
                ]],
            ]);
        }
        if ($proposal->kind === 'artist_rename') {
            $artist = Artist::findOrFail($payload['artist_id']);
            abort_unless((int) $artist->revision === (int) $snapshot['expected_revision'], 409, __('اطلاعات هنرمند تغییر کرده است؛ دوباره بارگذاری کنید.'));

            return $this->preview->handle($actor, [
                'kind' => 'artist_rename',
                'changes' => [[
                    'artist_id' => $artist->id,
                    'revision' => (int) $artist->revision,
                    'name' => $payload['name'],
                    'sort_name' => $payload['sort_name'],
                ]],
            ]);
        }
        $left = Track::findOrFail($payload['left_track_id']);
        $right = Track::findOrFail($payload['right_track_id']);
        $survivorId = (int) $payload['suggested_survivor_track_id'];
        $sourceId = $survivorId === $left->id ? $right->id : $left->id;
        $survivor = Track::findOrFail($survivorId);
        $source = Track::findOrFail($sourceId);

        return $this->preview->handle($actor, [
            'kind' => 'merge_recordings',
            'survivor_track_id' => $survivor->id,
            'source_track_id' => $source->id,
            'survivor_revision' => (int) $survivor->catalog_revision,
            'source_revision' => (int) $source->catalog_revision,
        ]);
    }

    private function previewRepair(User $actor, MemberProposal $proposal): ?CatalogChangeBatch
    {
        $payload = $proposal->payload;
        $track = Track::withTrashed()->findOrFail($payload['track_id']);
        if ($payload['code'] === 'broken_primary_release') {
            return $this->preview->handle($actor, [
                'kind' => 'repair',
                'changes' => [[
                    'track_id' => $track->id,
                    'revision' => (int) $track->catalog_revision,
                    'action' => 'clear_primary_release',
                ]],
            ]);
        }

        return null;
    }

    private function acceptPlaylist(User $actor, MemberProposal $proposal): Playlist
    {
        $payload = $proposal->payload;
        $playlist = Playlist::create([
            'name' => $payload['name'],
            'mood' => $payload['mood'],
            'status' => Playlist::DRAFT,
            'created_by' => $actor->id,
            'artwork_seed' => random_int(0, 65535),
        ]);
        $rows = [];
        foreach (array_values($payload['track_ids']) as $index => $trackId) {
            abort_unless(Track::whereKey($trackId)->exists(), 422, __('آهنگ نامعتبر است.'));
            $rows[$trackId] = ['position' => $index + 1];
        }
        $playlist->tracks()->attach($rows);
        $playlist->revision = 1;
        $playlist->save();
        AuditEvent::create([
            'user_id' => $actor->id,
            'action' => 'playlist.created',
            'subject_id' => (string) $playlist->id,
            'metadata' => ['from_proposal' => $proposal->id],
        ]);

        return $playlist;
    }
}
