<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\TrackLyric;
use App\Models\User;
use App\Support\Lyrics\LrcParser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LyricsTimelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_parser_strips_bom_controls_duplicate_timestamps_and_rejects_markup(): void
    {
        $parser = new LrcParser;
        $parsed = $parser->parse("\xEF\xBB\xBF[ti:Demo]\n[00:01.00]one\n[00:01.00]later\n[00:02.00]two");
        $this->assertSame('Demo', $parsed['tags']['ti']);
        $this->assertSame([['time' => 1.0, 'text' => 'later'], ['time' => 2.0, 'text' => 'two']], $parsed['lines']);
        $this->expectExceptionMessage('lyrics_markup_forbidden');
        $parser->parse('[00:01.00]<script>x</script>');
    }

    public function test_owner_can_save_licensed_lrc_and_listeners_get_seek_synced_lines(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $listener = User::factory()->create();
        $track = Track::factory()->create();
        Playlist::factory()->create(['created_by' => $owner->id, 'status' => Playlist::PUBLISHED])->tracks()->attach($track->id, ['position' => 1]);
        $body = "[00:00.00]شروع\n[00:05.50]میانه\n[00:10.00]پایان";
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->putJson('/api/v1/admin/tracks/'.$track->id.'/lyrics', [
            'revision' => 0,
            'format' => 'lrc',
            'source' => 'licensed',
            'license_note' => 'Publisher grant 2026',
            'body' => $body,
        ])->assertCreated()->assertJsonPath('data.format', 'lrc')->assertJsonPath('data.lines.1.text', 'میانه');
        $this->actingAs($listener);
        $this->getJson('/api/v1/tracks/'.$track->id.'/lyrics')->assertOk()
            ->assertJsonPath('data.source', 'licensed')
            ->assertJsonPath('data.lines.0.time', 0)
            ->assertJsonPath('data.lines.1.time', 5.5)
            ->assertJsonPath('data.lines.2.text', 'پایان');
        $this->assertSame(1, TrackLyric::where('track_id', $track->id)->value('revision'));
    }

    public function test_html_negative_time_and_past_duration_lyrics_are_rejected(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $track = Track::factory()->create();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->putJson('/api/v1/admin/tracks/'.$track->id.'/lyrics', [
            'revision' => 0,
            'format' => 'plain',
            'source' => 'manual',
            'body' => 'hello <b>x</b>',
        ])->assertUnprocessable();
        $this->putJson('/api/v1/admin/tracks/'.$track->id.'/lyrics', [
            'revision' => 0,
            'format' => 'lrc',
            'source' => 'manual',
            'body' => '[00:70.00]bad',
        ])->assertUnprocessable();
        $seconds = (int) ceil($track->mediaAsset->duration_seconds) + 5;
        $over = sprintf('[%d:%02d.00]too late', intdiv($seconds, 60), $seconds % 60);
        $this->putJson('/api/v1/admin/tracks/'.$track->id.'/lyrics', [
            'revision' => 0,
            'format' => 'lrc',
            'source' => 'manual',
            'body' => $over,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('track_lyrics', 0);
    }

    public function test_member_cannot_write_lyrics_and_unpublished_tracks_stay_hidden(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $member = User::factory()->create();
        $track = Track::factory()->create();
        $this->actingAs($member)->putJson('/api/v1/admin/tracks/'.$track->id.'/lyrics', [
            'revision' => 0,
            'format' => 'plain',
            'source' => 'manual',
            'body' => 'secret',
        ])->assertForbidden();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->putJson('/api/v1/admin/tracks/'.$track->id.'/lyrics', [
            'revision' => 0,
            'format' => 'plain',
            'source' => 'manual',
            'body' => 'only after publish',
        ])->assertCreated();
        $this->actingAs($member)->getJson('/api/v1/tracks/'.$track->id.'/lyrics')->assertNotFound();
    }

    public function test_loudness_metadata_is_stored_without_rewriting_master_bytes(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $track = Track::factory()->create();
        $path = $track->mediaAsset->storagePath();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_confirmed_at' => now()->timestamp, 'auth.mfa_authenticated' => true]);
        $this->putJson('/api/v1/admin/media/'.$track->media_asset_id.'/loudness', [
            'loudness_lufs' => -9.5,
            'loudness_peak' => -1.2,
        ])->assertOk()->assertJsonPath('data.master_rewritten', false)->assertJsonPath('data.loudness_lufs', -9.5);
        $this->assertSame($path, $track->mediaAsset->fresh()->storagePath());
        $this->assertSame(-9.5, (float) $track->fresh()->mediaAsset->loudness_lufs);
    }
}
