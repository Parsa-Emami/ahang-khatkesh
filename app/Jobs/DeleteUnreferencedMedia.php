<?php

namespace App\Jobs;

use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteUnreferencedMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $path) {}

    public function handle(): void
    {
        if (! preg_match('~^(covers|audio)/[A-Za-z0-9._/-]+$~D', $this->path) || str_contains($this->path, '..')) {
            throw new RuntimeException('Invalid private media cleanup path.');
        }
        DB::transaction(function (): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            if (MediaAsset::where('path', $this->path)->exists() || Playlist::withTrashed()->where('cover_path', $this->path)->exists()) {
                return;
            }
            $disk = Storage::disk('media');
            if ($disk->exists($this->path) && ! $disk->delete($this->path)) {
                throw new RuntimeException('Private media cleanup must be retried.');
            }
        }, 5);
    }
}
