<?php

namespace App\Services\Catalog;

use App\Models\Artist;
use App\Models\MediaAsset;
use App\Models\MediaConversion;
use App\Models\Playlist;
use App\Models\Track;
use App\Services\MediaFiles;
use App\Services\PlaybackMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CatalogPlan
{
    public function __construct(private CatalogSnapshot $snapshots, private MemberProposals $proposals, private PlaybackMedia $media, private MediaFiles $files) {}

    private function track(int $id, ?int $revision = null): Track
    {
        $track = Track::canonical()->with(['artists', 'releases', 'mediaAsset.blob'])->findOrFail($id);
        abort_unless($revision === null || $track->catalog_revision === $revision, 409, __('catalog.changed'));

        return $track;
    }

    private function playlist(int $id, int $revision): Playlist
    {
        $playlist = Playlist::findOrFail($id);
        abort_unless((int) $playlist->revision === $revision, 409, __('catalog.changed'));

        return $playlist;
    }

    private function members(int $id): array
    {
        return DB::table('playlist_track')->where('playlist_id', $id)->orderBy('position')->orderBy('track_id')->pluck('track_id')->map(fn ($id) => (int) $id)->all();
    }

    public function assertPlayable(array $ids, bool $published): void
    {
        $tracks = Track::canonical()->whereIn('id', $ids)->with('mediaAsset.blob')->get();
        abort_unless($tracks->count() === count($ids), 422, __('catalog.unavailable_tracks'));
        if (! $published) {
            return;
        }
        abort_if($ids === [], 422, __('catalog.empty_published'));
        foreach ($tracks as $track) {
            abort_unless($track->mediaAsset->duration_seconds > 0 && (! $track->mediaAsset->blob_id || $track->mediaAsset->blob?->state === 'ready') && Storage::disk('media')->exists($track->mediaAsset->storagePath()), 422, __('catalog.media_unavailable'));
        }
        $this->media->assertReady($tracks->pluck('media_asset_id')->all());
    }

    public function assertBytes(MediaAsset $asset): void
    {
        abort_unless(! $asset->blob_id || $asset->blob?->state === 'ready', 422, __('catalog.media_unavailable'));
        abort_unless($this->files->snapshot($asset->storagePath()) === ['size_bytes' => $asset->size_bytes, 'sha256' => $asset->sha256], 422, __('catalog.media_mismatch'));
    }

    private function noActiveConversions(array $assets): void
    {
        abort_if(MediaConversion::whereIn('source_asset_id', $assets)->whereIn('status', MediaConversion::ACTIVE)->exists(), 409, __('catalog.conversion_active'));
    }

    public function build(array $input): array
    {
        $scope = ['tracks' => [], 'playlists' => [], 'assets' => [], 'artists' => [], 'releases' => []];
        $summary = [];
        $proposal = null;
        if ($input['operation'] === 'metadata') {
            $tracks = Track::canonical()->whereIn('id', array_column($input['rows'], 'id'))->with(['artists', 'releases', 'mediaAsset.blob'])->get()->keyBy('id');
            $artistIds = collect($input['rows'])->flatMap(fn ($row) => array_column($row['credits'], 'artist_id'))->unique()->all();
            $artists = Artist::whereIn('id', $artistIds)->orderBy('id')->get(['id', 'name', 'revision'])->keyBy('id');
            foreach ($input['rows'] as $row) {
                $track = $tracks->get((int) $row['id']);
                abort_unless($track, 404);
                abort_unless($track->catalog_revision === (int) $row['revision'], 409, __('catalog.changed'));
                $ids = array_values(array_unique(array_column($row['credits'], 'artist_id')));
                abort_unless(count(array_intersect($ids, $artists->keys()->all())) === count($ids), 422, __('catalog.credits'));
                abort_unless(($row['primary_release_id'] ?? null) === null || $track->releases->contains('id', $row['primary_release_id']), 422, __('catalog.release_membership'));
                if (array_key_exists('recording_identity', $row) && ($row['recording_identity'] !== $track->recording_identity || $row['recording_provenance'] !== $track->recording_provenance)) {
                    abort_if(Track::withTrashed()->where('merged_into_id', $track->id)->exists(), 409, __('catalog.identity_in_use'));
                }
                $scope['tracks'][] = $track->id;
                $scope['artists'] = array_merge($scope['artists'], $ids);
                if ($row['primary_release_id'] !== null) {
                    $scope['releases'][] = (int) $row['primary_release_id'];
                }
                $names = $artists->filter(fn ($artist) => in_array($artist->id, $ids))->sortKeys();
                $summary[] = ['entity' => 'track', 'id' => $track->id, 'before' => ['credits' => $track->artists->map(fn ($artist) => ['artist_id' => $artist->id, 'role' => $artist->pivot->role])->all(), 'title' => $track->title, 'artist' => $track->artist, 'primary_release_id' => $track->primary_release_id, 'recording_identity' => $track->recording_identity, 'recording_provenance' => $track->recording_provenance], 'after' => ['credits' => $row['credits'], 'title' => $row['title'], 'artist' => collect($row['credits'])->whereIn('role', ['primary', 'featured'])->map(fn ($credit) => $names[$credit['artist_id']]->name)->implode('، '), 'primary_release_id' => $row['primary_release_id'], 'recording_identity' => array_key_exists('recording_identity', $row) ? $row['recording_identity'] : $track->recording_identity, 'recording_provenance' => array_key_exists('recording_provenance', $row) ? $row['recording_provenance'] : $track->recording_provenance], 'credit_snapshot' => $names->values()->toArray()];
            }
        } elseif ($input['operation'] === 'playlists') {
            foreach ($input['rows'] as $row) {
                $playlist = $this->playlist((int) $row['id'], (int) $row['revision']);
                $ids = $this->members($playlist->id);
                $this->assertPlayable($ids, $row['status'] === Playlist::PUBLISHED);
                $scope['playlists'][] = $playlist->id;
                $scope['tracks'] = array_merge($scope['tracks'], $ids);
                $summary[] = ['entity' => 'playlist', 'id' => $playlist->id, 'before' => $playlist->only(['name', 'description', 'mood', 'status']), 'after' => array_intersect_key($row, array_flip(['name', 'description', 'mood', 'status']))];
            }
        } elseif ($input['operation'] === 'move') {
            $source = $this->playlist((int) $input['source_playlist_id'], (int) $input['source_revision']);
            $target = $this->playlist((int) $input['target_playlist_id'], (int) $input['target_revision']);
            abort_if($source->id === $target->id, 422, __('catalog.move_membership'));
            $sourceIds = $this->members($source->id);
            $targetIds = $this->members($target->id);
            abort_unless(array_diff($input['track_ids'], $sourceIds) === [], 422, __('catalog.move_membership'));
            $nextSource = array_values(array_diff($sourceIds, $input['track_ids']));
            $nextTarget = array_values(array_unique(array_merge($targetIds, $input['track_ids'])));
            abort_if(count($nextTarget) > 500, 422, __('catalog.playlist_limit'));
            $this->assertPlayable($nextSource, $source->status === Playlist::PUBLISHED);
            $this->assertPlayable($nextTarget, $target->status === Playlist::PUBLISHED);
            $scope['playlists'] = [$source->id, $target->id];
            $scope['tracks'] = array_merge($sourceIds, $targetIds);
            foreach ([[$source, $sourceIds, $nextSource], [$target, $targetIds, $nextTarget]] as [$playlist, $before, $after]) {
                $summary[] = ['entity' => 'playlist_order', 'id' => $playlist->id, 'before' => $before, 'after' => $after];
            }
        } elseif ($input['operation'] === 'merge') {
            $root = $this->track((int) $input['canonical_id'], (int) $input['canonical_revision']);
            abort_if(in_array($root->id, array_map('intval', $input['source_ids']), true), 422, __('catalog.merge_self'));
            abort_unless(filled($root->recording_identity) && $root->recording_identity === $input['recording_identity'] && $root->recording_provenance === $input['recording_provenance'], 422, __('catalog.identity_required'));
            $this->assertBytes($root->mediaAsset);
            $this->media->assertReady([$root->media_asset_id]);
            if ($playback = $this->media->ready($root->mediaAsset)) {
                $this->assertBytes($playback);
            }
            $public = $root->playlists()->where('status', Playlist::PUBLISHED)->exists();
            foreach ($input['source_ids'] as $id) {
                $source = $this->track((int) $id);
                abort_unless($source->recording_identity === $root->recording_identity && $source->recording_provenance === $root->recording_provenance, 422, __('catalog.identity_mismatch'));
                abort_unless($source->playlists()->where('status', Playlist::PUBLISHED)->exists() === $public, 422, __('catalog.visibility_mismatch'));
                abort_if(abs($source->mediaAsset->duration_seconds - $root->mediaAsset->duration_seconds) > 0.25, 422, __('catalog.duration_mismatch'));
            }
            $all = Track::withTrashed()->where(fn ($query) => $query->whereIn('id', array_merge([$root->id], $input['source_ids']))->orWhereIn('merged_into_id', array_merge([$root->id], $input['source_ids'])))->orderBy('id')->get();
            abort_if($all->count() > 100 || $all->contains(fn ($track) => $track->trashed()), 422, __('catalog.merge_limit'));
            $scope['tracks'] = $all->pluck('id')->all();
            $this->noActiveConversions($all->pluck('media_asset_id')->all());
            $scope['playlists'] = DB::table('playlist_track')->whereIn('track_id', $scope['tracks'])->distinct()->orderBy('playlist_id')->pluck('playlist_id')->map(fn ($id) => (int) $id)->all();
            abort_if(count($scope['playlists']) > 50, 422, __('catalog.merge_limit'));
            foreach ($scope['playlists'] as $id) {
                $playlist = Playlist::findOrFail($id);
                $before = $this->members($id);
                $after = array_values(array_unique(array_map(fn ($trackId) => in_array($trackId, $scope['tracks'], true) ? $root->id : $trackId, $before)));
                $this->assertPlayable($after, $playlist->status === Playlist::PUBLISHED);
                $summary[] = ['entity' => 'playlist_order', 'id' => $id, 'before' => $before, 'after' => $after];
            }
            $summary[] = ['entity' => 'recording_merge', 'id' => $root->id, 'before' => ['sources' => $input['source_ids']], 'after' => ['canonical_id' => $root->id, 'identity' => $root->recording_identity, 'provenance' => $root->recording_provenance, 'retained_ids' => $scope['tracks']]];
        } elseif ($input['operation'] === 'repair') {
            $track = $this->track((int) $input['track_id'], (int) $input['revision']);
            abort_unless(filled($track->recording_identity) && $track->recording_identity === $input['recording_identity'] && $track->recording_provenance === $input['recording_provenance'], 422, __('catalog.identity_required'));
            $asset = MediaAsset::with(['blob', 'conversions.output.blob'])->findOrFail($input['asset_id']);
            abort_if($asset->id === $track->media_asset_id || $asset->source_asset_id || Track::withTrashed()->where('media_asset_id', $asset->id)->exists() || DB::table('track_media')->where('media_asset_id', $asset->id)->exists(), 422, __('catalog.asset_owned'));
            abort_if(abs($asset->duration_seconds - $track->mediaAsset->duration_seconds) > 0.25, 422, __('catalog.duration_mismatch'));
            $this->noActiveConversions([$track->media_asset_id, $asset->id]);
            $this->assertBytes($asset);
            $this->media->assertReady([$asset->id]);
            $scope['tracks'] = [$track->id];
            $scope['assets'] = [$asset->id, $track->media_asset_id];
            $scope['check_conversions'] = true;
            $output = $this->media->ready($asset);
            if ($output) {
                $this->assertBytes($output);
                abort_if(DB::table('track_media')->where('media_asset_id', $output->id)->exists(), 422, __('catalog.asset_owned'));
                $scope['assets'][] = $output->id;
            }
            $summary[] = ['entity' => 'media_repair', 'id' => $track->id, 'before' => ['asset_id' => $track->media_asset_id, 'sha256' => $track->mediaAsset->sha256, 'source_exists' => Storage::disk('media')->exists($track->mediaAsset->storagePath())], 'after' => ['asset_id' => $asset->id, 'sha256' => $asset->sha256, 'size_bytes' => $asset->size_bytes, 'recording_identity' => $track->recording_identity]];
        } elseif ($input['operation'] === 'proposal') {
            $proposal = $this->proposals->pending($input['proposal_id'], (int) $input['revision']);
            $payload = $proposal->payload;
            $scope['tracks'] = $proposal->kind === 'playlist' ? $payload['track_ids'] : [$payload['track_id']];
            if ($proposal->kind === 'playlist') {
                $this->assertPlayable($scope['tracks'], $input['publish'] ?? false);
                $summary[] = ['entity' => 'new_playlist', 'id' => null, 'before' => null, 'after' => ['name' => $payload['name'], 'description' => $payload['description'], 'mood' => $payload['mood'], 'status' => ($input['publish'] ?? false) ? Playlist::PUBLISHED : Playlist::DRAFT, 'track_ids' => $scope['tracks']]];
            } else {
                $track = $this->track((int) $payload['track_id']);
                $summary[] = ['entity' => 'track', 'id' => $track->id, 'before' => ['title' => $track->title], 'after' => ['title' => $payload['title']]];
            }
        }
        $scope['assets'] = array_merge($scope['assets'], DB::table('track_media')->whereIn('track_id', $scope['tracks'])->pluck('media_asset_id')->all(), Track::whereIn('id', $scope['tracks'])->pluck('media_asset_id')->all());
        $scope['artists'] = array_merge($scope['artists'], DB::table('artist_track')->whereIn('track_id', $scope['tracks'])->pluck('artist_id')->all());
        $scope['releases'] = array_merge($scope['releases'], DB::table('release_track')->whereIn('track_id', $scope['tracks'])->pluck('release_id')->all());
        foreach (['tracks', 'playlists', 'assets', 'artists', 'releases'] as $key) {
            $scope[$key] = array_values(array_unique($scope[$key]));
            sort($scope[$key]);
        }
        $before = $this->snapshots->capture($scope);
        if ($proposal) {
            $before['proposal'] = ['id' => $proposal->id, 'revision' => $proposal->revision, 'status' => $proposal->status, 'payload_hash' => $proposal->payload_hash];
        }
        $hashInput = ['input' => $input, 'scope' => $scope, 'before' => $before];
        if ($input['operation'] === 'metadata') {
            $hashInput['requested_credits'] = array_column($summary, 'credit_snapshot');
        }

        return ['input' => $input, 'scope' => $scope, 'before' => $before, 'summary' => $summary, 'snapshot_hash' => $this->snapshots->hash($hashInput), 'proposal_id' => $proposal?->id];
    }
}
