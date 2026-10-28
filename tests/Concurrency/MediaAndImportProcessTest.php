<?php

namespace Tests\Concurrency;

use App\Actions\Imports\ImportExecution;
use App\Actions\Imports\QueueImport;
use App\Actions\Media\CleanupUpload;
use App\Actions\Media\StorageBudget;
use App\Actions\PreviewPlaylistImport;
use App\Enums\UserRole;
use App\Jobs\PublishPlaylistImport;
use App\Models\MediaAsset;
use App\Models\PlaylistImport;
use App\Models\StorageReservation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\Support\AudioFixture;
use Tests\TestCase;

class MediaAndImportProcessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AHANG_CONCURRENCY') !== '1' || ! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
            throw new \RuntimeException('Concurrency tests only run against ahang_f07_test with AHANG_CONCURRENCY=1.');
        }
        $this->root = storage_path('framework/testing/f07-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0750, true);
        config(['filesystems.disks.media.root' => $this->root.'/media', 'music.storage_headroom_bytes' => 0]);
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

    private function process(string $mode, array $extra = []): Process
    {
        $command = [PHP_BINARY];
        if (str_contains(basename(PHP_BINARY), 'frankenphp')) {
            $command[] = 'php-cli';
        }
        $command[] = base_path('tests/Support/f07-worker.php');
        $process = new Process($command, base_path(), array_merge([
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'AHANG_CONCURRENCY' => '1', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ahang_f07_test', 'DB_URL' => '', 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database', 'MUSIC_MEDIA_ROOT' => $this->root.'/media', 'MUSIC_STORAGE_HEADROOM_BYTES' => '0', 'F07_MODE' => $mode,
        ], $extra));
        $process->setTimeout(30);

        return $process;
    }

    private function concurrent(string $mode, User $owner, ?int $quota = null): array
    {
        $barrier = $this->root.'/start';
        $env = ['F07_BARRIER' => $barrier, 'F07_USER_ID' => (string) $owner->id];
        if ($quota !== null) {
            $env['MUSIC_STORAGE_QUOTA_BYTES'] = (string) $quota;
        }
        $first = $this->process($mode, $env);
        $second = $this->process($mode, $env);
        try {
            $first->start();
            $second->start();
            file_put_contents($barrier, 'go');
            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput());

            return [json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR), json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR)];
        } finally {
            if ($first->isRunning()) {
                $first->stop(0, SIGKILL);
            }
            if ($second->isRunning()) {
                $second->stop(0, SIGKILL);
            }
        }
    }

    public function test_two_real_processes_cannot_overbook_the_last_available_quota(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $outcomes = $this->concurrent('reserve', $owner, strlen(AudioFixture::bytes()));
        $this->assertSame(1, count(array_filter($outcomes, fn ($result) => isset($result['reserved']))));
        $this->assertSame(1, count(array_filter($outcomes, fn ($result) => ($result['status'] ?? null) === 507)));
        $this->assertDatabaseCount('storage_reservations', 1);
        $this->assertSame(strlen(AudioFixture::bytes()), app(StorageBudget::class)->snapshot()['reserved_bytes']);
    }

    public function test_two_real_upload_processes_deduplicate_bytes_without_merging_assets(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $outcomes = $this->concurrent('ingest', $owner);
        $this->assertNotSame($outcomes[0]['asset'], $outcomes[1]['asset']);
        $this->assertSame($outcomes[0]['blob'], $outcomes[1]['blob']);
        foreach (StorageReservation::where('status', 'cleanup_pending')->pluck('id') as $id) {
            app(CleanupUpload::class)->handle($id);
        }
        $this->assertDatabaseCount('media_blobs', 1);
        $this->assertDatabaseCount('media_assets', 2);
        $this->assertCount(1, Storage::disk('media')->allFiles('blobs'));
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        $this->assertSame(strlen(AudioFixture::bytes()), app(StorageBudget::class)->snapshot()['used_bytes']);
    }

    #[TestWith(['pause-import'])]
    #[TestWith(['pause-publication'])]
    public function test_killed_real_queue_worker_is_recovered_once_and_duplicate_delivery_is_fenced(string $pause): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $asset = MediaAsset::factory()->create();
        $import = app(PreviewPlaylistImport::class)->handle($owner, UploadedFile::fake()->createWithContent('tracks.json', json_encode(['tracks' => [['asset_id' => $asset->id, 'title' => 'Killed worker track', 'artist' => 'Artist']]])), ['name' => 'Recovered', 'mood' => 'calm', 'idempotency_key' => 'killed-worker-preview-0001']);
        app(QueueImport::class)->handle($owner, $import, true);
        $import->refresh();
        $old = new PublishPlaylistImport($import->id, $import->generation, $import->execution_token);
        $marker = $this->root.'/claimed';
        $worker = $this->process($pause, ['F07_IMPORT_ID' => $import->id, 'F07_MARKER' => $marker]);
        try {
            $worker->start();
            $deadline = microtime(true) + 10;
            while (! is_file($marker) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($marker, $worker->getOutput().$worker->getErrorOutput());
            $this->assertSame('running', $import->fresh()->status);
            $worker->stop(0, SIGKILL);
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(0, SIGKILL);
            }
        }
        $this->assertDatabaseCount('playlists', 0);
        $this->assertDatabaseCount('tracks', 0);
        PlaylistImport::whereKey($import->id)->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertSame(1, app(ImportExecution::class)->recover());
        $fresh = $import->fresh();
        $old->failed(new \RuntimeException('Late callback from killed attempt'));
        DB::table('jobs')->whereNotNull('reserved_at')->delete();
        PublishPlaylistImport::dispatch($fresh->id, $fresh->generation, $fresh->execution_token)->onConnection('database');
        $first = $this->process('work');
        $second = $this->process('work');
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
        app()->call([$old, 'handle']);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(2, $import->fresh()->generation);
        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseCount('tracks', 1);
        $this->assertDatabaseCount('playlist_track', 1);
    }
}
