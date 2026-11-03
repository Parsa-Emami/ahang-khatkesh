<?php

namespace App\Console\Commands;

use App\Actions\Ops\BuildBackupManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupManifest extends Command
{
    protected $signature = 'ops:backup-manifest {--path=} {--db-dump=}';

    protected $description = 'Write a backup manifest with media checksums and config presence (no secrets)';

    public function handle(BuildBackupManifest $build): int
    {
        $path = (string) ($this->option('path') ?: storage_path('app/backups/manifest-'.now()->format('YmdHis').'.json'));
        File::ensureDirectoryExists(dirname($path));
        $manifest = $build->handle($this->option('db-dump') ? (string) $this->option('db-dump') : null);
        File::put($path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode(['path' => $path, 'media' => count($manifest['media']), 'counts' => $manifest['counts']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
