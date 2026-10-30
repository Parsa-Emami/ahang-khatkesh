<?php

namespace Tests\Feature;

use App\Actions\UpdatePlaylist;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class MemberProposalTest extends TestCase
{
    use CatalogFixtures, LazilyRefreshDatabase;

    private function playlistRequest(Track $track): array
    {
        return ['kind' => 'playlist', 'idempotency_key' => (string) Str::uuid(), 'payload' => ['name' => 'Requested collection', 'description' => 'A member request', 'mood' => 'focus', 'track_ids' => [$track->id]]];
    }

    public function test_member_submission_is_idempotent_isolated_and_never_writes_catalog(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $this->catalogPlaylist($owner, [$track]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($member);
        $input = $this->playlistRequest($track);
        $first = $this->postJson('/api/v1/member-proposals', $input)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.snapshot')->assertJsonMissingPath('data.payload_hash')->assertJsonMissingPath('data.actor_version')->json('data.id');
        $this->postJson('/api/v1/member-proposals', $input)->assertOk()->assertJsonPath('data.id', $first);
        $input['payload']['name'] = 'Different request';
        $this->postJson('/api/v1/member-proposals', $input)->assertConflict();
        $this->assertDatabaseCount('member_proposals', 1);
        $this->assertDatabaseCount('tracks', 1);
        $this->assertDatabaseCount('playlists', 1);
        $this->actingAs($other)->getJson('/api/v1/member-proposals')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/member-proposals/'.$first)->assertNotFound();
        $this->getJson('/api/v1/admin/proposals')->assertForbidden();
        $this->postJson('/api/v1/admin/catalog-changes/preview', ['operation' => 'proposal', 'proposal_id' => $first, 'revision' => 0])->assertForbidden();
    }

    public function test_acceptance_requires_owner_preview_and_defaults_to_a_draft_hidden_from_the_member(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $this->catalogPlaylist($owner, [$track]);
        $member = User::factory()->create();
        $id = $this->actingAs($member)->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertCreated()->json('data.id');
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'proposal', 'proposal_id' => $id, 'revision' => 0]);
        $this->assertDatabaseCount('playlists', 1);
        $created = $this->commitCatalog($preview)->assertOk()->json('data.result.created_playlist_id');
        $this->commitCatalog($preview)->assertOk();
        $this->assertSame('draft', Playlist::findOrFail($created)->status);
        $this->assertSame($owner->id, Playlist::findOrFail($created)->created_by);
        $this->assertDatabaseCount('playlists', 2);
        $this->actingAs($member)->getJson('/api/v1/member-proposals/'.$id)->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.result_playlist_id', null);
        app(UpdatePlaylist::class)->handle(Playlist::findOrFail($created), $owner, ['revision' => 1, 'status' => 'published']);
        $this->getJson('/api/v1/member-proposals/'.$id)->assertOk()->assertJsonPath('data.result_playlist_id', $created);
    }

    public function test_explicit_publication_and_rollback_preserve_catalog_ids_and_review_outcome(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $this->catalogPlaylist($owner, [$track]);
        $member = User::factory()->create();
        $id = $this->actingAs($member)->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertCreated()->json('data.id');
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'proposal', 'proposal_id' => $id, 'revision' => 0, 'publish' => true]);
        $created = $this->commitCatalog($preview)->assertOk()->json('data.result.created_playlist_id');
        $this->assertSame('published', Playlist::findOrFail($created)->status);
        $this->rollbackCatalog($preview)->assertOk();
        $this->assertTrue(Playlist::withTrashed()->findOrFail($created)->trashed());
        $this->assertDatabaseCount('tracks', 1);
        $this->actingAs($member)->getJson('/api/v1/member-proposals/'.$id)->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.resolution', 'rolled_back')->assertJsonPath('data.result_playlist_id', null);
    }

    public function test_a_title_request_is_applied_only_after_review_and_can_be_safely_undone(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner, 'Original');
        $this->catalogPlaylist($owner, [$track]);
        $member = User::factory()->create();
        $id = $this->actingAs($member)->postJson('/api/v1/member-proposals', ['kind' => 'track_metadata', 'idempotency_key' => (string) Str::uuid(), 'payload' => ['track_id' => $track->id, 'title' => 'Suggested title']])->assertCreated()->json('data.id');
        $this->assertSame('Original', $track->fresh()->title);
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'proposal', 'proposal_id' => $id, 'revision' => 0]);
        $this->commitCatalog($preview)->assertOk();
        $this->assertSame('Suggested title', $track->fresh()->title);
        $this->rollbackCatalog($preview)->assertOk();
        $this->assertSame('Original', $track->fresh()->title);
        $this->assertSame(2, $track->fresh()->catalog_revision);
    }

    public function test_stale_catalog_or_submitter_versions_cannot_be_accepted_but_can_be_rejected_with_a_private_reason(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $this->catalogPlaylist($owner, [$track]);
        $member = User::factory()->create();
        $id = $this->actingAs($member)->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertCreated()->json('data.id');
        $track->update(['catalog_revision' => 1, 'title' => 'Later title']);
        $this->authenticateCatalog($owner);
        $this->postJson('/api/v1/admin/catalog-changes/preview', ['operation' => 'proposal', 'proposal_id' => $id, 'revision' => 0])->assertConflict()->assertJsonPath('message', __('catalog.proposal_snapshot_changed'));
        $this->postJson('/api/v1/admin/proposals/'.$id.'/reject', ['revision' => 0, 'reason' => 'Private review note'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson('/api/v1/admin/proposals/'.$id.'/reject', ['revision' => 0, 'reason' => 'Again'])->assertConflict();
        $this->assertStringNotContainsString('Private review note', AuditEvent::all()->toJson());
        $this->actingAs($member)->getJson('/api/v1/member-proposals/'.$id)->assertOk()->assertJsonPath('data.review_note', 'Private review note');
        $next = $this->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertCreated()->json('data.id');
        $member->increment('access_version');
        $this->authenticateCatalog($owner);
        $this->postJson('/api/v1/admin/catalog-changes/preview', ['operation' => 'proposal', 'proposal_id' => $next, 'revision' => 0])->assertConflict()->assertJsonPath('message', __('catalog.proposal_actor_changed'));
        $this->assertDatabaseCount('catalog_change_sets', 0);
    }

    public function test_invisible_or_trashed_music_cannot_enter_a_member_proposal(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $playlist = $this->catalogPlaylist($owner, [$track], 'draft');
        $member = User::factory()->create();
        $this->actingAs($member)->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertNotFound();
        $playlist->update(['status' => 'published']);
        $track->delete();
        $this->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertNotFound();
        $this->assertDatabaseCount('member_proposals', 0);
    }

    public function test_pending_requests_are_bounded_without_breaking_idempotent_retries_at_capacity(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $this->catalogPlaylist($owner, [$track]);
        $member = User::factory()->create();
        $this->actingAs($member);
        $first = $this->playlistRequest($track);
        $this->postJson('/api/v1/member-proposals', $first)->assertCreated();
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertCreated();
        }
        $this->postJson('/api/v1/member-proposals', $this->playlistRequest($track))->assertUnprocessable()->assertJsonPath('message', __('catalog.proposal_limit'));
        $this->postJson('/api/v1/member-proposals', $first)->assertOk();
        $this->assertDatabaseCount('member_proposals', 10);
        $this->assertDatabaseCount('playlists', 1);
    }

    public function test_owner_step_up_is_required_for_every_review_mutation_and_members_cannot_review(): void
    {
        $owner = $this->catalogOwner();
        $this->authenticateCatalog($owner, false);
        $this->postJson('/api/v1/admin/proposals/'.Str::uuid().'/reject', ['revision' => 0, 'reason' => 'Reason'])->assertStatus(423);
        foreach ([UserRole::Administrator, UserRole::Curator, UserRole::Listener] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->authenticateCatalog($user);
            $this->getJson('/api/v1/admin/proposals')->assertForbidden();
            $this->postJson('/api/v1/admin/proposals/'.Str::uuid().'/reject', ['revision' => 0, 'reason' => 'Reason'])->assertForbidden();
        }
        $this->assertDatabaseCount('member_proposals', 0);
    }
}
