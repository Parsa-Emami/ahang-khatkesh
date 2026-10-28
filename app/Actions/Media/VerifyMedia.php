<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Playlist;
use App\Services\MediaFiles;
use Illuminate\Support\Facades\DB;

class VerifyMedia
{
    public function __construct(private MediaFiles $files) {}

    public function handle(int $limit = 1000, string $after = ''): array
    {
        $errors = [];
        $blobs = MediaBlob::where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
        foreach ($blobs->take($limit) as $blob) {
            $snapshot = $this->files->snapshot($blob->path);
            if (! $snapshot || $snapshot !== ['size_bytes' => $blob->size_bytes, 'sha256' => $blob->sha256] || $blob->state !== 'ready') {
                $errors[] = ['type' => 'blob', 'id' => $blob->id, 'code' => 'missing_mismatch_or_deleting'];
            }
        }
        $unlinked = MediaAsset::whereNull('blob_id')->count() + Playlist::withTrashed()->whereNotNull('cover_path')->whereNull('cover_blob_id')->count();
        $mismatched = DB::table('media_assets as a')->join('media_blobs as b', 'b.id', '=', 'a.blob_id')->where(fn ($q) => $q->whereColumn('a.sha256', '!=', 'b.sha256')->orWhereColumn('a.size_bytes', '!=', 'b.size_bytes')->orWhereColumn('a.path', '!=', 'b.path')->orWhereColumn('a.mime_type', '!=', 'b.mime_type'))->count();
        $mismatched += DB::table('playlists as p')->join('media_blobs as b', 'b.id', '=', 'p.cover_blob_id')->whereColumn('p.cover_path', '!=', 'b.path')->count();
        $badOriginals = DB::table('tracks as t')->leftJoin('track_media as m', fn ($join) => $join->on('m.track_id', '=', 't.id')->on('m.media_asset_id', '=', 't.media_asset_id')->where('m.purpose', 'original'))->whereNull('m.track_id')->count();
        $invalidLineage = DB::table('media_conversions as c')->leftJoin('media_assets as a', 'a.id', '=', 'c.output_asset_id')->where('c.status', 'ready')->where(fn ($q) => $q->whereNull('a.id')->orWhereNull('a.source_asset_id')->orWhereNull('a.codec')->orWhereColumn('a.source_asset_id', '!=', 'c.source_asset_id')->orWhere('a.codec', '!=', 'mp3'))->count();
        $invalidLineage += DB::table('tracks as t')->join('media_assets as a', 'a.id', '=', 't.media_asset_id')->whereNotNull('a.source_asset_id')->count();
        $next = $blobs->count() > $limit ? $blobs->take($limit)->last()->id : null;

        return ['checked_blobs' => min($limit, $blobs->count()), 'errors' => $errors, 'unlinked_references' => $unlinked, 'metadata_mismatches' => $mismatched, 'invalid_original_links' => $badOriginals, 'invalid_conversion_lineage' => $invalidLineage, 'next_cursor' => $next, 'ok' => ! $errors && $unlinked === 0 && $mismatched === 0 && $badOriginals === 0 && $invalidLineage === 0];
    }
}
