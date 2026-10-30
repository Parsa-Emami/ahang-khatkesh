<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackMedia;
use Illuminate\Support\Facades\DB;

class UpdatePlaylist
{
    public function __construct(private AuthorizeLibraryWrite $authorize, private PlaybackMedia $playback) {}

    public function handle(Playlist $playlist, User $actor, array $data, ?array $tracks = null): Playlist
    {
        return DB::transaction(function () use ($playlist, $actor, $data, $tracks): Playlist {
            $actor = $this->authorize->handle($actor);
            $locked = Playlist::whereKey($playlist->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->revision === (int) $data['revision'], 409, __('این پلی‌لیست هم‌زمان تغییر کرده است؛ اطلاعات تازه را بارگذاری کنید.'));
            if ($tracks !== null) {
                abort_unless(Track::canonical()->whereIn('id', $tracks)->count() === count($tracks), 422, __('بعضی آهنگ‌ها در دسترس نیستند.'));
                $locked->tracks()->detach();
                foreach ($tracks as $i => $id) {
                    $locked->tracks()->attach($id, ['position' => $i + 1]);
                }
            }
            $locked->fill(collect($data)->except('revision')->all());
            abort_if($locked->status === Playlist::PUBLISHED && (isset($data['status']) || $tracks !== null) && ! $locked->tracks()->exists(), 422, __('برای انتشار، حداقل یک آهنگ لازم است.'));
            if ($locked->status === Playlist::PUBLISHED) {
                $this->playback->assertReady($locked->tracks()->pluck('media_asset_id')->all());
            }
            $locked->revision++;
            $locked->save();
            AuditEvent::create(['user_id' => $actor->id, 'action' => $tracks === null ? 'playlist.updated' : 'playlist.tracks_updated', 'subject_id' => (string) $locked->id, 'metadata' => ['revision' => $locked->revision, 'tracks' => $tracks === null ? $locked->tracks()->count() : count($tracks)]]);

            return $locked;
        }, 5);
    }
}
