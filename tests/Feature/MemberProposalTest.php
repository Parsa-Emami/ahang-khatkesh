<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Artist;
use App\Models\MemberProposal;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class MemberProposalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);

        return $owner;
    }

    public function test_member_can_propose_metadata_without_writing_catalog(): void
    {
        $member = User::factory()->create(['role' => UserRole::Listener]);
        $track = Track::factory()->create(['title' => 'اصلی']);
        $this->actingAs($member);
        $proposal = $this->postJson('/api/v1/proposals', [
            'kind' => 'track_metadata',
            'track_id' => $track->id,
            'title' => 'پیشنهادی',
            'note' => 'لطفاً بررسی شود',
        ])->assertCreated()->json('data');
        $this->assertSame('pending', $proposal['status']);
        $this->assertSame('اصلی', $track->fresh()->title);
        $this->assertDatabaseCount('tracks', 1);
        $this->getJson('/api/v1/proposals')->assertOk()->assertJsonPath('data.0.id', $proposal['id']);
        $this->patchJson('/api/v1/admin/tracks/'.$track->id, [
            'revision' => 0,
            'title' => 'حمله مستقیم',
            'credits' => [['artist_id' => Artist::create(['name' => 'X', 'search_text' => 'x'])->id, 'role' => 'primary']],
        ])->assertForbidden();
    }

    public function test_only_owner_can_accept_proposal_and_apply_catalog_change(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create(['role' => UserRole::Listener]);
        $track = Track::factory()->create(['title' => 'کهنه']);
        $this->actingAs($member);
        $proposal = $this->postJson('/api/v1/proposals', [
            'kind' => 'track_metadata',
            'track_id' => $track->id,
            'title' => 'تأییدشده',
        ])->assertCreated()->json('data');
        $this->actingAs($member)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->postJson('/api/v1/admin/proposals/'.$proposal['id'].'/accept', ['revision' => 0])->assertForbidden();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->postJson('/api/v1/admin/proposals/'.$proposal['id'].'/accept', ['revision' => 0])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame('تأییدشده', $track->fresh()->title);
        $this->assertNotNull(MemberProposal::find($proposal['id'])->accepted_batch_id);
        $this->postJson('/api/v1/admin/proposals/'.$proposal['id'].'/accept', ['revision' => 1])->assertConflict();
    }

    public function test_owner_can_reject_and_member_can_withdraw_without_catalog_mutation(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create();
        $track = Track::factory()->create(['title' => 'ثابت']);
        $this->actingAs($member);
        $first = $this->postJson('/api/v1/proposals', ['kind' => 'track_metadata', 'track_id' => $track->id, 'title' => 'رد'])->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/proposals', ['kind' => 'track_metadata', 'track_id' => $track->id, 'title' => 'انصراف'])->assertCreated()->json('data');
        $this->deleteJson('/api/v1/proposals/'.$second['id'], ['revision' => 0])->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->postJson('/api/v1/admin/proposals/'.$first['id'].'/reject', ['revision' => 0, 'review_note' => 'ناکافی'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('ثابت', $track->fresh()->title);
        $this->getJson('/api/v1/admin/proposals?status=pending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_accepted_playlist_proposal_creates_draft_owned_by_superadmin_only(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create();
        $tracks = Track::factory()->count(2)->create();
        $this->actingAs($member);
        $proposal = $this->postJson('/api/v1/proposals', [
            'kind' => 'playlist_create',
            'name' => 'شب پیشنهادی',
            'mood' => 'night',
            'track_ids' => $tracks->pluck('id')->all(),
        ])->assertCreated()->json('data');
        $this->assertDatabaseCount('playlists', 0);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->postJson('/api/v1/admin/proposals/'.$proposal['id'].'/accept', ['revision' => 0])->assertOk();
        $playlist = Playlist::first();
        $this->assertNotNull($playlist);
        $this->assertSame(Playlist::DRAFT, $playlist->status);
        $this->assertSame($owner->id, $playlist->created_by);
        $this->assertSame(2, $playlist->tracks()->count());
        $this->actingAs($member);
        $this->getJson('/api/v1/playlists/'.$playlist->id)->assertNotFound();
    }

    public function test_duplicate_report_acceptance_merges_into_suggested_survivor(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create();
        $survivor = Track::factory()->create(['title' => 'اصل']);
        $source = Track::factory()->create(['title' => 'تکراری']);
        $this->actingAs($member);
        $proposal = $this->postJson('/api/v1/proposals', [
            'kind' => 'duplicate_report',
            'left_track_id' => $survivor->id,
            'right_track_id' => $source->id,
            'suggested_survivor_track_id' => $survivor->id,
        ])->assertCreated()->json('data');
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->postJson('/api/v1/admin/proposals/'.$proposal['id'].'/accept', ['revision' => 0])->assertOk();
        $this->assertNotNull($source->fresh()->deleted_at);
        $this->assertNull($survivor->fresh()->deleted_at);
    }

    public function test_stale_proposal_revision_cannot_be_accepted_after_catalog_drift(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create();
        $track = Track::factory()->create(['title' => 'پایه']);
        $this->actingAs($member);
        $proposal = $this->postJson('/api/v1/proposals', [
            'kind' => 'track_metadata',
            'track_id' => $track->id,
            'title' => 'تازه',
        ])->assertCreated()->json('data');
        $artist = Artist::create(['name' => 'هنرمند', 'search_text' => 'هنرمند']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->patchJson('/api/v1/admin/tracks/'.$track->id, [
            'revision' => 0,
            'title' => 'دستی',
            'credits' => [['artist_id' => $artist->id, 'role' => 'primary']],
        ])->assertOk();
        $this->postJson('/api/v1/admin/proposals/'.$proposal['id'].'/accept', ['revision' => 0])->assertConflict();
        $this->assertSame('دستی', $track->fresh()->title);
        $this->assertSame('pending', MemberProposal::find($proposal['id'])->status);
    }
}
