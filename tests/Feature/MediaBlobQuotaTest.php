<?php

namespace Tests\Feature;

use App\Actions\Media\BackfillMediaBlobs;
use App\Actions\Media\CleanupUpload;
use App\Actions\Media\CollectMediaGarbage;
use App\Actions\Media\IngestMedia;
use App\Actions\Media\RecoverUploads;
use App\Actions\Media\ReserveStorage;
use App\Actions\Media\StorageBudget;
use App\Actions\Media\VerifyMedia;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\MediaGarbageCandidate;
use App\Models\StorageReservation;
use App\Models\User;
use App\Services\MediaFiles;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AudioFixture;
use Tests\TestCase;

class MediaBlobQuotaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function owner(): User
    {
        Storage::fake('media');
        $user = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);

        return $user;
    }

    private function reserve(User $owner): StorageReservation
    {
        return app(ReserveStorage::class)->handle($owner, strlen(AudioFixture::bytes()), 'audio');
    }

    public function test_storage_is_reserved_before_an_upload_and_limit_rejection_has_no_writes(): void
    {
        $this->owner();
        config(['music.storage_quota_bytes' => 10]);
        $this->postJson('/api/v1/admin/storage-reservations', ['purpose' => 'audio', 'size_bytes' => 11])->assertStatus(507);
        $this->post('/api/v1/admin/media', ['audio' => AudioFixture::file()], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('reservation_id');
        $this->assertDatabaseCount('storage_reservations', 0);
        $this->assertDatabaseCount('media_assets', 0);
        Storage::disk('media')->assertDirectoryEmpty('/');
    }

    public function test_physical_headroom_is_enforced_even_when_logical_quota_is_available(): void
    {
        $this->owner();
        config(['music.storage_headroom_bytes' => 1000, 'music.storage_quota_bytes' => 100000]);
        $files = Mockery::mock(app(MediaFiles::class))->makePartial();
        $files->shouldReceive('availableBytes')->once()->andReturn(2000);
        $this->app->instance(MediaFiles::class, $files);
        $this->postJson('/api/v1/admin/storage-reservations', ['purpose' => 'audio', 'size_bytes' => 1001])->assertStatus(507);
        $this->assertDatabaseCount('storage_reservations', 0);
    }

    public function test_only_super_administrators_can_reserve_and_cancel_unused_space(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        $this->postJson('/api/v1/admin/storage-reservations', ['purpose' => 'audio', 'size_bytes' => 10])->assertForbidden();
        $this->deleteJson('/api/v1/admin/storage-reservations/'.$reservation->id)->assertForbidden();
        $this->actingAs($owner);
        $this->deleteJson('/api/v1/admin/storage-reservations/'.$reservation->id)->assertOk();
        $this->deleteJson('/api/v1/admin/storage-reservations/'.$reservation->id)->assertOk();
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
    }

    public function test_same_bytes_can_back_independent_assets_without_merging_recording_identity(): void
    {
        $owner = $this->owner();
        $first = app(IngestMedia::class)->handle($owner, $this->reserve($owner)->id, AudioFixture::file(), reuse: false);
        $secondReservation = $this->reserve($owner);
        $second = app(IngestMedia::class)->handle($owner, $secondReservation->id, AudioFixture::file(), reuse: false);
        $this->assertNotSame($first['asset']->id, $second['asset']->id);
        $this->assertSame($first['blob']->id, $second['blob']->id);
        $this->assertDatabaseCount('media_blobs', 1);
        $this->assertDatabaseCount('media_assets', 2);
        $this->assertSame(strlen(AudioFixture::bytes()), app(StorageBudget::class)->snapshot()['reserved_bytes']);
        app(CleanupUpload::class)->handle($secondReservation->id);
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        $this->assertSame(strlen(AudioFixture::bytes()), app(StorageBudget::class)->snapshot()['used_bytes']);
        $this->assertCount(1, Storage::disk('media')->allFiles('blobs'));
        $this->assertDatabaseCount('tracks', 0);
    }

    public function test_default_dedup_and_consumed_reservation_replay_are_idempotent_but_changed_bytes_are_refused(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $first = app(IngestMedia::class)->handle($owner, $reservation->id, AudioFixture::file());
        $again = app(IngestMedia::class)->handle($owner, $reservation->id, AudioFixture::file());
        $this->assertSame($first['asset']->id, $again['asset']->id);
        $this->assertDatabaseCount('media_assets', 1);
        $changed = UploadedFile::fake()->createWithContent('changed.wav', str_repeat('x', strlen(AudioFixture::bytes())));
        try {
            app(IngestMedia::class)->handle($owner, $reservation->id, $changed);
            $this->fail('A consumed reservation accepted different bytes.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Storage::disk('media')->assertExists($first['blob']->path);
    }

    public function test_revoking_actor_during_file_io_prevents_finalization_and_preserves_cleanup_accounting(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $files = app(MediaFiles::class);
        $proxy = Mockery::mock($files)->makePartial();
        $proxy->shouldReceive('promote')->once()->andReturnUsing(function ($staging, $candidate) use ($files, $owner): void {
            $files->promote($staging, $candidate);
            DB::table('users')->where('id', $owner->id)->update(['role' => 'listener', 'access_version' => 1]);
        });
        $this->app->instance(MediaFiles::class, $proxy);
        try {
            app(IngestMedia::class)->handle($owner, $reservation->id, AudioFixture::file());
            $this->fail('A revoked writer finalized media.');
        } catch (HttpException $error) {
            $this->assertSame(401, $error->getStatusCode());
        }
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_blobs', 0);
        $this->assertSame('cleanup_pending', $reservation->fresh()->status);
        $this->assertSame(strlen(AudioFixture::bytes()), app(StorageBudget::class)->snapshot()['reserved_bytes']);
        $this->app->instance(MediaFiles::class, $files);
        app(CleanupUpload::class)->handle($reservation->id);
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_expired_or_foreign_reservations_cannot_write_and_recovery_returns_capacity(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $other = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($other)->post('/api/v1/admin/media', ['reservation_id' => $reservation->id, 'audio' => AudioFixture::file()], ['Accept' => 'application/json'])->assertNotFound();
        $reservation->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($owner)->post('/api/v1/admin/media', ['reservation_id' => $reservation->id, 'audio' => AudioFixture::file()], ['Accept' => 'application/json'])->assertConflict();
        app(RecoverUploads::class)->handle();
        app(CleanupUpload::class)->handle($reservation->id);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertDatabaseCount('media_blobs', 0);
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
    }

    public function test_partial_and_zero_byte_staging_failures_remain_charged_until_cleanup(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $files = app(MediaFiles::class);
        $proxy = Mockery::mock($files)->makePartial();
        $proxy->shouldReceive('stage')->once()->andReturnUsing(function ($file, $path): void {
            Storage::disk('media')->put($path, '');
            throw new RuntimeException('Injected disk full');
        });
        $this->app->instance(MediaFiles::class, $proxy);
        try {
            app(IngestMedia::class)->handle($owner, $reservation->id, AudioFixture::file());
            $this->fail('Disk failure was accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected disk full', $error->getMessage());
        }
        $this->assertSame('cleanup_pending', $reservation->fresh()->status);
        $this->assertSame(strlen(AudioFixture::bytes()), app(StorageBudget::class)->snapshot()['reserved_bytes']);
        $this->assertDatabaseCount('media_blobs', 0);
        $this->app->instance(MediaFiles::class, $files);
        app(CleanupUpload::class)->handle($reservation->id);
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        Storage::disk('media')->assertMissing('staging/'.$reservation->id.'/input');
    }

    public function test_a_successful_storage_call_with_zero_bytes_cannot_publish_an_asset(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $files = app(MediaFiles::class);
        $proxy = Mockery::mock($files)->makePartial();
        $proxy->shouldReceive('stage')->once()->andReturnUsing(fn ($file, $path) => Storage::disk('media')->put($path, ''));
        $this->app->instance(MediaFiles::class, $proxy);
        try {
            app(IngestMedia::class)->handle($owner, $reservation->id, AudioFixture::file());
            $this->fail('Zero-byte staging was published.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('audio', $error->errors());
        }
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_blobs', 0);
        $this->assertSame('cleanup_pending', $reservation->fresh()->status);
        $this->app->instance(MediaFiles::class, $files);
        app(CleanupUpload::class)->handle($reservation->id);
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_active_filesystem_writer_fences_cleanup_even_after_database_lease_expiry(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $reservation->update(['status' => 'writing', 'staging_path' => 'staging/'.$reservation->id.'/input', 'expires_at' => now()->subSecond()]);
        Storage::disk('media')->put($reservation->staging_path, 'partial');
        app(MediaFiles::class)->withUploadLock($reservation->id, function () use ($reservation): void {
            app(RecoverUploads::class)->handle();
            try {
                app(CleanupUpload::class)->handle($reservation->id);
                $this->fail('An active writer lost its file.');
            } catch (RuntimeException $error) {
                $this->assertSame('Upload filesystem lease is busy.', $error->getMessage());
            }
            Storage::disk('media')->assertExists($reservation->staging_path);
        });
        app(CleanupUpload::class)->handle($reservation->id);
        Storage::disk('media')->assertMissing($reservation->staging_path);
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_resumable_backfill_is_read_only_by_default_and_preserves_asset_identifiers(): void
    {
        $owner = $this->owner();
        Storage::disk('media')->put('audio/legacy.wav', AudioFixture::bytes());
        $asset = MediaAsset::create(['sha256' => hash('sha256', AudioFixture::bytes()), 'path' => 'audio/legacy.wav', 'size_bytes' => strlen(AudioFixture::bytes()), 'mime_type' => 'audio/wav', 'duration_seconds' => .1, 'original_name' => 'legacy.wav', 'uploaded_by' => $owner->id]);
        $this->assertSame(1, app(BackfillMediaBlobs::class)->handle()['checked']);
        $this->assertNull($asset->fresh()->blob_id);
        $applied = app(BackfillMediaBlobs::class)->handle(true);
        $this->assertSame(1, $applied['linked']);
        $this->assertNotNull($asset->fresh()->blob_id);
        $this->assertSame($asset->id, MediaAsset::firstOrFail()->id);
        $this->assertSame(0, app(BackfillMediaBlobs::class)->handle(true)['linked']);
        $this->assertTrue(app(VerifyMedia::class)->handle()['ok']);
    }

    public function test_terminal_upload_locks_are_pruned_after_retention_without_touching_media(): void
    {
        $owner = $this->owner();
        $reservation = $this->reserve($owner);
        $result = app(IngestMedia::class)->handle($owner, $reservation->id, AudioFixture::file());
        Storage::disk('media')->assertExists('.locks/'.$reservation->id.'.lock');
        $this->assertSame(0, app(CleanupUpload::class)->pruneLocks());
        $this->travel(86401)->seconds();
        $this->assertSame(1, app(CleanupUpload::class)->pruneLocks());
        $this->assertSame(0, app(CleanupUpload::class)->pruneLocks());
        Storage::disk('media')->assertMissing('.locks/'.$reservation->id.'.lock');
        Storage::disk('media')->assertExists($result['blob']->path);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_integrity_check_detects_corruption_without_repairing_or_deleting_data(): void
    {
        $owner = $this->owner();
        $ingested = app(IngestMedia::class)->handle($owner, $this->reserve($owner)->id, AudioFixture::file());
        Storage::disk('media')->put($ingested['blob']->path, 'corrupt');
        $result = app(VerifyMedia::class)->handle();
        $this->assertFalse($result['ok']);
        $this->assertSame($ingested['blob']->id, $result['errors'][0]['id']);
        $this->assertDatabaseCount('media_assets', 1);
        Storage::disk('media')->assertExists($ingested['blob']->path);
    }

    public function test_garbage_collection_requires_mark_retention_and_a_fresh_reference_check(): void
    {
        $owner = $this->owner();
        Storage::disk('media')->put('audio/orphan.wav', AudioFixture::bytes());
        $garbage = app(CollectMediaGarbage::class);
        $this->assertSame(1, $garbage->scan()['candidates']);
        $this->assertDatabaseCount('media_garbage_candidates', 0);
        $garbage->scan(true);
        $this->assertSame(0, $garbage->sweep());
        $this->travel(86401)->seconds();
        $this->assertSame(1, $garbage->sweep());
        Storage::disk('media')->assertMissing('audio/orphan.wav');
        $this->assertDatabaseCount('media_garbage_candidates', 0);
    }

    public function test_a_new_reference_and_changed_bytes_both_fence_previously_marked_garbage(): void
    {
        $owner = $this->owner();
        Storage::disk('media')->put('audio/retained.wav', AudioFixture::bytes());
        Storage::disk('media')->put('audio/changed.wav', 'old');
        $garbage = app(CollectMediaGarbage::class);
        $garbage->scan(true);
        $blob = MediaBlob::create(['sha256' => hash('sha256', AudioFixture::bytes()), 'size_bytes' => strlen(AudioFixture::bytes()), 'path' => 'audio/retained.wav', 'mime_type' => 'audio/wav', 'state' => 'ready']);
        MediaAsset::create(['blob_id' => $blob->id, 'sha256' => $blob->sha256, 'size_bytes' => $blob->size_bytes, 'path' => $blob->path, 'mime_type' => $blob->mime_type, 'duration_seconds' => .1, 'original_name' => 'retained.wav', 'uploaded_by' => $owner->id]);
        Storage::disk('media')->put('audio/changed.wav', 'new bytes');
        $this->travel(86401)->seconds();
        $this->assertSame(0, $garbage->sweep());
        Storage::disk('media')->assertExists('audio/retained.wav');
        Storage::disk('media')->assertExists('audio/changed.wav');
        $this->assertSame(hash('sha256', 'new bytes'), MediaGarbageCandidate::where('path', 'audio/changed.wav')->firstOrFail()->sha256);
    }
}
