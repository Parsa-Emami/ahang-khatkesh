<?php

namespace Tests\Feature;

use App\Models\ListeningEvent;
use App\Models\MediaAsset;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExplainableMixTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function catalog(User $user): array
    {
        $playlist = Playlist::factory()->create(['created_by' => $user->id]);
        $tracks = [];
        for ($i = 0; $i < 8; $i++) {
            $track = Track::factory()->create(['artist' => 'Artist '.intdiv($i, 3), 'album' => 'Release '.intdiv($i, 2), 'media_asset_id' => MediaAsset::factory()->create(['uploaded_by' => $user->id])]);
            $playlist->tracks()->attach($track->id, ['position' => $i]);
            $tracks[] = $track;
        }

        return $tracks;
    }

    public function test_cold_start_is_curated_deterministic_diverse_and_never_writes_catalog(): void
    {
        $user = User::factory()->create();
        $tracks = $this->catalog($user);
        $this->actingAs($user);
        $first = $this->getJson('/api/v1/listening/mix')->assertOk()->assertJsonPath('basis', 'curated')->json('data');
        $second = $this->getJson('/api/v1/listening/mix')->assertOk()->json('data');
        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
        $counts = array_count_values(array_column(array_column($first, 'track'), 'artist'));
        $this->assertLessThanOrEqual(2, max($counts));
        foreach ($first as $row) {
            $this->assertSame($row['score'], array_sum(array_column($row['reasons'], 'weight')));
        }
        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseCount('tracks', count($tracks));
    }

    public function test_likes_are_explained_without_using_another_users_likes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tracks = $this->catalog($user);
        $user->likedTracks()->attach($tracks[7]->id);
        $other->likedTracks()->attach($tracks[0]->id);
        $rows = $this->actingAs($user)->getJson('/api/v1/listening/mix')->assertOk()->assertJsonPath('basis', 'personalized')->json('data');
        $this->assertSame($tracks[7]->id, $rows[0]['track']['id']);
        $this->assertContains('liked', array_column($rows[0]['reasons'], 'code'));
        foreach ($rows as $row) {
            if ($row['track']['id'] === $tracks[0]->id) {
                $this->assertNotContains('liked', array_column($row['reasons'], 'code'));
            }
        }
    }

    public function test_private_sessions_use_only_curated_signals_and_opt_out_returns_no_mix(): void
    {
        $user = User::factory()->create();
        $tracks = $this->catalog($user);
        $user->likedTracks()->attach($tracks[7]->id);
        $this->actingAs($user);
        $this->patchJson('/api/v1/listening/settings', ['revision' => 0, 'private_session' => true])->assertOk();
        $rows = $this->getJson('/api/v1/listening/mix')->assertOk()->assertJsonPath('basis', 'curated')->json('data');
        foreach ($rows as $row) {
            $this->assertSame(['curated'], array_column($row['reasons'], 'code'));
        }
        $this->patchJson('/api/v1/listening/settings', ['revision' => 1, 'recommendations_enabled' => false])->assertOk();
        $this->getJson('/api/v1/listening/mix')->assertOk()->assertJsonPath('basis', 'disabled')->assertJsonCount(0, 'data');
    }

    public function test_drafts_trash_unready_media_and_nonmatching_moods_are_excluded(): void
    {
        $user = User::factory()->create();
        $tracks = $this->catalog($user);
        $tracks[0]->delete();
        $tracks[1]->mediaAsset->blob->update(['state' => 'garbage']);
        $draft = Track::factory()->create();
        Playlist::factory()->draft()->create()->tracks()->attach($draft->id, ['position' => 0]);
        $this->actingAs($user);
        $ids = array_column(array_column($this->getJson('/api/v1/listening/mix')->assertOk()->json('data'), 'track'), 'id');
        foreach ([$tracks[0]->id, $tracks[1]->id, $draft->id] as $id) {
            $this->assertNotContains($id, $ids);
        }
        $this->getJson('/api/v1/listening/mix?mood=night')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/listening/mix?mood=invalid')->assertUnprocessable();
    }

    public function test_retained_own_history_recency_and_mood_have_explainable_weights(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $tracks = $this->catalog($user);
        $user->forceFill(['listening_history_enabled' => true])->save();
        foreach ([[$user, $tracks[7], now()], [$user, $tracks[6], now()->subDays(31)], [$other, $tracks[0], now()]] as [$actor, $track, $at]) {
            ListeningEvent::create(['id' => (string) Str::uuid(), 'user_id' => $actor->id, 'track_id' => $track->id, 'session_pseudonym' => str_repeat('a', 64), 'started_at' => $at, 'last_event_at' => $at, 'ended_at' => $at, 'listened_seconds' => 100, 'outcome' => 'completed']);
        }
        $rows = $this->actingAs($user)->getJson('/api/v1/listening/mix?mood=focus')->assertOk()->assertJsonPath('basis', 'personalized')->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $reasons = array_column($row['reasons'], 'weight', 'code');
            $this->assertSame(20, $reasons['mood']);
            if ($row['track']['id'] === $tracks[7]->id) {
                $this->assertSame(8, $reasons['history']);
                $this->assertSame(-20, $reasons['recent']);
            } else {
                $this->assertArrayNotHasKey('history', $reasons);
            }
        }
        $this->assertContains($tracks[7]->id, array_column(array_column($rows, 'track'), 'id'));
    }
}
