<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\ListeningEvent;
use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Services\Listening\ListeningLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ListeningPrivacyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function track(User $user): Track
    {
        $track = Track::factory()->create(['media_asset_id' => MediaAsset::factory()->create(['uploaded_by' => $user->id])]);
        Playlist::factory()->create(['created_by' => $user->id])->tracks()->attach($track->id, ['position' => 0]);

        return $track;
    }

    private function enable(User $user): int
    {
        $this->actingAs($user);

        return $this->patchJson('/api/v1/listening/settings', ['revision' => 0, 'history_enabled' => true])->assertOk()->json('data.revision');
    }

    private function grant(Track $track, int $revision): string
    {
        $this->withCredentials();
        $response = $this->postJson('/api/v1/listening/plays', ['track_id' => $track->id, 'privacy_revision' => $revision])->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId());

        return $response->json('data.token');
    }

    public function test_history_requires_explicit_consent_and_does_not_store_unqualified_starts(): void
    {
        $user = User::factory()->create();
        $track = $this->track($user);
        $this->actingAs($user)->getJson('/api/v1/listening/settings')->assertOk()->assertJsonPath('data.history_enabled', false);
        $this->postJson('/api/v1/listening/plays', ['track_id' => $track->id, 'privacy_revision' => 0])->assertOk()->assertJsonPath('data.recording', false);
        $revision = $this->enable($user);
        $token = $this->grant($track, $revision);
        $this->assertDatabaseCount('listening_events', 0);
        $this->travel(10)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 10])->assertOk()->assertJsonPath('data.recorded', false);
        $this->assertDatabaseCount('listening_events', 0);
    }

    public function test_qualified_progress_is_deduplicated_monotonic_and_completion_is_real(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $track = $this->track($user);
        $token = $this->grant($track, $this->enable($user));
        $this->travel(40)->seconds();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 35])->assertOk()->assertJsonPath('data.recorded', true);
        }
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 31])->assertOk();
        $this->assertDatabaseCount('listening_events', 1);
        $this->assertSame(35.0, ListeningEvent::first()->listened_seconds);
        $this->travel(80)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'completed', 'listened_seconds' => 115])->assertOk();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'skip', 'listened_seconds' => 120])->assertOk();
        $event = ListeningEvent::first();
        $this->assertSame('completed', $event->outcome);
        $this->assertNotNull($event->ended_at);
        $this->assertSame(64, strlen($event->session_pseudonym));
        $this->assertNotSame(session()->getId(), $event->session_pseudonym);
    }

    public function test_private_session_fences_pending_grants_and_never_persists_resume_state(): void
    {
        $user = User::factory()->create();
        $track = $this->track($user);
        $revision = $this->enable($user);
        $token = $this->grant($track, $revision);
        $this->patchJson('/api/v1/listening/settings', ['revision' => $revision, 'private_session' => true])->assertOk()->assertJsonPath('data.private_mode', true);
        $this->travel(40)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'skip', 'listened_seconds' => 35])->assertConflict();
        $this->postJson('/api/v1/listening/plays', ['track_id' => $track->id, 'privacy_revision' => $revision + 1])->assertOk()->assertJsonPath('data.recording', false);
        $this->putJson('/api/v1/playback', ['track_id' => $track->id, 'queue' => [$track->id], 'position_seconds' => 40, 'volume' => 1, 'shuffle' => false, 'repeat_mode' => 'off', 'revision' => 0])->assertOk()->assertJsonPath('data.suppressed', true);
        $this->assertDatabaseCount('listening_events', 0);
        $this->assertDatabaseCount('playback_states', 0);
        $this->travel(7)->hours();
        $this->withSession(['auth.access_version' => $user->access_version]);
        $this->postJson('/api/v1/listening/plays', ['track_id' => $track->id, 'privacy_revision' => $revision + 1])->assertOk()->assertJsonPath('data.recording', true);
    }

    public function test_erase_and_consent_withdrawal_fence_replayed_requests_and_delete_real_rows(): void
    {
        $user = User::factory()->create();
        $track = $this->track($user);
        $revision = $this->enable($user);
        $token = $this->grant($track, $revision);
        $this->travel(40)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 35])->assertOk();
        $this->deleteJson('/api/v1/listening/history', ['revision' => $revision])->assertOk()->assertJsonPath('data.revision', 2);
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 36])->assertConflict();
        $this->assertDatabaseCount('listening_events', 0);
        $token = $this->grant($track, 2);
        $this->travel(40)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 35])->assertOk();
        $this->patchJson('/api/v1/listening/settings', ['revision' => 2, 'history_enabled' => false])->assertOk()->assertJsonPath('data.history_enabled', false);
        $this->assertDatabaseCount('listening_events', 0);
    }

    public function test_user_isolation_export_single_delete_and_hidden_tracks_are_enforced(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $track = $this->track($user);
        $revision = $this->enable($user);
        $token = $this->grant($track, $revision);
        $this->travel(40)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 35])->assertOk();
        $id = ListeningEvent::first()->id;
        $this->actingAs($other)->getJson('/api/v1/listening/history')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'skip', 'listened_seconds' => 35])->assertForbidden();
        $this->deleteJson('/api/v1/listening/history/'.$id, ['revision' => 0])->assertNotFound();
        $export = $this->actingAs($user)->getJson('/api/v1/listening/export')->assertOk()->assertJsonCount(1, 'data');
        foreach (['private', 'no-store'] as $directive) {
            $this->assertTrue($export->headers->hasCacheControlDirective($directive));
        }
        $track->playlists()->first()->update(['status' => 'draft']);
        $this->getJson('/api/v1/listening/history')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/listening/export')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/listening/history/'.$id, ['revision' => $revision])->assertOk();
        $this->assertDatabaseCount('listening_events', 0);
    }

    public function test_retention_is_immediate_for_reads_and_the_pruner_removes_expired_rows(): void
    {
        $user = User::factory()->create();
        $track = $this->track($user);
        $token = $this->grant($track, $this->enable($user));
        $this->travel(40)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 35])->assertOk();
        $this->travel(31)->days();
        $this->getJson('/api/v1/listening/history')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/listening/export')->assertOk()->assertJsonCount(0, 'data');
        $this->artisan('listening:prune')->assertSuccessful();
        $this->assertDatabaseCount('listening_events', 0);
    }

    public function test_untrusted_clock_tampering_expiry_and_stale_settings_are_rejected(): void
    {
        $user = User::factory()->create();
        $track = $this->track($user);
        $revision = $this->enable($user);
        $token = $this->grant($track, $revision);
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 100])->assertUnprocessable();
        $this->postJson('/api/v1/listening/events', ['token' => 'forged', 'kind' => 'progress', 'listened_seconds' => 35])->assertUnprocessable();
        $this->patchJson('/api/v1/listening/settings', ['revision' => 0, 'retention_days' => 90])->assertConflict();
        $this->patchJson('/api/v1/listening/settings', ['revision' => $revision, 'retention_days' => 999])->assertUnprocessable();
        $this->travel(7)->hours();
        $this->withSession(['auth.access_version' => $user->access_version]);
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'progress', 'listened_seconds' => 35])->assertGone();
        $this->assertDatabaseCount('listening_events', 0);
    }

    public function test_guests_cannot_read_or_change_listening_data(): void
    {
        foreach (['settings', 'history', 'export', 'mix'] as $path) {
            $this->getJson('/api/v1/listening/'.$path)->assertUnauthorized();
        }
        $this->postJson('/api/v1/listening/plays', [])->assertUnauthorized();
    }

    public function test_short_tracks_use_half_duration_but_seeking_does_not_imply_completion(): void
    {
        $user = User::factory()->create();
        $track = $this->track($user);
        $track->mediaAsset->update(['duration_seconds' => 20]);
        $token = $this->grant($track, $this->enable($user));
        $this->travel(12)->seconds();
        $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'completed', 'listened_seconds' => 10])->assertOk()->assertJsonPath('data.recorded', true);
        $this->assertSame('skip', ListeningEvent::first()->outcome);
    }

    public function test_retention_changes_and_the_per_account_cap_physically_remove_old_data(): void
    {
        config(['listening.max_events_per_user' => 2]);
        $user = User::factory()->create();
        $track = $this->track($user);
        $revision = $this->enable($user);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $token = $this->grant($track, $revision);
            $this->travel(40)->seconds();
            $ids[] = $this->postJson('/api/v1/listening/events', ['token' => $token, 'kind' => 'skip', 'listened_seconds' => 35])->assertOk()->json('data.play_id');
        }
        $this->assertDatabaseCount('listening_events', 2);
        $this->assertDatabaseMissing('listening_events', ['id' => $ids[0]]);
        ListeningEvent::whereKey($ids[1])->update(['started_at' => now()->subDays(8)]);
        $this->patchJson('/api/v1/listening/settings', ['revision' => $revision, 'retention_days' => 7])->assertOk()->assertJsonPath('data.retention_days', 7);
        $this->assertDatabaseCount('listening_events', 1);
        $this->assertDatabaseMissing('listening_events', ['id' => $ids[1]]);
    }

    public function test_session_replay_is_rejected_and_user_serialization_keeps_privacy_settings_hidden(): void
    {
        $user = User::factory()->create();
        $track = $this->track($user);
        $token = $this->grant($track, $this->enable($user));
        $this->travel(40)->seconds();
        try {
            app(ListeningLedger::class)->record($user, 'another-valid-session', ['token' => $token, 'kind' => 'skip', 'listened_seconds' => 35]);
            $this->fail('A grant must be bound to its original session.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        foreach ([$user->fresh()->toArray(), (new UserResource($user->fresh()))->resolve()] as $data) {
            $this->assertSame([], array_filter(array_keys($data), fn ($key) => str_starts_with($key, 'listening_')));
        }
        $this->assertDatabaseCount('listening_events', 0);
    }
}
