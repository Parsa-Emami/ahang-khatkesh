<?php

namespace App\Actions\Media;

use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Playlist;
use App\Services\MediaFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackfillMediaBlobs
{
    public function __construct(private MediaFiles $files, private CollectMediaGarbage $garbage) {}

    public function handle(bool $apply = false, int $limit = 100): array
    {
        $report = ['checked' => 0, 'linked' => 0, 'errors' => [], 'remaining' => 0];
        $items = MediaAsset::whereNull('blob_id')->orderBy('id')->limit($limit)->get()->concat(Playlist::withTrashed()->whereNotNull('cover_path')->whereNull('cover_blob_id')->orderBy('id')->limit($limit)->get());
        foreach ($items as $item) {
            $report['checked']++;
            $asset = $item instanceof MediaAsset;
            $path = $asset ? $item->path : $item->cover_path;
            $snapshot = $this->files->snapshot($path);
            if (! $snapshot || $snapshot['size_bytes'] === 0 || ($asset && ($snapshot['sha256'] !== $item->sha256 || $snapshot['size_bytes'] !== $item->size_bytes))) {
                $report['errors'][] = ['type' => $asset ? 'asset' : 'playlist', 'id' => $item->id, 'code' => 'missing_or_mismatched_file'];

                continue;
            }
            if (! $apply) {
                continue;
            }
            $linked = DB::transaction(function () use ($item, $asset, $path, $snapshot): bool {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $current = $asset ? MediaAsset::whereKey($item->id)->lockForUpdate()->firstOrFail() : Playlist::withTrashed()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $column = $asset ? 'blob_id' : 'cover_blob_id';
                if ($current->$column || ($asset ? $current->path : $current->cover_path) !== $path || $this->files->snapshot($path) !== $snapshot) {
                    return false;
                }
                $blob = MediaBlob::firstOrCreate($snapshot, ['path' => $path, 'mime_type' => $asset ? $current->mime_type : Storage::disk('media')->mimeType($path), 'state' => 'ready', 'verified_at' => now()]);
                abort_unless($blob->state === 'ready' && $this->files->snapshot($blob->path) === $snapshot, 409);
                $current->update([$column => $blob->id, $asset ? 'path' : 'cover_path' => $blob->path]);

                return true;
            }, 5);
            $report['linked'] += $linked ? 1 : 0;
        }
        $report['remaining'] = MediaAsset::whereNull('blob_id')->count() + Playlist::withTrashed()->whereNotNull('cover_path')->whereNull('cover_blob_id')->count();
        if ($apply && $report['remaining'] === 0) {
            $cursor = LibrarySetting::whereKey(1)->value('media_inventory_cursor') ?? '';
            $scan = $this->garbage->scan(true, $limit, $cursor);
            DB::transaction(function () use ($scan): void {
                LibrarySetting::whereKey(1)->increment('lock_version');
                LibrarySetting::whereKey(1)->update(['media_inventory_cursor' => $scan['next_cursor'], 'media_inventory_ready' => $scan['next_cursor'] === null]);
            }, 5);
            $report['inventory_complete'] = $scan['next_cursor'] === null;
            $report['next_cursor'] = $scan['next_cursor'];
        }

        return $report;
    }
}
