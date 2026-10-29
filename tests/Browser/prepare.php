<?php

use App\Enums\UserRole;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Laravel\Fortify\Fortify;
use Tests\Support\FlacFixture;

$root = dirname(__DIR__, 2);
$runId = getenv('AHANG_E2E_RUN_ID') ?: '';
if (! preg_match('/^[a-f0-9-]{36}$/D', $runId)) {
    throw new RuntimeException('Isolated browser run id required.');
}
$runRoot = str_replace('\\', '/', rtrim(sys_get_temp_dir(), '/\\')).'/ahang-e2e-'.$runId;
$database = $runRoot.'/database.sqlite';
$media = $runRoot.'/media';
if (getenv('APP_ENV') !== 'testing' || getenv('AHANG_E2E') !== '1' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $database || getenv('MUSIC_MEDIA_ROOT') !== $media || strlen(getenv('AHANG_E2E_PASSWORD') ?: '') < 24) {
    throw new RuntimeException('Browser fixture reset is restricted to the isolated test database and media root.');
}
if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0755, true);
}
if (! $handle = fopen($database, 'x')) {
    throw new RuntimeException('Browser database must be newly created.');
}
fclose($handle);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database || config('filesystems.disks.media.root') !== $media) {
    throw new RuntimeException('Effective browser configuration must point only to the isolated fixtures. Clear stale config cache first.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Isolated browser migration failed.');
}
foreach (['desktop', 'mobile'] as $device) {
    $owner = User::create(['name' => 'Browser super administrator', 'email' => 'owner-'.$device.'@example.test', 'password' => getenv('AHANG_E2E_PASSWORD'), 'role' => UserRole::Owner]);
    $owner->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(array_map(fn ($i) => 'browser-recovery-'.$i, range(1, 40)))), 'two_factor_confirmed_at' => now()])->save();
    User::create(['name' => 'Browser listener', 'email' => 'listener-'.$device.'@example.test', 'password' => getenv('AHANG_E2E_PASSWORD'), 'role' => UserRole::Listener]);
    User::create(['name' => 'Visual listener', 'email' => 'visual-'.$device.'@example.test', 'password' => getenv('AHANG_E2E_PASSWORD'), 'role' => UserRole::Listener]);
}
if (Artisan::call('app:demo') !== 0 || Playlist::count() !== 6) {
    throw new RuntimeException('Synthetic browser library could not be prepared.');
}
if (Artisan::call('media:backfill', ['--apply' => true, '--limit' => 1000]) !== 0) {
    throw new RuntimeException('Synthetic browser media could not be backfilled.');
}
foreach (['desktop', 'mobile'] as $device) {
    file_put_contents(base_path('reports/f08-'.$device.'.flac'), FlacFixture::bytes(2, 44100, $device === 'desktop' ? 880 : 990, 8));
}
echo "Isolated browser fixtures prepared.\n";
