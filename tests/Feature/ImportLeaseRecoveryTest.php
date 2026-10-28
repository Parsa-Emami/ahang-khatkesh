<?php

namespace Tests\Feature;

use App\Actions\Imports\ImportExecution;
use App\Enums\UserRole;
use App\Jobs\PublishPlaylistImport;
use App\Models\MediaAsset;
use App\Models\PlaylistImport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportLeaseRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function queued(): PlaylistImport
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $asset = MediaAsset::factory()->create();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $manifest = UploadedFile::fake()->createWithContent('tracks.json', json_encode(['tracks' => [['asset_id' => $asset->id, 'title' => 'Lease track', 'artist' => 'Artist']]]));
        $id = $this->post('/api/v1/admin/imports', ['name' => 'Lease playlist', 'mood' => 'calm', 'manifest' => $manifest], ['Accept' => 'application/json', 'Idempotency-Key' => 'lease-preview-idempotency-0001'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();

        return PlaylistImport::findOrFail($id);
    }

    public function test_claim_and_heartbeat_fence_duplicates_and_protect_an_active_worker(): void
    {
        $import = $this->queued();
        $execution = app(ImportExecution::class);
        $this->assertTrue($execution->claim($import->id, 1, $import->execution_token));
        $deadline = $import->fresh()->lease_expires_at;
        $this->assertTrue($execution->renew($import->id, 1, $import->execution_token));
        $this->assertFalse($execution->claim($import->id, 1, $import->execution_token));
        $this->travel(20)->seconds();
        $this->assertTrue($execution->renew($import->id, 1, $import->execution_token));
        $this->assertTrue($import->fresh()->lease_expires_at->greaterThan($deadline));
        $this->assertSame(0, $execution->recover());
        $this->assertDatabaseCount('playlists', 0);
        Queue::assertPushed(PublishPlaylistImport::class, 1);
    }

    public function test_expired_worker_and_late_failure_cannot_publish_over_the_recovered_generation(): void
    {
        $import = $this->queued();
        $old = new PublishPlaylistImport($import->id, 1, $import->execution_token);
        $execution = app(ImportExecution::class);
        $this->assertTrue($execution->claim($import->id, 1, $import->execution_token));
        $this->travel(241)->seconds();
        $this->assertFalse($execution->renew($import->id, 1, $import->execution_token));
        $this->assertSame(1, $execution->recover());
        $fresh = $import->fresh();
        $this->assertSame(2, $fresh->generation);
        $this->assertNotSame($import->execution_token, $fresh->execution_token);
        $old->failed(new \RuntimeException('Late failure'));
        app()->call([$old, 'handle']);
        $this->assertDatabaseCount('playlists', 0);
        app()->call([new PublishPlaylistImport($fresh->id, 2, $fresh->execution_token), 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $import->id, 'status' => 'completed']);
        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseCount('tracks', 1);
        Queue::assertPushed(PublishPlaylistImport::class, 2);
    }

    public function test_queued_stall_is_recovered_but_repeated_dispatch_and_unversioned_payload_are_harmless(): void
    {
        $import = $this->queued();
        $this->postJson('/api/v1/admin/imports/'.$import->id.'/commit', ['publish' => true])->assertOk();
        app()->call([new PublishPlaylistImport($import->id), 'handle']);
        $legacy = (new \ReflectionClass(PublishPlaylistImport::class))->newInstanceWithoutConstructor();
        $legacy->importId = $import->id;
        $legacy->generation = 1;
        app()->call([$legacy, 'handle']);
        $this->assertDatabaseCount('playlists', 0);
        $this->assertSame(0, app(ImportExecution::class)->recover());
        $this->travel(601)->seconds();
        $this->assertSame(1, app(ImportExecution::class)->recover());
        $this->assertSame(2, $import->fresh()->generation);
        Queue::assertPushed(PublishPlaylistImport::class, 2);
    }

    public function test_automatic_recovery_has_a_budget_and_manual_retry_requires_current_superadmin(): void
    {
        $import = $this->queued();
        for ($i = 0; $i < 4; $i++) {
            $this->travel(601)->seconds();
            app(ImportExecution::class)->recover();
        }
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertSame(3, $import->fresh()->recovery_count);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $this->postJson('/api/v1/admin/imports/'.$import->id.'/commit', ['publish' => true])->assertOk();
        $this->assertSame(0, $import->fresh()->recovery_count);
        $this->assertSame('processing', $import->fresh()->status);
        Queue::assertPushed(PublishPlaylistImport::class, 5);
    }

    public function test_worker_timeout_is_recovered_after_backoff_and_stale_callback_cannot_extend_a_new_attempt(): void
    {
        $import = $this->queued();
        $old = new PublishPlaylistImport($import->id, 1, $import->execution_token);
        app(ImportExecution::class)->claim($import->id, 1, $import->execution_token);
        $old->failed(new TimeoutExceededException('Injected worker timeout'));
        $this->assertSame('running', $import->fresh()->status);
        $this->assertSame(0, app(ImportExecution::class)->recover());
        $this->travel(31)->seconds();
        $this->assertSame(1, app(ImportExecution::class)->recover());
        $fresh = $import->fresh();
        $old->failed(new TimeoutExceededException('Late timeout'));
        $this->assertSame('processing', $import->fresh()->status);
        $this->assertSame($fresh->execution_token, $import->fresh()->execution_token);
        Queue::assertPushed(PublishPlaylistImport::class, 2);
    }

    public function test_recovery_cancels_a_revoked_creators_intent_instead_of_requeueing(): void
    {
        $import = $this->queued();
        User::whereKey($import->created_by)->update(['is_active' => false]);
        $this->travel(601)->seconds();
        app(ImportExecution::class)->recover();
        $this->assertSame('cancelled', $import->fresh()->status);
        $this->assertDatabaseCount('playlists', 0);
        Queue::assertPushed(PublishPlaylistImport::class, 1);
    }
}
