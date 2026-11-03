<?php

namespace App\Actions\Catalog;

use App\Actions\AuthorizeLibraryWrite;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\CatalogChangeBatch;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommitCatalogBatch
{
    public function __construct(private AuthorizeLibraryWrite $authorize) {}

    public function handle(User $actor, CatalogChangeBatch $batch, string $planHash): CatalogChangeBatch
    {
        return DB::transaction(function () use ($actor, $batch, $planHash): CatalogChangeBatch {
            $actor = $this->authorize->handle($actor);
            $locked = CatalogChangeBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->created_by === $actor->id, 403);
            abort_unless($locked->isPreviewAlive(), 409, __('پیش‌نمایش منقضی یا مصرف شده است؛ دوباره بسازید.'));
            abort_unless(hash_equals($locked->plan_hash, $planHash), 409, __('برنامه تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.'));
            $applied = match ($locked->kind) {
                'track_metadata' => $this->applyTrackMetadata($locked->plan),
                'artist_rename' => $this->applyArtistRename($locked->plan),
                'track_move' => $this->applyTrackMove($locked->plan),
                'merge_recordings' => $this->applyMerge($locked->plan[0]),
                'repair' => $this->applyRepair($locked->plan),
            };
            $locked->update([
                'status' => CatalogChangeBatch::COMMITTED,
                'committed_at' => now(),
                'result' => ['effects' => $applied, 'dry_run' => false],
            ]);
            AuditEvent::create([
                'user_id' => $actor->id,
                'action' => 'catalog.batch_committed',
                'subject_type' => 'catalog_change_batch',
                'subject_id' => $locked->id,
                'metadata' => ['kind' => $locked->kind, 'plan_hash' => $locked->plan_hash, 'effects' => count($applied)],
            ]);

            return $locked->fresh();
        }, 5);
    }

    private function applyTrackMetadata(array $plan): array
    {
        $effects = [];
        foreach ($plan as $change) {
            $track = Track::whereKey($change['track_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $track->catalog_revision === (int) $change['revision'], 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
            if (is_array($change['credits'])) {
                $track->artists()->detach();
                $rows = collect($change['credits'])->values()->map(fn ($credit, $i) => [
                    'track_id' => $track->id,
                    'artist_id' => $credit['artist_id'],
                    'role' => $credit['role'],
                    'position' => $i + 1,
                ])->all();
                DB::table('artist_track')->insert($rows);
                $names = Artist::whereIn('id', collect($change['credits'])->whereIn('role', ['primary', 'featured'])->pluck('artist_id'))->pluck('name', 'id');
                $display = collect($change['credits'])->whereIn('role', ['primary', 'featured'])->map(fn ($c) => $names[$c['artist_id']])->implode('، ');
            } else {
                $display = $track->artist;
            }
            $primary = $change['primary_release_id'] === '__keep__' ? $track->primary_release_id : $change['primary_release_id'];
            if ($primary !== null) {
                abort_unless($track->releases()->where('releases.id', $primary)->exists(), 422, __('ابتدا آهنگ را به فهرست انتشار اضافه کنید.'));
            }
            $track->update([
                'title' => $change['title'],
                'artist' => $display !== '' ? $display : $track->artist,
                'primary_release_id' => $primary,
                'album' => $primary ? Release::findOrFail($primary)->title : ($change['primary_release_id'] === '__keep__' ? $track->album : null),
                'catalog_revision' => $track->catalog_revision + 1,
            ]);
            $effects[] = ['track_id' => $track->id, 'revision' => $track->catalog_revision];
        }

        return $effects;
    }

    private function applyArtistRename(array $plan): array
    {
        $effects = [];
        foreach ($plan as $change) {
            $artist = Artist::whereKey($change['artist_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $artist->revision === (int) $change['revision'], 409, __('اطلاعات هنرمند تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $artist->fill([
                'name' => $change['name'],
                'sort_name' => $change['sort_name'],
            ]);
            $artist->revision = $artist->revision + 1;
            $artist->save();
            foreach ($artist->tracks()->lockForUpdate()->get() as $track) {
                $names = $track->artists()->wherePivotIn('role', ['primary', 'featured'])->orderByPivot('position')->pluck('name');
                $track->update([
                    'artist' => $names->implode('، '),
                    'catalog_revision' => $track->catalog_revision + 1,
                ]);
            }
            $effects[] = ['artist_id' => $artist->id, 'revision' => $artist->revision];
        }

        return $effects;
    }

    private function applyTrackMove(array $plan): array
    {
        $effects = [];
        foreach ($plan as $change) {
            $track = Track::whereKey($change['track_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $track->catalog_revision === (int) $change['revision'], 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
            $release = Release::whereKey($change['primary_release_id'])->lockForUpdate()->firstOrFail();
            $exists = DB::table('release_track')->where('release_id', $release->id)->where('track_id', $track->id)->exists();
            if ($exists) {
                DB::table('release_track')->where('release_id', $release->id)->where('track_id', $track->id)->update([
                    'disc_number' => $change['disc_number'],
                    'track_number' => $change['track_number'],
                ]);
            } else {
                $conflict = DB::table('release_track')
                    ->where('release_id', $release->id)
                    ->where('disc_number', $change['disc_number'])
                    ->where('track_number', $change['track_number'])
                    ->exists();
                abort_if($conflict, 422, __('شمارهٔ آهنگ در هر دیسک باید یکتا باشد.'));
                DB::table('release_track')->insert([
                    'release_id' => $release->id,
                    'track_id' => $track->id,
                    'disc_number' => $change['disc_number'],
                    'track_number' => $change['track_number'],
                ]);
            }
            $track->update([
                'primary_release_id' => $release->id,
                'album' => $release->title,
                'catalog_revision' => $track->catalog_revision + 1,
            ]);
            $release->revision = $release->revision + 1;
            $release->save();
            $effects[] = ['track_id' => $track->id, 'primary_release_id' => $release->id, 'revision' => $track->catalog_revision];
        }

        return $effects;
    }

    private function applyMerge(array $change): array
    {
        $survivor = Track::whereKey($change['survivor_track_id'])->lockForUpdate()->firstOrFail();
        $source = Track::whereKey($change['source_track_id'])->lockForUpdate()->firstOrFail();
        abort_unless((int) $survivor->catalog_revision === (int) $change['survivor_revision'], 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
        abort_unless((int) $source->catalog_revision === (int) $change['source_revision'], 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));

        $survivorLikes = DB::table('liked_tracks')->where('track_id', $survivor->id)->pluck('user_id')->all();
        foreach (DB::table('liked_tracks')->where('track_id', $source->id)->pluck('user_id') as $userId) {
            if (! in_array($userId, $survivorLikes, true)) {
                DB::table('liked_tracks')->insert(['user_id' => $userId, 'track_id' => $survivor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('liked_tracks')->where('track_id', $source->id)->delete();

        foreach (DB::table('playlist_track')->where('track_id', $source->id)->orderBy('playlist_id')->get() as $row) {
            $already = DB::table('playlist_track')->where('playlist_id', $row->playlist_id)->where('track_id', $survivor->id)->exists();
            if ($already) {
                DB::table('playlist_track')->where('playlist_id', $row->playlist_id)->where('track_id', $source->id)->delete();

                continue;
            }
            DB::table('playlist_track')->where('playlist_id', $row->playlist_id)->where('track_id', $source->id)->update(['track_id' => $survivor->id]);
        }

        foreach (DB::table('release_track')->where('track_id', $source->id)->get() as $slot) {
            $exists = DB::table('release_track')->where('release_id', $slot->release_id)->where('track_id', $survivor->id)->exists();
            if ($exists) {
                DB::table('release_track')->where('release_id', $slot->release_id)->where('track_id', $source->id)->delete();

                continue;
            }
            $numberTaken = DB::table('release_track')
                ->where('release_id', $slot->release_id)
                ->where('disc_number', $slot->disc_number)
                ->where('track_number', $slot->track_number)
                ->exists();
            if ($numberTaken) {
                DB::table('release_track')->where('release_id', $slot->release_id)->where('track_id', $source->id)->delete();

                continue;
            }
            DB::table('release_track')->where('release_id', $slot->release_id)->where('track_id', $source->id)->update(['track_id' => $survivor->id]);
        }

        $source->catalog_revision++;
        $source->save();
        $source->delete();
        $survivor->update(['catalog_revision' => $survivor->catalog_revision + 1]);

        return [[
            'survivor_track_id' => $survivor->id,
            'source_track_id' => $source->id,
            'survivor_revision' => $survivor->catalog_revision,
            'source_revision' => $source->catalog_revision,
        ]];
    }

    private function applyRepair(array $plan): array
    {
        $effects = [];
        foreach ($plan as $change) {
            $track = Track::withTrashed()->whereKey($change['track_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $track->catalog_revision === (int) $change['revision'], 409, __('اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.'));
            if ($change['action'] === 'clear_primary_release') {
                $track->primary_release_id = null;
                $track->album = null;
            } else {
                $release = Release::findOrFail($change['primary_release_id']);
                abort_unless(DB::table('release_track')->where('track_id', $track->id)->where('release_id', $release->id)->exists(), 422, __('ابتدا آهنگ را به فهرست انتشار اضافه کنید.'));
                $track->primary_release_id = $release->id;
                $track->album = $release->title;
            }
            $track->catalog_revision++;
            $track->save();
            $effects[] = ['track_id' => $track->id, 'action' => $change['action'], 'revision' => $track->catalog_revision];
        }

        return $effects;
    }
}
