<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('AHANG_CONCURRENCY') !== '1' || ! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
    throw new RuntimeException('F08 processes require the disposable ahang_f07_test database.');
}
if (getenv('F08_MODE') === 'pause-finalize') {
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains(strtolower($query->sql), 'insert into `media_assets`')) {
            file_put_contents(getenv('F08_MARKER'), 'before commit');
            while (true) {
                usleep(10000);
            }
        }
    });
}
$status = Artisan::call('queue:work', ['connection' => 'media_database', '--queue' => 'media', '--once' => true, '--tries' => 1, '--timeout' => 800]);
echo Artisan::output();
exit($status);
