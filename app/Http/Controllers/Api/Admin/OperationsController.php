<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;

class OperationsController extends Controller
{
    public function __invoke(ExecutableFinder $finder): JsonResponse
    {
        return response()->json(['data' => ['php_version' => PHP_VERSION, 'laravel_version' => app()->version(), 'database_driver' => DB::connection()->getDriverName(), 'queue_driver' => config('queue.connections.'.config('queue.default').'.driver'), 'queued_jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'ffprobe_available' => $finder->find(config('music.ffprobe')) !== null, 'checked_at' => now()->toIso8601String()]]);
    }
}
