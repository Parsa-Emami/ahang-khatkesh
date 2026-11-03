<?php

namespace App\Services\Catalog;

use App\Models\Artist;
use App\Models\Track;
use App\Support\TextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DuplicateDetector
{
    public function __construct(private CatalogSnapshot $snapshots) {}

    /**
     * @return list<array{kind:string,reason:string,track_ids:list<int>,artist_ids?:list<int>,evidence:array}>
     */
    public function findings(?string $query = null): array
    {
        $tracks = Track::query()
            ->with(['mediaAsset.blob', 'artists'])
            ->when(filled($query), fn ($q) => $q->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($query)]))
            ->orderBy('id')
            ->limit(500)
            ->get();

        $findings = [];
        $byBlob = [];
        $bySource = [];
        $byIdentity = [];

        foreach ($tracks as $track) {
            $asset = $track->mediaAsset;
            if ($asset?->blob_id) {
                $byBlob[$asset->blob_id][] = $track->id;
            }
            if ($asset?->source_asset_id) {
                $bySource[$asset->source_asset_id][] = $track->id;
            } elseif ($asset?->id) {
                $bySource[$asset->id][] = $track->id;
            }
            $primaryArtist = $track->artists->firstWhere('pivot.role', 'primary')?->id;
            $key = implode(':', [
                $this->snapshots->normalizeTitle($track->title),
                (string) ($primaryArtist ?? 'none'),
                (string) round((float) ($asset?->duration_seconds ?? 0), 1),
                (string) ($asset?->blob_id ?? 'noblob'),
            ]);
            $byIdentity[$key][] = $track->id;
        }

        foreach ($byBlob as $blobId => $ids) {
            $unique = array_values(array_unique($ids));
            if (count($unique) < 2) {
                continue;
            }
            $findings[] = [
                'kind' => 'shared_bytes',
                'reason' => 'recording_identity.shared_blob',
                'track_ids' => $unique,
                'evidence' => ['blob_id' => $blobId],
            ];
        }

        foreach ($bySource as $sourceId => $ids) {
            $unique = array_values(array_unique($ids));
            if (count($unique) < 2) {
                continue;
            }
            $findings[] = [
                'kind' => 'shared_lineage',
                'reason' => 'recording_identity.shared_source_asset',
                'track_ids' => $unique,
                'evidence' => ['source_asset_id' => $sourceId],
            ];
        }

        foreach ($byIdentity as $key => $ids) {
            $unique = array_values(array_unique($ids));
            if (count($unique) < 2) {
                continue;
            }
            [$title, $artistId, $duration, $blobId] = explode(':', $key, 4);
            if ($blobId === 'noblob') {
                $findings[] = [
                    'kind' => 'name_collision',
                    'reason' => 'review.homonymous_recording_metadata',
                    'track_ids' => $unique,
                    'evidence' => ['normalized_title' => $title, 'primary_artist_id' => $artistId === 'none' ? null : (int) $artistId, 'duration_seconds' => (float) $duration],
                ];

                continue;
            }
            $findings[] = [
                'kind' => 'recording_candidate',
                'reason' => 'recording_identity.title_artist_duration_blob',
                'track_ids' => $unique,
                'evidence' => ['normalized_title' => $title, 'primary_artist_id' => $artistId === 'none' ? null : (int) $artistId, 'duration_seconds' => (float) $duration, 'blob_id' => $blobId],
            ];
        }

        $provenance = DB::table('artists')
            ->select('provenance')
            ->whereNotNull('provenance')
            ->groupBy('provenance')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('provenance');
        foreach ($provenance as $value) {
            $ids = Artist::where('provenance', $value)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $findings[] = [
                'kind' => 'provenance_collision',
                'reason' => 'provenance.duplicate_artist_key',
                'track_ids' => [],
                'artist_ids' => $ids,
                'evidence' => ['provenance' => $value],
            ];
        }

        $names = Artist::query()
            ->selectRaw('name, COUNT(*) as aggregate')
            ->groupBy('name')
            ->having('aggregate', '>', 1)
            ->orderBy('name')
            ->limit(100)
            ->get();
        foreach ($names as $row) {
            $ids = Artist::where('name', $row->name)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $findings[] = [
                'kind' => 'homonym',
                'reason' => 'identity.homonymous_artists_are_not_merged',
                'track_ids' => [],
                'artist_ids' => $ids,
                'evidence' => ['name' => $row->name, 'count' => (int) $row->aggregate],
            ];
        }

        return Collection::make($findings)
            ->unique(fn ($item) => $item['kind'].':'.implode(',', $item['track_ids']).':'.implode(',', $item['artist_ids'] ?? []).':'.json_encode($item['evidence']))
            ->values()
            ->all();
    }
}
