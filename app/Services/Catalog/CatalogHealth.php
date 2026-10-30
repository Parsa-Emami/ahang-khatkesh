<?php

namespace App\Services\Catalog;

use App\Models\Track;
use App\Services\MediaFiles;
use App\Support\TextNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class CatalogHealth
{
    public function __construct(private MediaFiles $files) {}

    public function page(?string $search): LengthAwarePaginator
    {
        $page = Track::canonical()->when(filled($search), fn ($query) => $query->whereRaw("search_text LIKE ? ESCAPE '!'", [TextNormalizer::like($search)]))->with('mediaAsset.blob')->orderBy('id')->paginate(config('catalog.diagnostic_page_size'));
        $rows = $page->getCollection();
        $identities = $rows->pluck('recording_identity')->filter()->unique()->all();
        $titles = $rows->pluck('title')->unique()->all();
        $hashes = $rows->pluck('mediaAsset.sha256')->unique()->all();
        $candidates = Track::canonical()->where(fn ($query) => $query->whereIn('recording_identity', $identities)->orWhereIn('title', $titles)->orWhereHas('mediaAsset', fn ($asset) => $asset->whereIn('sha256', $hashes)))->with('mediaAsset')->orderBy('id')->limit(1001)->get();
        $truncated = $candidates->count() > 1000;
        $candidates = $candidates->take(1000);
        $byIdentity = $candidates->filter(fn ($track) => filled($track->recording_identity) && filled($track->recording_provenance))->groupBy(fn ($track) => $track->recording_identity.'|'.$track->recording_provenance);
        $byTitle = $candidates->groupBy(fn ($track) => TextNormalizer::normalize($track->title));
        $byHash = $candidates->groupBy(fn ($track) => $track->mediaAsset->sha256);
        $page->setCollection($rows->map(function (Track $track) use ($byIdentity, $byTitle, $byHash, $truncated): array {
            $asset = $track->mediaAsset;
            $path = $asset->storagePath();
            $exists = $this->files->managed($path) && Storage::disk('media')->exists($path);
            $issues = [];
            if (! $exists) {
                $issues[] = 'missing_source';
            }
            if ($asset->blob_id && $asset->blob?->state !== 'ready') {
                $issues[] = 'source_not_ready';
            }
            $identity = ($byIdentity->get($track->recording_identity.'|'.$track->recording_provenance) ?? collect())->where('id', '!=', $track->id)->values();
            $homonyms = ($byTitle->get(TextNormalizer::normalize($track->title)) ?? collect())->where('id', '!=', $track->id)->values();
            $sameBytes = ($byHash->get($asset->sha256) ?? collect())->where('id', '!=', $track->id)->values();
            if ($identity->isNotEmpty()) {
                $issues[] = 'recording_identity_match';
            }
            if ($homonyms->isNotEmpty()) {
                $issues[] = 'title_advisory';
            }
            if ($sameBytes->isNotEmpty()) {
                $issues[] = 'bytes_advisory';
            }
            $project = fn ($items) => $items->take(20)->map(fn ($item) => ['id' => $item->id, 'title' => $item->title, 'revision' => $item->catalog_revision])->all();

            return ['id' => $track->id, 'title' => $track->title, 'revision' => $track->catalog_revision, 'recording_identity' => $track->recording_identity, 'recording_provenance' => $track->recording_provenance, 'source_exists' => $exists, 'source_ready' => ! $asset->blob_id || $asset->blob?->state === 'ready', 'issues' => $issues, 'identity_matches' => $project($identity), 'title_matches' => $project($homonyms), 'bytes_matches' => $project($sameBytes), 'candidates_truncated' => $truncated];
        }));

        return $page;
    }
}
