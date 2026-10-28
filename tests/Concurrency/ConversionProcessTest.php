<?php

namespace Tests\Concurrency;

use App\Actions\Conversions\ConversionExecution;
use App\Actions\Media\CleanupUpload;
use App\Actions\Media\IngestMedia;
use App\Actions\Media\ReserveStorage;
use App\Enums\UserRole;
use App\Jobs\TranscodeMedia;
use App\Models\MediaConversion;
use App\Models\StorageReservation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\Support\FlacFixture;
use Tests\TestCase;

class ConversionProcessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AHANG_CONCURRENCY') !== '1' || ! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
            throw new \RuntimeException('F08 process tests require the disposable ahang_f07_test database.');
        }
        $this->root = storage_path('framework/testing/f08-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0750, true);
        config(['filesystems.disks.media.root' => $this->root.'/media', 'music.storage_headroom_bytes' => 0, 'music.conversions_enabled' => true]);
        Storage::forgetDisk('media');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function source(): array
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $file = FlacFixture::file();
        $r = app(ReserveStorage::class)->handle($owner, $file->getSize(), 'audio');
        $asset = app(IngestMedia::class)->handle($owner, $r->id, $file)['asset'];

        return [$asset, MediaConversion::where('source_asset_id', $asset->id)->firstOrFail()];
    }

    private function worker(array $extra = []): Process
    {
        $command = ['/usr/bin/setsid', PHP_BINARY];
        if (str_contains(basename(PHP_BINARY), 'frankenphp')) {
            $command[] = 'php-cli';
        }
        $command[] = base_path('tests/Support/f08-worker.php');
        $process = new Process($command, base_path(), array_merge(['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'AHANG_CONCURRENCY' => '1', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ahang_f07_test', 'DB_URL' => '', 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database', 'MUSIC_MEDIA_ROOT' => $this->root.'/media', 'MUSIC_STORAGE_HEADROOM_BYTES' => '0', 'MUSIC_CONVERSIONS_ENABLED' => 'true'], $extra));
        $process->setTimeout(30);

        return $process;
    }

    public function test_two_real_media_workers_publish_one_output_for_duplicate_delivery(): void
    {
        [$source, $c] = $this->source();
        TranscodeMedia::dispatch($c->id, $c->generation, $c->lease_token)->onConnection('media_database')->onQueue('media');
        $first = $this->worker();
        $second = $this->worker();
        try {
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getOutput().$first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getOutput().$second->getErrorOutput());
        } finally {
            if ($first->isRunning()) {
                $first->stop(0, SIGKILL);
            }
            if ($second->isRunning()) {
                $second->stop(0, SIGKILL);
            }
        }
        $this->assertSame('ready', $c->fresh()->status, $c->fresh()->error_code ?? '');
        $this->assertDatabaseCount('media_assets', 2);
        $this->assertDatabaseCount('media_conversion_attempts', 1);
        $this->assertDatabaseCount('media_blobs', 2);
        Storage::disk('media')->assertExists($source->storagePath());
    }

    #[TestWith(['encoding'])]
    #[TestWith(['publish'])]
    #[TestWith(['group'])]
    public function test_killed_media_worker_stops_its_encoder_and_recovers_without_partial_publication(string $point): void
    {
        [$source, $c] = $this->source();
        $old = new TranscodeMedia($c->id, $c->generation, $c->lease_token);
        $marker = $this->root.'/marker';
        $encoderPid = null;
        $env = ['F08_MARKER' => $marker, 'F08_MODE' => $point === 'publish' ? 'pause-finalize' : 'work'];
        if (in_array($point, ['encoding', 'group'], true)) {
            $wrapper = $this->root.'/slow-ffmpeg';
            $php = '#!'.PHP_BINARY."\n<?php\n".'$arguments = array_slice($argv, 1); if (in_array("libmp3lame", $arguments, true)) { file_put_contents(getenv("F08_MARKER"), (string) getmypid()); } $index = array_search("-i", $arguments, true); if ($index !== false) { array_splice($arguments, $index, 0, ["-re"]); } pcntl_exec("/usr/bin/ffmpeg", $arguments); exit(3);';
            file_put_contents($wrapper, $php);
            chmod($wrapper, 0750);
            $env['FFMPEG_BINARY'] = $wrapper;
        }
        $worker = $this->worker($env);
        try {
            $worker->start();
            $deadline = microtime(true) + 15;
            while (! is_file($marker) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($marker, $worker->getOutput().$worker->getErrorOutput());
            if (in_array($point, ['encoding', 'group'], true)) {
                $encoderPid = (int) file_get_contents($marker);
                $this->assertTrue(posix_kill($encoderPid, 0));
            }
            if ($point === 'group') {
                posix_kill(-$worker->getPid(), SIGKILL);
                try {
                    $worker->wait();
                } catch (ProcessSignaledException $error) {
                    $this->assertSame(SIGKILL, $error->getSignal());
                }
            } else {
                $worker->stop(0, SIGKILL);
            }
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(0, SIGKILL);
            }
        }
        try {
            if ($encoderPid) {
                $deadline = microtime(true) + 5;
                while (posix_kill($encoderPid, 0) && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertFalse(posix_kill($encoderPid, 0), 'The guarded FFmpeg process must stop after PHP worker death.');
            }
            $this->assertDatabaseCount('media_assets', 1);
            $this->assertNull($c->fresh()->output_asset_id);
            MediaConversion::whereKey($c->id)->update(['lease_expires_at' => now()->subSecond()]);
            $this->assertSame(1, app(ConversionExecution::class)->recover());
            foreach (StorageReservation::where('status', 'cleanup_pending')->pluck('id') as $id) {
                app(CleanupUpload::class)->handle($id);
            }
            $this->travel(31)->seconds();
            $this->assertSame(1, app(ConversionExecution::class)->recover());
            DB::table('jobs')->whereNotNull('reserved_at')->delete();
            DB::table('jobs')->where('queue', 'media')->update(['available_at' => time()]);
            $old->failed(new \RuntimeException('Late callback from dead worker'));
            $freshWorker = $this->worker();
            $freshWorker->run();
            $this->assertTrue($freshWorker->isSuccessful(), $freshWorker->getOutput().$freshWorker->getErrorOutput());
            $this->assertSame('ready', $c->fresh()->status, $c->fresh()->error_code ?? '');
            $this->assertSame(2, $c->fresh()->generation);
            $this->assertDatabaseCount('media_assets', 2);
            $this->assertDatabaseCount('media_conversion_attempts', 2);
            Storage::disk('media')->assertExists($source->storagePath());
        } finally {
            if ($encoderPid && posix_kill($encoderPid, 0)) {
                posix_kill(-$encoderPid, SIGKILL);
            }
        }
    }

    public function test_actual_database_redelivery_before_lease_expiry_is_recovered_instead_of_failed_permanently(): void
    {
        [$source, $c] = $this->source();
        app(ConversionExecution::class)->claim($c->id, $c->generation, $c->lease_token);
        DB::table('jobs')->where('queue', 'media')->update(['attempts' => 1, 'reserved_at' => time() - 901]);
        $worker = $this->worker();
        $worker->run();
        $this->assertTrue($worker->isSuccessful(), $worker->getOutput().$worker->getErrorOutput());
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertNotNull($c->fresh()->next_retry_at);
        $this->travel(31)->seconds();
        app(ConversionExecution::class)->recover();
        DB::table('jobs')->where('queue', 'media')->update(['available_at' => time()]);
        $fresh = $this->worker();
        $fresh->run();
        $this->assertTrue($fresh->isSuccessful(), $fresh->getOutput().$fresh->getErrorOutput());
        $this->assertSame('ready', $c->fresh()->status, $c->fresh()->error_code ?? '');
        $this->assertSame(2, $c->fresh()->generation);
        $this->assertDatabaseCount('media_assets', 2);
        Storage::disk('media')->assertExists($source->storagePath());
    }
}
