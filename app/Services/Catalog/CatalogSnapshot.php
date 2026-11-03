<?php

namespace App\Services\Catalog;

use App\Models\Artist;
use App\Models\Release;
use App\Models\Track;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CatalogSnapshot
{
    public function track(Track $track): array
    {
        return [
            'id' => $track->id,
            'title' => $track->title,
            'artist' => $track->artist,
            'album' => $track->album,
            'media_asset_id' => $track->media_asset_id,
            'primary_release_id' => $track->primary_release_id,
            'catalog_revision' => (int) $track->catalog_revision,
            'deleted_at' => $track->deleted_at?->toISOString(),
            'credits' => $track->artists()->get(['artists.id', 'artist_track.role', 'artist_track.position'])->map(fn ($a) => [
                'artist_id' => $a->id,
                'role' => $a->pivot->role,
                'position' => (int) $a->pivot->position,
            ])->values()->all(),
            'releases' => $track->releases()->get(['releases.id', 'release_track.disc_number', 'release_track.track_number'])->map(fn ($r) => [
                'release_id' => $r->id,
                'disc_number' => (int) $r->pivot->disc_number,
                'track_number' => (int) $r->pivot->track_number,
            ])->values()->all(),
            'liked_by' => DB::table('liked_tracks')->where('track_id', $track->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
            'playlists' => DB::table('playlist_track')->where('track_id', $track->id)->get(['playlist_id', 'position'])->map(fn ($row) => [
                'playlist_id' => (int) $row->playlist_id,
                'position' => (int) $row->position,
            ])->all(),
        ];
    }

    public function artist(Artist $artist): array
    {
        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'sort_name' => $artist->sort_name,
            'description' => $artist->description,
            'country_code' => $artist->country_code,
            'provenance' => $artist->provenance,
            'revision' => (int) $artist->revision,
        ];
    }

    public function release(Release $release): array
    {
        return [
            'id' => $release->id,
            'title' => $release->title,
            'type' => $release->type,
            'released_on' => $release->released_on?->format('Y-m-d'),
            'provenance' => $release->provenance,
            'revision' => (int) $release->revision,
        ];
    }

    public function clearTrackRelations(int $trackId): void
    {
        DB::table('artist_track')->where('track_id', $trackId)->delete();
        DB::table('release_track')->where('track_id', $trackId)->delete();
        DB::table('liked_tracks')->where('track_id', $trackId)->delete();
        DB::table('playlist_track')->where('track_id', $trackId)->delete();
    }

    public function restoreTrack(array $snapshot, bool $relations = true): void
    {
        $track = Track::withTrashed()->whereKey($snapshot['id'])->lockForUpdate()->firstOrFail();
        if ($snapshot['deleted_at']) {
            if ($track->trashed()) {
                // keep trashed while attributes are rewritten
            } elseif (! $track->trashed()) {
                $track->delete();
                $track = Track::withTrashed()->whereKey($snapshot['id'])->lockForUpdate()->firstOrFail();
            }
        } elseif ($track->trashed()) {
            $track->restore();
            $track = Track::withTrashed()->whereKey($snapshot['id'])->lockForUpdate()->firstOrFail();
        }
        $track->fill([
            'title' => $snapshot['title'],
            'artist' => $snapshot['artist'],
            'album' => $snapshot['album'],
            'media_asset_id' => $snapshot['media_asset_id'],
            'primary_release_id' => $snapshot['primary_release_id'],
            'catalog_revision' => $snapshot['catalog_revision'],
        ]);
        $track->save();
        if (! $relations) {
            return;
        }
        $this->clearTrackRelations($track->id);
        foreach ($snapshot['credits'] as $credit) {
            DB::table('artist_track')->insert([
                'track_id' => $track->id,
                'artist_id' => $credit['artist_id'],
                'role' => $credit['role'],
                'position' => $credit['position'],
            ]);
        }
        foreach ($snapshot['releases'] as $slot) {
            DB::table('release_track')->insert([
                'release_id' => $slot['release_id'],
                'track_id' => $track->id,
                'disc_number' => $slot['disc_number'],
                'track_number' => $slot['track_number'],
            ]);
        }
        foreach ($snapshot['liked_by'] as $userId) {
            DB::table('liked_tracks')->insert(['user_id' => $userId, 'track_id' => $track->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($snapshot['playlists'] as $membership) {
            DB::table('playlist_track')->insert([
                'playlist_id' => $membership['playlist_id'],
                'track_id' => $track->id,
                'position' => $membership['position'],
            ]);
        }
    }

    public function restoreArtist(array $snapshot): void
    {
        $artist = Artist::whereKey($snapshot['id'])->lockForUpdate()->firstOrFail();
        $artist->fill([
            'name' => $snapshot['name'],
            'sort_name' => $snapshot['sort_name'],
            'description' => $snapshot['description'],
            'country_code' => $snapshot['country_code'],
            'provenance' => $snapshot['provenance'],
        ]);
        $artist->revision = $snapshot['revision'];
        $artist->save();
    }

    public function mediaMissing(?string $path): bool
    {
        return $path === null || $path === '' || ! Storage::disk('media')->exists($path);
    }

    public function normalizeTitle(string $title): string
    {
        return TextNormalizer::normalize($title);
    }
}
