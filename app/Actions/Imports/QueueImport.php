<?php

namespace App\Actions\Imports;

use App\Actions\AuthorizeLibraryWrite;
use App\Jobs\PublishPlaylistImport;
use App\Models\AuditEvent;
use App\Models\PlaylistImport;
use App\Models\User;
use App\Services\DatabaseQueueContract;
use App\Services\PlaybackMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueImport
{
    public function __construct(private AuthorizeLibraryWrite $authorize, private DatabaseQueueContract $queue, private PlaybackMedia $playback) {}

    public function handle(User $actor, PlaylistImport $import, bool $publish): void
    {
        $this->queue->verify();
        DB::transaction(function () use ($actor, $import, $publish): void {
            $actor = $this->authorize->handle($actor);
            $locked = PlaylistImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [PlaylistImport::COMPLETED, PlaylistImport::PROCESSING, PlaylistImport::RUNNING], true)) {
                return;
            }
            abort_if(in_array($locked->status, [PlaylistImport::NEEDS_REVIEW, PlaylistImport::CANCELLED], true) || empty($locked->report['plan_hash']), 409, __('پیش‌نمایش تازه بسازید.'));
            if ($publish) {
                $this->playback->assertReady(array_column($locked->report['source_rows'], 'asset_id'));
            }
            $this->enqueue($locked, $publish, false);
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'import.queued', 'subject_id' => $locked->id]);
        }, 5);
    }

    public function enqueue(PlaylistImport $import, bool $publish, bool $recovery): void
    {
        $this->queue->verify();
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Import dispatch requires the global SQL transaction.');
        }
        $import->update(['status' => PlaylistImport::PROCESSING, 'publish' => $publish, 'error' => null, 'generation' => $import->generation + 1, 'execution_token' => (string) Str::uuid(), 'queued_at' => now(), 'heartbeat_at' => null, 'lease_expires_at' => null, 'recovery_count' => $recovery ? $import->recovery_count + 1 : 0]);
        PublishPlaylistImport::dispatch($import->id, $import->generation, $import->execution_token)->onConnection('database')->beforeCommit();
    }
}
