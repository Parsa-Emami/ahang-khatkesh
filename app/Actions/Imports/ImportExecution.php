<?php

namespace App\Actions\Imports;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\PlaylistImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ImportExecution
{
    public function __construct(private QueueImport $queue) {}

    public function claim(string $id, int $generation, ?string $token): bool
    {
        if (! $token) {
            return false;
        }

        return DB::transaction(function () use ($id, $generation, $token): bool {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $import = PlaylistImport::whereKey($id)->lockForUpdate()->first();
            if (! $import || $import->status !== PlaylistImport::PROCESSING || $import->generation !== $generation || $import->execution_token !== $token) {
                return false;
            }
            $import->update(['status' => PlaylistImport::RUNNING, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds(config('music.import_lease_seconds'))]);

            return true;
        }, 5);
    }

    public function renew(string $id, int $generation, string $token): bool
    {
        return DB::transaction(function () use ($id, $generation, $token): bool {
            LibrarySetting::whereKey(1)->increment('lock_version');

            $import = PlaylistImport::whereKey($id)->where('generation', $generation)->where('execution_token', $token)->where('status', PlaylistImport::RUNNING)->where('lease_expires_at', '>', now())->lockForUpdate()->first();
            if (! $import) {
                return false;
            }
            $import->update(['heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds(config('music.import_lease_seconds'))]);

            return true;
        }, 5);
    }

    public function retryLater(string $id, int $generation, ?string $token): void
    {
        if (! $token) {
            return;
        }
        DB::transaction(function () use ($id, $generation, $token): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            PlaylistImport::whereKey($id)->where('generation', $generation)->where('execution_token', $token)->whereIn('status', [PlaylistImport::PROCESSING, PlaylistImport::RUNNING])->update(['status' => PlaylistImport::RUNNING, 'lease_expires_at' => now()->addSeconds(30), 'error' => 'خطای موقت؛ بازیابی خودکار پس از مهلت کوتاه انجام می‌شود.']);
        }, 5);
    }

    public function finish(string $id, int $generation, ?string $token, string $status, string $message): void
    {
        if (! $token) {
            return;
        }
        DB::transaction(function () use ($id, $generation, $token, $status, $message): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $changed = PlaylistImport::whereKey($id)->where('generation', $generation)->where('execution_token', $token)->whereIn('status', [PlaylistImport::PROCESSING, PlaylistImport::RUNNING])->update(['status' => $status, 'error' => $message, 'lease_expires_at' => null]);
            if ($changed) {
                AuditEvent::create(['action' => 'import.'.$status, 'subject_id' => $id, 'metadata' => ['generation' => $generation]]);
            }
        }, 5);
    }

    public function recover(int $limit = 50): int
    {
        return DB::transaction(function () use ($limit): int {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $imports = PlaylistImport::where(function ($query): void {
                $query->where(fn ($q) => $q->where('status', PlaylistImport::RUNNING)->where('lease_expires_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('status', PlaylistImport::PROCESSING)->where(fn ($time) => $time->where('queued_at', '<=', now()->subSeconds(config('music.import_queue_stall_seconds')))->orWhere(fn ($legacy) => $legacy->whereNull('queued_at')->where('updated_at', '<=', now()->subSeconds(config('music.import_queue_stall_seconds'))))));
            })->orderBy('id')->limit($limit)->lockForUpdate()->get();
            foreach ($imports as $import) {
                $creator = User::find($import->created_by);
                if (! $creator || ! $creator->is_active || $creator->role !== UserRole::Owner || $creator->access_version !== ($import->report['actor_epoch'] ?? null)) {
                    $this->finish($import->id, $import->generation, $import->execution_token, PlaylistImport::CANCELLED, 'دسترسی سازنده تغییر کرده است.');
                    if (! $import->execution_token) {
                        $import->update(['status' => PlaylistImport::CANCELLED, 'error' => 'ایمپورت قدیمی نیاز به پیش‌نمایش تازه دارد.']);
                    }
                } elseif (empty($import->report['plan_hash'])) {
                    $import->update(['status' => PlaylistImport::NEEDS_REVIEW, 'error' => 'پیش‌نمایش تازه بسازید.', 'lease_expires_at' => null]);
                } elseif ($import->recovery_count >= config('music.import_max_recoveries')) {
                    $import->update(['status' => PlaylistImport::FAILED, 'error' => 'بودجهٔ بازیابی خودکار تمام شد؛ وضعیت worker را بررسی کنید.', 'lease_expires_at' => null]);
                } else {
                    $this->queue->enqueue($import, $import->publish, true);
                    AuditEvent::create(['action' => 'import.recovered', 'subject_id' => $import->id, 'metadata' => ['generation' => $import->generation]]);
                }
            }

            return $imports->count();
        }, 5);
    }
}
