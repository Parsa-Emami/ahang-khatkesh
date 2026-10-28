<?php

namespace Tests\Feature;

use App\Actions\Media\IngestMedia;
use App\Actions\Media\ReserveStorage;
use App\Actions\PreviewPlaylistImport;
use App\Actions\RegisterTrack;
use App\Enums\UserRole;
use App\Jobs\TranscodeMedia;
use App\Models\MediaConversion;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ConversionProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\FlacFixture;
use Tests\TestCase;

class AudioConversionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function upload(int $channels = 2, int $rate = 44100): array
    {
        config(['music.storage_headroom_bytes' => 0, 'music.conversions_enabled' => true]);
        Storage::fake('media');
        Queue::fake([TranscodeMedia::class]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $file = FlacFixture::file($channels, $rate);
        $reservation = app(ReserveStorage::class)->handle($owner, $file->getSize(), 'audio');
        $source = app(IngestMedia::class)->handle($owner, $reservation->id, $file)['asset'];
        $conversion = MediaConversion::where('source_asset_id', $source->id)->firstOrFail();

        return [$owner, $source, $conversion];
    }

    #[TestWith([1, 48000, 48000])]
    #[TestWith([2, 44100, 44100])]
    #[TestWith([2, 96000, 44100])]
    public function test_actual_flac_is_converted_to_fully_decoded_cbr_320_mp3_with_original_retained(int $channels, int $inputRate, int $expectedRate): void
    {
        [$owner, $source, $c] = $this->upload($channels, $inputRate);
        $before = Storage::disk('media')->get($source->storagePath());
        app()->call([new TranscodeMedia($c->id, $c->generation, $c->lease_token), 'handle']);
        $c->refresh()->load('output');
        $this->assertSame('ready', $c->status, json_encode($c->toArray()));
        $this->assertSame('mp3', $c->output->codec);
        $this->assertSame($expectedRate, $c->output->sample_rate);
        $this->assertSame($channels, $c->output->channels);
        $this->assertSame(320000, $c->report['bitrate_bps']);
        $this->assertTrue($c->report['full_decode']);
        $this->assertTrue($c->report['full_source_decode']);
        $this->assertSame($inputRate, $c->report['validated_source_samples']);
        $this->assertGreaterThan(0, $c->report['validated_frames']);
        $this->assertSame($source->id, $c->output->source_asset_id);
        $this->assertNotSame($source->id, $c->output->id);
        $this->assertSame($before, Storage::disk('media')->get($source->storagePath()));
        $this->assertDatabaseCount('media_assets', 2);
        $this->assertDatabaseCount('media_conversion_attempts', 1);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }

    public function test_publication_waits_for_mp3_and_quality_streams_keep_authorization_and_byte_ranges(): void
    {
        [$owner, $source, $c] = $this->upload();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $manifest = UploadedFile::fake()->createWithContent('playlist.json', json_encode(['tracks' => [['asset_id' => $source->id, 'title' => 'FLAC song', 'artist' => 'Synthetic artist']]]));
        $preview = app(PreviewPlaylistImport::class)->handle($owner, $manifest, ['name' => 'Quality', 'mood' => 'calm', 'idempotency_key' => 'conversion-preview-001']);
        $this->postJson('/api/v1/admin/imports/'.$preview->id.'/commit', ['publish' => true])->assertConflict()->assertJsonPath('code', 'media_not_ready');
        app()->call([new TranscodeMedia($c->id, $c->generation, $c->lease_token), 'handle']);
        $c->refresh();
        $this->assertSame('ready', $c->status, $c->error_code ?? '');
        $track = app(RegisterTrack::class)->handle(['asset_id' => $source->id, 'title' => 'Quality song', 'artist' => 'Artist', 'album' => null]);
        $this->assertDatabaseHas('track_media', ['track_id' => $track->id, 'media_asset_id' => $c->output_asset_id, 'label' => ConversionProfile::ID, 'purpose' => 'rendition']);
        $playlist = Playlist::factory()->create(['status' => 'published']);
        $playlist->tracks()->attach($track->id, ['position' => 1]);
        $listener = User::factory()->create(['role' => UserRole::Listener]);
        $this->actingAs($listener);
        $this->getJson('/api/v1/tracks')->assertOk()->assertJsonPath('data.0.audio_qualities.0.id', 'mp3-320');
        $response = $this->withHeader('Range', 'bytes=0-9')->get('/api/v1/tracks/'.$track->id.'/stream');
        $response->assertStatus(206)->assertHeader('Content-Type', 'audio/mpeg')->assertHeader('Content-Length', '10');
        $etag = $response->headers->get('ETag');
        $original = $this->withHeader('Range', 'bytes=0-9')->get('/api/v1/tracks/'.$track->id.'/stream?quality=original')->assertStatus(206);
        $this->assertNotSame($etag, $original->headers->get('ETag'));
        $this->withHeaders(['Range' => '', 'If-None-Match' => $etag])->get('/api/v1/tracks/'.$track->id.'/stream')->assertStatus(304);
        $this->withHeaders(['Range' => '', 'If-None-Match' => ''])->head('/api/v1/tracks/'.$track->id.'/stream')->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->withHeader('Range', 'bytes=999999999-')->get('/api/v1/tracks/'.$track->id.'/stream?quality=mp3-320')->assertStatus(416);
        $this->withHeader('Range', 'bytes=-999999999')->get('/api/v1/tracks/'.$track->id.'/stream?quality=mp3-320')->assertStatus(206);
        $this->withHeaders(['Range' => 'bytes=999999999-', 'If-Range' => '"stale-version"'])->get('/api/v1/tracks/'.$track->id.'/stream?quality=mp3-320')->assertOk();
        $this->withHeaders(['Range' => 'bytes=0-9', 'If-Range' => $etag])->get('/api/v1/tracks/'.$track->id.'/stream?quality=mp3-320')->assertStatus(206);
        $playlist->update(['status' => 'draft']);
        $this->get('/api/v1/tracks/'.$track->id.'/stream?quality=mp3-320')->assertNotFound();
        $this->assertSame('flac', $source->fresh()->codec);
        Queue::assertPushed(TranscodeMedia::class, 1);
    }
}
