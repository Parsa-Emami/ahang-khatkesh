<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Ops\CollectOperationsAlerts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;

class OperationsController extends Controller
{
    public function __invoke(ExecutableFinder $finder, CollectOperationsAlerts $alerts): JsonResponse
    {
        $ops = $alerts->handle();

        return response()->json([
            'data' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'database_driver' => DB::connection()->getDriverName(),
                'queue_driver' => config('queue.connections.'.config('queue.default').'.driver'),
                'queued_jobs' => $ops['queued_jobs'],
                'failed_jobs' => $ops['failed_jobs'],
                'ffprobe_available' => $finder->find(config('music.ffprobe')) !== null,
                'disk_available_bytes' => $ops['disk_available_bytes'],
                'disk_low' => $ops['disk_low'],
                'oldest_job_seconds' => $ops['oldest_job_seconds'],
                'dead_queue' => $ops['dead_queue'],
                'expired_import_leases' => $ops['expired_import_leases'],
                'expired_conversion_leases' => $ops['expired_conversion_leases'],
                'expired_reservations' => $ops['expired_reservations'],
                'alerts' => $ops['alerts'],
                'stream_accel_enabled' => (bool) config('music.stream_accel_enabled'),
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
