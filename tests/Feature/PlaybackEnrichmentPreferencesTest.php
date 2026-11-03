<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PlaybackEnrichmentPreferencesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_crossfade_stays_off_until_device_limitations_are_acknowledged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/me', ['crossfade_enabled' => true])->assertUnprocessable();
        $this->assertFalse((bool) $user->fresh()->crossfade_enabled);
        $this->patchJson('/api/v1/me', [
            'crossfade_acknowledged' => true,
            'crossfade_enabled' => true,
            'crossfade_seconds' => 4,
            'loudness_normalization' => false,
        ])->assertOk()
            ->assertJsonPath('data.crossfade_enabled', true)
            ->assertJsonPath('data.crossfade_acknowledged', true)
            ->assertJsonPath('data.crossfade_seconds', 4)
            ->assertJsonPath('data.loudness_normalization', false);
    }
}
