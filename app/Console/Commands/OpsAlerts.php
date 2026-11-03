<?php

namespace App\Console\Commands;

use App\Actions\Ops\CollectOperationsAlerts;
use Illuminate\Console\Command;

class OpsAlerts extends Command
{
    protected $signature = 'ops:alerts';

    protected $description = 'Emit structured disk/queue/lease/failed-job alerts';

    public function handle(CollectOperationsAlerts $alerts): int
    {
        $payload = $alerts->handle();
        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload['alerts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
