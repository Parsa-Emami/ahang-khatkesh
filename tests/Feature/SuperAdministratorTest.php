<?php

namespace Tests\Feature;

use App\Actions\SaveArtist;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SuperAdministratorTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function nonOwners(): array
    {
        return [['administrator'], ['curator'], ['listener']];
    }

    #[DataProvider('nonOwners')]
    public function test_non_owners_cannot_mutate_any_catalog_entry_point(string $role): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => UserRole::from($role)]);
        $track = Track::factory()->create();
        $playlist = Playlist::factory()->create();
        $artist = Artist::firstOrFail();
        $release = $track->primary_release_id;
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $paths = [['POST', 'artists'], ['PATCH', 'artists/'.$artist->id], ['POST', 'releases'], ['PATCH', 'releases/'.$release], ['PUT', 'releases/'.$release.'/tracks'], ['PATCH', 'tracks/'.$track->id], ['POST', 'tracks/'.$track->id.'/renditions'], ['POST', 'playlists'], ['PATCH', 'playlists/'.$playlist->id], ['PUT', 'playlists/'.$playlist->id.'/tracks'], ['POST', 'playlists/'.$playlist->id.'/cover'], ['POST', 'media'], ['POST', 'imports'], ['DELETE', 'tracks/'.$track->id], ['POST', 'tracks/'.$track->id.'/restore'], ['DELETE', 'playlists/'.$playlist->id], ['POST', 'playlists/'.$playlist->id.'/restore']];
        foreach ($paths as [$method, $path]) {
            $this->json($method, '/api/v1/admin/'.$path, [])->assertForbidden();
        }
        $this->assertDatabaseCount('tracks', 1);
        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseCount('playlist_imports', 0);
        $this->assertDatabaseCount('audit_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_catalog_capabilities_belong_only_to_active_super_administrators(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->make(['role' => $role, 'is_active' => true]);
            foreach ([Permission::CatalogManage, Permission::MediaManage, Permission::ImportsManage] as $permission) {
                $this->assertSame($role === UserRole::Owner, Gate::forUser($user)->allows($permission->value));
            }
            $user->is_active = false;
            $this->assertFalse(Gate::forUser($user)->allows('catalog.manage'));
        }
    }

    public function test_trash_and_restore_preserve_media_likes_and_order_and_refuse_stale_revisions(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $track = Track::factory()->create();
        $playlist = Playlist::factory()->create();
        $playlist->tracks()->attach($track->id, ['position' => 1]);
        $owner->likedTracks()->attach($track->id);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->deleteJson('/api/v1/admin/tracks/'.$track->id, ['revision' => 0])->assertOk()->assertJsonPath('data.revision', 1);
        $this->deleteJson('/api/v1/admin/tracks/'.$track->id, ['revision' => 0])->assertOk();
        $this->getJson('/api/v1/tracks/'.$track->id.'/stream')->assertNotFound();
        $this->getJson('/api/v1/admin/trash?type=track')->assertOk()->assertJsonPath('data.0.id', $track->id)->assertJsonMissingPath('data.0.media_asset_id');
        $this->postJson('/api/v1/admin/tracks/'.$track->id.'/restore', ['revision' => 0])->assertConflict();
        $this->postJson('/api/v1/admin/tracks/'.$track->id.'/restore', ['revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame($track->media_asset_id, $track->fresh()->media_asset_id);
        $this->assertDatabaseHas('liked_tracks', ['track_id' => $track->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('playlist_track', ['playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 1]);
        $this->deleteJson('/api/v1/admin/playlists/'.$playlist->id, ['revision' => 0])->assertOk()->assertJsonPath('data.revision', 1);
        $this->getJson('/api/v1/playlists/'.$playlist->id)->assertNotFound();
        $this->postJson('/api/v1/admin/playlists/'.$playlist->id.'/restore', ['revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->getJson('/api/v1/playlists/'.$playlist->id)->assertOk()->assertJsonPath('data.tracks.0.id', $track->id);
        $this->assertDatabaseHas('audit_events', ['action' => 'track.trashed', 'subject_id' => (string) $track->id]);
    }

    public function test_restoring_a_track_refreshes_metadata_changed_while_it_was_trashed(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $track = Track::factory()->create(['artist' => 'Old name']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->deleteJson('/api/v1/admin/tracks/'.$track->id, ['revision' => 0])->assertOk();
        $artist = $track->artists()->firstOrFail();
        app(SaveArtist::class)->handle($owner, ['name' => 'Renamed while trashed', 'revision' => $artist->revision], $artist);
        $this->postJson('/api/v1/admin/tracks/'.$track->id.'/restore', ['revision' => 1])->assertOk();
        $this->assertSame('Renamed while trashed', $track->fresh()->artist);
        $this->assertDatabaseHas('tracks', ['id' => $track->id, 'catalog_revision' => 2, 'deleted_at' => null]);
    }

    public function test_an_action_refuses_a_previously_authorized_but_demoted_actor(): void
    {
        $snapshot = User::factory()->create(['role' => UserRole::Owner]);
        DB::table('users')->where('id', $snapshot->id)->update(['role' => 'listener']);
        try {
            app(SaveArtist::class)->handle($snapshot, ['name' => 'Must not exist']);
            $this->fail('A stale owner snapshot wrote catalog data.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('artists', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
