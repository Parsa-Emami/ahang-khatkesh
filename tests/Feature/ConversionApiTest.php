<?php

namespace Tests\Feature;

use App\Actions\Media\CleanupUpload;
use App\Actions\Media\IngestMedia;
use App\Actions\Media\ReserveStorage;
use App\Enums\UserRole;
use App\Jobs\TranscodeMedia;
use App\Models\User;
use App\Services\ConversionProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CreatesConversions;
use Tests\Support\FlacFixture;
use Tests\TestCase;

class ConversionApiTest extends TestCase
{
    use CreatesConversions, LazilyRefreshDatabase;

    public function test_conversion_api_is_idempotent_allowlisted_and_rejects_stale_control(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->putJson('/api/v1/admin/media/'.$source->id.'/conversions/'.ConversionProfile::ID, ['source_revision' => 1, 'profile_version' => 1, 'output_path' => 'public/escape.mp3', 'codec' => 'aac'])->assertOk()->assertJsonPath('data.id', $c->id)->assertJsonMissingPath('data.lease_token')->assertJsonMissingPath('data.source.path');
        $conversionReservation = $c->attempts()->first()->reservation_id;
        $this->deleteJson('/api/v1/admin/storage-reservations/'.$conversionReservation)->assertConflict();
        $response = $this->getJson('/api/v1/admin/media-conversions/'.$c->id)->assertOk();
        $spec = json_decode(file_get_contents(base_path('docs/openapi.json')));
        $schema = (object) ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'components' => $spec->components, '$ref' => '#/components/schemas/Conversion'];
        $this->assertTrue((new CompliantValidator)->validate(json_decode(json_encode($response->json('data'))), $schema)->isValid());
        $this->postJson('/api/v1/admin/media-conversions/'.$c->id.'/cancel', ['revision' => 999, 'expected_generation' => 1])->assertConflict();
        $this->postJson('/api/v1/admin/media-conversions/'.$c->id.'/cancel', ['revision' => $c->revision, 'expected_generation' => 1])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $fresh = $c->fresh();
        $this->postJson('/api/v1/admin/media-conversions/'.$c->id.'/retry', ['revision' => $fresh->revision, 'expected_generation' => 1])->assertOk()->assertJsonPath('data.generation', 2);
        $this->getJson('/api/v1/admin/media-conversions?status=queued')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/media-conversions?status=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/media-policy')->assertOk()->assertJsonPath('data.retain_original', true);
        $this->assertDatabaseCount('media_conversion_attempts', 2);
        Queue::assertPushed(TranscodeMedia::class, 2);
    }

    #[TestWith(['administrator'])]
    #[TestWith(['curator'])]
    #[TestWith(['listener'])]
    public function test_non_superadmin_roles_cannot_read_or_mutate_conversions(string $role): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $user = User::factory()->create(['role' => UserRole::from($role)]);
        $this->actingAs($user)->withSession(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.password_confirmed_at' => now()->timestamp]);
        $this->getJson('/api/v1/admin/media-conversions')->assertForbidden();
        $this->getJson('/api/v1/admin/media-conversions/'.$c->id)->assertForbidden();
        $this->getJson('/api/v1/admin/media-policy')->assertForbidden();
        $this->putJson('/api/v1/admin/media/'.$source->id.'/conversions/'.ConversionProfile::ID, ['source_revision' => 1, 'profile_version' => 1])->assertForbidden();
        $this->postJson('/api/v1/admin/media-conversions/'.$c->id.'/cancel', ['revision' => $c->revision, 'expected_generation' => 1])->assertForbidden();
        $this->postJson('/api/v1/admin/media-conversions/'.$c->id.'/retry', ['revision' => $c->revision, 'expected_generation' => 1])->assertForbidden();
        $this->assertSame('queued', $c->fresh()->status);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_anonymous_and_expired_step_up_requests_do_not_control_media(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $this->getJson('/api/v1/admin/media-conversions')->assertUnauthorized();
        $this->actingAs($owner)->withSession(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp]);
        $this->postJson('/api/v1/admin/media-conversions/'.$c->id.'/cancel', ['revision' => $c->revision, 'expected_generation' => 1])->assertStatus(423);
        $this->assertSame('queued', $c->fresh()->status);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_missing_conversion_quota_rolls_back_upload_admission_and_keeps_cleanup_charged(): void
    {
        $owner = $this->library();
        $file = FlacFixture::file();
        config(['music.storage_quota_bytes' => $file->getSize() + 512]);
        $reservation = app(ReserveStorage::class)->handle($owner, $file->getSize(), 'audio');
        $this->actingAs($owner)->withSession(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.password_confirmed_at' => now()->timestamp]);
        $this->post('/api/v1/admin/media', ['reservation_id' => $reservation->id, 'audio' => $file], ['Accept' => 'application/json'])->assertStatus(507);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_conversions', 0);
        $this->assertSame('cleanup_pending', $reservation->fresh()->status);
        app(CleanupUpload::class)->handle($reservation->id);
        $this->assertCount(0, Storage::disk('media')->allFiles('blobs'));
        Queue::assertNotPushed(TranscodeMedia::class);
    }

    public function test_multichannel_flac_and_corrupt_audio_are_rejected_before_queueing(): void
    {
        $owner = $this->library();
        $this->actingAs($owner)->withSession(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.password_confirmed_at' => now()->timestamp]);
        foreach ([FlacFixture::file(6), UploadedFile::fake()->createWithContent('bad.flac', 'not flac')] as $file) {
            $reservation = app(ReserveStorage::class)->handle($owner, $file->getSize(), 'audio');
            $this->post('/api/v1/admin/media', ['reservation_id' => $reservation->id, 'audio' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('media_conversions', 0);
        $this->assertDatabaseCount('media_assets', 0);
        Queue::assertNotPushed(TranscodeMedia::class);
    }

    public function test_truncated_flac_never_publishes_an_mp3_even_when_header_has_duration(): void
    {
        $owner = $this->library();
        $bytes = FlacFixture::bytes();
        [$source, $c] = $this->source($owner, UploadedFile::fake()->createWithContent('truncated.flac', substr($bytes, 0, -500)));
        $this->runConversion($c);
        $this->assertSame('failed', $c->fresh()->status);
        $this->assertNull($c->fresh()->output_asset_id);
        $this->assertDatabaseCount('media_assets', 1);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_generated_derivative_cannot_be_imported_as_another_recording(): void
    {
        $owner = $this->library();
        [$source, $c] = $this->source($owner);
        $this->runConversion($c);
        $c->refresh();
        $this->actingAs($owner)->withSession(['auth.mfa_authenticated' => true, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.password_confirmed_at' => now()->timestamp]);
        $manifest = UploadedFile::fake()->createWithContent('wrong.json', json_encode(['tracks' => [['asset_id' => $c->output_asset_id, 'title' => 'Wrong source', 'artist' => 'Artist']]]));
        $this->post('/api/v1/admin/imports', ['name' => 'Wrong', 'mood' => 'calm', 'manifest' => $manifest], ['Accept' => 'application/json', 'Idempotency-Key' => 'derivative-is-not-master-001'])->assertUnprocessable();
        $this->getJson('/api/v1/admin/media')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.path')->assertJsonMissingPath('data.0.blob_id');
        $this->assertDatabaseCount('tracks', 0);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_legacy_flac_preparation_is_read_only_until_applied_by_a_superadmin(): void
    {
        $owner = $this->library();
        config(['music.conversions_enabled' => false]);
        $file = FlacFixture::file();
        $r = app(ReserveStorage::class)->handle($owner, $file->getSize(), 'audio');
        $source = app(IngestMedia::class)->handle($owner, $r->id, $file)['asset'];
        $source->update(['codec' => null, 'sample_rate' => null, 'channels' => null]);
        config(['music.conversions_enabled' => true]);
        $this->artisan('media:prepare-flac')->assertSuccessful();
        $this->assertDatabaseCount('media_conversions', 0);
        $this->artisan('media:prepare-flac', ['--apply' => true, '--owner' => $owner->id])->assertSuccessful();
        $this->assertDatabaseCount('media_conversions', 1);
        $this->assertSame('flac', $source->fresh()->codec);
        $this->assertDatabaseCount('media_assets', 1);
        Storage::disk('media')->assertExists($source->storagePath());
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_reuploading_legacy_bytes_reuses_the_source_and_backfills_probe_facts_before_conversion(): void
    {
        $owner = $this->library();
        $bytes = FlacFixture::bytes();
        config(['music.conversions_enabled' => false]);
        $file = UploadedFile::fake()->createWithContent('legacy.flac', $bytes);
        $r = app(ReserveStorage::class)->handle($owner, $file->getSize(), 'audio');
        $source = app(IngestMedia::class)->handle($owner, $r->id, $file)['asset'];
        $source->update(['codec' => null, 'sample_rate' => null, 'channels' => null]);
        config(['music.conversions_enabled' => true]);
        $r = app(ReserveStorage::class)->handle($owner, strlen($bytes), 'audio');
        $result = app(IngestMedia::class)->handle($owner, $r->id, UploadedFile::fake()->createWithContent('again.flac', $bytes));
        $this->assertSame($source->id, $result['asset']->id);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('media_conversions', 1);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }
}
