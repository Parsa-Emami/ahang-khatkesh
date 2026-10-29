<?php

namespace App\Jobs;

use App\Actions\Imports\ImportExecution;
use App\Actions\RegisterTrack;
use App\Actions\ResolveImportPlan;
use App\Enums\UserRole;
use App\Exceptions\ImportLeaseExpired;
use App\Exceptions\ImportPlanChanged;
use App\Exceptions\MediaNotReady;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\Playlist;
use App\Models\PlaylistImport;
use App\Models\User;
use App\Services\PlaybackMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishPlaylistImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $importId, public int $generation = 1, public ?string $token = null) {}

    public function handle(RegisterTrack $register, ResolveImportPlan $plans, ImportExecution $execution, PlaybackMedia $playback): void
    {
        if (! $execution->claim($this->importId, $this->generation ?? 0, $this->token ?? null)) {
            return;
        }
        if (! $execution->renew($this->importId, $this->generation, $this->token)) {
            return;
        }
        try {
            $this->publish($register, $plans, $playback);
        } catch (ImportPlanChanged|MediaNotReady) {
            $execution->finish($this->importId, $this->generation, $this->token, PlaylistImport::NEEDS_REVIEW, __('اطلاعات یا مالکیت کاتالوگ تغییر کرده است؛ پیش‌نمایش تازه بسازید.'));
        }
    }

    private function publish(RegisterTrack $register, ResolveImportPlan $plans, PlaybackMedia $playback): void
    {
        DB::transaction(function () use ($register, $plans, $playback): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $import = PlaylistImport::whereKey($this->importId)->lockForUpdate()->firstOrFail();
            if ($import->status !== PlaylistImport::RUNNING || $import->generation !== $this->generation || $import->execution_token !== $this->token || ! $import->lease_expires_at?->isFuture()) {
                return;
            }
            $creator = User::whereKey($import->created_by)->lockForUpdate()->first();
            if (! $creator || ! $creator->is_active || $creator->role !== UserRole::Owner || $creator->access_version !== ($import->report['actor_epoch'] ?? null)) {
                $import->update(['status' => PlaylistImport::CANCELLED, 'error' => 'دسترسی سازندهٔ ایمپورت تغییر کرده است.']);
                AuditEvent::create(['user_id' => $import->created_by, 'action' => 'import.cancelled', 'subject_id' => $import->id]);

                return;
            }
            $report = $import->report;
            if (empty($report['source_rows']) || empty($report['plan_hash'])) {
                throw new ImportPlanChanged;
            }
            $plan = $plans->handle($report['source_rows']);
            if (! hash_equals($report['plan_hash'], $plan['hash'])) {
                throw new ImportPlanChanged;
            }
            if ($import->publish) {
                $playback->assertReady(array_column($plan['rows'], 'asset_id'));
            }
            $playlist = Playlist::create(['name' => $import->name, 'description' => $import->description, 'mood' => $import->mood, 'created_by' => $import->created_by, 'status' => Playlist::DRAFT, 'artwork_seed' => hexdec(substr($import->payload_hash, 0, 4))]);
            $reused = 0;
            foreach ($plan['rows'] as $index => $row) {
                if (! $import->lease_expires_at->isFuture()) {
                    throw new ImportLeaseExpired('Import publication lease expired.');
                }
                $track = $register->handle($row);
                $reused += $track->wasRecentlyCreated ? 0 : 1;
                $playlist->tracks()->attach($track->id, ['position' => $index + 1]);
            }
            if (! $import->lease_expires_at->isFuture()) {
                throw new ImportLeaseExpired('Import publication lease expired.');
            }
            $playlist->update(['status' => $import->publish ? Playlist::PUBLISHED : Playlist::DRAFT]);
            $import->update(['status' => PlaylistImport::COMPLETED, 'lease_expires_at' => null, 'playlist_id' => $playlist->id, 'error' => null, 'report' => array_replace($report, ['validated_tracks' => count($plan['rows']), 'created_tracks' => count($plan['rows']) - $reused, 'reused_tracks' => $reused, 'metadata_policy' => 'Existing shared track metadata is preserved.'])]);
            AuditEvent::create(['user_id' => $import->created_by, 'action' => 'import.completed', 'subject_id' => $import->id, 'metadata' => ['playlist_id' => $playlist->id, 'tracks' => count($plan['rows']), 'generation' => $this->generation]]);
        }, 5);
    }

    public function failed(?Throwable $exception): void
    {
        $transientSql = $exception instanceof QueryException && (str_starts_with((string) $exception->getCode(), '08') || $exception->getCode() === '40001' || in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213, 2006, 2013], true));
        if ($transientSql || $exception instanceof TimeoutExceededException || $exception instanceof MaxAttemptsExceededException || $exception instanceof ImportLeaseExpired) {
            app(ImportExecution::class)->retryLater($this->importId, $this->generation ?? 0, $this->token ?? null);

            return;
        }
        app(ImportExecution::class)->finish($this->importId, $this->generation ?? 0, $this->token ?? null, PlaylistImport::FAILED, __('ساخت پلی‌لیست انجام نشد؛ وضعیت worker را بررسی و دوباره تلاش کنید.'));
    }
}
