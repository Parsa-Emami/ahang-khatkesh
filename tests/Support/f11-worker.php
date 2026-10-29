<?php

use App\Actions\Listening\ChangeListeningPrivacy;
use App\Models\User;
use App\Services\Listening\ListeningLedger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('AHANG_CONCURRENCY') !== '1' || ! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
    throw new RuntimeException('F11 processes require the disposable ahang_f07_test database.');
}
$user = User::findOrFail(getenv('F11_USER_ID'));
$pause = getenv('F11_PAUSE') === '1';
DB::listen(function (QueryExecuted $query) use (&$pause): void {
    if ($pause && str_contains(strtolower($query->sql), 'update `library_settings`')) {
        $pause = false;
        file_put_contents(getenv('F11_LOCKED'), 'locked');
        $deadline = microtime(true) + 10;
        while (! is_file(getenv('F11_RELEASE')) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file(getenv('F11_RELEASE'))) {
            throw new RuntimeException('Privacy race release timed out.');
        }
    }
});
file_put_contents(getenv('F11_STARTED'), 'started');
try {
    if (getenv('F11_MODE') === 'erase') {
        $result = app(ChangeListeningPrivacy::class)->handle($user, ['revision' => 1], erase: true)->listeningPreferences();
    } else {
        $result = app(ListeningLedger::class)->record($user, 'isolated-f11-session', ['token' => getenv('F11_TOKEN'), 'kind' => 'progress', 'listened_seconds' => 35]);
    }
    echo json_encode(['status' => 200, 'data' => $result], JSON_THROW_ON_ERROR)."\n";
} catch (HttpException $error) {
    echo json_encode(['status' => $error->getStatusCode()], JSON_THROW_ON_ERROR)."\n";
}
