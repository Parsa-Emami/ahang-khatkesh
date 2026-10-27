<?php

namespace Tests\Feature;

use App\Jobs\PublishPlaylistImport;
use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\PlaylistImport;
use App\Models\User;
use App\Services\MediaProbe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlaylistImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function preview(User $admin, array $rows, string $key = 'test-idempotency-key-0001'): TestResponse
    {
        return $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true])->post('/api/v1/admin/imports', ['name' => 'ساعت آبی', 'mood' => 'night', 'manifest' => UploadedFile::fake()->createWithContent('playlist.json', json_encode(['tracks' => $rows], JSON_THROW_ON_ERROR))], ['Accept' => 'application/json', 'Idempotency-Key' => $key]);
    }

    public function test_preview_is_atomic_and_does_not_publish_content(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $asset = MediaAsset::factory()->create(['uploaded_by' => $admin->id]);
        $this->preview($admin, [['asset_id' => $asset->id, 'title' => 'یک آهنگ', 'artist' => 'یک هنرمند']])->assertCreated()->assertJsonPath('data.status', 'ready');
        $this->assertDatabaseCount('playlists', 0);
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('playlist_imports', 1);
    }

    public function test_invalid_row_and_unknown_fields_prevent_all_import_writes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $asset = MediaAsset::factory()->create(['uploaded_by' => $admin->id]);
        $this->preview($admin, [['asset_id' => $asset->id, 'title' => 'Valid', 'artist' => 'Artist'], ['asset_id' => '00000000-0000-4000-8000-000000000001', 'title' => 'Missing', 'artist' => 'Artist']])->assertUnprocessable()->assertJsonValidationErrors('tracks.1.asset_id');
        $this->preview($admin, [['asset_id' => $asset->id, 'title' => 'Injected', 'artist' => 'Artist', 'path' => '../../.env']])->assertUnprocessable();
        $this->assertDatabaseCount('playlist_imports', 0);
        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_duplicate_rows_are_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $asset = MediaAsset::factory()->create(['uploaded_by' => $admin->id]);
        $row = ['asset_id' => $asset->id, 'title' => 'Track', 'artist' => 'Artist'];
        $this->preview($admin, [$row, $row])->assertUnprocessable()->assertJsonValidationErrors('tracks.0.asset_id');
        $this->assertDatabaseCount('playlist_imports', 0);
    }

    public function test_idempotency_key_reuses_preview_and_rejects_different_payload(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $asset = MediaAsset::factory()->create(['uploaded_by' => $admin->id]);
        $rows = [['asset_id' => $asset->id, 'title' => 'Track', 'artist' => 'Artist']];
        $first = $this->preview($admin, $rows)->assertCreated();
        $this->preview($admin, $rows)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $rows[0]['title'] = 'Changed';
        $this->preview($admin, $rows)->assertConflict();
        $this->assertDatabaseCount('playlist_imports', 1);
    }

    public function test_confirmation_and_job_retries_do_not_duplicate_playlist(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        $assets = MediaAsset::factory()->count(2)->create(['uploaded_by' => $admin->id]);
        $rows = $assets->map(fn ($asset, $index) => ['asset_id' => $asset->id, 'title' => 'Track '.$index, 'artist' => 'Artist'])->all();
        $id = $this->preview($admin, $rows)->json('data.id');
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk()->assertJsonPath('data.status', 'processing');
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        Queue::assertPushed(PublishPlaylistImport::class, 1);
        $job = new PublishPlaylistImport($id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseCount('tracks', 2);
        $this->assertDatabaseCount('playlist_track', 2);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'completed']);
        $this->assertDatabaseHas('playlists', ['status' => 'published']);
    }

    public function test_failed_job_rolls_back_every_playlist_and_track_write(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $asset = MediaAsset::factory()->create(['uploaded_by' => $admin->id]);
        $id = $this->preview($admin, [['asset_id' => $asset->id, 'title' => 'Track', 'artist' => 'Artist']])->json('data.id');
        PlaylistImport::whereKey($id)->update(['status' => 'processing']);
        $asset->delete();
        try {
            app()->call([new PublishPlaylistImport($id), 'handle']);
            $this->fail('Expected missing media to fail import');
        } catch (QueryException) {
            $this->assertDatabaseCount('playlists', 0);
            $this->assertDatabaseCount('tracks', 0);
            $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'processing']);
        }
    }

    public function test_csv_supports_bom_and_quoted_artist_names(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $asset = MediaAsset::factory()->create(['uploaded_by' => $admin->id]);
        $csv = "\xEF\xBB\xBFasset_id,title,artist\n".$asset->id.',Track,"Artist, Duo"'."\n";
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true])->post('/api/v1/admin/imports', ['name' => 'CSV', 'mood' => 'focus', 'manifest' => UploadedFile::fake()->createWithContent('playlist.csv', $csv)], ['Accept' => 'application/json', 'Idempotency-Key' => 'test-csv-idempotency-0001'])->assertCreated()->assertJsonPath('data.rows.0.artist', 'Artist, Duo');
    }

    public function test_malformed_json_is_rejected_without_creating_preview(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true])->post('/api/v1/admin/imports', ['name' => 'Bad', 'mood' => 'night', 'manifest' => UploadedFile::fake()->createWithContent('playlist.json', '{broken')], ['Accept' => 'application/json', 'Idempotency-Key' => 'test-malformed-key-0001'])->assertUnprocessable()->assertJsonValidationErrors('manifest');
        $this->assertDatabaseCount('playlist_imports', 0);
    }

    public function test_audio_upload_is_deduplicated_and_never_exposes_disk_path(): void
    {
        Storage::fake('media');
        $admin = User::factory()->create(['is_admin' => true]);
        $samples = str_repeat(pack('v', 0), 1600);
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        $this->mock(MediaProbe::class)->shouldReceive('inspect')->once()->andReturn(['duration_seconds' => 0.1]);
        $first = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true])->post('/api/v1/admin/media', ['audio' => UploadedFile::fake()->createWithContent('sample.wav', $wave)], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.path');
        $this->post('/api/v1/admin/media', ['audio' => UploadedFile::fake()->createWithContent('again.wav', $wave)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_manifest_above_five_hundred_rows_is_rejected_before_writes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $rows = array_fill(0, 501, ['asset_id' => '00000000-0000-4000-8000-000000000001', 'title' => 'Track', 'artist' => 'Artist']);
        $this->preview($admin, $rows)->assertUnprocessable()->assertJsonValidationErrors('tracks');
        $this->assertDatabaseCount('playlist_imports', 0);
    }

    public function test_cover_is_private_and_requires_admin_to_replace(): void
    {
        Storage::fake('media');
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        $playlist = Playlist::factory()->create(['created_by' => $admin->id]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2VkAAAAASUVORK5CYII=');
        $this->actingAs($member)->post('/api/v1/admin/playlists/'.$playlist->id.'/cover', ['revision' => $playlist->fresh()->revision, 'cover' => UploadedFile::fake()->createWithContent('cover.png', $png)], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true])->post('/api/v1/admin/playlists/'.$playlist->id.'/cover', ['revision' => $playlist->fresh()->revision, 'cover' => UploadedFile::fake()->createWithContent('cover.png', $png)], ['Accept' => 'application/json'])->assertOk()->assertJsonMissingPath('data.cover_path');
        $this->actingAs($member)->get('/api/v1/playlists/'.$playlist->id.'/cover')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true])->patchJson('/api/v1/admin/playlists/'.$playlist->id, ['revision' => $playlist->fresh()->revision, 'status' => 'draft'])->assertOk();
        $this->actingAs($member)->getJson('/api/v1/playlists/'.$playlist->id.'/cover')->assertNotFound();
    }
}
