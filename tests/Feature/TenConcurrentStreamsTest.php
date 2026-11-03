<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenConcurrentStreamsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ten_authenticated_range_streams_complete_with_ops_snapshot(): void
    {
        Storage::fake('media');
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listener = User::factory()->create();
        $tracks = [];
        for ($i = 0; $i < 10; $i++) {
            $asset = MediaAsset::factory()->create();
            $payload = str_repeat((string) $i, 200);
            Storage::disk('media')->put($asset->path, $payload);
            $asset->update(['sha256' => hash('sha256', $payload), 'size_bytes' => strlen($payload), 'mime_type' => 'audio/wav']);
            $track = Track::factory()->create(['media_asset_id' => $asset->id]);
            $tracks[] = $track;
        }
        $playlist = Playlist::factory()->create(['created_by' => $owner->id, 'status' => Playlist::PUBLISHED]);
        foreach ($tracks as $index => $track) {
            $playlist->tracks()->attach($track->id, ['position' => $index + 1]);
        }
        $this->actingAs($listener);
        $started = hrtime(true);
        $bodies = [];
        foreach ($tracks as $track) {
            $response = $this->withHeader('Range', 'bytes=0-9')->get('/api/v1/tracks/'.$track->id.'/stream');
            $response->assertStatus(206);
            $bodies[] = strlen($response->streamedContent());
        }
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $this->actingAs($owner)->withSession([
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_confirmed_at' => now()->timestamp,
            'auth.mfa_authenticated' => true,
        ]);
        $ops = $this->getJson('/api/v1/admin/operations')->assertOk()->json('data');
        $this->assertArrayHasKey('alerts', $ops);
        $this->assertArrayHasKey('disk_low', $ops);
        $this->assertArrayHasKey('dead_queue', $ops);
        $this->assertSame(10, count($bodies));
        $report = [
            'measured_at' => now()->toIso8601String(),
            'streams' => 10,
            'range_bytes' => 10,
            'elapsed_ms' => $elapsedMs,
            'hardware' => 'declared host; SLO not claimed until production observation',
            'ops' => [
                'disk_available_bytes' => $ops['disk_available_bytes'],
                'queued_jobs' => $ops['queued_jobs'],
                'failed_jobs' => $ops['failed_jobs'],
                'alerts' => $ops['alerts'],
            ],
            'slo' => null,
        ];
        $path = storage_path('framework/testing/f15-capacity.json');
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertFileExists($path);
        $this->assertNull($report['slo']);
    }

    public function test_stream_accel_header_after_policy_when_enabled(): void
    {
        Storage::fake('media');
        config(['music.stream_accel_enabled' => true]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listener = User::factory()->create();
        $asset = MediaAsset::factory()->create();
        Storage::disk('media')->put($asset->path, str_repeat('z', 100));
        $track = Track::factory()->create(['media_asset_id' => $asset->id]);
        Playlist::factory()->create(['created_by' => $owner->id, 'status' => Playlist::PUBLISHED])
            ->tracks()->attach($track->id, ['position' => 1]);
        $this->actingAs($listener);
        $this->get('/api/v1/tracks/'.$track->id.'/stream')
            ->assertOk()
            ->assertHeader('X-Accel-Redirect')
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringContainsString('/internal-media/', (string) $this->get('/api/v1/tracks/'.$track->id.'/stream')->headers->get('X-Accel-Redirect'));
        $track->delete();
        $this->get('/api/v1/tracks/'.$track->id.'/stream')->assertNotFound();
        $this->assertSame(0, Artisan::call('ops:alerts'));
    }

    public function test_stream_range_head_content_disposition_and_revoke(): void
    {
        Storage::fake('media');
        config(['music.stream_accel_enabled' => false]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listener = User::factory()->create();
        $asset = MediaAsset::factory()->create(['mime_type' => 'audio/wav']);
        Storage::disk('media')->put($asset->path, str_repeat('1', 64));
        $track = Track::factory()->create(['media_asset_id' => $asset->id]);
        Playlist::factory()->create(['created_by' => $owner->id, 'status' => Playlist::PUBLISHED])
            ->tracks()->attach($track->id, ['position' => 1]);
        $this->actingAs($listener);
        $this->head('/api/v1/tracks/'.$track->id.'/stream')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline')
            ->assertHeader('Content-Type', 'audio/wav');
        $this->withHeader('Range', 'bytes=0-7')->get('/api/v1/tracks/'.$track->id.'/stream')->assertStatus(206);
        $this->withHeader('Range', 'bytes=999999-')->get('/api/v1/tracks/'.$track->id.'/stream')->assertStatus(416);
        $track->delete();
        $this->get('/api/v1/tracks/'.$track->id.'/stream')->assertNotFound();
    }
}
