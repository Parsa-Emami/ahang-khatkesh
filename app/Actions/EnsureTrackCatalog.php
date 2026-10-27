<?php

namespace App\Actions;

use App\Models\Artist;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

class EnsureTrackCatalog
{
    public function handle(Track $track, ?int $artistId = null, ?int $releaseId = null): void
    {
        if (! $track->artists()->exists()) {
            $artist = $artistId ? Artist::findOrFail($artistId) : Artist::firstOrCreate(['provenance' => 'track:'.$track->id], ['name' => $track->artist]);
            $track->artists()->attach($artist->id, ['role' => 'primary', 'position' => 1]);
            if ($releaseId || filled($track->album)) {
                $release = $releaseId ? Release::findOrFail($releaseId) : Release::firstOrCreate(['provenance' => 'track:'.$track->id], ['title' => $track->album, 'type' => 'album']);
                if (! $release->artists()->exists()) {
                    $release->artists()->attach($artist->id, ['position' => 1]);
                }
                $number = ((int) $release->tracks()->wherePivot('disc_number', 1)->max('release_track.track_number')) + 1;
                $release->tracks()->syncWithoutDetaching([$track->id => ['disc_number' => 1, 'track_number' => $number]]);
                $track->album = $release->title;
                $track->primary_release_id = $release->id;
            }
            $track->artist = $artist->name;
            $track->save();
        }
        DB::table('track_media')->insertOrIgnore(['track_id' => $track->id, 'media_asset_id' => $track->media_asset_id, 'purpose' => 'original', 'label' => 'original']);
    }
}
