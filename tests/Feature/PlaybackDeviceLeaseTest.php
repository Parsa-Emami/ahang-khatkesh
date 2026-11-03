<?php

namespace Tests\Feature;

use App\Models\PlaybackDevice;
use App\Models\PlaybackDeviceCommand;
use App\Models\PlaybackLease;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PlaybackDeviceLeaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_device_fences_other_writers_and_invalidates_commands_on_revoke(): void
    {
        [$user, $track] = $this->memberWithTrack();
        $this->actingAs($user);
        $a = $this->registerDevice('device-aaaa-aaaa-aaaa', 'Phone A');
        $b = $this->registerDevice('device-bbbb-bbbb-bbbb', 'Laptop B');
        $leaseA = $this->postJson('/api/v1/playback/devices/'.$a.'/claim')->assertOk()->json('data');
        $this->assertSame(PlaybackLease::ACTIVE, $leaseA['state']);
        $payload = $this->playbackPayload($track->id, 0, $b, 'stale-token');
        $this->putJson('/api/v1/playback', $payload)->assertStatus(409)->assertJsonPath('code', 'playback_device_fenced');
        $this->putJson('/api/v1/playback', $this->playbackPayload($track->id, 0, $a, $leaseA['lease_token']))
            ->assertOk()
            ->assertJsonPath('data.revision', 1);
        $this->postJson('/api/v1/playback/devices/'.$a.'/transfer', [
            'lease_token' => $leaseA['lease_token'],
            'target_device_id' => $b,
        ])->assertOk()->assertJsonPath('data.state', PlaybackLease::TRANSFERRING);
        $commands = $this->getJson('/api/v1/playback/devices/'.$b.'/commands')->assertOk()->json('data');
        $this->assertTrue(collect($commands)->contains(fn ($row) => $row['type'] === 'transfer_offer'));
        $accepted = $this->postJson('/api/v1/playback/devices/'.$b.'/transfer/accept')->assertOk()->json('data');
        $this->assertSame($b, $accepted['device_id']);
        $this->putJson('/api/v1/playback', $this->playbackPayload($track->id, 1, $a, $leaseA['lease_token']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'playback_device_fenced');
        $this->deleteJson('/api/v1/playback/devices/'.$b)->assertOk();
        $this->assertNotNull(PlaybackDevice::find($b)->revoked_at);
        $this->assertTrue(
            PlaybackDeviceCommand::where('target_device_id', $b)->whereNotNull('invalidated_at')->exists()
            || PlaybackDeviceCommand::where('target_device_id', $b)->whereNotNull('consumed_at')->exists()
        );
        $this->assertSame(PlaybackLease::IDLE, PlaybackLease::where('user_id', $user->id)->value('state'));
    }

    public function test_expired_transfer_falls_back_and_foreign_device_is_hidden(): void
    {
        [$user, $track] = $this->memberWithTrack();
        $other = User::factory()->create();
        $this->actingAs($user);
        $a = $this->registerDevice('device-cccc-cccc-cccc', 'Tablet');
        $b = $this->registerDevice('device-dddd-dddd-dddd', 'TV');
        $lease = $this->postJson('/api/v1/playback/devices/'.$a.'/claim')->assertOk()->json('data');
        $this->postJson('/api/v1/playback/devices/'.$a.'/transfer', [
            'lease_token' => $lease['lease_token'],
            'target_device_id' => $b,
        ])->assertOk();
        $this->travel(35)->seconds();
        $this->postJson('/api/v1/playback/devices/'.$a.'/heartbeat', ['lease_token' => $lease['lease_token']])
            ->assertOk()
            ->assertJsonPath('data.state', PlaybackLease::ACTIVE)
            ->assertJsonPath('data.transfer_target_id', null);
        $fallback = $this->getJson('/api/v1/playback/devices/'.$b.'/commands')->assertOk()->json('data');
        $this->assertTrue(collect($fallback)->contains(fn ($row) => $row['type'] === 'transfer_fallback'));
        $this->actingAs($other);
        $this->postJson('/api/v1/playback/devices/'.$a.'/claim')->assertNotFound();
        $this->getJson('/api/v1/playback/devices/'.$a.'/commands')->assertNotFound();
        unset($track);
    }

    public function test_reject_transfer_keeps_source_lease(): void
    {
        [$user] = $this->memberWithTrack();
        $this->actingAs($user);
        $a = $this->registerDevice('device-eeee-eeee-eeee', 'A');
        $b = $this->registerDevice('device-ffff-ffff-ffff', 'B');
        $lease = $this->postJson('/api/v1/playback/devices/'.$a.'/claim')->assertOk()->json('data');
        $this->postJson('/api/v1/playback/devices/'.$a.'/transfer', [
            'lease_token' => $lease['lease_token'],
            'target_device_id' => $b,
        ])->assertOk();
        $this->postJson('/api/v1/playback/devices/'.$b.'/transfer/reject')->assertOk()
            ->assertJsonPath('data.device_id', $a)
            ->assertJsonPath('data.state', PlaybackLease::ACTIVE);
    }

    /**
     * @return array{0: User, 1: Track}
     */
    private function memberWithTrack(): array
    {
        $user = User::factory()->create();
        $track = Track::factory()->create();
        Playlist::factory()->create(['created_by' => $user->id, 'status' => Playlist::PUBLISHED])
            ->tracks()->attach($track->id, ['position' => 1]);

        return [$user, $track];
    }

    private function registerDevice(string $key, string $name): string
    {
        return $this->postJson('/api/v1/playback/devices', [
            'device_key' => $key,
            'name' => $name,
        ])->assertCreated()->json('data.id');
    }

    private function playbackPayload(int $trackId, int $revision, ?string $deviceId = null, ?string $token = null): array
    {
        return [
            'track_id' => $trackId,
            'queue' => [$trackId],
            'position_seconds' => 1,
            'volume' => 0.5,
            'shuffle' => false,
            'repeat_mode' => 'off',
            'revision' => $revision,
            'device_id' => $deviceId,
            'lease_token' => $token,
        ];
    }
}
