<?php

namespace Tests\Concurrency;

use App\Models\MemberProposal;
use App\Models\User;
use App\Services\Catalog\CatalogChanges;
use App\Services\Catalog\MemberProposals;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class CatalogWorkflowProcessTest extends TestCase
{
    use CatalogFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AHANG_CONCURRENCY') !== '1' || ! app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'ahang_f07_test') {
            throw new \RuntimeException('Catalog process tests require the disposable ahang_f07_test database.');
        }
        $this->root = storage_path('framework/testing/f12-'.bin2hex(random_bytes(8)));
        File::makeDirectory($this->root, 0750, true);
        config(['filesystems.disks.media.root' => $this->root.'/media']);
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

    private function worker(User $user, string $mode, bool $pause, array $extra, string $marker): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/f12-worker.php')], base_path(), $extra + ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'AHANG_CONCURRENCY' => '1', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'ahang_f07_test', 'DB_URL' => '', 'MUSIC_MEDIA_ROOT' => $this->root.'/media', 'F12_USER_ID' => (string) $user->id, 'F12_MODE' => $mode, 'F12_PAUSE' => $pause ? '1' : '0', 'F12_LOCKED' => $this->root.'/locked', 'F12_RELEASE' => $this->root.'/release', 'F12_STARTED' => $this->root.'/'.$marker], timeout: 25);
    }

    private function marker(string $name, Process $process): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($this->root.'/'.$name) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->root.'/'.$name, $process->getErrorOutput().$process->getOutput());
    }

    private function race(Process $first, Process $second): array
    {
        try {
            $first->start();
            $this->marker('locked', $first);
            $second->start();
            $this->marker('second', $second);
            file_put_contents($this->root.'/release', 'go');
            $results = [];
            foreach ([$first, $second] as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0, SIGKILL);
                }
            }
        }
    }

    public function test_two_real_processes_commit_one_preview_exactly_once(): void
    {
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $change = app(CatalogChanges::class)->preview($owner, ['operation' => 'metadata', 'rows' => [$this->catalogRow($track, 'Single commit')]]);
        $extra = ['F12_CHANGE_ID' => $change->id];
        $results = $this->race($this->worker($owner, 'commit', true, $extra, 'first'), $this->worker($owner, 'commit', false, $extra, 'second'));
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(1, $track->fresh()->catalog_revision);
        $this->assertSame('committed', $change->fresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.batch_committed')->count());
    }

    public function test_two_real_processes_cannot_exceed_ten_pending_proposals(): void
    {
        $owner = $this->catalogOwner();
        $member = User::factory()->create();
        $track = $this->catalogTrack($owner);
        $this->catalogPlaylist($owner, [$track]);
        for ($i = 0; $i < 9; $i++) {
            app(MemberProposals::class)->submit($member, ['kind' => 'track_metadata', 'idempotency_key' => (string) Str::uuid(), 'payload' => ['track_id' => $track->id, 'title' => 'Pending '.$i]]);
        }
        $extra = ['F12_TRACK_ID' => (string) $track->id];
        $results = $this->race($this->worker($member, 'proposal', true, $extra + ['F12_KEY' => (string) Str::uuid()], 'first'), $this->worker($member, 'proposal', false, $extra + ['F12_KEY' => (string) Str::uuid()], 'second'));
        $this->assertSame([200, 422], array_column($results, 'status'));
        $this->assertSame(10, MemberProposal::where('user_id', $member->id)->where('status', 'pending')->count());
    }
}
