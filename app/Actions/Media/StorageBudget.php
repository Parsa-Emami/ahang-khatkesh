<?php

namespace App\Actions\Media;

use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\MediaGarbageCandidate;
use App\Models\Playlist;
use App\Models\StorageReservation;
use App\Services\MediaFiles;
use Illuminate\Support\Facades\Storage;

class StorageBudget
{
    public function __construct(private MediaFiles $files) {}

    public function assertReady(): void
    {
        abort_unless(config('filesystems.disks.media.driver') === 'local', 503, 'F07 requires a shared local private volume.');
        abort_if(MediaAsset::whereNull('blob_id')->exists() || Playlist::withTrashed()->whereNotNull('cover_path')->whereNull('cover_blob_id')->exists(), 503, __('ابتدا media:backfill --apply و media:verify را اجرا کنید.'));
        if (! LibrarySetting::whereKey(1)->value('media_inventory_ready')) {
            $paths = Storage::disk('media')->allFiles();
            abort_if(count($paths) > 10000, 503, __('ابتدا media:backfill --apply را اجرا کنید.'));
            foreach ($paths as $path) {
                if (str_starts_with($path, '.locks/')) {
                    continue;
                }
                $known = MediaBlob::where('path', $path)->exists() || MediaGarbageCandidate::where('path', $path)->exists() || StorageReservation::whereIn('status', StorageReservation::CHARGED)->where(fn ($q) => $q->where('candidate_path', $path)->orWhere('staging_path', $path))->exists();
                abort_unless($known, 503, __('ابتدا media:backfill --apply و media:verify را اجرا کنید.'));
            }
            LibrarySetting::whereKey(1)->update(['media_inventory_ready' => true]);
        }

    }

    public function snapshot(): array
    {
        $reserved = (int) StorageReservation::whereIn('status', StorageReservation::CHARGED)->sum('size_bytes');
        $orphans = (int) MediaGarbageCandidate::whereNotIn('path', MediaBlob::select('path'))
            ->whereNotIn('path', StorageReservation::whereIn('status', StorageReservation::CHARGED)->whereNotNull('candidate_path')->select('candidate_path'))
            ->whereNotIn('path', StorageReservation::whereIn('status', StorageReservation::CHARGED)->whereNotNull('staging_path')->select('staging_path'))->sum('size_bytes');
        $used = (int) MediaBlob::sum('size_bytes') + $orphans;
        $limit = max(0, (int) config('music.storage_quota_bytes'));

        $quotaAvailable = max(0, $limit - $used - $reserved);
        $diskAvailable = max(0, $this->files->availableBytes() - $reserved - max(0, (int) config('music.storage_headroom_bytes')));

        return ['quota_available_bytes' => $quotaAvailable, 'disk_available_bytes' => $diskAvailable, 'used_bytes' => $used, 'reserved_bytes' => $reserved, 'quota_bytes' => $limit, 'available_bytes' => min($quotaAvailable, $diskAvailable), 'orphan_bytes' => $orphans];
    }
}
