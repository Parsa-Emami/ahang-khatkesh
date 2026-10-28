<?php

namespace Tests\Feature;

use App\Actions\Conversions\ConversionExecution;
use App\Actions\Media\CleanupUpload;
use App\Actions\Media\RecoverUploads;
use App\Actions\Media\StorageBudget;
use App\Actions\RegisterTrack;
use App\Exceptions\ConversionFailure;
use App\Exceptions\UploadLockBusy;
use App\Jobs\TranscodeMedia;
use App\Models\StorageReservation;
use App\Models\User;
use App\Services\AudioTranscoder;
use App\Services\MediaFiles;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesConversions;
use Tests\TestCase;

class ConversionRecoveryTest extends TestCase
{
    use CreatesConversions, LazilyRefreshDatabase;

    public function test_duplicate_delivery_and_old_failure_do_not_create_an_extra_output(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $job = new TranscodeMedia($c->id, $c->generation, $c->lease_token);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $job->failed(new TimeoutExceededException('late timeout'));
        $this->assertSame('ready', $c->fresh()->status);
        $this->assertDatabaseCount('media_assets', 2);
        $this->assertDatabaseCount('media_conversion_attempts', 1);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_timeout_retries_with_a_new_attempt_and_stale_callbacks_cannot_change_it(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $old = new TranscodeMedia($c->id, $c->generation, $c->lease_token);
        $this->mock(AudioTranscoder::class)->shouldReceive('convert')->once()->andThrow(new ConversionFailure('timeout', true));
        app()->call([$old, 'handle']);
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertNotNull($c->fresh()->next_retry_at);
        $this->assertSame(0, app(ConversionExecution::class)->recover());
        $this->travel(31)->seconds();
        $this->assertSame(1, app(ConversionExecution::class)->recover());
        $fresh = $c->fresh();
        $this->assertSame(2, $fresh->generation);
        $this->assertSame('queued', $fresh->status);
        $old->failed(new TimeoutExceededException('late timeout'));
        app()->call([$old, 'handle']);
        $this->assertSame($fresh->lease_token, $c->fresh()->lease_token);
        $this->assertSame('queued', $c->fresh()->status);
        $this->assertDatabaseCount('media_conversion_attempts', 2);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 2);
    }

    public function test_expiry_does_not_release_space_while_the_conversion_filesystem_lock_is_held(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $execution = app(ConversionExecution::class);
        $claim = $execution->claim($c->id, $c->generation, $c->lease_token);
        $reservation = $claim['reservation'];
        Storage::disk('media')->put($reservation->staging_path, 'partial output');
        $this->travel(961)->seconds();
        $this->assertSame(0, app(RecoverUploads::class)->handle());
        $this->assertSame(1, $execution->recover());
        $reserved = app(StorageBudget::class)->snapshot()['reserved_bytes'];
        app(MediaFiles::class)->withUploadLock($reservation->id, function () use ($reservation): void {
            try {
                app(CleanupUpload::class)->handle($reservation->id);
                $this->fail('A live conversion writer must keep its files.');
            } catch (UploadLockBusy) {
                Storage::disk('media')->assertExists($reservation->staging_path);
            }
        });
        $this->assertSame($reserved, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        app(CleanupUpload::class)->handle($reservation->id);
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_cancellation_during_encoding_is_fenced_and_never_publishes_partial_output(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $this->mock(AudioTranscoder::class)->shouldReceive('convert')->once()->andReturnUsing(function ($asset, $reservation, $heartbeat) use ($owner, $c): array {
            Storage::disk('media')->put($reservation->staging_path, 'partial');
            $fresh = $c->fresh();
            app(ConversionExecution::class)->control($owner, $fresh, $fresh->revision, $fresh->generation, false);
            $this->assertFalse($heartbeat(0.1, false));
            throw new ConversionFailure('attempt_revoked');
        });
        $this->runConversion($c);
        $this->assertSame('cancelled', $c->fresh()->status);
        $this->assertNull($c->fresh()->output_asset_id);
        $this->assertDatabaseCount('media_assets', 1);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_revocation_and_ownership_change_are_checked_again_during_conversion(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $execution = app(ConversionExecution::class);
        $execution->claim($c->id, $c->generation, $c->lease_token);
        User::whereKey($owner->id)->increment('access_version');
        $this->assertFalse($execution->heartbeat($c->id, $c->generation, $c->lease_token, 0.2, false));
        $this->assertSame('cancelled', $c->fresh()->status);
        $this->assertSame('access_revoked', $c->fresh()->error_code);
        $this->assertDatabaseCount('media_assets', 1);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_changed_source_identity_requires_review_and_old_job_cannot_become_ready(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $execution = app(ConversionExecution::class);
        $execution->claim($c->id, $c->generation, $c->lease_token);
        $source->increment('ownership_revision');
        $this->assertFalse($execution->heartbeat($c->id, $c->generation, $c->lease_token, 0.2, false));
        $this->assertSame('needs_review', $c->fresh()->status);
        $this->assertNull($c->fresh()->next_retry_at);
        $this->assertDatabaseCount('media_assets', 1);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_automatic_retry_is_bounded_to_three_generations(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $this->mock(AudioTranscoder::class)->shouldReceive('convert')->times(3)->andThrow(new ConversionFailure('timeout', true));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->runConversion($c->fresh());
            foreach (StorageReservation::where('status', 'cleanup_pending')->pluck('id') as $id) {
                app(CleanupUpload::class)->handle($id);
            }
            $this->travel(61)->seconds();
            app(ConversionExecution::class)->recover();
        }
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertSame(3, $c->fresh()->generation);
        $this->assertNull($c->fresh()->next_retry_at);
        $this->assertDatabaseCount('media_conversion_attempts', 3);
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        Queue::assertPushed(TranscodeMedia::class, 3);
    }

    public function test_corrupted_master_and_hard_output_limit_never_produce_a_ready_asset(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        Storage::disk('media')->put($source->storagePath(), 'corrupt');
        $this->runConversion($c);
        $this->assertSame('needs_review', $c->fresh()->status);
        $this->assertSame('source_changed', $c->fresh()->error_code);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertNull($c->fresh()->output_asset_id);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_real_encoder_is_stopped_by_the_reserved_file_size_limit(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        StorageReservation::where('purpose', 'conversion')->update(['size_bytes' => 512]);
        $this->runConversion($c);
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertNull($c->fresh()->output_asset_id);
        $this->assertDatabaseCount('media_assets', 1);
        foreach (StorageReservation::where('status', 'cleanup_pending')->pluck('id') as $id) {
            app(CleanupUpload::class)->handle($id);
        }
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_semantically_independent_sources_share_only_physical_mp3_bytes(): void
    {
        $owner = $this->library();
        [$first, $a] = $this->source($owner);
        [$second, $b] = $this->source($owner);
        $this->runConversion($a);
        $this->runConversion($b);
        $a->refresh()->load('output');
        $b->refresh()->load('output');
        $this->assertSame('ready', $a->status, $a->error_code ?? '');
        $this->assertSame('ready', $b->status, $b->error_code ?? '');
        $this->assertNotSame($a->output_asset_id, $b->output_asset_id);
        $this->assertSame($a->output->blob_id, $b->output->blob_id);
        $this->assertSame($first->id, $a->output->source_asset_id);
        $this->assertSame($second->id, $b->output->source_asset_id);
        foreach (StorageReservation::where('status', 'cleanup_pending')->pluck('id') as $id) {
            app(CleanupUpload::class)->handle($id);
        }
        $this->assertDatabaseCount('media_assets', 4);
        $this->assertDatabaseCount('media_blobs', 2);
        $this->assertSame(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        Queue::assertPushed(TranscodeMedia::class, 2);
    }

    public function test_a_second_encoder_is_deferred_while_the_global_process_lock_is_held(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $lock = fopen(Storage::disk('media')->path('.locks/transcode.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->runConversion($c);
            $this->assertSame('queued', $c->fresh()->status);
            $this->assertNull($c->fresh()->output_asset_id);
            $this->assertSame(0, $c->fresh()->automatic_retries);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->runConversion($c->fresh());
        $this->assertSame('ready', $c->fresh()->status);
        Queue::assertPushed(TranscodeMedia::class, 2);
    }

    public function test_zero_byte_staging_is_never_accepted_even_if_the_adapter_reports_success(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $this->mock(AudioTranscoder::class)->shouldReceive('convert')->once()->andReturnUsing(function ($asset, $reservation): array {
            Storage::disk('media')->put($reservation->staging_path, '');

            return ['sha256' => hash('sha256', ''), 'size_bytes' => 0, 'full_decode' => true, 'bitrate_bps' => 320000, 'codec' => 'mp3', 'validated_frames' => 1];
        });
        $this->runConversion($c);
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertSame('invalid_output', $c->fresh()->error_code);
        $this->assertDatabaseCount('media_assets', 1);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_promotion_disk_failure_keeps_the_master_and_allows_bounded_recovery(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $files = \Mockery::mock(app(MediaFiles::class))->makePartial();
        $files->shouldReceive('promote')->once()->andThrow(new \RuntimeException('Injected disk full'));
        $this->app->instance(MediaFiles::class, $files);
        $this->runConversion($c);
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertSame('storage_unavailable', $c->fresh()->error_code);
        $this->assertNotNull($c->fresh()->next_retry_at);
        $this->assertDatabaseCount('media_assets', 1);
        Storage::disk('media')->assertExists($source->storagePath());
        $this->assertGreaterThan(0, app(StorageBudget::class)->snapshot()['reserved_bytes']);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_queued_work_is_not_recovered_as_lost_while_an_encoder_has_a_recent_heartbeat(): void
    {
        $owner = $this->library();
        [$source, $a] = $this->source($owner);
        [$other, $b] = $this->source($owner);
        app(ConversionExecution::class)->claim($a->id, $a->generation, $a->lease_token);
        $this->travel(1201)->seconds();
        $a->update(['lease_expires_at' => now()->addSeconds(960), 'heartbeat_at' => now()]);
        app(ConversionExecution::class)->recover();
        $this->assertSame('queued', $b->fresh()->status);
        $this->assertSame(1, $b->fresh()->generation);
        $this->assertDatabaseCount('media_conversion_attempts', 2);
        Queue::assertPushed(TranscodeMedia::class, 2);
    }

    public function test_creating_a_draft_binds_the_same_master_without_invalidating_its_pending_conversion(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $track = app(RegisterTrack::class)->handle(['asset_id' => $source->id, 'title' => 'Waiting draft', 'artist' => 'Artist', 'album' => null]);
        $this->runConversion($c->fresh());
        $this->assertSame('ready', $c->fresh()->status);
        $this->assertDatabaseHas('track_media', ['track_id' => $track->id, 'media_asset_id' => $c->fresh()->output_asset_id, 'purpose' => 'rendition']);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_a_changed_encoder_binary_pin_cannot_publish_output(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        config(['music.ffmpeg_sha256' => str_repeat('0', 64)]);
        $this->runConversion($c);
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertSame('encoder_build_changed', $c->fresh()->error_code);
        $this->assertNull($c->fresh()->output_asset_id);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }
}
