<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\PublishPlaylistImport;
use App\Models\Artist;
use App\Models\MediaAsset;
use App\Models\PlaylistImport;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(UserRole $role = UserRole::Owner): User
    {
        $owner = User::factory()->create(['role' => $role]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);

        return $owner;
    }

    public function test_controlled_password_reset_revokes_sessions_without_disabling_mfa(): void
    {
        $this->administrator();
        $member = User::factory()->create(['role' => UserRole::Curator]);
        $secret = $member->two_factor_secret;
        $this->putJson('/api/v1/admin/users/'.$member->id.'/password', ['password' => 'FreshMemberPassword123', 'password_confirmation' => 'FreshMemberPassword123'])->assertOk()->assertJsonPath('data.reset', true)->assertJsonMissingPath('data.password');
        $member->refresh();
        $this->assertTrue(Hash::check('FreshMemberPassword123', $member->password));
        $this->assertSame(1, $member->access_version);
        $this->assertSame($secret, $member->two_factor_secret);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.password_reset', 'subject_id' => (string) $member->id]);
    }

    public function test_administrator_cannot_reset_an_owners_password(): void
    {
        $this->administrator(UserRole::Administrator);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $hash = $owner->password;
        $this->putJson('/api/v1/admin/users/'.$owner->id.'/password', ['password' => 'FreshOwnerPassword123', 'password_confirmation' => 'FreshOwnerPassword123'])->assertForbidden();
        $this->assertSame($hash, $owner->fresh()->password);
    }

    public function test_pending_mfa_challenge_expires_after_ten_minutes(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'OwnerPassword123']);
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'OwnerPassword123'])->assertOk();
        $this->travel(10)->minutes();
        $this->postJson('/api/v1/login/challenge', ['recovery_code' => 'test-recovery-code-1'])->assertUnprocessable()->assertJsonPath('code', 'challenge_expired');
        $this->assertGuest();
    }

    public function test_import_can_reuse_explicit_artist_and_release_identities(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = $this->administrator();
        $artist = Artist::create(['name' => 'هنرمند مشخص']);
        $release = Release::create(['title' => 'انتشار مشخص', 'type' => 'album']);
        $assets = MediaAsset::factory()->count(2)->create(['uploaded_by' => $owner->id]);
        $rows = $assets->map(fn ($asset, $i) => ['asset_id' => $asset->id, 'title' => 'آهنگ '.$i, 'artist' => 'نام ورودی', 'artist_id' => $artist->id, 'release_id' => $release->id])->all();
        $id = $this->post('/api/v1/admin/imports', ['name' => 'انتخاب', 'mood' => 'calm', 'manifest' => UploadedFile::fake()->createWithContent('catalog.json', json_encode(['tracks' => $rows]))], ['Accept' => 'application/json', 'Idempotency-Key' => 'canonical-identities-test-1'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        app()->call([new PublishPlaylistImport($id, 1, PlaylistImport::findOrFail($id)->execution_token), 'handle']);
        $this->assertDatabaseCount('artists', 1);
        $this->assertDatabaseCount('releases', 1);
        $this->assertDatabaseHas('tracks', ['artist' => 'هنرمند مشخص', 'album' => 'انتشار مشخص', 'primary_release_id' => $release->id]);
        $this->assertSame(2, $release->tracks()->count());
        $this->assertDatabaseHas('release_track', ['release_id' => $release->id, 'disc_number' => 1, 'track_number' => 2]);
    }

    public function test_csv_optional_identity_columns_accept_empty_cells(): void
    {
        Queue::fake([PublishPlaylistImport::class]);
        $owner = $this->administrator();
        $asset = MediaAsset::factory()->create(['uploaded_by' => $owner->id]);
        $csv = "asset_id,title,artist,artist_id,release_id\n".$asset->id.",Track,Artist,,\n";
        $id = $this->post('/api/v1/admin/imports', ['name' => 'Optional identities', 'mood' => 'calm', 'manifest' => UploadedFile::fake()->createWithContent('catalog.csv', $csv)], ['Accept' => 'application/json', 'Idempotency-Key' => 'empty-csv-identities-test-1'])->assertCreated()->assertJsonMissingPath('data.rows.0.artist_id')->json('data.id');
        $this->postJson('/api/v1/admin/imports/'.$id.'/commit', ['publish' => true])->assertOk();
        app()->call([new PublishPlaylistImport($id, 1, PlaylistImport::findOrFail($id)->execution_token), 'handle']);
        $this->assertDatabaseHas('playlist_imports', ['id' => $id, 'status' => 'completed']);
        $this->assertDatabaseCount('tracks', 1);
    }
}
