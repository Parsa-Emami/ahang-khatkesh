<?php

namespace App\Services\Catalog;

use App\Actions\Conversions\ConversionExecution;
use App\Actions\UpdatePlaylist;
use App\Actions\UpdateTrackCatalog;
use App\Models\MediaAsset;
use App\Models\MemberProposal;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\PlaybackMedia;
use Illuminate\Support\Facades\DB;

class ApplyCatalogPlan
{
    public function __construct(private UpdateTrackCatalog $tracks, private UpdatePlaylist $playlists, private PlaybackMedia $media, private ConversionExecution $conversions, private CatalogPlan $plans) {}

    public function apply(User $actor, array $plan): array
    {
        $input = $plan['input'];
        $operation = $input['operation'];
        $result = [];
        if ($operation === 'metadata') {
            foreach ($input['rows'] as $row) {
                $track = $this->tracks->handle(Track::findOrFail($row['id']), $actor, $row);
                if (array_key_exists('recording_identity', $row)) {
                    $track->update(['recording_identity' => $row['recording_identity'], 'recording_provenance' => $row['recording_provenance']]);
                }
            }
        } elseif ($operation === 'playlists') {
            foreach ($input['rows'] as $row) {
                $this->playlists->handle(Playlist::findOrFail($row['id']), $actor, array_intersect_key($row, array_flip(['revision', 'name', 'description', 'mood', 'status'])));
            }
        } elseif (in_array($operation, ['move', 'merge'], true)) {
            foreach ($plan['summary'] as $row) {
                if ($row['entity'] === 'playlist_order') {
                    $playlist = Playlist::findOrFail($row['id']);
                    $this->playlists->handle($playlist, $actor, ['revision' => (int) $playlist->revision], $row['after']);
                }
            }
            if ($operation === 'merge') {
                foreach (Track::whereIn('id', $plan['scope']['tracks'])->orderBy('id')->get() as $track) {
                    $track->update(['merged_into_id' => $track->id === (int) $input['canonical_id'] ? null : (int) $input['canonical_id'], 'catalog_revision' => $track->catalog_revision + 1]);
                }
            }
        } elseif ($operation === 'repair') {
            $track = Track::findOrFail($input['track_id']);
            $asset = MediaAsset::with(['blob', 'conversions.output.blob'])->findOrFail($input['asset_id']);
            $this->plans->assertBytes($asset);
            $oldAssets = DB::table('track_media')->where('track_id', $track->id)->pluck('media_asset_id')->all();
            DB::table('track_media')->where('track_id', $track->id)->delete();
            MediaAsset::whereIn('id', $oldAssets)->increment('ownership_revision');
            $track->update(['media_asset_id' => $asset->id, 'catalog_revision' => $track->catalog_revision + 1]);
            DB::table('track_media')->insert(['track_id' => $track->id, 'media_asset_id' => $asset->id, 'purpose' => 'original', 'label' => 'original']);
            $asset->increment('ownership_revision');
            if ($output = $this->media->ready($asset)) {
                $this->plans->assertBytes($output);
                $this->conversions->attach($track, $output);
            }
        } elseif ($operation === 'proposal') {
            $proposal = MemberProposal::findOrFail($plan['proposal_id']);
            $payload = $proposal->payload;
            if ($proposal->kind === 'playlist') {
                $playlist = Playlist::create(['name' => $payload['name'], 'description' => $payload['description'], 'mood' => $payload['mood'], 'status' => Playlist::DRAFT, 'artwork_seed' => random_int(1, 1000000), 'created_by' => $actor->id]);
                $playlist = $this->playlists->handle($playlist, $actor, ['revision' => 0, 'status' => ($input['publish'] ?? false) ? Playlist::PUBLISHED : Playlist::DRAFT], $payload['track_ids']);
                $result['created_playlist_id'] = $playlist->id;
            } else {
                $track = Track::with('artists')->findOrFail($payload['track_id']);
                $this->tracks->handle($track, $actor, ['revision' => $track->catalog_revision, 'title' => $payload['title'], 'primary_release_id' => $track->primary_release_id, 'credits' => $track->artists->map(fn ($artist) => ['artist_id' => $artist->id, 'role' => $artist->pivot->role])->all()]);
            }
            $proposal->update(['status' => 'accepted', 'revision' => $proposal->revision + 1, 'reviewed_by' => $actor->id, 'resolution' => 'accepted', 'review_note' => null, 'result_playlist_id' => $result['created_playlist_id'] ?? null, 'reviewed_at' => now()]);
        }

        return $result;
    }

    public function restore(User $actor, array $before, array $result, string $operation, ?string $proposalId): void
    {
        if ($operation === 'repair') {
            foreach ($before['tracks'] as $row) {
                $asset = MediaAsset::with('blob')->findOrFail($row['media_asset_id']);
                $this->plans->assertBytes($asset);
                $this->media->assertReady([$asset->id]);
                if ($output = $this->media->ready($asset)) {
                    $this->plans->assertBytes($output);
                }
            }
            $ids = array_column($before['tracks'], 'id');
            $detached = DB::table('track_media')->whereIn('track_id', $ids)->pluck('media_asset_id')->all();
            DB::table('track_media')->whereIn('track_id', $ids)->delete();
            MediaAsset::whereIn('id', $detached)->increment('ownership_revision');
        }
        if (in_array($operation, ['metadata', 'merge', 'repair'], true) || ($operation === 'proposal' && ! isset($result['created_playlist_id']))) {
            foreach ($before['tracks'] as $row) {
                $track = Track::findOrFail($row['id']);
                $values = array_intersect_key($row, array_flip(['title', 'artist', 'album', 'primary_release_id', 'media_asset_id', 'recording_identity', 'recording_provenance', 'merged_into_id']));
                $values['catalog_revision'] = $track->catalog_revision + 1;
                $track->update($values);
                if (in_array($operation, ['metadata', 'proposal'], true)) {
                    DB::table('artist_track')->where('track_id', $track->id)->delete();
                    DB::table('artist_track')->insert(array_map(fn ($credit) => ['track_id' => $track->id, 'artist_id' => $credit['artist_id'], 'role' => $credit['role'], 'position' => $credit['position']], $row['credits']));
                }
                if ($operation === 'repair') {
                    foreach ($row['media_links'] as $link) {
                        abort_if(DB::table('track_media')->where('media_asset_id', $link['media_asset_id'])->exists(), 409, __('catalog.changed'));
                        DB::table('track_media')->insert(['track_id' => $track->id] + $link);
                        MediaAsset::whereKey($link['media_asset_id'])->increment('ownership_revision');
                    }
                }
            }
        }
        foreach ($before['playlists'] as $row) {
            $playlist = Playlist::findOrFail($row['id']);
            $ids = array_column($row['tracks'], 'track_id');
            $this->plans->assertPlayable($ids, $row['status'] === Playlist::PUBLISHED);
            $this->playlists->handle($playlist, $actor, ['revision' => (int) $playlist->revision] + array_intersect_key($row, array_flip(['name', 'description', 'mood', 'status'])), $ids);
            DB::table('playlist_track')->where('playlist_id', $playlist->id)->delete();
            if ($row['tracks'] !== []) {
                DB::table('playlist_track')->insert(array_map(fn ($member) => ['playlist_id' => $playlist->id] + $member, $row['tracks']));
            }
        }
        if (isset($result['created_playlist_id'])) {
            $created = Playlist::findOrFail($result['created_playlist_id']);
            $created->revision++;
            $created->save();
            $created->delete();
        }
        if ($proposalId) {
            $proposal = MemberProposal::findOrFail($proposalId);
            $proposal->update(['status' => 'rejected', 'resolution' => 'rolled_back', 'revision' => $proposal->revision + 1, 'reviewed_by' => $actor->id, 'review_note' => null, 'reviewed_at' => now()]);
        }
    }
}
