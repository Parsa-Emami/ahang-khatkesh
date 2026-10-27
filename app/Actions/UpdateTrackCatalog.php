<?php

namespace App\Actions;

use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTrackCatalog
{
    public function handle(Track $track, User $actor, array $data): Track
    {
        return DB::transaction(function () use ($track, $actor, $data): Track {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $locked = Track::whereKey($track->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->catalog_revision === (int) $data['revision'], 409, 'اطلاعات آهنگ تغییر کرده است؛ دوباره بارگذاری کنید.');
            $locked->artists()->detach();
            $credits = collect($data['credits'])->map(fn ($credit, $i) => ['track_id' => $locked->id, 'artist_id' => $credit['artist_id'], 'role' => $credit['role'], 'position' => $i + 1])->all();
            DB::table('artist_track')->insert($credits);
            $names = Artist::whereIn('id', collect($data['credits'])->whereIn('role', ['primary', 'featured'])->pluck('artist_id'))->pluck('name', 'id');
            $display = collect($data['credits'])->whereIn('role', ['primary', 'featured'])->map(fn ($c) => $names[$c['artist_id']])->implode('، ');
            $primary = $data['primary_release_id'] ?? null;
            if ($primary !== null) {
                abort_unless($locked->releases()->where('releases.id', $primary)->exists(), 422, 'ابتدا آهنگ را به فهرست انتشار اضافه کنید.');
            }
            $locked->update(['title' => $data['title'], 'artist' => $display, 'primary_release_id' => $primary, 'album' => $primary ? Release::findOrFail($primary)->title : null, 'catalog_revision' => $locked->catalog_revision + 1]);
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'catalog.track_updated', 'subject_type' => 'track', 'subject_id' => (string) $locked->id, 'metadata' => ['revision' => $locked->catalog_revision]]);
            return $locked;
        }, 5);
    }
}
