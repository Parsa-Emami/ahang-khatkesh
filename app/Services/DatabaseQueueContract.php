<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

class DatabaseQueueContract
{
    public function verify(string $name = 'database'): void
    {
        if ((int) config('queue.connections.'.$name.'.retry_after') <= ($name === 'media_database' ? 800 : 120)) {
            throw new LogicException('Queue retry_after must exceed the worker timeout.');
        }
        $connection = config('queue.connections.'.$name.'.connection');
        if (config('queue.connections.'.$name.'.driver') !== 'database' || ($connection !== null && $connection !== DB::getDefaultConnection())) {
            throw new LogicException('Durable application jobs require the same SQL connection as the application.');
        }
    }
}
