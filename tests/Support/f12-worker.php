<?php

use App\Models\CatalogChangeSet;
use App\Models\User;
use App\Services\Catalog\CatalogChanges;
use App\Services\Catalog\MemberProposals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('AHANG_CONCURRENCY') !== '1' || ! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
    throw new RuntimeException('Catalog processes require the disposable ahang_f07_test database.');
}
$user = User::findOrFail(getenv('F12_USER_ID'));
$pause = getenv('F12_PAUSE') === '1';
DB::listen(function (QueryExecuted $query) use (&$pause): void {
    if ($pause && str_contains(strtolower($query->sql), 'update `library_settings`')) {
        $pause = false;
        file_put_contents(getenv('F12_LOCKED'), 'locked');
        $deadline = microtime(true) + 10;
        while (! is_file(getenv('F12_RELEASE')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file(getenv('F12_RELEASE'))) {
            throw new RuntimeException('Catalog race release timed out.');
        }
    }
});
file_put_contents(getenv('F12_STARTED'), 'started');
try {
    if (getenv('F12_MODE') === 'commit') {
        $change = CatalogChangeSet::findOrFail(getenv('F12_CHANGE_ID'));
        $result = app(CatalogChanges::class)->commit($user, $change->id, 0, $change->snapshot_hash);
    } else {
        $result = app(MemberProposals::class)->submit($user, ['kind' => 'track_metadata', 'idempotency_key' => getenv('F12_KEY'), 'payload' => ['track_id' => (int) getenv('F12_TRACK_ID'), 'title' => 'Concurrent proposal']]);
    }
    echo json_encode(['status' => 200, 'id' => $result->id], JSON_THROW_ON_ERROR)."\n";
} catch (HttpException $error) {
    echo json_encode(['status' => $error->getStatusCode()], JSON_THROW_ON_ERROR)."\n";
}
