<?php

namespace Tests\Feature;

use App\Actions\SaveArtist;
use App\Enums\UserRole;
use App\Models\Artist;
use App\Models\AuditEvent;
use App\Models\CatalogChangeSet;
use App\Models\ListeningEvent;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CatalogFixtures;
use Tests\Support\CreatesConversions;
use Tests\Support\FlacFixture;
use Tests\TestCase;

class CatalogBulkChangeTest extends TestCase
{
    use CatalogFixtures, LazilyRefreshDatabase;
    use CreatesConversions;

    public function test_preview_commit_replay_and_rollback_preserve_ids_likes_and_audit_only_safe_metadata(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $a = $this->catalogTrack($owner, 'Original A');
        $b = $this->catalogTrack($owner, 'Original B');
        $owner->likedTracks()->attach([$a->id, $b->id]);
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'metadata', 'rows' => [$this->catalogRow($a, 'Secret new A'), $this->catalogRow($b, 'New B')]]);
        $this->assertSame('Original A', $a->fresh()->title);
        $this->assertSame(0, $a->fresh()->catalog_revision);
        $this->commitCatalog($preview)->assertOk()->assertJsonPath('data.status', 'committed')->assertJsonMissingPath('data.before')->assertJsonMissingPath('data.input');
        $this->commitCatalog($preview)->assertOk();
        $this->assertSame('Secret new A', $a->fresh()->title);
        $this->assertSame(1, $a->fresh()->catalog_revision);
        $this->assertSame(1, AuditEvent::where('action', 'catalog.batch_committed')->count());
        $this->assertStringNotContainsString('Secret new A', AuditEvent::all()->toJson());
        $this->rollbackCatalog($preview)->assertOk()->assertJsonPath('data.status', 'rolled_back');
        $this->rollbackCatalog($preview)->assertOk();
        $this->commitCatalog($preview)->assertConflict();
        $this->assertSame('Original A', $a->fresh()->title);
        $this->assertSame('Original B', $b->fresh()->title);
        $this->assertSame(2, $a->fresh()->catalog_revision);
        $this->assertDatabaseCount('tracks', 2);
        $this->assertDatabaseCount('liked_tracks', 2);
    }

    public function test_one_stale_row_prevents_the_entire_batch_and_later_edits_prevent_rollback(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $a = $this->catalogTrack($owner, 'Original A');
        $b = $this->catalogTrack($owner, 'Original B');
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'metadata', 'rows' => [$this->catalogRow($a, 'New A'), $this->catalogRow($b, 'New B')]]);
        $b->update(['catalog_revision' => 1, 'title' => 'Other edit']);
        $this->commitCatalog($preview)->assertConflict();
        $this->assertSame('Original A', $a->fresh()->title);
        $next = $this->previewCatalog(['operation' => 'metadata', 'rows' => [$this->catalogRow($a, 'Committed A')]]);
        $this->commitCatalog($next)->assertOk();
        $a->refresh()->update(['catalog_revision' => 2, 'title' => 'Later edit']);
        $this->rollbackCatalog($next)->assertConflict();
        $this->assertSame('Later edit', $a->fresh()->title);
    }

    public function test_a_failure_during_the_second_write_rolls_back_all_rows_and_audit_entries(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $a = $this->catalogTrack($owner, 'Original A');
        $b = $this->catalogTrack($owner, 'Original B');
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'metadata', 'rows' => [$this->catalogRow($a, 'New A'), $this->catalogRow($b, 'New B')]]);
        Track::updating(function (Track $track) use ($b): void {
            if ($track->id === $b->id && $track->title === 'New B') {
                throw new RuntimeException('Injected transactional failure');
            }
        });
        $this->commitCatalog($preview)->assertStatus(500);
        $this->assertSame('Original A', $a->fresh()->title);
        $this->assertSame('Original B', $b->fresh()->title);
        $this->assertSame('previewed', CatalogChangeSet::findOrFail($preview['id'])->status);
        $this->assertSame(0, AuditEvent::where('action', 'catalog.track_updated')->count());
    }

    public function test_expiry_actor_versions_and_preview_ownership_are_enforced(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'metadata', 'rows' => [$this->catalogRow($track, 'New')]]);
        $other = $this->catalogOwner();
        $this->authenticateCatalog($other);
        $this->getJson('/api/v1/admin/catalog-changes/'.$preview['id'])->assertNotFound();
        $this->commitCatalog($preview)->assertNotFound();
        $this->travel(16)->minutes();
        $this->authenticateCatalog($owner);
        $this->commitCatalog($preview)->assertGone();
        $new = $this->previewCatalog(['operation' => 'metadata', 'rows' => [$this->catalogRow($track, 'New')]]);
        $owner->increment('access_version');
        $this->authenticateCatalog($owner->fresh());
        $this->commitCatalog($new)->assertConflict();
        $this->assertSame('Recording', $track->fresh()->title);
    }

    public function test_move_is_a_previewed_cut_with_exact_order_and_published_empty_protection(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        [$a, $b, $c] = [$this->catalogTrack($owner, 'A'), $this->catalogTrack($owner, 'B'), $this->catalogTrack($owner, 'C')];
        $source = $this->catalogPlaylist($owner, [$a, $b]);
        $target = $this->catalogPlaylist($owner, [$c]);
        $this->authenticateCatalog($owner);
        $input = ['operation' => 'move', 'source_playlist_id' => $source->id, 'target_playlist_id' => $target->id, 'source_revision' => 0, 'target_revision' => 0, 'track_ids' => [$a->id]];
        $preview = $this->previewCatalog($input);
        $this->assertSame([$a->id, $b->id], $source->tracks()->pluck('tracks.id')->all());
        $this->commitCatalog($preview)->assertOk();
        $this->assertSame([$b->id], $source->tracks()->pluck('tracks.id')->all());
        $this->assertSame([$c->id, $a->id], $target->tracks()->pluck('tracks.id')->all());
        $this->rollbackCatalog($preview)->assertOk();
        $this->assertSame([$a->id, $b->id], $source->tracks()->pluck('tracks.id')->all());
        $this->assertSame([$c->id], $target->tracks()->pluck('tracks.id')->all());
        $input['source_revision'] = 2;
        $input['target_revision'] = 2;
        $input['track_ids'] = [$a->id, $b->id];
        $this->postJson('/api/v1/admin/catalog-changes/preview', $input)->assertUnprocessable()->assertJsonPath('message', __('catalog.empty_published'));
    }

    public function test_merge_uses_proven_identity_and_keeps_alias_streams_and_likes_without_rewriting_personal_data(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $a = $this->catalogTrack($owner, 'Canonical', 'isrc:USAAA2600001');
        $b = $this->catalogTrack($owner, 'Alias', 'isrc:USAAA2600001');
        $playlist = $this->catalogPlaylist($owner, [$b, $a]);
        $listener = User::factory()->create();
        $listener->likedTracks()->attach($b->id);
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'merge', 'canonical_id' => $a->id, 'canonical_revision' => 0, 'source_ids' => [$b->id], 'recording_identity' => $a->recording_identity, 'recording_provenance' => $a->recording_provenance]);
        $this->commitCatalog($preview)->assertOk();
        $this->assertDatabaseCount('tracks', 2);
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $listener->id, 'track_id' => $b->id]);
        $this->assertSame([$a->id], $playlist->tracks()->pluck('tracks.id')->all());
        $this->assertSame($a->id, $b->fresh()->merged_into_id);
        $this->actingAs($listener)->getJson('/api/v1/tracks?liked=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.0.is_liked', true);
        $this->get('/api/v1/tracks/'.$b->id.'/stream', ['Range' => 'bytes=0-43'])->assertStatus(206);
        $this->authenticateCatalog($owner);
        $this->rollbackCatalog($preview)->assertOk();
        $this->assertNull($b->fresh()->merged_into_id);
        $this->assertSame([$b->id, $a->id], $playlist->tracks()->pluck('tracks.id')->all());
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $listener->id, 'track_id' => $b->id]);
    }

    public function test_merge_rollback_cannot_resurrect_erased_history_or_a_members_explicit_unlike(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $a = $this->catalogTrack($owner, 'A', 'isrc:USAAA2600001');
        $b = $this->catalogTrack($owner, 'B', 'isrc:USAAA2600001');
        $this->catalogPlaylist($owner, [$a, $b]);
        $listener = User::factory()->create();
        $listener->forceFill(['listening_history_enabled' => true, 'listening_consent_at' => now()])->save();
        $listener->likedTracks()->attach($b->id);
        $event = ListeningEvent::create(['id' => (string) Str::uuid(), 'user_id' => $listener->id, 'track_id' => $b->id, 'session_pseudonym' => str_repeat('a', 64), 'started_at' => now(), 'last_event_at' => now(), 'ended_at' => now(), 'listened_seconds' => 30, 'outcome' => 'skip']);
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'merge', 'canonical_id' => $a->id, 'canonical_revision' => 0, 'source_ids' => [$b->id], 'recording_identity' => $a->recording_identity, 'recording_provenance' => $a->recording_provenance]);
        $response = $this->commitCatalog($preview)->assertOk();
        $this->assertStringNotContainsString($event->id, $response->getContent());
        $this->assertStringNotContainsString($event->id, json_encode(CatalogChangeSet::findOrFail($preview['id'])->before, JSON_THROW_ON_ERROR));
        $this->actingAs($listener)->deleteJson('/api/v1/listening/history', ['revision' => 0])->assertOk();
        $this->deleteJson('/api/v1/tracks/'.$a->id.'/like')->assertOk();
        $this->authenticateCatalog($owner);
        $this->rollbackCatalog($preview)->assertOk();
        $this->assertDatabaseCount('listening_events', 0);
        $this->assertDatabaseCount('liked_tracks', 0);
    }

    public function test_matching_names_and_bytes_are_not_recording_identity_proof(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $a = $this->catalogTrack($owner, 'Homonym', 'isrc:USAAA2600001');
        $b = $this->catalogTrack($owner, 'Homonym');
        $this->catalogPlaylist($owner, [$a, $b]);
        $this->authenticateCatalog($owner);
        $this->postJson('/api/v1/admin/catalog-changes/preview', ['operation' => 'merge', 'canonical_id' => $a->id, 'canonical_revision' => 0, 'source_ids' => [$b->id], 'recording_identity' => $a->recording_identity, 'recording_provenance' => $a->recording_provenance])->assertUnprocessable()->assertJsonPath('message', __('catalog.identity_mismatch'));
        $this->assertDatabaseCount('catalog_change_sets', 0);
        $this->assertNull($b->fresh()->merged_into_id);
    }

    public function test_repair_verifies_bytes_preserves_the_track_and_only_rolls_back_to_a_restored_source(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner, 'Repair', 'isrc:USAAA2600001');
        $original = $track->mediaAsset;
        $replacement = $this->catalogAsset($owner, 1);
        Storage::disk('media')->delete($original->storagePath());
        $owner->likedTracks()->attach($track->id);
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'repair', 'track_id' => $track->id, 'revision' => 0, 'asset_id' => $replacement->id, 'recording_identity' => $track->recording_identity, 'recording_provenance' => $track->recording_provenance]);
        $this->commitCatalog($preview)->assertOk();
        $this->assertSame($replacement->id, $track->fresh()->media_asset_id);
        $this->assertDatabaseHas('track_media', ['track_id' => $track->id, 'media_asset_id' => $replacement->id, 'purpose' => 'original']);
        $this->rollbackCatalog($preview)->assertUnprocessable();
        Storage::disk('media')->put($original->storagePath(), $this->catalogBytes());
        $this->rollbackCatalog($preview)->assertOk();
        $this->assertSame($original->id, $track->fresh()->media_asset_id);
        $this->assertDatabaseHas('liked_tracks', ['user_id' => $owner->id, 'track_id' => $track->id]);
    }

    public function test_replacement_tampering_between_preview_and_commit_is_rejected(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner, 'Repair', 'isrc:USAAA2600001');
        $replacement = $this->catalogAsset($owner, 1);
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'repair', 'track_id' => $track->id, 'revision' => 0, 'asset_id' => $replacement->id, 'recording_identity' => $track->recording_identity, 'recording_provenance' => $track->recording_provenance]);
        Storage::disk('media')->put($replacement->storagePath(), 'Changed bytes');
        $this->commitCatalog($preview)->assertUnprocessable()->assertJsonPath('message', __('catalog.media_mismatch'));
        $this->assertNotSame($replacement->id, $track->fresh()->media_asset_id);
    }

    public function test_rollback_fences_changes_to_an_old_artist_no_longer_in_the_current_credits(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $track = $this->catalogTrack($owner);
        $old = $track->artists->first();
        $next = Artist::create(['name' => 'Next artist']);
        $row = $this->catalogRow($track, 'Next title');
        $row['credits'] = [['artist_id' => $next->id, 'role' => 'primary']];
        $this->authenticateCatalog($owner);
        $preview = $this->previewCatalog(['operation' => 'metadata', 'rows' => [$row]]);
        $this->commitCatalog($preview)->assertOk();
        app(SaveArtist::class)->handle($owner, ['revision' => 0, 'name' => 'Later old artist'], $old);
        $this->rollbackCatalog($preview)->assertConflict();
        $this->assertSame('Next artist', $track->fresh()->artist);
    }

    public function test_catalog_workflows_require_super_administrator_mfa_and_recent_confirmation(): void
    {
        $this->getJson('/api/v1/admin/catalog-changes')->assertUnauthorized();
        foreach ([UserRole::Listener, UserRole::Curator, UserRole::Administrator] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->authenticateCatalog($user);
            $this->getJson('/api/v1/admin/catalog-changes')->assertForbidden();
            $this->postJson('/api/v1/admin/catalog-changes/preview', [])->assertForbidden();
        }
        $owner = $this->catalogOwner();
        $this->authenticateCatalog($owner, false);
        $this->postJson('/api/v1/admin/catalog-changes/preview', [])->assertStatus(423);
        $this->assertDatabaseCount('catalog_change_sets', 0);
    }

    public function test_alias_streams_follow_current_reference_visibility_and_canonical_bytes_are_rechecked(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $a = $this->catalogTrack($owner, 'Root', 'isrc:USAAA2600001');
        $b = $this->catalogTrack($owner, 'Source', 'isrc:USAAA2600001', 1);
        $playlist = $this->catalogPlaylist($owner, [$a, $b]);
        $this->authenticateCatalog($owner);
        $input = ['operation' => 'merge', 'canonical_id' => $a->id, 'canonical_revision' => 0, 'source_ids' => [$b->id], 'recording_identity' => $a->recording_identity, 'recording_provenance' => $a->recording_provenance];
        $preview = $this->previewCatalog($input);
        Storage::disk('media')->put($a->mediaAsset->storagePath(), 'Tampered canonical');
        $this->commitCatalog($preview)->assertUnprocessable();
        $this->assertNull($b->fresh()->merged_into_id);
        Storage::disk('media')->put($a->mediaAsset->storagePath(), $this->catalogBytes());
        $this->commitCatalog($preview)->assertOk();
        $member = User::factory()->create();
        $this->actingAs($member)->withHeader('Range', 'bytes=0-31')->get('/api/v1/tracks/'.$b->id.'/stream')->assertStatus(206);
        $this->authenticateCatalog($owner);
        $this->deleteJson('/api/v1/admin/tracks/'.$a->id, ['revision' => 1])->assertOk();
        $this->actingAs($member)->get('/api/v1/tracks/'.$b->id.'/stream')->assertNotFound();
        $this->putJson('/api/v1/tracks/'.$b->id.'/like')->assertNotFound();
        $this->authenticateCatalog($owner);
        $this->postJson('/api/v1/admin/tracks/'.$a->id.'/restore', ['revision' => 2])->assertOk();
        $this->actingAs($member)->withHeader('Range', 'bytes=0-31')->get('/api/v1/tracks/'.$b->id.'/stream')->assertStatus(206);
    }

    public function test_flac_repair_requires_real_validated_output_bytes_at_preview_and_commit(): void
    {
        $owner = $this->library();
        $track = $this->catalogTrack($owner, 'FLAC repair', 'isrc:USAAA2600001');
        $file = UploadedFile::fake()->createWithContent('replacement.flac', FlacFixture::bytes(2, 44100, 440, 40));
        [$asset, $conversion] = $this->source($owner, $file);
        $this->runConversion($conversion);
        $this->assertSame('ready', $conversion->fresh()->status);
        $output = $conversion->fresh()->output;
        $bytes = Storage::disk('media')->get($output->storagePath());
        Storage::disk('media')->delete($output->storagePath());
        $this->authenticateCatalog($owner);
        $input = ['operation' => 'repair', 'track_id' => $track->id, 'revision' => 0, 'asset_id' => $asset->id, 'recording_identity' => $track->recording_identity, 'recording_provenance' => $track->recording_provenance];
        $this->postJson('/api/v1/admin/catalog-changes/preview', $input)->assertUnprocessable();
        Storage::disk('media')->put($output->storagePath(), $bytes);
        $preview = $this->previewCatalog($input);
        Storage::disk('media')->put($output->storagePath(), 'Tampered output');
        $this->commitCatalog($preview)->assertUnprocessable();
        $this->assertNotSame($asset->id, $track->fresh()->media_asset_id);
    }
}
