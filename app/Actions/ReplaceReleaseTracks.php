<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReplaceReleaseTracks
{
    public function handle(User $actor, Release $release, array $data): void
    {
        DB::transaction(function () use ($actor, $release, $data): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            abort_unless(User::whereKey($actor->id)->firstOrFail()->hasPermission(Permission::CatalogManage), 403);
            $locked = Release::whereKey($release->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->revision === (int) $data['revision'], 409, 'اطلاعات انتشار تغییر کرده است.');
            Track::where('primary_release_id', $locked->id)->whereNotIn('id', array_column($data['tracks'], 'track_id'))->get()->each(fn ($track) => $track->update(['primary_release_id' => null, 'album' => null, 'catalog_revision' => $track->catalog_revision + 1]));
            $locked->tracks()->detach();
            foreach ($data['tracks'] as $row) {
                $locked->tracks()->attach($row['track_id'], ['disc_number' => $row['disc_number'], 'track_number' => $row['track_number']]);
            }
            $locked->increment('revision');
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'catalog.release_tracks_updated', 'subject_type' => 'release', 'subject_id' => (string) $release->id, 'metadata' => ['revision' => $locked->revision, 'tracks' => count($data['tracks'])]]);
        }, 5);
    }
}
