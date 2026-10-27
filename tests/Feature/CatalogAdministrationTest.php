<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogAdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);

        return $owner;
    }

    public function test_homonymous_artists_remain_separate_identities(): void
    {
        $this->administrator();
        $a = $this->postJson('/api/v1/admin/artists', ['name' => 'نام مشترک'])->assertCreated()->json('data.id');
        $b = $this->postJson('/api/v1/admin/artists', ['name' => 'نام مشترک'])->assertCreated()->json('data.id');
        $this->assertNotSame($a, $b);
        $this->assertDatabaseCount('artists', 2);
    }

    public function test_shared_recording_metadata_edits_preserve_likes_memberships_and_media(): void
    {
        $owner = $this->administrator();
        $track = Track::factory()->create();
        $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
        $playlist->tracks()->attach($track->id, ['position' => 1]);
        $owner->likedTracks()->attach($track->id);
        $artist = Artist::create(['name' => 'هویت مشخص']);
        $payload = ['revision' => 0, 'title' => 'عنوان تازه', 'credits' => [['artist_id' => $artist->id, 'role' => 'primary']], 'primary_release_id' => $track->primary_release_id];
        $this->patchJson('/api/v1/admin/tracks/'.$track->id, $payload)->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.artist', 'هویت مشخص');
        $this->assertDatabaseHas('playlist_track', ['playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 1]);
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $owner->id, 'track_id' => $track->id]);
        $this->assertSame($track->media_asset_id, $track->fresh()->media_asset_id);
        $this->patchJson('/api/v1/admin/tracks/'.$track->id, $payload)->assertConflict();
        $this->assertSame('عنوان تازه', $track->fresh()->title);
    }

    public function test_release_track_slots_are_unique_and_recordings_can_appear_in_multiple_releases(): void
    {
        $this->administrator();
        $a = Artist::create(['name' => 'هنرمند']);
        $track = Track::factory()->create();
        $second = Track::factory()->create();
        $r = $this->postJson('/api/v1/admin/releases', ['title' => 'گردآوری', 'type' => 'compilation', 'artist_ids' => [$a->id]])->assertCreated()->json('data');
        $this->putJson('/api/v1/admin/releases/'.$r['id'].'/tracks', ['revision' => $r['revision'], 'tracks' => [['track_id' => $track->id, 'disc_number' => 1, 'track_number' => 1], ['track_id' => $second->id, 'disc_number' => 1, 'track_number' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('tracks');
        $this->putJson('/api/v1/admin/releases/'.$r['id'].'/tracks', ['revision' => $r['revision'], 'tracks' => [['track_id' => $track->id, 'disc_number' => 1, 'track_number' => 1]]])->assertOk()->assertJsonPath('data.revision', $r['revision'] + 1);
        $this->assertSame(2, $track->releases()->count());
        $this->getJson('/api/v1/admin/releases/'.$r['id'])->assertOk()->assertJsonPath('data.tracks.0.id', $track->id);
    }

    public function test_playlist_order_is_transactional_and_stale_changes_are_refused(): void
    {
        $this->administrator();
        $tracks = Track::factory()->count(2)->create();
        $playlist = $this->postJson('/api/v1/admin/playlists', ['name' => 'انتخاب شب', 'mood' => 'night'])->assertCreated()->json('data');
        $ids = [$tracks[1]->id, $tracks[0]->id];
        $this->putJson('/api/v1/admin/playlists/'.$playlist['id'].'/tracks', ['revision' => 0, 'track_ids' => $ids])->assertOk()->assertJsonPath('data.revision', 1);
        $this->putJson('/api/v1/admin/playlists/'.$playlist['id'].'/tracks', ['revision' => 0, 'track_ids' => array_reverse($ids)])->assertConflict();
        $this->assertSame($ids, DB::table('playlist_track')->where('playlist_id', $playlist['id'])->orderBy('position')->pluck('track_id')->all());
        $this->putJson('/api/v1/admin/playlists/'.$playlist['id'].'/tracks', ['revision' => 1, 'track_ids' => [$ids[0], $ids[0]]])->assertUnprocessable();
    }

    public function test_publishing_an_empty_playlist_is_refused_and_archiving_hides_its_tracks(): void
    {
        $this->administrator();
        $playlist = $this->postJson('/api/v1/admin/playlists', ['name' => 'مجموعه', 'mood' => 'other'])->assertCreated()->json('data');
        $this->patchJson('/api/v1/admin/playlists/'.$playlist['id'], ['revision' => 0, 'status' => 'published'])->assertUnprocessable();
        $track = Track::factory()->create();
        $this->putJson('/api/v1/admin/playlists/'.$playlist['id'].'/tracks', ['revision' => 0, 'track_ids' => [$track->id]])->assertOk();
        $this->patchJson('/api/v1/admin/playlists/'.$playlist['id'], ['revision' => 1, 'status' => 'published'])->assertOk();
        $this->patchJson('/api/v1/admin/playlists/'.$playlist['id'], ['revision' => 2, 'status' => 'archived'])->assertOk();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/playlists/'.$playlist['id'])->assertNotFound();
        $this->getJson('/api/v1/tracks/'.$track->id.'/stream')->assertNotFound();
        $this->assertDatabaseHas('playlist_track', ['playlist_id' => $playlist['id'], 'track_id' => $track->id]);
    }
}
