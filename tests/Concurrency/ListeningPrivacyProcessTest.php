<?php

namespace Tests\Concurrency;

use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\Listening\ListeningLedger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ListeningPrivacyProcessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AHANG_CONCURRENCY') !== '1' || ! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
            throw new \RuntimeException('Privacy process tests require the disposable ahang_f07_test database.');
        }
        $this->root = storage_path('framework/testing/f11-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0750, true);
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function process(string $mode, User $user, string $token, bool $pause): Process
    {
        $command = [PHP_BINARY];
        if (str_contains(basename(PHP_BINARY), 'frankenphp')) {
            $command[] = 'php-cli';
        }
        $command[] = base_path('tests/Support/f11-worker.php');

        return new Process($command, base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'AHANG_CONCURRENCY' => '1', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ahang_f07_test', 'DB_URL' => '',
            'F11_USER_ID' => (string) $user->id, 'F11_MODE' => $mode, 'F11_TOKEN' => $token, 'F11_PAUSE' => $pause ? '1' : '0',
            'F11_LOCKED' => $this->root.'/locked', 'F11_RELEASE' => $this->root.'/release', 'F11_STARTED' => $this->root.'/'.$mode,
        ], timeout: 25);
    }

    private function marker(string $name, Process $process): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($this->root.'/'.$name) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->root.'/'.$name, $process->getErrorOutput().$process->getOutput());
    }

    #[TestWith(['erase'])]
    #[TestWith(['record'])]
    public function test_erase_and_inflight_ingest_are_serialized_without_resurrecting_deleted_history(string $firstMode): void
    {
        $user = User::factory()->create();
        $user->forceFill(['listening_history_enabled' => true, 'listening_revision' => 1, 'listening_consent_at' => now()])->save();
        $track = Track::factory()->create(['media_asset_id' => MediaAsset::factory()->create(['uploaded_by' => $user->id])]);
        Playlist::factory()->create(['created_by' => $user->id])->tracks()->attach($track->id, ['position' => 0]);
        $this->travel(-40)->seconds();
        try {
            $token = app(ListeningLedger::class)->issue($user, 'isolated-f11-session', $track->id, 1)['token'];
        } finally {
            $this->travelBack();
        }
        $secondMode = $firstMode === 'erase' ? 'record' : 'erase';
        $first = $this->process($firstMode, $user, $token, true);
        $second = $this->process($secondMode, $user, $token, false);
        try {
            $first->start();
            $this->marker('locked', $first);
            $second->start();
            $this->marker($secondMode, $second);
            file_put_contents($this->root.'/release', 'go');
            foreach ([$first, $second] as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }
            $a = json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(200, $a['status']);
            $this->assertSame($firstMode === 'erase' ? 409 : 200, $b['status']);
            $this->assertSame(2, $user->fresh()->listening_revision);
            $this->assertDatabaseCount('listening_events', 0);
        } finally {
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0, SIGKILL);
                }
            }
        }
    }
}
