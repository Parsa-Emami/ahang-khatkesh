<?php

namespace Tests\Feature;

use App\Models\OfflineDownload;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OfflineRevocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_offline_stays_disabled_until_revocation_limit_is_acknowledged(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->create();
        Playlist::factory()->create(['created_by' => $user->id, 'status' => Playlist::PUBLISHED])
            ->tracks()->attach($track->id, ['position' => 1]);
        $this->actingAs($user);
        $this->getJson('/api/v1/offline/downloads')->assertStatus(422)->assertJsonPath('code', 'offline_disabled');
        $this->postJson('/api/v1/offline/downloads/'.$track->id)->assertStatus(422)->assertJsonPath('code', 'offline_disabled');
        $this->patchJson('/api/v1/me', ['offline_opt_in' => true])->assertUnprocessable();
        $this->assertFalse((bool) $user->fresh()->offline_opt_in);
        $this->patchJson('/api/v1/me', [
            'offline_revocation_acknowledged' => true,
            'offline_opt_in' => true,
        ])->assertOk()
            ->assertJsonPath('data.offline_opt_in', true)
            ->assertJsonPath('data.offline_revocation_acknowledged', true);
        $this->postJson('/api/v1/offline/downloads/'.$track->id)
            ->assertCreated()
            ->assertJsonPath('data.status', OfflineDownload::READY)
            ->assertJsonPath('data.track_id', $track->id);
        $this->getJson('/api/v1/offline/downloads')->assertOk()
            ->assertJsonPath('data.usage.used_bytes', (int) $track->mediaAsset->size_bytes)
            ->assertJsonPath('data.downloads.0.status', OfflineDownload::READY);
        $this->assertStringContainsString('Fully offline', $this->getJson('/api/v1/offline/downloads')->json('data.usage.revocation_limit'));
        $this->deleteJson('/api/v1/offline/downloads/'.$track->id)->assertOk();
        $this->assertSame(OfflineDownload::REVOKED, OfflineDownload::where('user_id', $user->id)->value('status'));
        $this->postJson('/api/v1/offline/downloads/'.$track->id)->assertCreated();
        $this->postJson('/api/v1/logout')->assertOk()->assertJsonPath('data.offline_wiped', true);
        $this->assertSame(OfflineDownload::REVOKED, OfflineDownload::where('user_id', $user->id)->value('status'));
    }

    public function test_offline_quota_blocks_overfill_and_shell_worker_never_targets_streams(): void
    {
        $user = User::factory()->create([
            'offline_opt_in' => true,
            'offline_revocation_acknowledged_at' => now(),
            'offline_quota_bytes' => 10,
        ]);
        $track = Track::factory()->create();
        Playlist::factory()->create(['created_by' => $user->id, 'status' => Playlist::PUBLISHED])
            ->tracks()->attach($track->id, ['position' => 1]);
        $this->actingAs($user);
        $this->postJson('/api/v1/offline/downloads/'.$track->id)->assertUnprocessable();
        $source = file_get_contents(resource_path('js/lib/shell-sw.ts'));
        $this->assertNotFalse($source);
        $this->assertStringContainsString("pathname.startsWith('/api/')", $source);
        $this->assertStringContainsString("pathname.includes('/stream')", $source);
        $this->assertStringContainsString('ak-shell-v1', $source);
    }
}
