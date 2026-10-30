<?php

namespace App\Services\Catalog;

use App\Models\Artist;
use App\Models\MediaAsset;
use App\Models\MediaConversion;
use App\Models\Playlist;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Support\Facades\DB;

class CatalogSnapshot
{
    public function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    public function capture(array $scope): array
    {
        $tracks = Track::withTrashed()->whereIn('id', $scope['tracks'])->with(['artists', 'releases', 'mediaAsset.blob'])->orderBy('id')->get();
        $links = DB::table('track_media')->whereIn('track_id', $scope['tracks'])->orderBy('track_id')->orderBy('label')->get()->groupBy('track_id');
        $trackRows = $tracks->map(function (Track $track) use ($links): array {
            $credits = $track->artists->map(fn ($artist) => ['artist_id' => $artist->id, 'role' => $artist->pivot->role, 'position' => (int) $artist->pivot->position, 'name' => $artist->name, 'revision' => (int) $artist->revision])->all();
            usort($credits, fn ($a, $b) => [$a['position'], $a['artist_id'], $a['role']] <=> [$b['position'], $b['artist_id'], $b['role']]);

            return $track->only(['id', 'title', 'artist', 'album', 'primary_release_id', 'media_asset_id', 'catalog_revision', 'recording_identity', 'recording_provenance', 'merged_into_id']) + [
                'deleted_at' => $track->deleted_at?->toISOString(),
                'credits' => $credits,
                'releases' => $track->releases->sortBy('id')->values()->map(fn ($release) => ['id' => $release->id, 'title' => $release->title, 'revision' => (int) $release->revision, 'disc_number' => (int) $release->pivot->disc_number, 'track_number' => (int) $release->pivot->track_number])->all(),
                'media_links' => ($links->get($track->id) ?? collect())->map(fn ($link) => ['media_asset_id' => $link->media_asset_id, 'purpose' => $link->purpose, 'label' => $link->label])->all(),
            ];
        })->all();
        $playlists = Playlist::withTrashed()->whereIn('id', $scope['playlists'])->orderBy('id')->get();
        $members = DB::table('playlist_track')->whereIn('playlist_id', $scope['playlists'])->orderBy('playlist_id')->orderBy('position')->orderBy('track_id')->get()->groupBy('playlist_id');
        $playlistRows = $playlists->map(fn ($playlist) => $playlist->only(['id', 'name', 'description', 'mood', 'status']) + [
            'revision' => (int) $playlist->revision,
            'deleted_at' => $playlist->deleted_at?->toISOString(),
            'tracks' => ($members->get($playlist->id) ?? collect())->map(fn ($row) => ['track_id' => (int) $row->track_id, 'position' => (int) $row->position])->all(),
        ])->all();
        $assetIds = array_values(array_unique(array_merge($scope['assets'] ?? [], $tracks->pluck('media_asset_id')->all(), $links->flatten(1)->pluck('media_asset_id')->all())));
        $assetLinks = DB::table('track_media')->whereIn('media_asset_id', $assetIds)->orderBy('track_id')->orderBy('label')->get()->groupBy('media_asset_id');
        $originals = Track::withTrashed()->whereIn('media_asset_id', $assetIds)->orderBy('id')->get(['id', 'media_asset_id'])->groupBy('media_asset_id');
        $assets = MediaAsset::whereIn('id', $assetIds)->with('blob')->orderBy('id')->get()->map(fn ($asset) => $asset->only(['id', 'sha256', 'size_bytes', 'duration_seconds', 'ownership_revision', 'source_asset_id', 'codec', 'mime_type']) + [
            'blob_id' => $asset->blob_id,
            'blob_state' => $asset->blob?->state,
            'original_track_ids' => ($originals->get($asset->id) ?? collect())->pluck('id')->all(),
            'links' => ($assetLinks->get($asset->id) ?? collect())->map(fn ($row) => ['track_id' => (int) $row->track_id, 'purpose' => $row->purpose, 'label' => $row->label])->all(),
        ])->all();
        $result = ['tracks' => $trackRows, 'playlists' => $playlistRows, 'assets' => $assets, 'artists' => Artist::whereIn('id', $scope['artists'] ?? [])->orderBy('id')->get(['id', 'name', 'revision'])->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'revision' => (int) $row->revision])->all(), 'releases' => Release::whereIn('id', $scope['releases'] ?? [])->orderBy('id')->get(['id', 'title', 'revision'])->map(fn ($row) => ['id' => $row->id, 'title' => $row->title, 'revision' => (int) $row->revision])->all()];
        if ($scope['check_conversions'] ?? false) {
            $result['conversions'] = MediaConversion::whereIn('source_asset_id', $assetIds)->orderBy('id')->get(['id', 'source_asset_id', 'output_asset_id', 'status', 'revision', 'generation', 'source_revision'])->toArray();
        }

        return $result;
    }

    public function trackSummary(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'title', 'artist', 'album', 'primary_release_id', 'catalog_revision', 'recording_identity', 'recording_provenance', 'merged_into_id', 'media_asset_id']));
    }

    public function playlistSummary(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'name', 'description', 'mood', 'status', 'revision', 'tracks']));
    }
}
