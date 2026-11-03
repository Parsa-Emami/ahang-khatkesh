<?php

namespace App\Services\Catalog;

use App\Models\Track;
use Illuminate\Support\Facades\DB;

class CatalogHealth
{
    public function __construct(private CatalogSnapshot $snapshots) {}

    /**
     * @return list<array{code:string,track_id:int,detail:array}>
     */
    public function issues(int $limit = 200): array
    {
        $issues = [];
        $tracks = Track::withTrashed()->with(['mediaAsset.blob'])->orderBy('id')->limit($limit)->get();
        foreach ($tracks as $track) {
            $asset = $track->mediaAsset;
            $path = $asset?->storagePath();
            if ($this->snapshots->mediaMissing($path)) {
                $issues[] = [
                    'code' => 'missing_media_file',
                    'track_id' => $track->id,
                    'detail' => ['media_asset_id' => $track->media_asset_id, 'trashed' => $track->trashed()],
                ];
            }
            if ($track->primary_release_id !== null) {
                $linked = DB::table('release_track')
                    ->where('track_id', $track->id)
                    ->where('release_id', $track->primary_release_id)
                    ->exists();
                if (! $linked) {
                    $issues[] = [
                        'code' => 'broken_primary_release',
                        'track_id' => $track->id,
                        'detail' => ['primary_release_id' => $track->primary_release_id],
                    ];
                }
            }
            $hasPrimary = DB::table('artist_track')->where('track_id', $track->id)->where('role', 'primary')->exists();
            if (! $hasPrimary && ! $track->trashed()) {
                $issues[] = [
                    'code' => 'missing_primary_credit',
                    'track_id' => $track->id,
                    'detail' => [],
                ];
            }
        }

        return $issues;
    }
}
