<?php

namespace App\Actions\Catalog;

use App\Actions\LockCurrentAccount;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\MemberProposal;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\Catalog\CatalogSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubmitMemberProposal
{
    public function __construct(
        private LockCurrentAccount $accounts,
        private CatalogSnapshot $snapshots,
    ) {}

    public function handle(User $actor, array $data): MemberProposal
    {
        return DB::transaction(function () use ($actor, $data): MemberProposal {
            $actor = $this->accounts->handle($actor);
            $kind = $data['kind'];
            abort_unless(in_array($kind, MemberProposal::KINDS, true), 422);
            [$payload, $snapshot, $summary] = match ($kind) {
                'track_metadata' => $this->trackMetadata($data),
                'artist_rename' => $this->artistRename($data),
                'playlist_create' => $this->playlistCreate($data),
                'duplicate_report' => $this->duplicateReport($data),
                'repair_report' => $this->repairReport($data),
            };
            $proposal = MemberProposal::create([
                'id' => (string) Str::uuid(),
                'proposed_by' => $actor->id,
                'kind' => $kind,
                'status' => MemberProposal::PENDING,
                'summary' => $summary,
                'note' => $data['note'] ?? null,
                'payload' => $payload,
                'catalog_snapshot' => $snapshot,
                'revision' => 0,
            ]);
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.proposal_submitted',
                'subject_type' => 'member_proposal',
                'subject_id' => $proposal->id,
                'metadata' => ['kind' => $kind],
            ]);

            return $proposal;
        }, 5);
    }

    private function trackMetadata(array $data): array
    {
        $track = Track::findOrFail((int) $data['track_id']);
        $title = trim((string) ($data['title'] ?? ''));
        abort_if($title === '' || mb_strlen($title) > 255, 422, __('عنوان نامعتبر است.'));
        $payload = [
            'track_id' => $track->id,
            'title' => $title,
            'credits' => $data['credits'] ?? null,
            'primary_release_id' => $data['primary_release_id'] ?? '__keep__',
        ];
        $snapshot = [
            'tracks' => [$track->id => $this->snapshots->track($track)],
            'expected_revision' => (int) $track->catalog_revision,
        ];

        return [$payload, $snapshot, mb_substr('ویرایش «'.$track->title.'» ← «'.$title.'»', 0, 240)];
    }

    private function artistRename(array $data): array
    {
        $artist = Artist::findOrFail((int) $data['artist_id']);
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '' || mb_strlen($name) > 255, 422, __('نام هنرمند نامعتبر است.'));
        $payload = ['artist_id' => $artist->id, 'name' => $name, 'sort_name' => $data['sort_name'] ?? $artist->sort_name];
        $snapshot = [
            'artists' => [$artist->id => $this->snapshots->artist($artist)],
            'expected_revision' => (int) $artist->revision,
        ];

        return [$payload, $snapshot, mb_substr('تغییر نام «'.$artist->name.'» ← «'.$name.'»', 0, 240)];
    }

    private function playlistCreate(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $mood = (string) ($data['mood'] ?? 'other');
        $trackIds = array_values(array_unique(array_map('intval', $data['track_ids'] ?? [])));
        abort_if($name === '' || mb_strlen($name) > 160, 422, __('نام پلی‌لیست نامعتبر است.'));
        abort_unless(in_array($mood, Playlist::MOODS, true), 422, __('حال‌وهوا نامعتبر است.'));
        abort_if($trackIds === [] || count($trackIds) > 200, 422, __('حداقل یک آهنگ لازم است.'));
        $tracks = Track::whereIn('id', $trackIds)->get();
        abort_unless($tracks->count() === count($trackIds), 422, __('آهنگ نامعتبر است.'));
        $payload = ['name' => $name, 'mood' => $mood, 'track_ids' => $trackIds];
        $snapshot = [
            'tracks' => $tracks->mapWithKeys(fn (Track $track) => [$track->id => ['id' => $track->id, 'catalog_revision' => (int) $track->catalog_revision]])->all(),
        ];

        return [$payload, $snapshot, mb_substr('پیشنهاد پلی‌لیست «'.$name.'»', 0, 240)];
    }

    private function duplicateReport(array $data): array
    {
        $left = Track::findOrFail((int) $data['left_track_id']);
        $right = Track::findOrFail((int) $data['right_track_id']);
        abort_if($left->id === $right->id, 422, __('دو آهنگ متمایز لازم است.'));
        $payload = [
            'left_track_id' => $left->id,
            'right_track_id' => $right->id,
            'suggested_survivor_track_id' => (int) ($data['suggested_survivor_track_id'] ?? $left->id),
        ];
        abort_unless(in_array($payload['suggested_survivor_track_id'], [$left->id, $right->id], true), 422);
        $snapshot = [
            'tracks' => [
                $left->id => $this->snapshots->track($left),
                $right->id => $this->snapshots->track($right),
            ],
        ];

        return [$payload, $snapshot, mb_substr('گزارش تکراری #'.$left->id.' / #'.$right->id, 0, 240)];
    }

    private function repairReport(array $data): array
    {
        $track = Track::withTrashed()->findOrFail((int) $data['track_id']);
        $code = (string) ($data['code'] ?? 'missing_media_file');
        abort_unless(in_array($code, ['missing_media_file', 'broken_primary_release', 'missing_primary_credit'], true), 422);
        $payload = ['track_id' => $track->id, 'code' => $code];
        $snapshot = ['tracks' => [$track->id => $this->snapshots->track($track)]];

        return [$payload, $snapshot, mb_substr('گزارش تعمیر #'.$track->id.' / '.$code, 0, 240)];
    }
}
