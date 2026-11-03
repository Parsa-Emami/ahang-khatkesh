<?php

namespace App\Actions\Catalog;

use App\Actions\AuthorizeLibraryWrite;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\CatalogChangeBatch;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Catalog\CatalogSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PreviewCatalogBatch
{
    public function __construct(
        private AuthorizeLibraryWrite $authorize,
        private CatalogSnapshot $snapshots,
    ) {}

    public function handle(User $actor, array $data): CatalogChangeBatch
    {
        return DB::transaction(function () use ($actor, $data): CatalogChangeBatch {
            $actor = $this->authorize->handle($actor);
            $kind = $data['kind'];
            abort_unless(in_array($kind, CatalogChangeBatch::KINDS, true), 422);
            [$plan, $snapshot, $effects] = match ($kind) {
                'track_metadata' => $this->trackMetadata($data['changes'] ?? []),
                'artist_rename' => $this->artistRename($data['changes'] ?? []),
                'track_move' => $this->trackMove($data['changes'] ?? []),
                'merge_recordings' => $this->mergeRecordings($data),
                'repair' => $this->repair($data['changes'] ?? []),
            };
            $canonical = $this->canonicalize(['kind' => $kind, 'plan' => $plan]);
            $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $batch = CatalogChangeBatch::create([
                'id' => (string) Str::uuid(),
                'created_by' => $actor->id,
                'kind' => $kind,
                'status' => CatalogChangeBatch::PREVIEWED,
                'plan_hash' => $hash,
                'plan' => $plan,
                'snapshot' => $snapshot,
                'result' => ['effects' => $effects, 'dry_run' => true],
                'expires_at' => now()->addMinutes(30),
            ]);
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.batch_previewed',
                'subject_type' => 'catalog_change_batch',
                'subject_id' => $batch->id,
                'metadata' => ['kind' => $kind, 'plan_hash' => $hash, 'effects' => count($effects)],
            ]);

            return $batch;
        }, 5);
    }

    private function trackMetadata(array $changes): array
    {
        abort_if($changes === [], 422, __('حداقل یک تغییر لازم است.'));
        abort_if(count($changes) > 100, 422, __('حداکثر صد تغییر در هر دسته مجاز است.'));
        $plan = [];
        $snapshot = ['tracks' => []];
        $effects = [];
        foreach ($changes as $index => $change) {
            $trackId = (int) ($change['track_id'] ?? 0);
            $revision = (int) ($change['revision'] ?? -1);
            $title = trim((string) ($change['title'] ?? ''));
            abort_if($trackId < 1 || $title === '' || mb_strlen($title) > 255, 422, __('تغییر آهنگ نامعتبر است.'));
            $track = Track::whereKey($trackId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $track->catalog_revision === $revision, 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $credits = $change['credits'] ?? null;
            if (is_array($credits)) {
                $this->assertCredits($credits);
            }
            $plan[] = [
                'track_id' => $track->id,
                'revision' => $revision,
                'title' => $title,
                'credits' => $credits,
                'primary_release_id' => array_key_exists('primary_release_id', $change) ? $change['primary_release_id'] : '__keep__',
            ];
            $snapshot['tracks'][$track->id] = $this->snapshots->track($track);
            $effects[] = ['track_id' => $track->id, 'from_title' => $track->title, 'to_title' => $title, 'index' => $index];
        }

        return [$plan, $snapshot, $effects];
    }

    private function artistRename(array $changes): array
    {
        abort_if($changes === [] || count($changes) > 50, 422, __('تغییر هنرمند نامعتبر است.'));
        $plan = [];
        $snapshot = ['artists' => [], 'tracks' => []];
        $effects = [];
        foreach ($changes as $change) {
            $artistId = (int) ($change['artist_id'] ?? 0);
            $revision = (int) ($change['revision'] ?? -1);
            $name = trim((string) ($change['name'] ?? ''));
            abort_if($artistId < 1 || $name === '' || mb_strlen($name) > 255, 422, __('نام هنرمند نامعتبر است.'));
            $artist = Artist::whereKey($artistId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $artist->revision === $revision, 409, __('اطلاعات هنرمند تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $plan[] = [
                'artist_id' => $artist->id,
                'revision' => $revision,
                'name' => $name,
                'sort_name' => $change['sort_name'] ?? $artist->sort_name,
            ];
            $snapshot['artists'][$artist->id] = $this->snapshots->artist($artist);
            foreach ($artist->tracks()->lockForUpdate()->get() as $track) {
                $snapshot['tracks'][$track->id] = $this->snapshots->track($track);
            }
            $effects[] = ['artist_id' => $artist->id, 'from_name' => $artist->name, 'to_name' => $name];
        }

        return [$plan, $snapshot, $effects];
    }

    private function trackMove(array $changes): array
    {
        abort_if($changes === [] || count($changes) > 100, 422, __('جابه‌جایی نامعتبر است.'));
        $plan = [];
        $snapshot = ['tracks' => [], 'releases' => []];
        $effects = [];
        foreach ($changes as $change) {
            $trackId = (int) ($change['track_id'] ?? 0);
            $revision = (int) ($change['revision'] ?? -1);
            $releaseId = (int) ($change['primary_release_id'] ?? 0);
            $disc = (int) ($change['disc_number'] ?? 1);
            $number = (int) ($change['track_number'] ?? 1);
            abort_if($trackId < 1 || $releaseId < 1 || $disc < 1 || $number < 1, 422, __('جابه‌جایی نامعتبر است.'));
            $track = Track::whereKey($trackId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $track->catalog_revision === $revision, 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $release = Release::whereKey($releaseId)->lockForUpdate()->firstOrFail();
            $conflict = DB::table('release_track')
                ->where('release_id', $release->id)
                ->where('disc_number', $disc)
                ->where('track_number', $number)
                ->where('track_id', '!=', $track->id)
                ->exists();
            abort_if($conflict, 422, __('شمارهٔ آهنگ در هر دیسک باید یکتا باشد.'));
            $plan[] = [
                'track_id' => $track->id,
                'revision' => $revision,
                'primary_release_id' => $release->id,
                'disc_number' => $disc,
                'track_number' => $number,
            ];
            $snapshot['tracks'][$track->id] = $this->snapshots->track($track);
            $snapshot['releases'][$release->id] = $this->snapshots->release($release);
            $effects[] = ['track_id' => $track->id, 'primary_release_id' => $release->id, 'disc_number' => $disc, 'track_number' => $number];
        }

        return [$plan, $snapshot, $effects];
    }

    private function mergeRecordings(array $data): array
    {
        $survivorId = (int) ($data['survivor_track_id'] ?? 0);
        $sourceId = (int) ($data['source_track_id'] ?? 0);
        $survivorRevision = (int) ($data['survivor_revision'] ?? -1);
        $sourceRevision = (int) ($data['source_revision'] ?? -1);
        abort_if($survivorId < 1 || $sourceId < 1 || $survivorId === $sourceId, 422, __('ادغام به دو ضبط متمایز نیاز دارد.'));
        $survivor = Track::whereKey($survivorId)->lockForUpdate()->firstOrFail();
        $source = Track::whereKey($sourceId)->lockForUpdate()->firstOrFail();
        abort_unless((int) $survivor->catalog_revision === $survivorRevision && (int) $source->catalog_revision === $sourceRevision, 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
        abort_if($survivor->media_asset_id === $source->media_asset_id, 422, __('ادغام فایل یکسان مجاز نیست.'));
        $plan = [[
            'survivor_track_id' => $survivor->id,
            'source_track_id' => $source->id,
            'survivor_revision' => $survivorRevision,
            'source_revision' => $sourceRevision,
        ]];
        $snapshot = [
            'tracks' => [
                $survivor->id => $this->snapshots->track($survivor),
                $source->id => $this->snapshots->track($source),
            ],
        ];
        $effects = [[
            'survivor_track_id' => $survivor->id,
            'source_track_id' => $source->id,
            'likes_moved' => DB::table('liked_tracks')->where('track_id', $source->id)->count(),
            'playlists_touched' => DB::table('playlist_track')->where('track_id', $source->id)->count(),
        ]];

        return [$plan, $snapshot, $effects];
    }

    private function repair(array $changes): array
    {
        abort_if($changes === [] || count($changes) > 100, 422, __('تعمیر نامعتبر است.'));
        $plan = [];
        $snapshot = ['tracks' => []];
        $effects = [];
        foreach ($changes as $change) {
            $trackId = (int) ($change['track_id'] ?? 0);
            $revision = (int) ($change['revision'] ?? -1);
            $action = (string) ($change['action'] ?? '');
            abort_unless(in_array($action, ['clear_primary_release', 'reattach_primary_release'], true), 422, __('اقدام تعمیر نامعتبر است.'));
            $track = Track::withTrashed()->whereKey($trackId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $track->catalog_revision === $revision, 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
            if ($action === 'reattach_primary_release') {
                $releaseId = (int) ($change['primary_release_id'] ?? 0);
                abort_if($releaseId < 1, 422, __('انتشار لازم است.'));
                abort_unless(DB::table('release_track')->where('track_id', $track->id)->where('release_id', $releaseId)->exists(), 422, __('ابتدا آهنگ را به فهرست انتشار اضافه کنید.'));
            }
            $plan[] = [
                'track_id' => $track->id,
                'revision' => $revision,
                'action' => $action,
                'primary_release_id' => $change['primary_release_id'] ?? null,
            ];
            $snapshot['tracks'][$track->id] = $this->snapshots->track($track);
            $effects[] = ['track_id' => $track->id, 'action' => $action];
        }

        return [$plan, $snapshot, $effects];
    }

    private function assertCredits(array $credits): void
    {
        if ($credits === [] || count($credits) > 30) {
            throw ValidationException::withMessages(['credits' => __('حداقل یک هنرمند اصلی لازم است؛ نقش تکراری برای یک هنرمند مجاز نیست.')]);
        }
        $pairs = [];
        $hasPrimary = false;
        foreach ($credits as $credit) {
            $artistId = (int) ($credit['artist_id'] ?? 0);
            $role = (string) ($credit['role'] ?? '');
            abort_unless($artistId > 0 && in_array($role, ['primary', 'featured', 'composer', 'producer'], true) && Artist::whereKey($artistId)->exists(), 422, __('اعتبار هنرمند نامعتبر است.'));
            $key = $artistId.':'.$role;
            abort_if(isset($pairs[$key]), 422, __('حداقل یک هنرمند اصلی لازم است؛ نقش تکراری برای یک هنرمند مجاز نیست.'));
            $pairs[$key] = true;
            $hasPrimary = $hasPrimary || $role === 'primary';
        }
        abort_unless($hasPrimary, 422, __('حداقل یک هنرمند اصلی لازم است؛ نقش تکراری برای یک هنرمند مجاز نیست.'));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
