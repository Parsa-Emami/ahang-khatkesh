<?php

namespace App\Actions\Ops;

use App\Models\MediaConversion;
use App\Models\PlaylistImport;
use App\Models\StorageReservation;
use App\Services\MediaFiles;
use Illuminate\Support\Facades\DB;

class CollectOperationsAlerts
{
    public function __construct(private MediaFiles $files) {}

    /**
     * @return array{
     *   disk_available_bytes: int,
     *   disk_low: bool,
     *   queued_jobs: int,
     *   failed_jobs: int,
     *   oldest_job_seconds: int|null,
     *   dead_queue: bool,
     *   expired_import_leases: int,
     *   expired_conversion_leases: int,
     *   expired_reservations: int,
     *   alerts: list<array{code: string, severity: string, message: string}>
     * }
     */
    public function handle(): array
    {
        $available = $this->files->availableBytes();
        $diskLowThreshold = (int) config('ops.disk_low_bytes');
        $diskLow = $available <= $diskLowThreshold;
        $queued = (int) DB::table('jobs')->count();
        $failed = (int) DB::table('failed_jobs')->count();
        $oldest = DB::table('jobs')->orderBy('id')->value('created_at');
        $oldestSeconds = null;
        if ($oldest !== null) {
            $created = is_numeric($oldest) ? (int) $oldest : strtotime((string) $oldest);
            $oldestSeconds = max(0, time() - (int) $created);
        }
        $deadQueue = $oldestSeconds !== null && $oldestSeconds >= (int) config('ops.dead_queue_seconds');
        $expiredImports = PlaylistImport::query()
            ->whereIn('status', [PlaylistImport::RUNNING, PlaylistImport::PROCESSING])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->count();
        $expiredConversions = MediaConversion::query()
            ->whereIn('status', ['processing', 'queued'])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->count();
        $expiredReservations = StorageReservation::query()
            ->whereIn('status', ['reserved', 'writing'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();
        $alerts = [];
        if ($diskLow) {
            $alerts[] = ['code' => 'disk_low', 'severity' => 'critical', 'message' => 'فضای دیسک رسانه از آستانهٔ هشدار کمتر است.'];
        }
        if ($deadQueue) {
            $alerts[] = ['code' => 'dead_queue', 'severity' => 'critical', 'message' => 'قدیمی‌ترین job صف از مهلت dead-queue گذشته است.'];
        }
        if ($failed >= (int) config('ops.failed_jobs_alert')) {
            $alerts[] = ['code' => 'failed_jobs', 'severity' => 'warning', 'message' => 'حداقل یک failed job در صف وجود دارد.'];
        }
        if ($expiredImports + $expiredConversions + $expiredReservations > 0) {
            $alerts[] = ['code' => 'expired_lease', 'severity' => 'warning', 'message' => 'اجارهٔ منقضی‌شده برای import/conversion/reservation مشاهده شد.'];
        }

        return [
            'disk_available_bytes' => $available,
            'disk_low' => $diskLow,
            'queued_jobs' => $queued,
            'failed_jobs' => $failed,
            'oldest_job_seconds' => $oldestSeconds,
            'dead_queue' => $deadQueue,
            'expired_import_leases' => $expiredImports,
            'expired_conversion_leases' => $expiredConversions,
            'expired_reservations' => $expiredReservations,
            'alerts' => $alerts,
        ];
    }
}
