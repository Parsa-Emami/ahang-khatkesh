<?php

namespace App\Console\Commands;

use App\Actions\Ops\AssessReleaseReadiness;
use Illuminate\Console\Command;

class ReleaseReadiness extends Command
{
    protected $signature = 'ops:release-readiness';

    protected $description = 'Report whether signed release evidence and production session settings are present';

    public function handle(AssessReleaseReadiness $assess): int
    {
        $result = $assess->handle();
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
