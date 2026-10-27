<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdatePlaylist
{
    public function handle(Playlist $playlist, User $actor, array $data, ?array $tracks = null): Playlist
    {
        return DB::transaction(function () use ($playlist, $actor, $data, $tracks): Playlist {
            LibrarySetting::whereKey(1)->increment('lock_version');
            abort_unless(User::whereKey($actor->id)->firstOrFail()->hasPermission(Permission::CatalogManage), 403);
            $locked = Playlist::whereKey($playlist->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->revision === (int) $data['revision'], 409, 'این پلی‌لیست هم‌زمان تغییر کرده است؛ اطلاعات تازه را بارگذاری کنید.');
            if ($tracks !== null) {
                $locked->tracks()->detach();
                foreach ($tracks as $i => $id) {
                    $locked->tracks()->attach($id, ['position' => $i + 1]);
                }
            }
            $locked->fill(collect($data)->except('revision')->all());
            abort_if($locked->status === Playlist::PUBLISHED && (isset($data['status']) || $tracks !== null) && ! $locked->tracks()->exists(), 422, 'برای انتشار، حداقل یک آهنگ لازم است.');
            $locked->revision++;
            $locked->save();
            AuditEvent::create(['user_id' => $actor->id, 'action' => $tracks === null ? 'playlist.updated' : 'playlist.tracks_updated', 'subject_id' => (string) $locked->id, 'metadata' => ['revision' => $locked->revision, 'tracks' => $tracks === null ? $locked->tracks()->count() : count($tracks)]]);

            return $locked;
        }, 5);
    }
}
