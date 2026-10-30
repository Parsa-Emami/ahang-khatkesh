<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class CatalogWorkflowTest extends TestCase
{
    use CatalogFixtures, LazilyRefreshDatabase;

    public function test_diagnostics_distinguish_recording_identity_from_homonyms_and_shared_bytes_without_exposing_paths(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $root = $this->catalogTrack($owner, 'Homonym', 'isrc:USAAA2600001');
        $same = $this->catalogTrack($owner, 'Different spelling', 'isrc:USAAA2600001', 1);
        $homonym = $this->catalogTrack($owner, 'Homonym', null, 2);
        $copy = $this->catalogTrack($owner, 'Different recording', null);
        Storage::disk('media')->delete($root->mediaAsset->storagePath());
        $this->authenticateCatalog($owner);
        $response = $this->getJson('/api/v1/admin/catalog-health')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $root->id);
        $this->assertSame([$same->id], array_column($row['identity_matches'], 'id'));
        $this->assertSame([$homonym->id], array_column($row['title_matches'], 'id'));
        $this->assertSame([$copy->id], array_column($row['bytes_matches'], 'id'));
        $this->assertFalse($row['source_exists']);
        $this->assertContains('missing_source', $row['issues']);
        foreach (['storage_path', 'path', 'password', 'lease_token', 'snapshot'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', $response->getContent());
        }
        $this->assertDatabaseCount('tracks', 4);
        $this->assertDatabaseCount('catalog_change_sets', 0);
    }

    public function test_workflow_counts_are_capability_scoped_and_include_inactive_members_in_capacity(): void
    {
        $owner = $this->catalogOwner();
        User::factory()->create(['is_active' => false]);
        $this->authenticateCatalog($owner);
        $rows = $this->getJson('/api/v1/admin/workflows')->assertOk()->json('data');
        $this->assertCount(9, $rows);
        $users = collect($rows)->firstWhere('id', 'users');
        $this->assertSame(2, $users['count']);
        $this->assertSame(10, $users['capacity']);
        $curator = User::factory()->create(['role' => UserRole::Curator]);
        $this->authenticateCatalog($curator);
        $ids = array_column($this->getJson('/api/v1/admin/workflows')->assertOk()->json('data'), 'id');
        $this->assertNotContains('users', $ids);
        $this->assertNotContains('proposals', $ids);
        $this->assertNotContains('changes', $ids);
        $this->getJson('/api/v1/admin/catalog-health')->assertForbidden();
    }

    public function test_bulk_playlist_metadata_and_cut_move_undo_restore_exact_legacy_positions(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $tracks = [$this->catalogTrack($owner), $this->catalogTrack($owner, sample: 1)];
        $source = $this->catalogPlaylist($owner, $tracks, 'draft');
        $target = $this->catalogPlaylist($owner, [], 'draft');
        DB::table('playlist_track')->where('playlist_id', $source->id)->where('track_id', $tracks[0]->id)->update(['position' => 0]);
        DB::table('playlist_track')->where('playlist_id', $source->id)->where('track_id', $tracks[1]->id)->update(['position' => 40]);
        $this->authenticateCatalog($owner);
        $change = $this->previewCatalog(['operation' => 'move', 'source_playlist_id' => $source->id, 'source_revision' => 0, 'target_playlist_id' => $target->id, 'target_revision' => 0, 'track_ids' => [$tracks[0]->id]]);
        $this->commitCatalog($change)->assertOk();
        $this->rollbackCatalog($change)->assertOk();
        $this->assertSame([0, 40], DB::table('playlist_track')->where('playlist_id', $source->id)->orderBy('position')->pluck('position')->all());
        $change = $this->previewCatalog(['operation' => 'playlists', 'rows' => [['id' => $source->id, 'revision' => 2, 'name' => 'Renamed in bulk', 'description' => 'Edited', 'mood' => 'focus', 'status' => 'published']]]);
        $this->commitCatalog($change)->assertOk();
        $this->assertSame('published', $source->fresh()->status);
        $this->travel(8)->days();
        $this->authenticateCatalog($owner);
        $this->rollbackCatalog($change)->assertStatus(410);
        $this->assertSame('Renamed in bulk', $source->fresh()->name);
    }

    public function test_batch_metadata_preview_keeps_reference_queries_bounded_as_row_count_grows(): void
    {
        Storage::fake('media');
        $owner = $this->catalogOwner();
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = $this->catalogRow($this->catalogTrack($owner, 'Track '.$i), 'Updated '.$i);
        }
        $this->authenticateCatalog($owner);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->previewCatalog(['operation' => 'metadata', 'rows' => [$rows[0]]]);
            $single = count(DB::getQueryLog());
            DB::flushQueryLog();
            $this->previewCatalog(['operation' => 'metadata', 'rows' => $rows]);
            $multiple = count(DB::getQueryLog());
            $this->assertLessThanOrEqual($single + 4, $multiple);
        } finally {
            DB::disableQueryLog();
        }
    }
}
