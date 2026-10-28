<?php

namespace App\Jobs;

use App\Actions\RegisterTrack;
use App\Actions\ResolveImportPlan;
use App\Enums\UserRole;
use App\Exceptions\ImportPlanChanged;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\Playlist;
use App\Models\PlaylistImport;
use App\Models\User;
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

    public function __construct(public string $importId, public int $generation = 1) {}

    public function handle(RegisterTrack $register, ResolveImportPlan $plans): void
    {
        try {
            $this->publish($register, $plans);
        } catch (ImportPlanChanged) {
            DB::transaction(function (): void {
                LibrarySetting::whereKey(1)->increment('lock_version');
                $import = PlaylistImport::whereKey($this->importId)->lockForUpdate()->firstOrFail();
                if ($import->status === PlaylistImport::PROCESSING && $import->generation === $this->generation) {
                    $import->update(['status' => PlaylistImport::NEEDS_REVIEW, 'error' => 'اطلاعات یا مالکیت کاتالوگ تغییر کرده است؛ پیش‌نمایش تازه بسازید.']);
                    AuditEvent::create(['user_id' => $import->created_by, 'action' => 'import.needs_review', 'subject_id' => $import->id]);
                }
            }, 5);
        }
    }

    private function publish(RegisterTrack $register, ResolveImportPlan $plans): void
    {
        DB::transaction(function () use ($register, $plans): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $import = PlaylistImport::whereKey($this->importId)->lockForUpdate()->firstOrFail();
            if ($import->status !== PlaylistImport::PROCESSING || $import->generation !== $this->generation) {
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
            $playlist = Playlist::create(['name' => $import->name, 'description' => $import->description, 'mood' => $import->mood, 'created_by' => $import->created_by, 'status' => Playlist::DRAFT, 'artwork_seed' => hexdec(substr($import->payload_hash, 0, 4))]);
            $reused = 0;
            foreach ($plan['rows'] as $index => $row) {
                $track = $register->handle($row);
                $reused += $track->wasRecentlyCreated ? 0 : 1;
                $playlist->tracks()->attach($track->id, ['position' => $index + 1]);
            }
            $playlist->update(['status' => $import->publish ? Playlist::PUBLISHED : Playlist::DRAFT]);
            $import->update(['status' => PlaylistImport::COMPLETED, 'playlist_id' => $playlist->id, 'error' => null, 'report' => array_replace($report, ['validated_tracks' => count($plan['rows']), 'created_tracks' => count($plan['rows']) - $reused, 'reused_tracks' => $reused, 'metadata_policy' => 'Existing shared track metadata is preserved.'])]);
            AuditEvent::create(['user_id' => $import->created_by, 'action' => 'import.completed', 'subject_id' => $import->id, 'metadata' => ['playlist_id' => $playlist->id, 'tracks' => count($plan['rows']), 'generation' => $this->generation]]);
        }, 5);
    }

    public function failed(?Throwable $exception): void
    {
        PlaylistImport::whereKey($this->importId)->where('generation', $this->generation)->where('status', PlaylistImport::PROCESSING)->update(['status' => PlaylistImport::FAILED, 'error' => 'ساخت پلی‌لیست انجام نشد. پس از بررسی، دوباره تلاش کنید.']);
    }
}
