<?php

namespace App\Jobs;

use App\Actions\RegisterTrack;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\Playlist;
use App\Models\PlaylistImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishPlaylistImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [5, 30, 120];

    public function __construct(public string $importId) {}

    public function handle(RegisterTrack $register): void
    {
        DB::transaction(function () use ($register): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $import = PlaylistImport::whereKey($this->importId)->lockForUpdate()->firstOrFail();
            if ($import->status !== PlaylistImport::PROCESSING) {
                return;
            }
            $playlist = Playlist::create([
                'name' => $import->name, 'description' => $import->description, 'mood' => $import->mood,
                'created_by' => $import->created_by, 'status' => Playlist::DRAFT,
                'artwork_seed' => hexdec(substr($import->payload_hash, 0, 4)),
            ]);
            $reused = 0;
            foreach ($import->rows as $index => $row) {
                $track = $register->handle($row);
                $reused += $track->wasRecentlyCreated ? 0 : 1;
                $playlist->tracks()->attach($track->id, ['position' => $index + 1]);
            }
            $playlist->update(['status' => $import->publish ? Playlist::PUBLISHED : Playlist::DRAFT]);
            $import->update(['status' => PlaylistImport::COMPLETED, 'playlist_id' => $playlist->id, 'error' => null, 'report' => ['validated_tracks' => count($import->rows), 'created_tracks' => count($import->rows) - $reused, 'reused_tracks' => $reused, 'metadata_policy' => 'Existing shared track metadata is preserved.']]);
            AuditEvent::create(['user_id' => $import->created_by, 'action' => 'import.completed', 'subject_id' => $import->id, 'metadata' => ['playlist_id' => $playlist->id, 'tracks' => count($import->rows)]]);
        }, 5);
    }

    public function failed(?Throwable $exception): void
    {
        PlaylistImport::whereKey($this->importId)->where('status', PlaylistImport::PROCESSING)->update(['status' => PlaylistImport::FAILED, 'error' => 'ساخت پلی‌لیست انجام نشد. مدیر می‌تواند پس از بررسی گزارش سرور دوباره تلاش کند.']);
    }
}
