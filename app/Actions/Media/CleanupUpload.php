<?php

namespace App\Actions\Media;

use App\Exceptions\UploadLockBusy;
use App\Models\LibrarySetting;
use App\Models\StorageReservation;
use App\Services\MediaFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupUpload
{
    public function __construct(private MediaFiles $files, private MediaReferences $references) {}

    public function pruneLocks(int $limit = 100): int
    {
        $ids = StorageReservation::whereIn('status', ['consumed', 'released'])->whereNull('lock_pruned_at')->where('updated_at', '<=', now()->subSeconds(config('music.garbage_retention_seconds')))->orderBy('id')->limit($limit)->pluck('id');
        $pruned = 0;
        foreach ($ids as $id) {
            try {
                $pruned += $this->files->withUploadLock($id, function () use ($id): int {
                    return DB::transaction(function () use ($id): int {
                        LibrarySetting::whereKey(1)->increment('lock_version');
                        $reservation = StorageReservation::whereKey($id)->whereIn('status', ['consumed', 'released'])->whereNull('lock_pruned_at')->where('updated_at', '<=', now()->subSeconds(config('music.garbage_retention_seconds')))->lockForUpdate()->first();
                        if (! $reservation) {
                            return 0;
                        }
                        Storage::disk('media')->delete('.locks/'.$id.'.lock');
                        $reservation->update(['lock_pruned_at' => now()]);

                        return 1;
                    }, 5);
                });
            } catch (UploadLockBusy) {
                continue;
            }
        }

        return $pruned;
    }

    public function handle(string $id): void
    {
        $this->files->withUploadLock($id, function () use ($id): void {
            DB::transaction(function () use ($id): void {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $reservation = StorageReservation::whereKey($id)->lockForUpdate()->first();
                if (! $reservation || $reservation->status !== 'cleanup_pending') {
                    return;
                }
                foreach (array_filter([$reservation->staging_path, $reservation->candidate_path]) as $path) {
                    if ($this->references->path($path, $reservation->id)) {
                        continue;
                    }
                    $this->files->delete($path);
                }
                if ($reservation->staging_path) {
                    $this->files->removeEmptyStaging($reservation->staging_path);
                }
                $remaining = collect([$reservation->staging_path, $reservation->candidate_path])->filter()->contains(fn ($path) => Storage::disk('media')->exists($path) && ! $this->references->path($path, $reservation->id));
                if (! $remaining) {
                    $reservation->update(['status' => $reservation->result_blob_id ? 'consumed' : 'released', 'lease_token' => null]);
                }
            }, 5);
        });
    }
}
