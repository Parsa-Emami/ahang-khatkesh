<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

class DatabaseQueueContract
{
    public function verify(): void
    {
        $connection = config('queue.connections.database.connection');
        if (config('queue.connections.database.driver') !== 'database' || ($connection !== null && $connection !== DB::getDefaultConnection())) {
            throw new LogicException('Durable application jobs require the same SQL connection as the application.');
        }
    }
}
