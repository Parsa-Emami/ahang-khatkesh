<?php

namespace App\Console\Commands;

use App\Actions\Ops\VerifyBackupRestore;
use Illuminate\Console\Command;

class RestoreVerify extends Command
{
    protected $signature = 'ops:restore-verify {--manifest=} {--media-root=}';

    protected $description = 'Verify media hashes against a backup manifest';

    public function handle(VerifyBackupRestore $verify): int
    {
        $manifestPath = (string) $this->option('manifest');
        if ($manifestPath === '' || ! is_file($manifestPath)) {
            $this->error('manifest file is required');

            return self::INVALID;
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $result = $verify->handle($manifest, $this->option('media-root') ? (string) $this->option('media-root') : null);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
