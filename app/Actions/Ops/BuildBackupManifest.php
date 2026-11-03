<?php

namespace App\Actions\Ops;

use App\Models\MediaBlob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BuildBackupManifest
{
    /**
     * @return array<string, mixed>
     */
    public function handle(?string $dbDumpPath = null): array
    {
        $media = [];
        foreach (MediaBlob::query()->orderBy('id')->cursor() as $blob) {
            $absolute = Storage::disk('media')->path($blob->path);
            $sha = is_file($absolute) ? hash_file('sha256', $absolute) : null;
            $size = is_file($absolute) ? filesize($absolute) : null;
            $media[] = [
                'blob_id' => $blob->id,
                'path' => $blob->path,
                'expected_sha256' => $blob->sha256,
                'expected_bytes' => (int) $blob->size_bytes,
                'observed_sha256' => $sha,
                'observed_bytes' => $size === false ? null : $size,
                'present' => is_file($absolute),
            ];
        }
        $dumpSha = null;
        if ($dbDumpPath && is_file($dbDumpPath)) {
            $dumpSha = hash_file('sha256', $dbDumpPath);
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'schema_version' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'database_driver' => DB::connection()->getDriverName(),
            'git_commit' => trim((string) @shell_exec('git rev-parse HEAD 2>/dev/null')) ?: null,
            'db_dump' => [
                'path' => $dbDumpPath,
                'sha256' => $dumpSha,
            ],
            'config_presence' => [
                'app_key' => filled(config('app.key')),
                'db_connection' => (string) config('database.default'),
                'media_disk' => 'media',
                'stream_accel_enabled' => (bool) config('music.stream_accel_enabled'),
            ],
            'key_recovery' => [
                'mode' => 'offline_envelope',
                'note' => 'APP_KEY and MFA secrets are never written into the manifest; recover from the sealed offline envelope.',
            ],
            'media' => $media,
            'counts' => [
                'users' => (int) DB::table('users')->count(),
                'tracks' => (int) DB::table('tracks')->count(),
                'playlists' => (int) DB::table('playlists')->count(),
                'media_blobs' => count($media),
            ],
        ];
    }
}
