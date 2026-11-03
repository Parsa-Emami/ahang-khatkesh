<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MySqlUpgradeRestoreTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_backup_manifest_and_restore_verify_round_trip_on_sqlite(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        [$asset] = $this->seedMediaBlob('f15-bytes-'.str_repeat('a', 64));
        $track = Track::factory()->create(['media_asset_id' => $asset->id]);
        $playlist = Playlist::factory()->create(['created_by' => $user->id, 'status' => Playlist::PUBLISHED]);
        $playlist->tracks()->attach($track->id, ['position' => 1]);
        $user->likedTracks()->attach($track->id);
        $manifestPath = storage_path('framework/testing/f15-manifest.json');
        $this->assertSame(0, Artisan::call('ops:backup-manifest', ['--path' => $manifestPath]));
        $this->assertSame(0, Artisan::call('ops:restore-verify', ['--manifest' => $manifestPath]));
        Storage::disk('media')->put($asset->path, 'corrupted-content');
        $this->assertSame(1, Artisan::call('ops:restore-verify', ['--manifest' => $manifestPath]));
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $expected = $manifest['media'][0]['expected_sha256'];
        Storage::disk('media')->put($asset->path, 'f15-bytes-'.str_repeat('a', 64));
        $this->assertSame($expected, hash_file('sha256', Storage::disk('media')->path($asset->path)));
        $this->assertSame(0, Artisan::call('ops:restore-verify', ['--manifest' => $manifestPath]));
    }

    public function test_upgrade_verification_command_is_continuable(): void
    {
        Storage::fake('media');
        for ($i = 0; $i < 3; $i++) {
            $this->seedMediaBlob('blob-'.$i.'-'.str_repeat('x', 32));
        }
        $this->assertSame(1, Artisan::call('ops:verify-upgrade', ['--limit' => 1]));
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotNull($first['next_cursor']);
        $this->assertTrue($first['continuable']);
        $this->assertSame(1, Artisan::call('ops:verify-upgrade', ['--limit' => 1, '--after' => (string) $first['next_cursor']]));
        $this->assertSame(0, Artisan::call('ops:verify-upgrade', ['--limit' => 10, '--after' => '']));
    }

    public function test_mysql_legacy_fixture_upgrades_and_restore_hashes(): void
    {
        if (config('database.default') !== 'mysql' || env('AHANG_F15_MYSQL') !== '1') {
            $this->markTestSkipped('Requires MySQL 8.4 fixture (AHANG_F15_MYSQL=1)');
        }
        Storage::fake('media');
        $user = User::factory()->create();
        [$asset] = $this->seedMediaBlob('mysql-f15-'.str_repeat('m', 48));
        $track = Track::factory()->create(['media_asset_id' => $asset->id]);
        Playlist::factory()->create(['created_by' => $user->id, 'status' => Playlist::PUBLISHED])
            ->tracks()->attach($track->id, ['position' => 1]);
        $user->likedTracks()->attach($track->id);
        $this->assertSame(0, Artisan::call('ops:verify-upgrade'));
        $manifestPath = storage_path('framework/testing/f15-mysql-manifest.json');
        $this->assertSame(0, Artisan::call('ops:backup-manifest', ['--path' => $manifestPath]));
        $this->assertSame(0, Artisan::call('ops:restore-verify', ['--manifest' => $manifestPath]));
        $this->assertFileExists(base_path('database/fixtures/f15/README.md'));
    }

    /**
     * @return array{0: MediaAsset, 1: MediaBlob}
     */
    private function seedMediaBlob(string $bytes): array
    {
        $asset = MediaAsset::factory()->create();
        Storage::disk('media')->put($asset->path, $bytes);
        $sha = hash('sha256', $bytes);
        $asset->update(['sha256' => $sha, 'size_bytes' => strlen($bytes)]);
        $blob = MediaBlob::whereKey($asset->fresh()->blob_id)->firstOrFail();
        $blob->update([
            'sha256' => $sha,
            'size_bytes' => strlen($bytes),
            'path' => $asset->path,
            'mime_type' => $asset->mime_type,
            'state' => 'ready',
        ]);

        return [$asset->fresh(), $blob->fresh()];
    }
}
