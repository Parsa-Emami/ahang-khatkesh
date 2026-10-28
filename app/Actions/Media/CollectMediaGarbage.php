<?php

namespace App\Actions\Media;

use App\Models\LibrarySetting;
use App\Models\MediaBlob;
use App\Models\MediaGarbageCandidate;
use App\Models\StorageReservation;
use App\Services\MediaFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CollectMediaGarbage
{
    public function __construct(private MediaFiles $files, private MediaReferences $references) {}

    public function scan(bool $mark = false, int $limit = 500, string $after = ''): array
    {
        $smallest = new \SplMaxHeap;
        foreach (Storage::disk('media')->getDriver()->listContents('', true) as $entry) {
            $path = $entry->path();
            if (! $entry->isFile() || ! $this->files->managed($path) || strcmp($path, $after) <= 0) {
                continue;
            }
            if ($smallest->count() < $limit + 1) {
                $smallest->insert($path);
            } elseif (strcmp($path, $smallest->top()) < 0) {
                $smallest->extract();
                $smallest->insert($path);
            }
        }
        $paths = collect(iterator_to_array($smallest))->sort()->values();
        $selected = $paths->take($limit);
        $candidates = 0;
        foreach ($selected as $path) {
            if ($this->references->path($path)) {
                continue;
            }
            $snapshot = $this->files->snapshot($path);
            if (! $snapshot) {
                continue;
            }
            $candidates++;
            if ($mark) {
                $this->mark($path, $snapshot);
            }
        }

        return ['scanned' => $selected->count(), 'candidates' => $candidates, 'marked' => $mark, 'next_cursor' => $paths->count() > $limit ? $selected->last() : null];
    }

    private function mark(string $path, array $snapshot): void
    {
        DB::transaction(function () use ($path, $snapshot): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            if ($this->references->path($path)) {
                return;
            }
            $existing = MediaGarbageCandidate::where('path', $path)->lockForUpdate()->first();
            if (! $existing || $existing->sha256 !== $snapshot['sha256'] || $existing->size_bytes !== $snapshot['size_bytes']) {
                MediaGarbageCandidate::updateOrCreate(['path' => $path], $snapshot + ['eligible_at' => now()->addSeconds(config('music.garbage_retention_seconds'))]);
            }
        }, 5);
    }

    public function trackPath(string $path): void
    {
        $snapshot = $this->files->snapshot($path);
        if ($snapshot) {
            $this->mark($path, $snapshot);
        }
    }

    public function sweep(int $limit = 100, ?string $path = null): int
    {
        $ids = MediaGarbageCandidate::where('eligible_at', '<=', now())->when($path, fn ($query) => $query->where('path', $path))->orderBy('id')->limit($limit)->pluck('id');
        $deleted = 0;
        foreach ($ids as $id) {
            $claimed = DB::transaction(function () use ($id): bool {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $candidate = MediaGarbageCandidate::whereKey($id)->lockForUpdate()->first();
                if (! $candidate || $candidate->eligible_at->isFuture()) {
                    return false;
                }
                if ($this->references->path($candidate->path)) {
                    $candidate->delete();

                    return false;
                }
                $blob = MediaBlob::where('path', $candidate->path)->lockForUpdate()->first();
                if ($blob) {
                    $blob->update(['state' => 'deleting']);
                }

                return true;
            }, 5);
            if (! $claimed) {
                continue;
            }
            $deleted += DB::transaction(function () use ($id): int {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $candidate = MediaGarbageCandidate::whereKey($id)->lockForUpdate()->first();
                if (! $candidate || $this->references->path($candidate->path)) {
                    return 0;
                }
                $snapshot = $this->files->snapshot($candidate->path);
                if ($snapshot && ($snapshot['sha256'] !== $candidate->sha256 || $snapshot['size_bytes'] !== $candidate->size_bytes)) {
                    $candidate->update($snapshot + ['eligible_at' => now()->addSeconds(config('music.garbage_retention_seconds'))]);

                    return 0;
                }
                $this->files->delete($candidate->path);
                StorageReservation::where('result_blob_id', MediaBlob::where('path', $candidate->path)->value('id'))->whereNotNull('result_blob_id')->update(['result_blob_id' => null]);
                MediaBlob::where('path', $candidate->path)->delete();
                $candidate->delete();

                return 1;
            }, 5);
        }

        return $deleted;
    }
}
