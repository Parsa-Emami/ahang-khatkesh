<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use App\Policies\PlaylistPolicy;
use App\Policies\TrackPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MusicLibraryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function catalog(User $owner, bool $published = true, array $attributes = []): array
    {
        $asset = MediaAsset::factory()->create(['uploaded_by' => $owner->id]);
        $track = Track::factory()->create(['media_asset_id' => $asset->id] + $attributes);
        $playlist = Playlist::factory()->create(['created_by' => $owner->id, 'status' => $published ? 'published' : 'draft']);
        $playlist->tracks()->attach($track->id, ['position' => 1]);

        return [$playlist, $track, $asset];
    }

    public function test_unpublished_playlist_and_audio_are_hidden_from_member(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        [$playlist, $track] = $this->catalog($admin, false);
        $this->actingAs($member)->getJson('/api/v1/playlists')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/playlists/'.$playlist->id)->assertNotFound();
        $this->getJson('/api/v1/tracks/'.$track->id.'/stream')->assertNotFound();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true])->getJson('/api/v1/playlists/'.$playlist->id)->assertOk()->assertJsonPath('data.tracks.0.id', $track->id);
    }

    public function test_policies_allow_active_members_only_for_published_content(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        $disabled = User::factory()->create(['is_active' => false]);
        [$playlist, $track] = $this->catalog($admin, false);
        $playlistPolicy = new PlaylistPolicy;
        $trackPolicy = new TrackPolicy;
        $this->assertTrue($playlistPolicy->view($admin, $playlist)->allowed());
        $this->assertTrue($trackPolicy->view($admin, $track)->allowed());
        $this->assertFalse($playlistPolicy->view($member, $playlist)->allowed());
        $this->assertFalse($trackPolicy->view($member, $track)->allowed());
        $playlist->update(['status' => 'published']);
        $this->assertTrue($playlistPolicy->view($member, $playlist)->allowed());
        $this->assertTrue($trackPolicy->view($member, $track)->allowed());
        $this->assertFalse($playlistPolicy->view($disabled, $playlist)->allowed());
        $this->assertFalse($trackPolicy->view($disabled, $track)->allowed());
    }

    public function test_private_stream_supports_ranges_and_rejects_invalid_ranges(): void
    {
        Storage::fake('media');
        $member = User::factory()->create();
        [$playlist, $track, $asset] = $this->catalog($member);
        Storage::disk('media')->put($asset->path, str_repeat('0123456789', 10));
        $this->actingAs($member)->get('/api/v1/tracks/'.$track->id.'/stream', ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-9/100')->assertHeader('Content-Length', '10')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/v1/tracks/'.$track->id.'/stream', ['Range' => 'bytes=1000-2000'])->assertStatus(416);
    }

    public function test_stream_does_not_follow_path_outside_private_media_root(): void
    {
        Storage::fake('media');
        $member = User::factory()->create();
        [$playlist, $track, $asset] = $this->catalog($member);
        $asset->update(['path' => '../../../../.env']);
        $this->actingAs($member)->getJson('/api/v1/tracks/'.$track->id.'/stream')->assertNotFound();
    }

    public function test_search_normalizes_persian_arabic_letters_and_treats_wildcards_literally(): void
    {
        $member = User::factory()->create();
        [$playlist, $track] = $this->catalog($member, true, ['title' => 'كيانَ نوری']);
        $this->actingAs($member)->getJson('/api/v1/tracks?q='.urlencode('کیان نوری'))->assertOk()->assertJsonPath('data.0.id', $track->id);
        $this->getJson('/api/v1/tracks?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_likes_and_saves_are_idempotent_and_personal(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        [$playlist, $track] = $this->catalog($member);
        $this->actingAs($member)->putJson('/api/v1/tracks/'.$track->id.'/like')->assertOk();
        $this->putJson('/api/v1/tracks/'.$track->id.'/like')->assertOk();
        $this->putJson('/api/v1/playlists/'.$playlist->id.'/save')->assertOk();
        $this->putJson('/api/v1/playlists/'.$playlist->id.'/save')->assertOk();
        $this->assertDatabaseCount('liked_tracks', 1);
        $this->assertDatabaseCount('saved_playlists', 1);
        $this->actingAs($other)->getJson('/api/v1/tracks?liked=1')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/playlists?saved=1')->assertJsonCount(0, 'data');
    }

    public function test_playback_is_restored_without_allowing_stale_revisions_to_overwrite(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        [$playlist, $track] = $this->catalog($member);
        $payload = ['track_id' => $track->id, 'queue' => [$track->id], 'position_seconds' => 42, 'volume' => 0.4, 'shuffle' => false, 'repeat_mode' => 'all', 'revision' => 0];
        $this->actingAs($member)->putJson('/api/v1/playback', $payload)->assertOk()->assertJsonPath('data.revision', 1);
        $this->putJson('/api/v1/playback', array_replace($payload, ['position_seconds' => 3]))->assertConflict();
        $this->getJson('/api/v1/playback')->assertOk()->assertJsonPath('data.position_seconds', 42)->assertJsonPath('data.queue.0.id', $track->id);
        $this->assertDatabaseHas('playback_states', ['user_id' => $member->id, 'position_seconds' => 42, 'revision' => 1]);
        $this->actingAs($other)->getJson('/api/v1/playback')->assertJsonCount(0, 'data.queue');
    }

    public function test_playback_rejects_draft_tracks_and_current_track_outside_queue(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        [$playlist, $track] = $this->catalog($admin, false);
        $this->actingAs($member)->putJson('/api/v1/playback', ['track_id' => $track->id, 'queue' => [$track->id], 'position_seconds' => 0, 'volume' => 0.4, 'shuffle' => false, 'repeat_mode' => 'off', 'revision' => 0])->assertUnprocessable()->assertJsonValidationErrors('queue');
        $this->assertDatabaseCount('playback_states', 0);
    }
}
