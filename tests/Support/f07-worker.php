<?php

use App\Actions\Media\IngestMedia;
use App\Actions\Media\ReserveStorage;
use App\Models\PlaylistImport;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AudioFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('AHANG_CONCURRENCY') !== '1' || ! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
    throw new RuntimeException('F07 subprocesses require the dedicated disposable MySQL database.');
}
$mode = getenv('F07_MODE');
if (in_array($mode, ['pause-import', 'pause-publication'], true)) {
    $id = getenv('F07_IMPORT_ID');
    $marker = getenv('F07_MARKER');
    if ($mode === 'pause-import') {
        Event::listen(TransactionCommitted::class, function () use ($id, $marker): void {
            if (PlaylistImport::whereKey($id)->where('status', PlaylistImport::RUNNING)->exists()) {
                file_put_contents($marker, 'claimed');
                while (true) {
                    usleep(10000);
                }
            }
        });
    } else {
        DB::listen(function (QueryExecuted $query) use ($marker): void {
            if (str_contains(strtolower($query->sql), 'insert into `playlist_track`')) {
                file_put_contents($marker, 'publishing');
                while (true) {
                    usleep(10000);
                }
            }
        });
    }
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--timeout' => 120]);
} elseif ($mode === 'work') {
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--timeout' => 120]);
} else {
    $barrier = getenv('F07_BARRIER');
    $deadline = microtime(true) + 10;
    while (! is_file($barrier) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($barrier)) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    $owner = User::findOrFail(getenv('F07_USER_ID'));
    try {
        $reservation = app(ReserveStorage::class)->handle($owner, strlen(AudioFixture::bytes()), 'audio');
        $result = ['reserved' => $reservation->id];
        if ($mode === 'ingest') {
            $ingested = app(IngestMedia::class)->handle($owner, $reservation->id, AudioFixture::file(), reuse: false);
            $result += ['asset' => $ingested['asset']->id, 'blob' => $ingested['blob']->id];
        }
        echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    } catch (HttpException $error) {
        echo json_encode(['status' => $error->getStatusCode()], JSON_THROW_ON_ERROR)."\n";
    }
}
