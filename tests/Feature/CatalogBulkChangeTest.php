<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\CatalogChangeBatch;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogBulkChangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);

        return $owner;
    }

    public function test_bulk_metadata_preview_commit_and_rollback_preserve_likes_and_audit(): void
    {
        $owner = $this->owner();
        $track = Track::factory()->create(['title' => 'عنوان کهنه']);
        $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
        $playlist->tracks()->attach($track->id, ['position' => 1]);
        $owner->likedTracks()->attach($track->id);
        $preview = $this->postJson('/api/v1/admin/catalog-batches', [
            'kind' => 'track_metadata',
            'changes' => [['track_id' => $track->id, 'revision' => 0, 'title' => 'عنوان تازه']],
        ])->assertCreated()->json('data');
        $this->assertSame('previewed', $preview['status']);
        $this->assertSame('عنوان کهنه', $track->fresh()->title);
        $this->postJson('/api/v1/admin/catalog-batches/'.$preview['id'].'/commit', ['plan_hash' => $preview['plan_hash']])->assertOk()->assertJsonPath('data.status', 'committed');
        $this->assertSame('عنوان تازه', $track->fresh()->title);
        $this->assertSame(1, (int) $track->fresh()->catalog_revision);
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $owner->id, 'track_id' => $track->id]);
        $this->assertDatabaseHas('playlist_track', ['playlist_id' => $playlist->id, 'track_id' => $track->id]);
        $this->assertTrue(AuditEvent::where('action', 'catalog.batch_committed')->where('subject_id', $preview['id'])->exists());
        $this->postJson('/api/v1/admin/catalog-batches/'.$preview['id'].'/rollback')->assertOk()->assertJsonPath('data.status', 'rolled_back');
        $this->assertSame('عنوان کهنه', $track->fresh()->title);
        $this->assertSame(0, (int) $track->fresh()->catalog_revision);
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $owner->id, 'track_id' => $track->id]);
        $this->postJson('/api/v1/admin/catalog-batches/'.$preview['id'].'/commit', ['plan_hash' => $preview['plan_hash']])->assertConflict();
    }

    public function test_stale_revision_and_plan_hash_are_fenced_before_mutation(): void
    {
        $this->owner();
        $track = Track::factory()->create(['title' => 'ثابت']);
        $preview = $this->postJson('/api/v1/admin/catalog-batches', [
            'kind' => 'track_metadata',
            'changes' => [['track_id' => $track->id, 'revision' => 0, 'title' => 'تلاش']],
        ])->assertCreated()->json('data');
        $track->update(['title' => 'رقیب', 'catalog_revision' => 1]);
        $this->postJson('/api/v1/admin/catalog-batches/'.$preview['id'].'/commit', ['plan_hash' => $preview['plan_hash']])->assertConflict();
        $this->assertSame('رقیب', $track->fresh()->title);
        $fresh = $this->postJson('/api/v1/admin/catalog-batches', [
            'kind' => 'track_metadata',
            'changes' => [['track_id' => $track->id, 'revision' => 1, 'title' => 'نهایی']],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/catalog-batches/'.$fresh['id'].'/commit', ['plan_hash' => str_repeat('a', 64)])->assertConflict();
        $this->assertSame('previewed', CatalogChangeBatch::find($fresh['id'])->status);
    }

    public function test_duplicate_detection_separates_homonyms_from_recording_identity(): void
    {
        $this->owner();
        Storage::fake('media');
        $left = Track::factory()->create(['title' => 'قطعه مشترک']);
        $right = Track::factory()->create(['title' => 'قطعه مشترک']);
        $sharedBlob = $left->mediaAsset->blob_id;
        $right->mediaAsset->forceFill(['blob_id' => $sharedBlob, 'sha256' => $left->mediaAsset->sha256, 'path' => $left->mediaAsset->path])->save();
        Artist::create(['name' => 'نام مشترک', 'search_text' => 'نام مشترک']);
        Artist::create(['name' => 'نام مشترک', 'search_text' => 'نام مشترک']);
        $findings = $this->getJson('/api/v1/admin/catalog-duplicates')->assertOk()->json('data.findings');
        $kinds = collect($findings)->pluck('kind');
        $this->assertTrue($kinds->contains('shared_bytes'));
        $this->assertTrue($kinds->contains('homonym'));
        $homonym = collect($findings)->firstWhere('kind', 'homonym');
        $this->assertSame('identity.homonymous_artists_are_not_merged', $homonym['reason']);
        $this->assertCount(2, $homonym['artist_ids']);
    }

    public function test_merge_preserves_survivor_id_likes_and_can_roll_back(): void
    {
        $owner = $this->owner();
        $survivor = Track::factory()->create(['title' => 'باقی']);
        $source = Track::factory()->create(['title' => 'ادغامی']);
        $member = User::factory()->create();
        $member->likedTracks()->attach($source->id);
        $owner->likedTracks()->attach($survivor->id);
        $playlist = Playlist::factory()->create(['created_by' => $owner->id]);
        $playlist->tracks()->attach($source->id, ['position' => 1]);
        $preview = $this->postJson('/api/v1/admin/catalog-batches', [
            'kind' => 'merge_recordings',
            'survivor_track_id' => $survivor->id,
            'source_track_id' => $source->id,
            'survivor_revision' => 0,
            'source_revision' => 0,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/catalog-batches/'.$preview['id'].'/commit', ['plan_hash' => $preview['plan_hash']])->assertOk();
        $this->assertNotNull($source->fresh()->deleted_at);
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $member->id, 'track_id' => $survivor->id]);
        $this->assertDatabaseHas('playlist_track', ['playlist_id' => $playlist->id, 'track_id' => $survivor->id]);
        $this->assertDatabaseMissing('liked_tracks', ['track_id' => $source->id]);
        $this->postJson('/api/v1/admin/catalog-batches/'.$preview['id'].'/rollback')->assertOk();
        $this->assertNull($source->fresh()->deleted_at);
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $member->id, 'track_id' => $source->id]);
        $this->assertDatabaseHas('playlist_track', ['playlist_id' => $playlist->id, 'track_id' => $source->id]);
        $this->assertDatabaseMissing('liked_tracks', ['user_id' => $member->id, 'track_id' => $survivor->id]);
    }

    public function test_repair_clears_broken_primary_release_and_health_lists_missing_files(): void
    {
        $this->owner();
        Storage::fake('media');
        $track = Track::factory()->create();
        $releaseId = $track->primary_release_id;
        DB::table('release_track')->where('track_id', $track->id)->delete();
        $track->update(['primary_release_id' => $releaseId]);
        $issues = $this->getJson('/api/v1/admin/catalog-health')->assertOk()->json('data.issues');
        $this->assertTrue(collect($issues)->contains(fn ($issue) => $issue['code'] === 'broken_primary_release' && $issue['track_id'] === $track->id));
        $preview = $this->postJson('/api/v1/admin/catalog-batches', [
            'kind' => 'repair',
            'changes' => [['track_id' => $track->id, 'revision' => (int) $track->fresh()->catalog_revision, 'action' => 'clear_primary_release']],
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/admin/catalog-batches/'.$preview['id'].'/commit', ['plan_hash' => $preview['plan_hash']])->assertOk();
        $this->assertNull($track->fresh()->primary_release_id);
        $workflows = $this->getJson('/api/v1/admin/workflows')->assertOk()->json('data');
        $this->assertArrayHasKey('catalog', $workflows);
        $this->assertArrayHasKey('members', $workflows);
    }

    public function test_non_owner_cannot_preview_or_commit_bulk_changes(): void
    {
        $listener = User::factory()->create(['role' => UserRole::Listener]);
        $track = Track::factory()->create();
        $this->actingAs($listener)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->postJson('/api/v1/admin/catalog-batches', [
            'kind' => 'track_metadata',
            'changes' => [['track_id' => $track->id, 'revision' => 0, 'title' => 'ممنوع']],
        ])->assertForbidden();
    }
}
