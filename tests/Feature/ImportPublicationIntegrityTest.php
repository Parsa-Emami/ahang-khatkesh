<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\PublishPlaylistImport;
use App\Models\Artist;
use App\Models\MediaAsset;
use App\Models\PlaylistImport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportPublicationIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function preview(User $owner, array $rows): string
    {
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);

        return $this->post('/api/v1/admin/imports', ['name' => 'Integrity', 'mood' => 'calm', 'manifest' => UploadedFile::fake()->createWithContent('tracks.json', json_encode(['tracks' => $rows]))], ['Accept' => 'application/json', 'Idempotency-Key' => 'integrity-preview-key-0001'])->assertCreated()->assertJsonMissingPath('data.report.source_rows')->json('data.id');
    }

    public function test_media_claimed_as_a_rendition_after_preview_cannot_become_another_recording(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $existing = Track::factory()->create();
        $asset = MediaAsset::factory()->create();
        $id = $this->preview($owner, [['asset_id' => $asset->id, 'title' => 'New', 'artist' => 'Artist']]);
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        DB::table('track_media')->insert(['track_id' => $existing->id, 'media_asset_id' => $asset->id, 'purpose' => 'rendition', 'label' => 'mp3_320']);
        app()->call([new PublishPlaylistImport($id, 1, PlaylistImport::findOrFail($id)->execution_token), 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'needs_review', 'playlist_id' => null]);
        $this->assertDatabaseCount('tracks', 1);
        $this->assertDatabaseCount('playlists', 0);
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertConflict();
        Queue::assertPushed(PublishPlaylistImport::class, 1);
    }

    public function test_previews_show_canonical_metadata_and_catalog_edits_require_new_review(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $track = Track::factory()->create(['title' => 'Canonical', 'artist' => 'Canonical artist']);
        $id = $this->preview($owner, [['asset_id' => $track->media_asset_id, 'title' => 'Wrong input', 'artist' => 'Wrong artist']]);
        $this->getJson('/api/v1/admin/imports/'.$id)->assertOk()->assertJsonPath('data.rows.0.title', 'Canonical')->assertJsonPath('data.rows.0.artist', 'Canonical artist');
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        $track->update(['title' => 'Changed after preview', 'catalog_revision' => 1]);
        app()->call([new PublishPlaylistImport($id, 1, PlaylistImport::findOrFail($id)->execution_token), 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'needs_review']);
        $this->assertDatabaseCount('playlists', 0);
        Queue::assertPushed(PublishPlaylistImport::class, 1);
    }

    public function test_revoking_creator_authority_cancels_the_pending_import(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $asset = MediaAsset::factory()->create();
        $id = $this->preview($owner, [['asset_id' => $asset->id, 'title' => 'New', 'artist' => 'Artist']]);
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        $owner->update(['role' => UserRole::Listener, 'access_version' => 1]);
        app()->call([new PublishPlaylistImport($id, 1, PlaylistImport::findOrFail($id)->execution_token), 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDatabaseCount('playlists', 0);
        Queue::assertPushed(PublishPlaylistImport::class, 1);
    }

    public function test_explicit_artist_revision_changes_require_review_before_publication(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $artist = Artist::create(['name' => 'Selected artist']);
        $asset = MediaAsset::factory()->create();
        $id = $this->preview($owner, [['asset_id' => $asset->id, 'title' => 'New', 'artist' => 'Input artist', 'artist_id' => $artist->id]]);
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        $artist->update(['name' => 'Renamed', 'revision' => 1]);
        app()->call([new PublishPlaylistImport($id, 1, PlaylistImport::findOrFail($id)->execution_token), 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'needs_review']);
        $this->assertDatabaseCount('playlists', 0);
        Queue::assertPushed(PublishPlaylistImport::class, 1);
    }

    public function test_late_job_execution_and_failure_cannot_change_a_newer_attempt(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $asset = MediaAsset::factory()->create();
        $id = $this->preview($owner, [['asset_id' => $asset->id, 'title' => 'New', 'artist' => 'Artist']]);
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        $old = new PublishPlaylistImport($id, 1, PlaylistImport::findOrFail($id)->execution_token);
        $old->failed(new \RuntimeException('Attempt one failed'));
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        $old->failed(new \RuntimeException('Late callback'));
        app()->call([$old, 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'processing', 'generation' => 2]);
        $this->assertDatabaseCount('playlists', 0);
        app()->call([new PublishPlaylistImport($id, 2, PlaylistImport::findOrFail($id)->execution_token), 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'completed', 'generation' => 2]);
        $this->assertDatabaseCount('playlists', 1);
        Queue::assertPushed(PublishPlaylistImport::class, 2);
    }
}
